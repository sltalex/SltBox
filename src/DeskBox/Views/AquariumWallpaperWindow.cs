using DeskBox.Controls.WidgetContents;
using DeskBox.Helpers;
using DeskBox.Platform;
using DeskBox.Services;
using Microsoft.UI;
using Microsoft.UI.Windowing;
using Microsoft.UI.Xaml;
using Windows.Graphics;
using WinRT.Interop;

namespace DeskBox.Views;

/// <summary>
/// A borderless, click-through aquarium surface hosted in the desktop icon
/// layer. The scene content remains the same content used by the aquarium
/// widget, while this window owns the full-screen desktop presentation.
/// </summary>
internal sealed class AquariumWallpaperWindow : IDisposable
{
    private readonly Window _window;
    private readonly AquariumWidgetContent _content;
    private readonly AppWindow _appWindow;
    private readonly IntPtr _hWnd;
    private bool _disposed;

    internal AquariumWallpaperWindow(AquariumService service, Func<bool> animationEnabled)
    {
        _content = new AquariumWidgetContent(service, animationEnabled, wallpaper: true)
        {
            IsHitTestVisible = false
        };
        _window = new Window
        {
            Title = "SltBox Aquarium Wallpaper",
            Content = _content
        };

        _hWnd = WindowNative.GetWindowHandle(_window);
        WindowId windowId = Win32Interop.GetWindowIdFromWindow(_hWnd);
        _appWindow = AppWindow.GetFromWindowId(windowId);
        WindowShellState.TryHideFromSwitchers(_appWindow);
        WindowShellState.TryApplyBorderlessOverlappedPresenter(_appWindow);

        int extendedStyle = Win32Helper.GetWindowLong(_hWnd, Win32Helper.GWL_EXSTYLE);
        extendedStyle |= Win32Helper.WS_EX_TOOLWINDOW |
                         Win32Helper.WS_EX_NOACTIVATE |
                         Win32Helper.WS_EX_TRANSPARENT |
                         Win32Helper.WS_EX_LAYERED;
        _ = Win32Helper.SetWindowLongPtr(
            _hWnd,
            Win32Helper.GWL_EXSTYLE,
            new IntPtr(extendedStyle));

        int style = Win32Helper.GetWindowLong(_hWnd, Win32Helper.GWL_STYLE);
        style &= ~(Win32Helper.WS_CAPTION |
                   Win32Helper.WS_BORDER |
                   Win32Helper.WS_DLGFRAME |
                   Win32Helper.WS_THICKFRAME);
        _ = Win32Helper.SetWindowLong(_hWnd, Win32Helper.GWL_STYLE, style);
        _ = Win32Helper.SetLayeredWindowAttributes(_hWnd, 0, 255, Win32Helper.LWA_ALPHA);
        Win32Helper.SetWindowBorderColor(
            _hWnd,
            unchecked((int)0xFFFFFFFE));
    }

    internal IntPtr WindowHandle => _hWnd;

    internal void Show(DisplayArea displayArea, IntPtr wallpaperHost)
    {
        if (_disposed)
        {
            return;
        }

        RectInt32 bounds = displayArea.OuterBounds;
        AquariumWallpaperDesktop.Attach(_hWnd, wallpaperHost, bounds);
        _appWindow.Show(activateWindow: false);
        _content.SetVisible(true);
        App.Log($"[AquariumWallpaper] shown hwnd=0x{_hWnd.ToInt64():X} " +
            $"bounds={bounds.X},{bounds.Y},{bounds.Width},{bounds.Height}");
    }

    internal void UpdatePlayback()
    {
        if (_disposed)
        {
            return;
        }

        _content.SetVisible(AquariumWallpaperDesktop.IsExposed(_hWnd));
    }

    public void Dispose()
    {
        if (_disposed)
        {
            return;
        }

        _disposed = true;
        _appWindow.Hide();
        _content.Dispose();
        _window.Close();
    }
}
