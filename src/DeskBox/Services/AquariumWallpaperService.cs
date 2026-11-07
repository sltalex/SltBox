using DeskBox.Views;
using Microsoft.UI.Dispatching;
using Microsoft.UI.Windowing;

namespace DeskBox.Services;

/// <summary>
/// Owns the optional full-screen aquarium wallpaper windows. The preference is
/// device-local and the windows are rebuilt from the current display topology;
/// aquarium scene data remains owned by <see cref="AquariumService"/>.
/// </summary>
internal sealed class AquariumWallpaperService : IDisposable
{
    private readonly AquariumService _aquarium;
    private readonly SettingsService _settings;
    private readonly DispatcherQueue _queue;
    private readonly List<AquariumWallpaperWindow> _windows = [];
    private readonly DispatcherQueueTimer _timer;
    private string? _identity;
    private string? _displaySignature;
    private IntPtr _host;
    private bool _started;
    private bool _disposed;

    internal AquariumWallpaperService(
        AquariumService aquarium,
        SettingsService settings,
        DispatcherQueue queue)
    {
        _aquarium = aquarium;
        _settings = settings;
        _queue = queue;
        _timer = queue.CreateTimer();
        _timer.Interval = TimeSpan.FromSeconds(3);
        _timer.Tick += OnTimer;
        _aquarium.Changed += OnAquariumChanged;
        _settings.SettingsChanged += OnAquariumChanged;
    }

    internal bool IsEnabled => _settings.Settings.WidgetLayout.AquariumWallpaperEnabled;

    internal void Start()
    {
        if (_disposed || _started)
        {
            return;
        }

        _started = true;
        ReconcileSafely();
    }

    internal void SetEnabled(bool enabled)
    {
        if (_disposed)
        {
            return;
        }

        _settings.Settings.WidgetLayout.AquariumWallpaperEnabled = enabled;
        _settings.SaveDebounced();
        Reconcile();
    }

    internal void Reconcile()
    {
        if (_disposed || !_started)
        {
            return;
        }

        if (!_queue.HasThreadAccess)
        {
            _queue.TryEnqueue(Reconcile);
            return;
        }

        if (!IsEnabled)
        {
            _timer.Stop();
            CloseWindows();
            return;
        }
        _timer.Start();
        if (!_aquarium.IsAvailable || _aquarium.State is null)
        {
            CloseWindows();
            return;
        }

        string signature = DisplayAreaWatcherService.CaptureCurrentSignature();
        string? identity = _aquarium.CurrentUserId;
        IntPtr existingHost = AquariumWallpaperDesktop.FindHost(createIfMissing: false);
        if (_windows.Count > 0 && identity == _identity && signature == _displaySignature &&
            existingHost != IntPtr.Zero && existingHost == _host)
        {
            foreach (var window in _windows) window.UpdatePlayback();
            return;
        }

        CloseWindows();
        _host = AquariumWallpaperDesktop.FindHost(createIfMissing: true);
        if (_host == IntPtr.Zero) return;

        IReadOnlyList<DisplayArea> displays = DisplayArea.FindAll();
        if (displays.Count == 0)
        {
            CloseWindows();
            return;
        }

        // The WinRT vector supports indexed access; its projected enumerable
        // is unavailable on some supported Windows App SDK runtimes.
        for (int index = 0; index < displays.Count; index++)
        {
            DisplayArea display = displays[index];
            AquariumWallpaperWindow? window = null;
            try
            {
                window = new AquariumWallpaperWindow(
                    _aquarium,
                    () => _settings.Settings.WidgetLayout.AquariumAnimationsEnabled);
                window.Show(display, _host);
                _windows.Add(window);
            }
            catch (Exception error)
            {
                window?.Dispose();
                App.Log($"[AquariumWallpaper] Failed to create display surface: " +
                    $"{error.GetType().Name}: {error.Message}");
            }
        }
        _identity = identity;
        _displaySignature = signature;

        App.Log($"[AquariumWallpaper] reconciled enabled={IsEnabled} " +
            $"available={_aquarium.IsAvailable} displays={displays.Count} " +
            $"windows={_windows.Count}");
    }

    private void OnTimer(DispatcherQueueTimer sender, object args) => ReconcileSafely();

    private void ReconcileSafely()
    {
        try { Reconcile(); }
        catch (Exception error) { App.Log($"[AquariumWallpaper] Reconcile failed: {error}"); }
    }

    private void OnAquariumChanged()
    {
        if (_disposed)
        {
            return;
        }

        _queue.TryEnqueue(ReconcileSafely);
    }

    private void CloseWindows()
    {
        foreach (AquariumWallpaperWindow window in _windows)
        {
            try
            {
                window.Dispose();
            }
            catch (Exception error)
            {
                App.Log($"[AquariumWallpaper] Window close failed: {error.Message}");
            }
        }

        _windows.Clear();
        _identity = _displaySignature = null;
        _host = IntPtr.Zero;
    }

    public void Dispose()
    {
        if (_disposed)
        {
            return;
        }

        _disposed = true;
        _timer.Stop();
        _timer.Tick -= OnTimer;
        _aquarium.Changed -= OnAquariumChanged;
        _settings.SettingsChanged -= OnAquariumChanged;
        CloseWindows();
    }
}
