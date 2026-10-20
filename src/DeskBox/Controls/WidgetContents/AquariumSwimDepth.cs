using DeskBox.Services;

namespace DeskBox.Controls.WidgetContents;

/// <summary>Transient swimming depth; saved scene placements are never rewritten.</summary>
internal static class AquariumSwimDepth
{
    private const double BackCenterY = .28;
    private const double FrontCenterY = .70;
    private const double BackScale = .55;
    private const double SwimHalfHeight = .025;

    internal static IReadOnlyList<AquariumPlacement> ForDisplay(
        IReadOnlyList<AquariumPlacement> placements,
        IReadOnlyDictionary<string, string> categories)
    {
        return placements
            .Select(item => categories.TryGetValue(item.InstanceId, out string? category) && IsSwimming(category)
                ? Project(item)
                : item)
            .OrderBy(item => item.Layer)
            .ThenBy(item => categories.TryGetValue(item.InstanceId, out string? category) && category == "fish" ? 1 : 0)
            .ThenBy(item => item.Y)
            .ToArray();
    }

    internal static (double MinY, double MaxY) Band(int layer)
    {
        double center = BackCenterY + (FrontCenterY - BackCenterY) *
            (Math.Clamp(layer, AquariumLayer.Min, AquariumLayer.Max) - AquariumLayer.Min) / (AquariumLayer.Count - 1);
        return (center - SwimHalfHeight, center + SwimHalfHeight);
    }

    internal static double ScaleFactor(int layer) => BackScale + (1 - BackScale) *
        (Math.Clamp(layer, AquariumLayer.Min, AquariumLayer.Max) - AquariumLayer.Min) /
        (double)(AquariumLayer.Count - 1);

    private static AquariumPlacement Project(AquariumPlacement placement)
    {
        uint hash = StableHash(placement.InstanceId);
        int layer = AquariumLayer.Min + (int)(hash % AquariumLayer.Count);
        (double minY, double maxY) = Band(layer);
        double y = (minY + maxY) / 2 +
            ((hash >> 12 & 255) / 255d - .5) * .012;
        return placement with { Y = y, Scale = placement.Scale * ScaleFactor(layer), Layer = layer };
    }

    internal static bool IsSwimming(string category) => category is "fish" or "shrimp" or "crab" or "jellyfish";

    internal static uint StableHash(string value)
    {
        uint hash = 2166136261;
        foreach (char character in value) hash = (hash ^ character) * 16777619;
        return hash;
    }
}
