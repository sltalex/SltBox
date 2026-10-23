namespace DeskBox.Services;

internal sealed record EncyclopediaCategoryProgress(string Category, int Obtained, int Total);

internal static class EncyclopediaCategorySummary
{
    internal static IReadOnlyList<EncyclopediaCategoryProgress> Fruits(
        IReadOnlyList<FruitCatalogItem> catalog,
        IReadOnlyList<FruitInventoryItem> inventory)
    {
        HashSet<string> discovered = inventory
            .Where(item => item.Discovered || item.Quantity > 0)
            .Select(item => item.FruitId)
            .ToHashSet(StringComparer.Ordinal);
        return catalog.GroupBy(item => item.Category, StringComparer.Ordinal)
            .Select(group => new EncyclopediaCategoryProgress(
                group.Key, group.Count(item => discovered.Contains(item.Id)), group.Count()))
            .ToArray();
    }

    internal static IReadOnlyList<EncyclopediaCategoryProgress> Aquarium(
        IReadOnlyList<AquariumCatalogItem> catalog,
        AquariumState? state)
    {
        HashSet<string> discovered = (state?.Discovered ?? [])
            .Concat(state?.Inventory.Select(item => item.ItemId) ?? [])
            .ToHashSet(StringComparer.Ordinal);
        return catalog.Where(item => item.Category != "pack")
            .GroupBy(item => item.Category, StringComparer.Ordinal)
            .Select(group => new EncyclopediaCategoryProgress(
                group.Key, group.Count(item => discovered.Contains(item.Id)), group.Count()))
            .ToArray();
    }

    internal static IReadOnlyList<EncyclopediaCategoryProgress> Wildlife(
        IReadOnlyList<WildlifeCatalogItem> catalog,
        IReadOnlyList<WildlifeInventoryItem> inventory)
    {
        HashSet<string> discovered = inventory
            .Where(item => item.Discovered || item.Quantity > 0)
            .Select(item => item.SpeciesId).ToHashSet(StringComparer.Ordinal);
        return catalog.GroupBy(item => item.Category, StringComparer.Ordinal)
            .Select(group => new EncyclopediaCategoryProgress(
                group.Key, group.Count(item => discovered.Contains(item.Id)), group.Count()))
            .ToArray();
    }
}
