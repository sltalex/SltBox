using System.Runtime.InteropServices;
using DeskBox.Core.Persistence;
using DeskBox.Models;
using DeskBox.Platform;

namespace DeskBox.Services;

/// <summary>Single process-wide tree counter. The hook only observes key codes and never blocks input.</summary>
public sealed class TreeCounterService : IDisposable
{
    private static readonly Lazy<TreeCounterService> s_instance = new(() => new TreeCounterService());
    public static TreeCounterService Instance => s_instance.Value;
    public static void FlushIfStarted()
    {
        if (s_instance.IsValueCreated) s_instance.Value.Flush();
    }
    private readonly object _gate = new();
    private readonly object _writeGate = new();
    private readonly TreeKeyFilter _filter = new();
    private readonly Win32Helper.LowLevelKeyboardProc _callback;
    private readonly string _path = Path.Combine(DeskBoxDataPathService.Current.DataDirectory, "tree", "progress.json");
    private readonly TreeProgressFileStore _store;
    private readonly Timer _saveTimer;
    private Thread? _thread;
    private uint _threadId;
    private IntPtr _hook;
    private bool _enabled;
    private bool _disposed;
    private long _callbackTicks;
    private long _probeTicks;
    private TreeProgress _progress;
    private const uint RecentInputWindowMs = 20_000;
    private const uint CallbackSilentThresholdMs = 60_000;
    private static readonly IntPtr TreeProbeTag = new(0x44425452);

    private TreeCounterService()
    {
        _callback = HookCallback;
        _store = new TreeProgressFileStore(_path);
        _progress = _store.Load();
        _saveTimer = new Timer(_ => { try { Save(); CheckHook(); } catch (Exception ex) { App.Log($"[Tree] Save/check failed: {ex.Message}"); } }, null, TimeSpan.FromSeconds(10), TimeSpan.FromSeconds(10));
    }

    public event Action? Changed;
    public TreeProgress Snapshot
    {
        get { lock (_gate) { _progress.RollDay(DateTime.UtcNow); return Clone(_progress); } }
    }

    public void SetEnabled(bool enabled)
    {
        lock (_gate)
        {
            if (_enabled == enabled) return;
            _enabled = enabled;
            _filter.Reset();
        }
        if (enabled && !Snapshot.Paused) StartHook(); else { StopHook(); Save(); }
        Changed?.Invoke();
    }

    public void SetPaused(bool paused)
    {
        lock (_gate) { _progress.Paused = paused; _filter.Reset(); }
        if (paused) StopHook(); else if (_enabled) StartHook();
        Save(); Changed?.Invoke();
    }

    public void SetGoal(int goal)
    {
        lock (_gate) _progress.SetGoal(goal);
        Save(); Changed?.Invoke();
    }

    public void SetShowEnergy(bool enabled)
    {
        lock (_gate)
        {
            if (_progress.ShowEnergy == enabled) return;
            _progress.ShowEnergy = enabled;
        }
        Save(); Changed?.Invoke();
    }

    public long Harvest()
    {
        long result;
        lock (_gate) result = _progress.Harvest();
        Save(); Changed?.Invoke();
        return result;
    }

    internal bool TryApplyCloudState(TreeStateDto state, TreeProgress expected)
    {
        ArgumentNullException.ThrowIfNull(state);
        lock (_gate)
        {
            if (!CloudFieldsEqual(_progress, expected)) return false;
            ApplyCloudFields(state);
        }
        Save();
        Changed?.Invoke();
        return true;
    }

    internal bool TryApplyAuthoritativeCloudState(TreeStateDto state, TreeProgress expected)
    {
        ArgumentNullException.ThrowIfNull(state);
        lock (_gate)
        {
            if (!CloudFieldsEqual(_progress, expected)) return false;
            ApplyCloudFields(state);
        }
        Save();
        Changed?.Invoke();
        return true;
    }

    /// <summary>Applies a server-confirmed fruit redemption without changing local-only settings.</summary>
    internal bool ApplyFruitRecycled(long quantity)
    {
        if (quantity < 1) return false;
        lock (_gate)
        {
            if (_progress.HarvestedFruit < quantity) return false;
            _progress.HarvestedFruit -= quantity;
        }
        Save();
        Changed?.Invoke();
        return true;
    }

    internal void ApplyAuthoritativeCloudState(TreeStateDto state, Action<TreeProgress> preserveLocal)
    {
        ArgumentNullException.ThrowIfNull(state);
        lock (_gate)
        {
            preserveLocal(Clone(_progress));
            ApplyCloudFields(state);
        }
        Save();
        Changed?.Invoke();
    }

    private void ApplyCloudFields(TreeStateDto state)
    {
        _progress.LocalDay = state.Day;
        _progress.TodayKeys = Math.Max(0, state.TodayGrowth);
        _progress.TotalEnergy = Math.Max(0, state.TotalEnergy);
        _progress.HighestStage = Math.Clamp(state.Stage, 1, 5);
        _progress.FruitEnergy = Math.Clamp(state.FruitEnergy, 0, TreeProgress.CloudFruitCost - 1);
        _progress.FruitInventory = Math.Max(0, state.FruitInventory);
        _progress.HarvestedFruit = Math.Max(0, state.HarvestedTotal);
    }

    public void Reset()
    {
        lock (_gate)
        {
            _progress = new TreeProgress
            {
                DailyGoal = _progress.DailyGoal,
                Paused = _progress.Paused,
                ShowEnergy = _progress.ShowEnergy
            };
        }
        Save(); Changed?.Invoke();
    }

    private void StartHook()
    {
        lock (_gate)
        {
            if (_disposed || _thread?.IsAlive == true) return;
            _thread = new Thread(HookThread) { IsBackground = true, Name = "DeskBox.TreeKeyCounter" };
            _thread.Start();
        }
    }

    private void StopHook()
    {
        Thread? thread;
        uint id;
        lock (_gate) { thread = _thread; id = _threadId; }
        if (id != 0) Win32Helper.PostThreadMessage(id, Win32Helper.WM_QUIT, UIntPtr.Zero, IntPtr.Zero);
        if (thread != Thread.CurrentThread) thread?.Join(500);
        lock (_gate) _filter.Reset();
    }

    private void HookThread()
    {
        IntPtr hook = IntPtr.Zero;
        try
        {
            uint id = Win32Helper.GetCurrentThreadId();
            Win32Helper.PeekMessage(out _, IntPtr.Zero, 0, 0, Win32Helper.PM_NOREMOVE);
            hook = Win32Helper.SetWindowsHookEx(Win32Helper.WH_KEYBOARD_LL, _callback, Win32Helper.GetModuleHandle(null), 0);
            lock (_gate) { _threadId = id; _hook = hook; }
            if (hook == IntPtr.Zero) { App.Log($"[Tree] Keyboard hook failed: {Marshal.GetLastWin32Error()}"); return; }
            lock (_gate) { if (!_enabled || _progress.Paused || _disposed) return; }
            while (Win32Helper.GetMessage(out Win32Helper.MSG message, IntPtr.Zero, 0, 0) > 0)
            { Win32Helper.TranslateMessage(in message); Win32Helper.DispatchMessage(in message); }
        }
        catch (Exception ex) { App.Log($"[Tree] Keyboard hook stopped: {ex.Message}"); }
        finally
        {
            if (hook != IntPtr.Zero) Win32Helper.UnhookWindowsHookEx(hook);
            lock (_gate) { _hook = IntPtr.Zero; _threadId = 0; _thread = null; _filter.Reset(); }
        }
    }

    private IntPtr HookCallback(int code, IntPtr message, IntPtr dataPointer)
    {
        IntPtr hook = _hook;
        if (code < 0) return Win32Helper.CallNextHookEx(hook, code, message, dataPointer);
        try
        {
            Volatile.Write(ref _callbackTicks, Environment.TickCount64);
            bool down = message == Win32Helper.WM_KEYDOWN || message == Win32Helper.WM_SYSKEYDOWN;
            bool up = message == Win32Helper.WM_KEYUP || message == Win32Helper.WM_SYSKEYUP;
            if (down || up)
            {
                Win32Helper.KBDLLHOOKSTRUCT data = Marshal.PtrToStructure<Win32Helper.KBDLLHOOKSTRUCT>(dataPointer);
                bool changed = false;
                lock (_gate)
                {
                    if (_enabled && !_progress.Paused && _filter.Process((int)data.vkCode, down, (data.flags & Win32Helper.LLKHF_INJECTED) != 0))
                    { _progress.AddEnergy(1, DateTime.UtcNow); changed = true; }
                }
                if (changed)
                    NotifyChangedSafely(Changed, ex => App.Log($"[Tree] Keyboard hook observer failed: {ex}"));

                // The health probe is an internal key event. It must never
                // reach the foreground application (where it can be seen as
                // Tab-like focus navigation or activate an accelerator).
                if ((data.flags & Win32Helper.LLKHF_INJECTED) != 0 &&
                    data.dwExtraInfo == TreeProbeTag)
                {
                    return (IntPtr)1;
                }
            }
        }
        catch (Exception ex) { App.Log($"[Tree] Keyboard hook callback failed: {ex}"); }
        return Win32Helper.CallNextHookEx(hook, code, message, dataPointer);
    }

    internal static void NotifyChangedSafely(Action? listener, Action<Exception> reportError)
    {
        try { listener?.Invoke(); }
        catch (Exception ex) { reportError(ex); }
    }

    private void CheckHook()
    {
        if (!_enabled || Snapshot.Paused || _disposed) return;
        if (_thread?.IsAlive != true || _hook == IntPtr.Zero) { StartHook(); return; }

        // Do not inject input while the machine is idle. A keyboard probe is
        // only useful when real input has recently been observed but this
        // hook has not received a callback for a long time. This keeps the
        // foreground application completely untouched during idle/mouse-only
        // sessions and avoids the old unconditional ten-second keystroke.
        if (!Win32Helper.TryGetLastInputTickCount(out uint lastInputTick) ||
            !HookHealthWatchdog.IsDivergent(
                lastInputTick,
                Volatile.Read(ref _callbackTicks),
                Environment.TickCount64,
                RecentInputWindowMs,
                CallbackSilentThresholdMs))
        {
            return;
        }

        long previous = Volatile.Read(ref _callbackTicks);
        if (!Win32Helper.TrySendTaggedKeyPress(ReservedHotkeyHookService.InternalMaskVirtualKey, TreeProbeTag, out _)) return;
        // Probe on the next timer tick; the callback consumes tagged probe
        // events, so they cannot add energy or reach another application.
        long lastProbe = Interlocked.Exchange(ref _probeTicks, previous);
        if (lastProbe != 0 && previous == lastProbe) { StopHook(); StartHook(); }
    }

    public void Flush() => Save();

    private void Save()
    {
        lock (_writeGate)
        {
            _store.Save(Snapshot);
        }
    }

    private static TreeProgress Clone(TreeProgress p) => new()
    {
        DailyGoal = p.DailyGoal, Paused = p.Paused, ShowEnergy = p.ShowEnergy, LocalDay = p.LocalDay,
        TodayKeys = p.TodayKeys, TotalEnergy = p.TotalEnergy, HighestStage = p.HighestStage,
        FruitEnergy = p.FruitEnergy, FruitInventory = p.FruitInventory, HarvestedFruit = p.HarvestedFruit
    };

    private static bool CloudFieldsEqual(TreeProgress left, TreeProgress right) =>
        left.LocalDay == right.LocalDay && left.TodayKeys == right.TodayKeys &&
        left.TotalEnergy == right.TotalEnergy && left.HighestStage == right.HighestStage &&
        left.FruitEnergy == right.FruitEnergy && left.FruitInventory == right.FruitInventory &&
        left.HarvestedFruit == right.HarvestedFruit;

    public void Dispose()
    {
        if (_disposed) return;
        _disposed = true;
        _saveTimer.Dispose();
        StopHook(); Save();
    }
}
