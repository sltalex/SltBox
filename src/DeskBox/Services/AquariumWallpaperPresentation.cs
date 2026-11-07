namespace DeskBox.Services;

internal static class AquariumWallpaperPresentation
{
    // Preserve the saved normalized layout while keeping fish proportions on
    // wide and portrait monitors. Only the local viewport gains width.
    internal static double SceneWidth(double width, double height)
        => width > 0 && height > 0 && double.IsFinite(width) && double.IsFinite(height)
            ? 600 * width / height
            : 840;

    internal static double DepthOpacity(int layer)
        => .58 + .42 * (Math.Clamp(layer, AquariumLayer.Min, AquariumLayer.Max) - AquariumLayer.Min) /
            (AquariumLayer.Count - 1d);
}
