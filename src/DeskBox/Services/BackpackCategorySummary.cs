namespace DeskBox.Services;

internal sealed record BackpackCategoryCount(string Category, int Count);

internal static class BackpackCategorySummary
{
    internal static IReadOnlyList<BackpackCategoryCount> Wildlife(
        IReadOnlyList<WildlifeCatalogItem> catalog,
        IReadOnlyList<WildlifeInventoryItem> inventory)
    {
        HashSet<string> owned = inventory.Where(item => item.Quantity > 0)
            .Select(item => item.SpeciesId).ToHashSet(StringComparer.Ordinal);
        return catalog.GroupBy(item => item.Category, StringComparer.Ordinal)
            .Select(group => new BackpackCategoryCount(group.Key, group.Count(item => owned.Contains(item.Id))))
            .ToArray();
    }

    internal static IReadOnlyList<BackpackCategoryCount> Fruits(
        IReadOnlyList<FruitCatalogItem> catalog,
        IReadOnlyList<FruitInventoryItem> inventory)
    {
        HashSet<string> owned = inventory.Where(item => item.Quantity > 0)
            .Select(item => item.FruitId).ToHashSet(StringComparer.Ordinal);
        return catalog.GroupBy(item => item.Category, StringComparer.Ordinal)
            .Select(group => new BackpackCategoryCount(group.Key, group.Count(item => owned.Contains(item.Id))))
            .ToArray();
    }
}
