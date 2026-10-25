namespace DeskBox.Services;

internal sealed record TreeDecorationOwnedProduct(
    TreeDecorationCatalogItem Product,
    int OwnedCount,
    int PlacedCount)
{
    internal bool CanPlace => Product.Category != "theme" && PlacedCount < OwnedCount;
    internal bool CanRecall => Product.Category != "theme" && PlacedCount > 0;
}

internal static class TreeDecorationInventoryPresentation
{
    internal static IReadOnlyList<TreeDecorationOwnedProduct> Summarize(
        IEnumerable<TreeDecorationInventoryItem> inventory,
        IEnumerable<TreeDecorationPlacement> placements,
        IEnumerable<TreeDecorationCatalogItem> catalog)
    {
        Dictionary<string, int> placed = placements
            .GroupBy(item => item.ItemId, StringComparer.Ordinal)
            .ToDictionary(group => group.Key, group => group.Count(), StringComparer.Ordinal);
        Dictionary<string, TreeDecorationCatalogItem> products = catalog
            .ToDictionary(item => item.Id, StringComparer.Ordinal);
        return Array.AsReadOnly(inventory.Where(item => item.Quantity > 0)
            .Select(item => products.TryGetValue(item.ItemId, out TreeDecorationCatalogItem? product)
                ? new TreeDecorationOwnedProduct(product, item.Quantity,
                    placed.GetValueOrDefault(item.ItemId)) : null)
            .Where(item => item is not null)
            .Select(item => item!)
            .ToArray());
    }
}
