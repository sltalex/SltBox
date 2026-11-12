using System.Text;
using System.Text.Json;
using System.Text.Json.Nodes;
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
    private const string AssetHost = "deskbox-town-assets.local";
    private readonly string _assetCacheRoot = Path.Combine(DeskBoxDataPathService.Current.RootPath, "town-prototype", "sheets");
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
    private readonly SemaphoreSlim _realtimeMessages = new(1, 1);

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
        _session.RealtimeMessage += OnRealtimeMessage;
        _session.RealtimeStateChanged += OnRealtimeStateChanged;
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
            // Map the complete local tree asset root so the town page can use
            // the existing SVG decoration catalog via ../Decorations without
            // copying 1500 files into the prototype directory.
            core.SetVirtualHostNameToFolderMapping(VirtualHost,
                Path.Combine(AppContext.BaseDirectory, "Assets", "Tree"),
                CoreWebView2HostResourceAccessKind.DenyCors);
            core.NewWindowRequested += OnNewWindow;
            core.ProcessFailed += OnProcessFailed;
            Directory.CreateDirectory(_assetCacheRoot);
            core.SetVirtualHostNameToFolderMapping(AssetHost, _assetCacheRoot, CoreWebView2HostResourceAccessKind.Allow);
            _web.Source = new Uri($"https://{VirtualHost}/TownPrototype/index.html");
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
                    string actionPayload = BuildActionPayload(message);
                    string actionResult = AttachOperationId(
                        await _session.ExecuteActionAsync(actionPayload, epoch), actionPayload);
                    if (epoch != _session.Epoch) break;
                    await PostAsync("setInventory", _session.InventoryJson());
                    // Actions are committed immediately by the shared-town
                    // endpoint.  Always deliver the authoritative result to
                    // the page: a successful response carries the server
                    // generated object id, while a failure lets the page
                    // roll back its optimistic edit.  The session advances
                    // its cursor from change_cursor, so querying changes
                    // before applying this result would skip the just-made
                    // object.
                    await PostAsync("applyActionResult", actionResult);
                    using (JsonDocument resultDocument = JsonDocument.Parse(actionResult))
                    {
                        if (resultDocument.RootElement.TryGetProperty("code", out _)) break;
                    }
                    await _session.LoadChangesAsync(epoch);
                    await PostAsync("applyChanges", _session.ChangesJson());
                    break;
                case "townAssetSheetRequested":
                    string sheetId = message.TryGetProperty("sheet_id", out JsonElement sheetElement) &&
                        sheetElement.ValueKind == JsonValueKind.String ? sheetElement.GetString() ?? string.Empty : string.Empty;
                    long sheetRelease = message.TryGetProperty("release_version", out JsonElement release) ? release.GetInt64() : -1;
                    string? sheetPath = await _session.LoadAssetSheetAsync(sheetId, epoch, _assetCacheRoot);
                    if (epoch != _session.Epoch || _closed) break;
                    await PostAsync("setAssetSheet", JsonSerializer.Serialize(new {
                        sheet_id = sheetId, session_epoch = epoch, release_version = sheetRelease,
                        status = sheetPath is null ? "error" : "ready",
                        url = sheetPath is null ? null : $"https://{AssetHost}/{Path.GetFileName(sheetPath)}"
                    }));
                    break;
                case "assetError":
                case "assetReady":
                    App.Log($"[SharedTown] Image decode result: {message.GetRawText()}");
                    break;
                case "retryAssetsRequested":
                    await PostSharedStateAsync();
                    break;
                case "presenceHeartbeat":
                    if (!await _session.SendPresenceRealtimeAsync(
                        message.GetProperty("payload").GetRawText(), epoch))
                    {
                        await _session.SendPresenceHeartbeatAsync(
                            message.GetProperty("payload").GetRawText(), epoch);
                        // The POST response only acknowledges the current user's
                        // heartbeat on older servers. Fetch the visible roster
                        // after the upsert so one heartbeat cannot wipe the other
                        // players from the map while still supporting those
                        // responses.
                        await _session.LoadPresenceAsync(null, epoch);
                        await PostAsync("setPresence", _session.PresenceJson());
                    }
                    break;
                case "presenceRequested":
                    if (_session.RealtimeConnected) break;
                    string? query = message.TryGetProperty("query", out JsonElement queryElement) &&
                        queryElement.ValueKind == JsonValueKind.String ? queryElement.GetString() : null;
                    await _session.LoadPresenceAsync(query, epoch);
                    await PostAsync("setPresence", _session.PresenceJson());
                    break;
                case "npcChangesRequested":
                    if (_session.RealtimeConnected) break;
                    await _session.LoadNpcChangesAsync(epoch);
                    await PostAsync("applyNpcChanges", _session.NpcsJson());
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
        catch (Exception ex) when (ex is HttpRequestException or OperationCanceledException or AuthApiException)
        {
            App.Log($"[SharedTown] Map request failed: {ex.GetType().Name}");
            try
            {
                using JsonDocument failed = JsonDocument.Parse(args.WebMessageAsJson);
                JsonElement failedMessage = failed.RootElement;
                if (failedMessage.TryGetProperty("type", out JsonElement kind) &&
                    kind.ValueKind == JsonValueKind.String && kind.GetString() == "townActionRequested")
                {
                    string operationId = string.Empty;
                    if (failedMessage.TryGetProperty("payload", out JsonElement payload) &&
                        payload.ValueKind == JsonValueKind.Object &&
                        payload.TryGetProperty("operation_id", out JsonElement operation) &&
                        operation.ValueKind == JsonValueKind.String)
                        operationId = operation.GetString() ?? string.Empty;
                    string error = JsonSerializer.Serialize(new { code = "town_network_error", message = "共享小镇同步失败，请稍后重试", operation_id = operationId, session_epoch = failedMessage.TryGetProperty("session_epoch", out JsonElement failedEpoch) ? failedEpoch.GetInt64() : -1 });
                    await PostAsync("applyActionResult", error);
                }
            }
            catch (Exception rollback) { App.Log($"[SharedTown] Action rollback failed: {rollback.GetType().Name}"); }
        }
    }

    private void OnStateChanged(bool replaceLayout)
    {
        DispatcherQueue.TryEnqueue(async () =>
        {
            if (!_closed && _ready)
                await PostSharedStateAsync();
        });
    }

    private void OnRealtimeStateChanged(long epoch, bool connected)
    {
        DispatcherQueue.TryEnqueue(async () =>
        {
            if (_closed || !_ready || epoch != _session.Epoch) return;
            await PostAsync("setRealtimeState", JsonSerializer.Serialize(new { session_epoch = epoch, connected }));
            if (!connected && _session.IsEnabled)
            {
                try
                {
                    await _session.LoadPresenceAsync(null, _session.Epoch);
                    await PostAsync("setPresence", _session.PresenceJson());
                    await _session.LoadNpcChangesAsync(_session.Epoch);
                    await PostAsync("applyNpcChanges", _session.NpcsJson());
                }
                catch (Exception error) when (error is HttpRequestException or OperationCanceledException or AuthApiException or JsonException)
                { App.Log($"[SharedTown] Realtime fallback refresh failed: {error.GetType().Name}"); }
            }
        });
    }

    private void OnRealtimeMessage(long epoch, string json)
    {
        DispatcherQueue.TryEnqueue(async () =>
        {
            await _realtimeMessages.WaitAsync();
            try
            {
                if (_closed || !_ready || epoch != _session.Epoch) return;
                using JsonDocument document = JsonDocument.Parse(json);
                JsonElement root = document.RootElement;
                string type = root.TryGetProperty("type", out JsonElement typeElement)
                    ? typeElement.GetString() ?? string.Empty : string.Empty;
                if (type is not ("presence" or "presence_delta" or "snapshot" or "npc_state" or "position_rejected")) return;
                if (type == "position_rejected")
                {
                    // Resume authoritative HTTP recovery instead of silently dropping movement.
                    await _session.DisconnectRealtimeAsync();
                    OnRealtimeStateChanged(epoch, false);
                    _ = _session.ConnectRealtimeAsync();
                    return;
                }
                var node = JsonNode.Parse(root.GetRawText())?.AsObject();
                if (node is null) return;
                node["session_epoch"] = epoch;
                if (type == "presence") node.Remove("type");
                string method = type switch
                {
                    "presence" => "setPresence", "presence_delta" => "applyRealtimePresence",
                    "snapshot" => "applyRealtimeSnapshot", _ => "applyRealtimeNpc"
                };
                await PostAsync(method, node.ToJsonString());
            }
            catch (JsonException) { }
            finally { _realtimeMessages.Release(); }
        });
    }

    private async Task PostSharedStateAsync()
    {
        if (_closed || !_ready) return;
        bool catalogFailed = false;
        if (_session.IsEnabled && _session.AssetCatalogNeedsReload)
            catalogFailed = !await _session.LoadAssetCatalogAsync(_session.Epoch);
        await PostAsync("setWorld", _session.WorldJson());
        await PostAsync("setChunks", _session.ChunksJson());
        await PostAsync("setInventory", _session.InventoryJson());
        await PostAsync("setPresence", _session.PresenceJson());
        await _session.LoadNpcSnapshotAsync(_session.Epoch);
        await PostAsync("setNpcs", _session.NpcsJson());
        await PostAsync("setAssetCatalog", catalogFailed
            ? JsonSerializer.Serialize(new { session_epoch = _session.Epoch, status = "error" })
            : _session.AssetCatalogJson());
        _ = _session.ConnectRealtimeAsync();
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

    private static string AttachOperationId(string result, string request)
    {
        try
        {
            JsonNode? resultNode = JsonNode.Parse(result);
            JsonNode? requestNode = JsonNode.Parse(request);
            if (resultNode is JsonObject resultObject && requestNode is JsonObject requestObject &&
                resultObject["operation_id"] is null && requestObject["operation_id"] is JsonNode operation)
            {
                resultObject["operation_id"] = operation.DeepClone();
                return resultObject.ToJsonString();
            }
        }
        catch (JsonException) { }
        return result;
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
        if (_allowClose || !_ready || !_session.IsEnabled) return;
        args.Cancel = true;
        if (_askingClose) return;
        _askingClose = true;
        try
        {
            string position = await _web.ExecuteScriptAsync(
                "window.DeskBoxTownPrototype.setHostActive(false);window.DeskBoxTownPrototype.playerPosition()");
            using JsonDocument document = JsonDocument.Parse(position);
            if (document.RootElement.ValueKind == JsonValueKind.Object &&
                document.RootElement.TryGetProperty("session_epoch", out JsonElement epochElement) &&
                epochElement.TryGetInt64(out long epoch) && epoch == _session.Epoch)
                if (!await _session.SendPresenceRealtimeAsync(position, epoch, durable: true))
                    await _session.SendPresenceHeartbeatAsync(position, epoch);
            _allowClose = true;
            Close();
        }
        catch (Exception ex)
        {
            App.Log($"[SharedTown] Position save on close failed: {ex.GetType().Name}");
            _message.Text = "人物位置暂未保存，请检查网络后再次关闭小镇。";
            _message.Visibility = Visibility.Visible;
            if (!_closed)
            {
                try { await _web.ExecuteScriptAsync("window.DeskBoxTownPrototype.setHostActive(true)"); }
                catch (Exception resume) { App.Log($"[SharedTown] Resume after position failure: {resume.GetType().Name}"); }
            }
        }
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
        _session.RealtimeMessage -= OnRealtimeMessage;
        _session.RealtimeStateChanged -= OnRealtimeStateChanged;
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
