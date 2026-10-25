namespace DeskBox.Services;

internal sealed class TreeDecorationSceneDraft
{
    private readonly Stack<Snapshot> _undo = new();
    private List<TreeDecorationPlacement> _placements;

    internal TreeDecorationSceneDraft(IEnumerable<TreeDecorationPlacement> placements, string? activeThemeId)
    {
        _placements = placements.ToList();
        ActiveThemeId = activeThemeId;
    }

    internal IReadOnlyList<TreeDecorationPlacement> Placements => _placements;
    internal string? ActiveThemeId { get; private set; }
    internal bool CanUndo => _undo.Count > 0;

    internal bool TryAdd(string itemId, double x = .5, double y = .5)
    {
        if (_placements.Count >= TreeDecorationPlacementRules.MaximumPlacements ||
            _placements.Any(item => item.ItemId == itemId)) return false;
        Remember();
        _placements.Add(Clamp(new TreeDecorationPlacement(itemId, x, y, 1, 0, 0)));
        return true;
    }

    internal bool TryUpdate(string itemId, double x, double y, double scale, double rotation, int layer)
    {
        int index = _placements.FindIndex(item => item.ItemId == itemId);
        if (index < 0) return false;
        TreeDecorationPlacement updated = Clamp(new(itemId, x, y, scale, rotation, layer));
        if (_placements[index] == updated) return true;
        Remember();
        _placements[index] = updated;
        return true;
    }

    internal bool MoveWithoutUndo(string itemId, double x, double y)
    {
        int index = _placements.FindIndex(item => item.ItemId == itemId);
        if (index < 0) return false;
        TreeDecorationPlacement current = _placements[index];
        _placements[index] = Clamp(current with { X = x, Y = y });
        return true;
    }

    internal void BeginContinuousEdit() => Remember();

    internal bool Remove(string itemId)
    {
        int index = _placements.FindIndex(item => item.ItemId == itemId);
        if (index < 0) return false;
        Remember();
        _placements.RemoveAt(index);
        return true;
    }

    internal void SetTheme(string? itemId)
    {
        if (ActiveThemeId == itemId) return;
        Remember();
        ActiveThemeId = itemId;
    }

    internal bool Undo()
    {
        if (!_undo.TryPop(out Snapshot? snapshot)) return false;
        _placements = snapshot.Placements.ToList();
        ActiveThemeId = snapshot.ActiveThemeId;
        return true;
    }

    internal void Reset(IEnumerable<TreeDecorationPlacement> placements, string? activeThemeId)
    {
        _placements = placements.ToList();
        ActiveThemeId = activeThemeId;
        _undo.Clear();
    }

    private void Remember() => _undo.Push(new(_placements.ToArray(), ActiveThemeId));

    private static TreeDecorationPlacement Clamp(TreeDecorationPlacement item) => item with
    {
        X = Math.Clamp(item.X, 0, 1),
        Y = Math.Clamp(item.Y, 0, 1),
        Scale = Math.Clamp(item.Scale, TreeDecorationPlacementRules.MinimumScale,
            TreeDecorationPlacementRules.MaximumScale),
        Rotation = Math.Clamp(item.Rotation, TreeDecorationPlacementRules.MinimumRotation,
            TreeDecorationPlacementRules.MaximumRotation),
        Layer = Math.Clamp(item.Layer, TreeDecorationPlacementRules.MinimumLayer,
            TreeDecorationPlacementRules.MaximumLayer)
    };

    private sealed record Snapshot(IReadOnlyList<TreeDecorationPlacement> Placements, string? ActiveThemeId);
}
