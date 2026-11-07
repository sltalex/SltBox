using System.ComponentModel;
using System.Runtime.InteropServices;
using System.Text;
using DeskBox.Platform;
using Windows.Graphics;

namespace DeskBox.Services;

/// <summary>Explorer's wallpaper host is below its desktop icon view.</summary>
internal static class AquariumWallpaperDesktop
{
    internal static IntPtr FindHost(bool createIfMissing)
    {
        IntPtr host = FindExistingHost();
        if (host != IntPtr.Zero || !createIfMissing) return host;

        IntPtr shell = Win32Helper.GetShellWindow();
        if (shell == IntPtr.Zero) return IntPtr.Zero;
        // Only the explicitly enabled wallpaper requests Explorer's wallpaper
        // surface. Ordinary widget startup retains its existing shell policy.
        _ = Win32Helper.SendMessageTimeout(shell, 0x052C, UIntPtr.Zero, IntPtr.Zero,
            0x0002, 1000, out _);
        return FindExistingHost();
    }

    private static IntPtr FindExistingHost()
    {
        IntPtr host = IntPtr.Zero;
        Win32Helper.EnumWindows((window, _) =>
        {
            IntPtr icons = Win32Helper.FindWindowEx(window, IntPtr.Zero, "SHELLDLL_DefView", null);
            if (icons == IntPtr.Zero) return true;

            host = Win32Helper.FindWindowEx(IntPtr.Zero, window, "WorkerW", null);
            // Newer Explorer versions place the wallpaper WorkerW inside
            // Progman instead of exposing it as a following top-level window.
            if (host == IntPtr.Zero)
                host = Win32Helper.FindWindowEx(window, IntPtr.Zero, "WorkerW", null);
            return host == IntPtr.Zero;
        }, IntPtr.Zero);
        return host;
    }

    internal static void Attach(IntPtr window, IntPtr host, RectInt32 bounds)
    {
        if (!Win32Helper.IsWindow(host)) throw new InvalidOperationException("Wallpaper host is unavailable.");
        int style = Win32Helper.GetWindowLong(window, Win32Helper.GWL_STYLE);
        _ = Win32Helper.SetWindowLong(window, Win32Helper.GWL_STYLE,
            (style & ~Win32Helper.WS_POPUP) | Win32Helper.WS_CHILD);
        Win32Helper.SetLastError(0);
        _ = Win32Helper.SetParent(window, host);
        if (Win32Helper.GetParent(window) != host)
            throw new Win32Exception(Marshal.GetLastWin32Error(), "Unable to attach aquarium wallpaper.");

        var origin = new Win32Helper.POINT { X = bounds.X, Y = bounds.Y };
        _ = Win32Helper.ScreenToClient(host, ref origin);
        if (!Win32Helper.SetWindowPos(window, Win32Helper.HWND_BOTTOM,
                origin.X, origin.Y, bounds.Width, bounds.Height,
                Win32Helper.SWP_NOACTIVATE | Win32Helper.SWP_FRAMECHANGED))
            throw new Win32Exception(Marshal.GetLastWin32Error(), "Unable to position aquarium wallpaper.");
    }

    internal static bool IsExposed(IntPtr window)
    {
        if (!Win32Helper.IsWindow(window) || !Win32Helper.IsWindowVisible(window)) return false;
        IntPtr foreground = Win32Helper.GetAncestor(Win32Helper.GetForegroundWindow(), Win32Helper.GA_ROOT);
        if (foreground == IntPtr.Zero || foreground == window ||
            Win32Helper.IsIconic(foreground) || Win32Helper.IsWindowCloaked(foreground)) return true;
        var className = new StringBuilder(128);
        _ = Win32Helper.GetClassName(foreground, className, className.Capacity);
        if (className.ToString() is "Progman" or "WorkerW" or "Shell_TrayWnd") return true;
        if (!Win32Helper.GetWindowRect(window, out var target) ||
            !Win32Helper.GetWindowRect(foreground, out var cover)) return true;
        return cover.Left > target.Left || cover.Top > target.Top ||
               cover.Right < target.Right || cover.Bottom < target.Bottom;
    }

}
