namespace DeskBox.Services;

internal sealed record AquariumStoreGroup(
    string Id,
    IReadOnlyList<string> Categories,
    IReadOnlyList<string> Subcategories);

internal static class AquariumStoreBrowse
{
    internal static IReadOnlyList<AquariumStoreGroup> Groups { get; } =
    [
        new("fish", ["fish"], ["all", "freshwater", "tropical", "marine"]),
        new("plants", ["plant", "algae"], ["all", "plant", "algae"]),
        new("scenery", ["coral", "decoration"], ["all", "coral", "decoration"]),
        new("invertebrates", ["shrimp", "crab", "shellfish", "snail"],
            ["all", "shrimp", "crab", "shellfish", "snail"]),
        new("pack", ["pack"], [])
    ];

    internal static IReadOnlyList<string> Subcategories(string groupId) =>
        Groups.FirstOrDefault(group => group.Id == groupId)?.Subcategories ?? [];

    internal static bool Matches(AquariumCatalogItem item, string groupId, string subcategory)
    {
        AquariumStoreGroup? group = Groups.FirstOrDefault(candidate => candidate.Id == groupId);
        if (group is null || !group.Categories.Contains(item.Category, StringComparer.Ordinal)) return false;
        if (subcategory == "all") return true;
        return groupId == "fish"
            ? item.Habitat == subcategory
            : item.Category == subcategory;
    }
}
