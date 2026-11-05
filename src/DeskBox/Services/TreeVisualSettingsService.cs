using DeskBox.Core.Persistence;

namespace DeskBox.Services;

/// <summary>Local presentation preference for the tree surface. It never enters cloud tree state.</summary>
public sealed class TreeVisualSettingsService
{
    private static readonly Lazy<TreeVisualSettingsService> s_instance = new(() => new());
    public static TreeVisualSettingsService Instance => s_instance.Value;

    private readonly object _gate = new();
    private readonly TreeVisualSettingsFileStore _store;
    private bool _useThreeD;

    public TreeVisualSettingsService(string? localRoot = null)
    {
        // Account backup scans DataDirectory recursively. Keep presentation and
        // browser cache outside it so neither can enter an account snapshot.
        string root = localRoot ?? DeskBoxDataPathService.Current.RootPath;
        _store = new TreeVisualSettingsFileStore(Path.Combine(root, "tree-visual", "presentation.json"));
        _useThreeD = _store.Load().UseThreeD;
    }

    public event Action? Changed;

    public bool UseThreeD
    {
        get { lock (_gate) return _useThreeD; }
    }

    public void SetUseThreeD(bool enabled)
    {
        lock (_gate)
        {
            if (_useThreeD == enabled) return;
            _store.Save(new TreeVisualSettings { UseThreeD = enabled });
            _useThreeD = enabled;
        }
        Changed?.Invoke();
    }

}
