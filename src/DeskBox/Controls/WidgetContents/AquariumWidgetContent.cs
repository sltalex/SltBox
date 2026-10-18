using DeskBox.Services;
using Microsoft.UI.Dispatching;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;

namespace DeskBox.Controls.WidgetContents;

public sealed class AquariumWidgetContent : UserControl, IDisposable
{
    private readonly AquariumService _service;
    private readonly Func<bool> _animationEnabled;
    private readonly AquariumSceneView _scene = new();
    private readonly DispatcherQueue _queue = DispatcherQueue.GetForCurrentThread();
    private readonly DispatcherTimer _motionPolicy = new() { Interval = TimeSpan.FromSeconds(5) };
    private bool _visible = true;
    private bool _disposed;
    private bool _subscribed;

    internal AquariumWidgetContent(AquariumService service, Func<bool> animationEnabled)
    {
        _service = service;
        _animationEnabled = animationEnabled;
        Content = _scene;
        // Subscribe as soon as the leaf is created.  The widget host can
        // materialize a view before it attaches it to the window; waiting for
        // Loaded here can otherwise lose the one Changed notification emitted
        // after a scene save.  Loaded still performs a full replay below.
        _service.Changed += OnChanged;
        _subscribed = true;
        Loaded += (_, _) =>
        {
            if (_disposed) return;
            if (!_subscribed)
            {
                _service.Changed += OnChanged;
                _subscribed = true;
            }
            Refresh();
            UpdatePolicy();
        };
        Unloaded += (_, _) => { Unsubscribe(); _motionPolicy.Stop(); _scene.SetVisible(false); };
        _motionPolicy.Tick += (_, _) => _scene.SetAnimationEnabled(_animationEnabled());
    }

    internal void Refresh()
    {
        if (_disposed) return;
        AquariumState? state = _service.State;
        App.Log($"[Aquarium] Widget refresh available={_service.IsAvailable} loaded={IsLoaded} " +
            $"state={(state is null ? "null" : $"initialized={state.Initialized} inventory={state.Inventory.Count} scene={state.Scene.Count} version={state.SceneVersion}")} " +
            $"catalog={_service.Catalog.Count} offline={_service.IsOffline}");
        if (_service.IsAvailable && state is not null)
        {
            IReadOnlyList<AquariumPlacement> displayScene = AquariumSceneProjection.ForDisplay(
                state, _service.Catalog, includeFirstUseFish: true);
            App.Log($"[Aquarium] Display scene projected={displayScene.Count} authoritative={state.Scene.Count} " +
                $"firstUse={(state.SceneVersion == 0 && state.Scene.Count == 0)}");
            _scene.Render(displayScene, state.Inventory, _service.Catalog);
        }
        else _scene.Render([], [], []);
        UpdatePolicy();
    }

    private void OnChanged() => _queue.TryEnqueue(() =>
    {
        if (!_disposed)
        {
            // Render the latest authoritative state even when the host has
            // not completed its Loaded transition yet.  UpdatePolicy keeps
            // motion paused until the view becomes visible.
            Refresh();
        }
    });

    public void SetVisible(bool visible)
    {
        if (_visible != visible)
            App.Log($"[Aquarium] Widget visibility changed {_visible} -> {visible} loaded={IsLoaded} activeState={_service.IsAvailable}");
        _visible = visible;
        UpdatePolicy();
    }

    private void UpdatePolicy()
    {
        bool visible = !_disposed && _visible && IsLoaded && _service.IsAvailable;
        _scene.SetVisible(visible);
        _scene.SetAnimationEnabled(_animationEnabled());
        if (visible) _motionPolicy.Start(); else _motionPolicy.Stop();
    }

    private void Unsubscribe()
    {
        if (_subscribed) _service.Changed -= OnChanged;
        _subscribed = false;
    }

    public void Dispose()
    {
        if (_disposed) return;
        _disposed = true;
        Unsubscribe();
        _motionPolicy.Stop();
        _scene.Dispose();
    }
}
