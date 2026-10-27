namespace DeskBox.Services;

internal sealed record TreeDecorationDisplayScene(
    TreeDecorationCatalogItem? Theme,
    IReadOnlyList<(TreeDecorationPlacement Placement, TreeDecorationCatalogItem Product)> Placements);

internal static class TreeDecorationSceneProjection
{
    internal static TreeDecorationDisplayScene ForDesktop(int treeStage,
        TreeDecorationState? state, TreeDecorationCatalog? catalog)
    {
        if (treeStage != 5 || state is null || catalog is null)
            return new(null, []);

        IReadOnlyDictionary<string, TreeDecorationCatalogItem> items = catalog.Items
            .ToDictionary(item => item.Id, StringComparer.Ordinal);
        TreeDecorationCatalogItem? theme = state.ActiveThemeId is string themeId &&
            items.TryGetValue(themeId, out TreeDecorationCatalogItem? selectedTheme) &&
            selectedTheme.Category == "theme" ? selectedTheme : null;
        var placements = state.Placements
            .Where(placement => items.TryGetValue(placement.ItemId, out TreeDecorationCatalogItem? product) &&
                product.Category != "theme")
            .OrderBy(placement => placement.Layer)
            .Select(placement => (placement, items[placement.ItemId]))
            .ToArray();
        return new(theme, Array.AsReadOnly(placements));
    }
}
