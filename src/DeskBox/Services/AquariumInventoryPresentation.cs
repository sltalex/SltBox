namespace DeskBox.Services;

internal sealed record AquariumOwnedProduct(
    AquariumCatalogItem Product,
    int OwnedCount,
    int PlacedCount,
    AquariumInventoryItem? NextToPlace,
    AquariumInventoryItem? NextToRecall);

internal static class AquariumInventoryPresentation
{
    internal static IReadOnlyList<string> Categories { get; } =
        ["fish", "plant", "coral", "algae", "snail", "shrimp", "crab", "shellfish", "jellyfish", "decoration", "pack"];

    internal static IReadOnlyList<string> AquariumCategories { get; } =
        Categories.Where(category => category != "pack").ToArray();

    internal static IReadOnlyList<AquariumOwnedProduct> AquariumItems(IEnumerable<AquariumOwnedProduct> owned) =>
        owned.Where(item => AquariumCategories.Contains(item.Product.Category, StringComparer.Ordinal)).ToArray();

    internal static IReadOnlyList<AquariumOwnedProduct> GeneralItems(IEnumerable<AquariumOwnedProduct> owned) =>
        owned.Where(item => item.Product.Category == "pack").ToArray();

    internal static IReadOnlyList<string> FishHabitats { get; } = ["all", "freshwater", "tropical", "marine"];

    // Habitat is a browsing group, not a water-compatibility restriction on scenes.
    // Older cached catalogs omit it and remain visible in the All group.
    internal static bool MatchesHabitat(AquariumCatalogItem product, string habitat) =>
        habitat == "all" || product.Category == "fish" && product.Habitat == habitat;

    internal static IReadOnlyList<AquariumOwnedProduct> Summarize(
        IReadOnlyList<AquariumInventoryItem> inventory,
        IReadOnlyList<AquariumPlacement> scene,
        IReadOnlyList<AquariumCatalogItem> catalog)
    {
        var products = catalog.ToDictionary(item => item.Id, StringComparer.Ordinal);
        var placedIds = scene.Select(item => item.InstanceId).ToHashSet(StringComparer.OrdinalIgnoreCase);
        var result = new List<AquariumOwnedProduct>();
        foreach (IGrouping<string, AquariumInventoryItem> group in inventory.GroupBy(item => item.ItemId))
        {
            if (!products.TryGetValue(group.Key, out AquariumCatalogItem? product) ||
                !Categories.Contains(product.Category))
                continue;

            AquariumInventoryItem[] owned = group.ToArray();
            AquariumInventoryItem[] placed = owned.Where(item => placedIds.Contains(item.Id)).ToArray();
            result.Add(new AquariumOwnedProduct(product, owned.Length, placed.Length,
                owned.FirstOrDefault(item => !placedIds.Contains(item.Id)), placed.LastOrDefault()));
        }
        return result;
    }
}
