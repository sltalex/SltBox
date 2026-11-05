using System.Text.Json;
using DeskBox.Services;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
using Microsoft.UI.Xaml.Input;
using Microsoft.UI.Xaml.Media;
using Microsoft.UI.Xaml.Media.Imaging;
using Microsoft.Web.WebView2.Core;
using Windows.Storage.Streams;
using Windows.Foundation;
using Windows.System;

namespace DeskBox.Controls.WidgetContents;

/// <summary>A local rendering surface. All state and actions belong to the native tree.</summary>
internal sealed partial class Tree3DView : UserControl, IDisposable
{
    private const string VirtualHost = "deskbox-tree3d.local";
    private readonly WebView2 _webView = new();
    private readonly Image _presentation = new() { Stretch = Stretch.Fill, IsHitTestVisible = false };
    private readonly Grid _surface = new() { Background = new SolidColorBrush(Microsoft.UI.Colors.Transparent) };
    private readonly CancellationTokenSource _lifetime = new();
    private string? _stateJson;
    private bool _initializing;
    private bool _navigating;
    private bool _ready;
    private bool _failed;
    private bool _disposed;
    private bool _visible;
    private bool _animationEnabled;
    private bool _hasFrame;
    private bool _decodingFrame;
    private uint? _dragPointer;
    private Point _dragStart;
    private Point _dragLast;
    private bool _dragMoved;

    internal Tree3DView()
    {
        HorizontalAlignment = HorizontalAlignment.Stretch;
        VerticalAlignment = VerticalAlignment.Stretch;
        IsTabStop = true;
        UseSystemFocusVisuals = true;
        string controls = App.Current.LocalizationService.T("Tree.Settings.ThreeD.Controls");
        Microsoft.UI.Xaml.Automation.AutomationProperties.SetName(this, controls);
        _webView.DefaultBackgroundColor = Windows.UI.Color.FromArgb(0, 0, 0, 0);
        // WinUI gives its native WebView composition bridge a separate theme
        // background. DefaultBackgroundColor only clears the browser layer.
        // Scope both theme variants to this decorative view, including high contrast.
        _webView.Resources["SolidBackgroundFillColorBase"] = Microsoft.UI.Colors.Transparent;
        _webView.Resources["SystemColorWindowColor"] = Microsoft.UI.Colors.Transparent;
        _webView.NavigationStarting += NavigationStarting;
        _webView.NavigationCompleted += NavigationCompleted;
        _webView.WebMessageReceived += WebMessageReceived;
        // The external WebView composition surface cannot reliably blend with
        // sibling XAML background images. Only the native alpha image is visible.
        _webView.Opacity = 0;
        _webView.IsHitTestVisible = false;
        _webView.IsTabStop = false;
        _surface.Children.Add(_webView);
        _surface.Children.Add(_presentation);
        _surface.PointerPressed += SurfacePointerPressed;
        _surface.PointerMoved += SurfacePointerMoved;
        _surface.PointerReleased += SurfacePointerReleased;
        _surface.PointerCanceled += SurfacePointerCanceled;
        _surface.PointerCaptureLost += SurfacePointerCanceled;
        _surface.PointerWheelChanged += SurfacePointerWheelChanged;
        KeyDown += SurfaceKeyDown;
        Content = _surface;
        Unloaded += (_, _) => SetVisible(false);
    }

    internal event Action<int, string>? FruitTapped;
    internal event Func<string, Task>? WildlifeTapped;
    internal event Action<Exception>? InitializationFailed;
    internal event Action? SceneReady;
    internal bool IsReady => _hasFrame;

    internal async Task InitializeAsync()
    {
        if (_disposed || _failed || _ready || _initializing || _navigating) return;
        _initializing = true;
        _ = WatchReadinessAsync();
        try
        {
            string treeRoot = Path.Combine(AppContext.BaseDirectory, "Assets", "Tree");
            if (!File.Exists(Path.Combine(treeRoot, "ThreeD", "index.html")))
                throw new DirectoryNotFoundException("Local tree 3D assets are missing.");
            string userData = Path.Combine(DeskBoxDataPathService.Current.RootPath, "tree-visual", "webview2");
            CoreWebView2Environment environment =
                await CoreWebView2Environment.CreateWithOptionsAsync(null, userData, null);
            if (_disposed) return;
            await _webView.EnsureCoreWebView2Async(environment);
            if (_disposed) return;
            CoreWebView2 core = _webView.CoreWebView2;
            core.Settings.AreDefaultContextMenusEnabled = false;
            core.Settings.AreDevToolsEnabled = false;
            core.Settings.AreHostObjectsAllowed = false;
            core.SetVirtualHostNameToFolderMapping(VirtualHost, treeRoot, CoreWebView2HostResourceAccessKind.DenyCors);
            core.NewWindowRequested += NewWindowRequested;
            core.ProcessFailed += ProcessFailed;
            _navigating = true;
            _webView.Source = new Uri($"https://{VirtualHost}/ThreeD/index.html");
        }
        catch (Exception ex) { Fail(ex); }
        finally { _initializing = false; }
    }

    private async Task WatchReadinessAsync()
    {
        try
        {
            await Task.Delay(TimeSpan.FromSeconds(20), _lifetime.Token);
            if (!_ready || (_visible && !_hasFrame))
                Fail(new TimeoutException("Three.js did not deliver a transparent presentation frame."));
        }
        catch (OperationCanceledException) { }
    }

    internal void SetSceneState(Tree3DSceneState state)
    {
        string json = JsonSerializer.Serialize(state, Tree3DJsonContext.Default.Tree3DSceneState);
        if (_stateJson == json) return;
        _stateJson = json;
        if (_ready) Post($"window.DeskBoxTree3D.setSceneState({json})");
    }

    internal void SetVisible(bool visible)
    {
        bool needsFirstFrame = visible && !_visible && _ready && !_hasFrame;
        _visible = visible;
        if (!visible) { _dragPointer = null; _surface.ReleasePointerCaptures(); }
        if (_ready) Post($"window.DeskBoxTree3D.setVisibility({Boolean(visible)})");
        if (needsFirstFrame) _ = WatchReadinessAsync();
    }

    internal void SetAnimationEnabled(bool enabled)
    {
        if (_animationEnabled == enabled) return;
        _animationEnabled = enabled;
        if (_ready) Post($"window.DeskBoxTree3D.setAnimationEnabled({Boolean(enabled)})");
    }

    private static string Boolean(bool value) => value ? "true" : "false";

    private void NavigationStarting(WebView2 sender, CoreWebView2NavigationStartingEventArgs args)
    {
        if (!Uri.TryCreate(args.Uri, UriKind.Absolute, out Uri? uri) ||
            uri.Scheme != "https" || uri.Host != VirtualHost || uri.AbsolutePath != "/ThreeD/index.html")
            args.Cancel = true;
    }

    private void NavigationCompleted(WebView2 sender, CoreWebView2NavigationCompletedEventArgs args)
    {
        if (!args.IsSuccess) Fail(new InvalidOperationException($"Local page failed: {args.WebErrorStatus}"));
        // Navigation success alone does not establish that WebGL or the module loaded.
    }

    private void NewWindowRequested(CoreWebView2 sender, CoreWebView2NewWindowRequestedEventArgs args) =>
        args.Handled = true;

    private void ProcessFailed(CoreWebView2 sender, CoreWebView2ProcessFailedEventArgs args) =>
        Fail(new InvalidOperationException($"WebView process failed: {args.ProcessFailedKind}"));

    private async void WebMessageReceived(WebView2 sender, CoreWebView2WebMessageReceivedEventArgs args)
    {
        if (_disposed || _failed || !args.Source.StartsWith($"https://{VirtualHost}/ThreeD/", StringComparison.Ordinal))
            return;
        try
        {
            using JsonDocument document = JsonDocument.Parse(args.WebMessageAsJson);
            JsonElement root = document.RootElement;
            if (root.ValueKind != JsonValueKind.Object ||
                !root.TryGetProperty("type", out JsonElement type)) return;
            switch (type.GetString())
            {
                case "ready":
                    _ready = true;
                    _navigating = false;
                    if (_stateJson is not null) Post($"window.DeskBoxTree3D.setSceneState({_stateJson})");
                    SetVisible(_visible);
                    Post($"window.DeskBoxTree3D.setAnimationEnabled({Boolean(_animationEnabled)})");
                    Post("window.DeskBoxTree3D.enableFrameTransport()");
                    break;
                case "frame" when _ready:
                    if (root.TryGetProperty("png", out JsonElement png) && png.ValueKind == JsonValueKind.String)
                        await PresentFrameAsync(png.GetString()!);
                    break;
                case "error":
                    Fail(new InvalidOperationException(root.GetProperty("message").GetString()));
                    break;
                case "fruitTapped" when _ready && _visible:
                    if (root.TryGetProperty("index", out JsonElement index) && index.TryGetInt32(out int value) &&
                        root.TryGetProperty("pendingId", out JsonElement pending) && pending.ValueKind == JsonValueKind.String)
                        FruitTapped?.Invoke(value, pending.GetString()!);
                    break;
                case "wildlifeTapped" when _ready && _visible:
                    if (root.TryGetProperty("encounterId", out JsonElement encounter) &&
                        encounter.ValueKind == JsonValueKind.String && WildlifeTapped is { } handler)
                        await handler(encounter.GetString()!);
                    break;
            }
        }
        catch (Exception ex) { App.Log($"[Tree3D] Message failed: {ex.Message}"); }
    }

    private void SurfacePointerPressed(object sender, PointerRoutedEventArgs args)
    {
        if (!_hasFrame || !_visible || _surface.ActualWidth <= 0 || _surface.ActualHeight <= 0) return;
        var point = args.GetCurrentPoint(_surface);
        if (!point.Properties.IsLeftButtonPressed) return;
        if (_dragPointer is not null) return;
        Focus(FocusState.Pointer);
        if (!_surface.CapturePointer(args.Pointer)) return;
        _dragPointer = args.Pointer.PointerId;
        _dragStart = _dragLast = point.Position;
        _dragMoved = false;
        args.Handled = true;
    }

    private void SurfacePointerMoved(object sender, PointerRoutedEventArgs args)
    {
        if (_dragPointer != args.Pointer.PointerId || !_visible) return;
        Point point = args.GetCurrentPoint(_surface).Position;
        if (Math.Abs(point.X - _dragStart.X) + Math.Abs(point.Y - _dragStart.Y) > 4) _dragMoved = true;
        if (_dragMoved && _surface.ActualWidth > 0 && _surface.ActualHeight > 0)
            Post(FormattableString.Invariant($"window.DeskBoxTree3D.orbitBy({(point.X - _dragLast.X) / _surface.ActualWidth},{(point.Y - _dragLast.Y) / _surface.ActualHeight})"));
        _dragLast = point;
        args.Handled = true;
    }

    private void SurfacePointerReleased(object sender, PointerRoutedEventArgs args)
    {
        if (_dragPointer != args.Pointer.PointerId) return;
        Point point = args.GetCurrentPoint(_surface).Position;
        bool tap = !_dragMoved && _visible && _surface.ActualWidth > 0 && _surface.ActualHeight > 0;
        _dragPointer = null;
        _surface.ReleasePointerCapture(args.Pointer);
        if (tap)
        {
            double x = point.X / _surface.ActualWidth, y = point.Y / _surface.ActualHeight;
            if (x is >= 0 and <= 1 && y is >= 0 and <= 1)
                Post(FormattableString.Invariant($"window.DeskBoxTree3D.tapAtNormalized({x},{y})"));
        }
        args.Handled = true;
    }

    private void SurfacePointerCanceled(object sender, PointerRoutedEventArgs args)
    {
        if (_dragPointer == args.Pointer.PointerId) _dragPointer = null;
    }

    private void SurfaceKeyDown(object sender, KeyRoutedEventArgs args)
    {
        if (!_hasFrame || !_visible) return;
        string? action = args.Key switch
        {
            VirtualKey.Left => "moveView(-1,0)",
            VirtualKey.Right => "moveView(1,0)",
            VirtualKey.Up => "moveView(0,1)",
            VirtualKey.Down => "moveView(0,-1)",
            VirtualKey.Home => "resetView()",
            _ => null
        };
        if (action is null) return;
        Post($"window.DeskBoxTree3D.{action}");
        args.Handled = true;
    }

    private void SurfacePointerWheelChanged(object sender, PointerRoutedEventArgs args)
    {
        if (!_hasFrame || !_visible) return;
        var properties = args.GetCurrentPoint(_surface).Properties;
        if (properties.IsHorizontalMouseWheel) return;
        Post(FormattableString.Invariant($"window.DeskBoxTree3D.zoomBy({properties.MouseWheelDelta / 120d})"));
        args.Handled = true;
    }

    private async Task PresentFrameAsync(string png)
    {
        const string prefix = "data:image/png;base64,";
        if (_decodingFrame || _disposed || !_visible) { Post("window.DeskBoxTree3D.acknowledgeFrame()"); return; }
        if (!png.StartsWith(prefix, StringComparison.Ordinal) || png.Length > 2 * 1024 * 1024)
        {
            Fail(new InvalidDataException("Invalid tree presentation frame."));
            return;
        }
        _decodingFrame = true;
        try
        {
            byte[] bytes = Convert.FromBase64String(png[prefix.Length..]);
            using var stream = new InMemoryRandomAccessStream();
            using (var writer = new DataWriter(stream.GetOutputStreamAt(0)))
            {
                writer.WriteBytes(bytes);
                await writer.StoreAsync();
            }
            stream.Seek(0);
            var bitmap = new BitmapImage();
            await bitmap.SetSourceAsync(stream);
            if (_disposed || _failed || !_visible) return;
            _presentation.Source = bitmap;
            if (!_hasFrame)
            {
                _hasFrame = true;
                App.Log("[Tree3D] Native transparent presentation ready.");
                SceneReady?.Invoke();
            }
        }
        catch (Exception ex) { Fail(ex); }
        finally
        {
            _decodingFrame = false;
            Post("window.DeskBoxTree3D.acknowledgeFrame()");
        }
    }

    private async void Post(string script)
    {
        if (_disposed || _failed || !_ready) return;
        try { await _webView.ExecuteScriptAsync(script); }
        catch (Exception ex) { Fail(ex); }
    }

    private void Fail(Exception exception)
    {
        if (_disposed || _failed) return;
        _failed = true;
        _ready = false;
        App.Log($"[Tree3D] Renderer failed: {exception}");
        InitializationFailed?.Invoke(exception);
    }

    public void Dispose()
    {
        if (_disposed) return;
        _disposed = true;
        _lifetime.Cancel();
        _lifetime.Dispose();
        _webView.NavigationStarting -= NavigationStarting;
        _webView.NavigationCompleted -= NavigationCompleted;
        _webView.WebMessageReceived -= WebMessageReceived;
        if (_webView.CoreWebView2 is { } core)
        {
            core.NewWindowRequested -= NewWindowRequested;
            core.ProcessFailed -= ProcessFailed;
        }
        _webView.Close();
        _surface.PointerPressed -= SurfacePointerPressed;
        _surface.PointerMoved -= SurfacePointerMoved;
        _surface.PointerReleased -= SurfacePointerReleased;
        _surface.PointerCanceled -= SurfacePointerCanceled;
        _surface.PointerCaptureLost -= SurfacePointerCanceled;
        _surface.PointerWheelChanged -= SurfacePointerWheelChanged;
        KeyDown -= SurfaceKeyDown;
        _surface.ReleasePointerCaptures();
        _presentation.Source = null;
    }
}
