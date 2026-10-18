namespace DeskBox.Services;

/// <summary>
/// Supplies a first-use display layout for accounts that already own aquarium
/// fish but have never saved a scene. The projection is deliberately local:
/// it does not mutate the authoritative scene or mark inventory as placed.
/// </summary>
internal static class AquariumSceneProjection
{
    private const int MaxFish = 12;

    internal static IReadOnlyList<AquariumPlacement> ForDisplay(
        AquariumState state,
        IReadOnlyList<AquariumCatalogItem> catalog,
        bool includeFirstUseFish)
    {
        if (!includeFirstUseFish || state.SceneVersion != 0 || state.Scene.Count != 0)
            return state.Scene;

        var products = catalog.ToDictionary(item => item.Id, StringComparer.Ordinal);
        var fish = state.Inventory
            .Where(item => products.TryGetValue(item.ItemId, out AquariumCatalogItem? product)
                && product.Category == "fish")
            .Take(MaxFish)
            .ToArray();
        if (fish.Length == 0) return state.Scene;

        var projected = new List<AquariumPlacement>(fish.Length);
        for (int index = 0; index < fish.Length; index++)
        {
            int column = index % 4;
            int row = index / 4;
            projected.Add(new AquariumPlacement(
                fish[index].Id,
                .22 + column * .19,
                .30 + row * .16,
                1));
        }
        return projected.AsReadOnly();
    }
}
