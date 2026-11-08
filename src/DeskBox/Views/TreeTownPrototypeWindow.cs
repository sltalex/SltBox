#if DEBUG
using System.Text;
using System.Text.Json;
using DeskBox.Contracts;
using DeskBox.Services;
using Microsoft.Extensions.DependencyInjection;
using Microsoft.UI.Windowing;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
using Microsoft.Web.WebView2.Core;
using Windows.Graphics;

namespace DeskBox.Views;

internal sealed partial class TreeTownPrototypeWindow : Window
{
    private const string VirtualHost = "deskbox-town-prototype.local";
    private static TreeTownPrototypeWindow? _current;
    private readonly WebView2 _web = new();
    private readonly Grid _root = new();
    private readonly TextBlock _message = new()
    {
        Text = "正在打开小镇预览…", Margin = new Thickness(28),
        TextWrapping = TextWrapping.Wrap, VerticalAlignment = VerticalAlignment.Center,
        HorizontalAlignment = HorizontalAlignment.Center
    };
    // Shared town state is global (main world), unlike the old account-scoped
    // prototype session.  The session still exposes the small compatibility
    // surface used by the optional native probe.
    private readonly SharedTownSession _session;
    private bool _ready;
    private bool _closed;
    private bool _allowClose;
    private bool _askingClose;

    internal static void ShowPreview()
    {
        if (_current is not null)
        {
            if (_current.AppWindow.Presenter is OverlappedPresenter presenter) presenter.Restore();
            _current.Activate();
            return;
        }
        try
        {
            _current = new TreeTownPrototypeWindow();
            _current.Activate();
        }
        catch (Exception ex)
        {
            _current = null;
            App.Log($"[TownPrototype] Open failed: {ex.GetType().Name}");
        }
    }

    private TreeTownPrototypeWindow()
    {
        Title = "小镇预览 · 实验";
        _session = new SharedTownSession(
            App.Current.Services.GetRequiredService<IAuthSession>(),
            App.Current.Services.GetRequiredService<IAuthenticatedAccountApi>());
        _root.Children.Add(_web);
        _root.Children.Add(_message);
        Content = _root;
        _web.DefaultBackgroundColor = Windows.UI.Color.FromArgb(255, 236, 239, 218);
        _web.NavigationStarting += OnNavigationStarting;
        _web.NavigationCompleted += OnNavigationCompleted;
        _web.WebMessageReceived += OnWebMessage;
        _root.Loaded += OnLoaded;
        _session.Changed += OnStateChanged;
        Activated += OnActivated;
        Closed += OnClosed;
        AppWindow.Closing += OnClosing;
        AppWindow.Changed += OnWindowChanged;
        SizeInt32 size = new(1100, 760);
        RectInt32 work = DisplayArea.GetFromWindowId(AppWindow.Id, DisplayAreaFallback.Primary).WorkArea;
        size.Width = Math.Min(size.Width, Math.Max(400, work.Width - 48));
        size.Height = Math.Min(size.Height, Math.Max(320, work.Height - 48));
        AppWindow.Resize(size);
        AppWindow.Move(new PointInt32(work.X + (work.Width - size.Width) / 2,
            work.Y + (work.Height - size.Height) / 2));
        if (AppWindow.Presenter is OverlappedPresenter presenter)
        {
            presenter.IsResizable = true;
            presenter.IsMaximizable = true;
            presenter.IsMinimizable = true;
        }
    }

    private async void OnLoaded(object sender, RoutedEventArgs args)
    {
        _root.Loaded -= OnLoaded;
        try
        {
            string userData = Path.Combine(DeskBoxDataPathService.Current.RootPath, "town-prototype", "webview2");
            CoreWebView2Environment environment = await CoreWebView2Environment.CreateWithOptionsAsync(null, userData, null);
            if (_closed) return;
            await _web.EnsureCoreWebView2Async(environment);
            if (_closed) return;
            CoreWebView2 core = _web.CoreWebView2;
            core.Settings.AreDefaultContextMenusEnabled = false;
            core.Settings.AreHostObjectsAllowed = false;
            core.SetVirtualHostNameToFolderMapping(VirtualHost,
                Path.Combine(AppContext.BaseDirectory, "Assets", "Tree", "TownPrototype"),
                CoreWebView2HostResourceAccessKind.DenyCors);
            core.NewWindowRequested += OnNewWindow;
            core.ProcessFailed += OnProcessFailed;
            _web.Source = new Uri($"https://{VirtualHost}/index.html");
            _ = CheckReadyAsync();
        }
        catch (Exception ex) { ShowFailure(ex); }
    }

    private async Task CheckReadyAsync()
    {
        await Task.Delay(TimeSpan.FromSeconds(20));
        if (!_closed && !_ready) ShowFailure(new TimeoutException("Local town page did not become ready."));
    }

    private void OnNavigationStarting(WebView2 sender, CoreWebView2NavigationStartingEventArgs args)
    {
        if (!Uri.TryCreate(args.Uri, UriKind.Absolute, out Uri? uri) ||
            uri.Scheme != "https" || uri.Host != VirtualHost) args.Cancel = true;
    }

    private void OnNavigationCompleted(WebView2 sender, CoreWebView2NavigationCompletedEventArgs args)
    {
        if (!args.IsSuccess) ShowFailure(new InvalidOperationException("Local town page navigation failed."));
    }

    private void OnNewWindow(CoreWebView2 sender, CoreWebView2NewWindowRequestedEventArgs args) => args.Handled = true;
    private void OnProcessFailed(CoreWebView2 sender, CoreWebView2ProcessFailedEventArgs args) =>
        ShowFailure(new InvalidOperationException("Town rendering process stopped."));

    private async void OnWebMessage(WebView2 sender, CoreWebView2WebMessageReceivedEventArgs args)
    {
        if (_closed || !args.Source.StartsWith($"https://{VirtualHost}/", StringComparison.Ordinal)) return;
        try
        {
            if (args.WebMessageAsJson.Length > 150000) throw new InvalidDataException("Town message is too large.");
            using JsonDocument document = JsonDocument.Parse(args.WebMessageAsJson);
            JsonElement message = document.RootElement;
            string? type = message.GetProperty("type").GetString();
            if (type == "ready")
            {
                _ready = true;
                _message.Visibility = Visibility.Collapsed;
                await _session.LoadWorldAsync();
                await PostSharedStateAsync();
                App.Log("[SharedTown] Local map ready.");
                _ = RunOptionalProbeAsync();
                return;
            }
            if (type == "error")
            {
                ShowFailure(new InvalidDataException(message.TryGetProperty("message", out JsonElement detail)
                    ? detail.GetString() : "Town page failed."));
                return;
            }
            if (!message.TryGetProperty("session_epoch", out JsonElement identity) ||
                !identity.TryGetInt64(out long epoch) || epoch != _session.Epoch) return;
            switch (type)
            {
                case "viewportChanged":
                    await _session.LoadChunksAsync(message.GetRawText(), epoch);
                    await PostAsync("setChunks", _session.ChunksJson());
                    break;
                case "changesRequested":
                    await _session.LoadChangesAsync(epoch);
                    await PostAsync("applyChanges", _session.ChangesJson());
                    break;
                case "townActionRequested":
                    _ = await _session.ExecuteActionAsync(
                        BuildActionPayload(message), epoch);
                    await PostAsync("setInventory", _session.InventoryJson());
                    await _session.LoadChangesAsync(epoch);
                    await PostAsync("applyChanges", _session.ChangesJson());
                    break;
                case "presenceHeartbeat":
                    await _session.SendPresenceHeartbeatAsync(
                        message.GetProperty("payload").GetRawText(), epoch);
                    await PostAsync("setPresence", _session.PresenceJson());
                    break;
                case "presenceRequested":
                    string? query = message.TryGetProperty("query", out JsonElement queryElement) &&
                        queryElement.ValueKind == JsonValueKind.String ? queryElement.GetString() : null;
                    await _session.LoadPresenceAsync(query, epoch);
                    await PostAsync("setPresence", _session.PresenceJson());
                    break;
                case "layoutChanged":
                    // Legacy prototype messages are ignored by the shared world;
                    // this keeps an older cached page from mutating cloud state.
                    _session.UpdateLayout(string.Empty, epoch);
                    break;
                case "saveRequested":
                    // Shared actions are committed on each placement/move/delete.
                    await PostAsync("setWorld", _session.WorldJson());
                    break;
                case "reloadRequested":
                    await _session.LoadWorldAsync();
                    await PostSharedStateAsync();
                    break;
            }
        }
        catch (Exception ex) when (ex is JsonException or InvalidDataException or KeyNotFoundException or InvalidOperationException)
        { App.Log($"[TownPrototype] Ignored a failed map message: {ex.GetType().Name}"); }
    }

    private void OnStateChanged(bool replaceLayout)
    {
        DispatcherQueue.TryEnqueue(async () =>
        {
            if (!_closed && _ready)
                await PostSharedStateAsync();
        });
    }

    private async Task PostSharedStateAsync()
    {
        if (_closed || !_ready) return;
        await PostAsync("setWorld", _session.WorldJson());
        await PostAsync("setChunks", _session.ChunksJson());
        await PostAsync("setInventory", _session.InventoryJson());
        await PostAsync("setPresence", _session.PresenceJson());
    }

    private static string BuildActionPayload(JsonElement message)
    {
        if (!message.TryGetProperty("payload", out JsonElement payload) ||
            payload.ValueKind != JsonValueKind.Object)
            throw new InvalidDataException("Shared town action payload is missing.");
        using JsonDocument copy = JsonDocument.Parse(payload.GetRawText());
        using var stream = new MemoryStream();
        using (var writer = new Utf8JsonWriter(stream))
        {
            writer.WriteStartObject();
            if (message.TryGetProperty("action", out JsonElement action) &&
                action.ValueKind == JsonValueKind.String)
                writer.WriteString("action", action.GetString());
            foreach (JsonProperty property in copy.RootElement.EnumerateObject())
            {
                if (property.NameEquals("action")) continue;
                property.WriteTo(writer);
            }
            writer.WriteEndObject();
        }
        return Encoding.UTF8.GetString(stream.ToArray());
    }

    private async Task PostAsync(string method, string json)
    {
        if (_closed || !_ready) return;
        try { await _web.ExecuteScriptAsync($"window.DeskBoxTownPrototype?.{method}({json})"); }
        catch (Exception ex) { if (!_closed) ShowFailure(ex); }
    }

    private async void OnActivated(object sender, WindowActivatedEventArgs args)
    {
        await PostAsync("setVisibility", args.WindowActivationState != WindowActivationState.Deactivated ? "true" : "false");
        if (args.WindowActivationState == WindowActivationState.Deactivated) await PostAsync("clearKeys", "");
    }

    private async void OnWindowChanged(AppWindow sender, AppWindowChangedEventArgs args)
    {
        if (args.DidPresenterChange || args.DidVisibilityChange)
        {
            bool visible = AppWindow.IsVisible &&
                (AppWindow.Presenter is not OverlappedPresenter presenter || presenter.State != OverlappedPresenterState.Minimized);
            await PostAsync("setVisibility", visible ? "true" : "false");
        }
    }

    private async void OnClosing(AppWindow sender, AppWindowClosingEventArgs args)
    {
        if (_allowClose || !_session.IsDirty) return;
        args.Cancel = true;
        if (_askingClose) return;
        _askingClose = true;
        try
        {
            var dialog = new ContentDialog
            {
                XamlRoot = _root.XamlRoot, Title = "小镇布置尚未保存",
                Content = _session.IsAuthenticated
                    ? "保存到当前账号后，下次打开可以继续查看。"
                    : "当前未登录，关闭后不会保留本次演示布置。",
                PrimaryButtonText = "保存并关闭",
                SecondaryButtonText = _session.IsAuthenticated ? "保留草稿并关闭" : "关闭演示",
                CloseButtonText = "继续编辑",
                IsPrimaryButtonEnabled = _session.IsAuthenticated,
                DefaultButton = _session.IsAuthenticated ? ContentDialogButton.Primary : ContentDialogButton.Close
            };
            ContentDialogResult result = await dialog.ShowAsync();
            if (result == ContentDialogResult.None) return;
            if (result == ContentDialogResult.Primary &&
                (!await _session.SaveAsync(_session.Epoch) || _session.IsDirty)) return;
            _allowClose = true;
            Close();
        }
        catch (Exception ex) { App.Log($"[TownPrototype] Close prompt failed: {ex.GetType().Name}"); }
        finally { _askingClose = false; }
    }

    private void ShowFailure(Exception exception)
    {
        if (_closed) return;
        App.Log($"[TownPrototype] Preview failed: {exception.GetType().Name}: {exception.Message}");
        _message.Text = "小镇预览未能启动，请关闭后重试。原有种树界面不受影响。";
        _message.Visibility = Visibility.Visible;
    }

    private void OnClosed(object sender, WindowEventArgs args)
    {
        _closed = true;
        _ready = false;
        _current = null;
        _session.Changed -= OnStateChanged;
        _session.Dispose();
        Activated -= OnActivated;
        Closed -= OnClosed;
        AppWindow.Closing -= OnClosing;
        AppWindow.Changed -= OnWindowChanged;
        _web.NavigationStarting -= OnNavigationStarting;
        _web.NavigationCompleted -= OnNavigationCompleted;
        _web.WebMessageReceived -= OnWebMessage;
        if (_web.CoreWebView2 is { } core)
        {
            core.NewWindowRequested -= OnNewWindow;
            core.ProcessFailed -= OnProcessFailed;
        }
        _web.Close();
    }
}
#endif
