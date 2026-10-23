namespace DeskBox.Controls.WidgetContents;

/// <summary>Coordinates shared by the tree artwork, fruit overlays and harvest hit target.</summary>
internal static class TreeIllustrationLayout
{
    public const double Width = 260;
    public const double Height = 220;
    public const double DisplayHeight = 110;
    // Keep the canopy below the weather band without moving the trunk off the ground.
    public const double TreeArtworkScale = .82;
    public const int MaxVisibleFruit = 12;
    public const double LeafCycleSeconds = 10;

    public static IReadOnlyList<(double X, double Y)> FruitPositions { get; } = Array.AsReadOnly<(double X, double Y)>(
    [
        (90, 83), (157, 87), (120, 33), (57, 61),
        (177, 54), (119, 106), (73, 110), (191, 99),
        (87, 44), (147, 48), (44, 91), (144, 115)
    ]);

    public static string StageAssetName(int stage) => $"stage-{Math.Clamp(stage, 1, 5)}.svg";

    public static string CanopyAssetName(int stage) => $"stage-{Math.Clamp(stage, 1, 5)}-canopy.svg";

    // The compact drawing is half-size; seedlings need a wider angle to
    // keep their short leaves visibly moving without enlarging the tree.
    public static double SwayAmplitudeDegrees(int stage) => Math.Clamp(stage, 1, 5) switch
    {
        1 => 6,
        2 => 5,
        3 => 4,
        4 => 3.5,
        _ => 3
    };

    public static double CanopyPivotY(int stage) => Math.Clamp(stage, 1, 5) switch
    {
        1 => 142,
        2 => 145,
        _ => 128
    };

    /// <summary>A small fixed population; falling leaves are decorative and never affect progress.</summary>
    public static IReadOnlyList<FallingLeaf> FallingLeaves(int stage) => stage switch
    {
        3 => SmallTreeLeaves,
        4 or 5 => GrownTreeLeaves,
        _ => Array.Empty<FallingLeaf>()
    };

    private static readonly IReadOnlyList<FallingLeaf> SmallTreeLeaves = Array.AsReadOnly<FallingLeaf>(
    [
        new(83, 105, -22, 0, 5.4, 15),
        new(171, 102, 25, 3.6, 5.8, -25)
    ]);

    private static readonly IReadOnlyList<FallingLeaf> GrownTreeLeaves = Array.AsReadOnly<FallingLeaf>(
    [
        new(67, 91, -25, 0, 5.8, 15),
        new(181, 85, 27, 2.5, 6, -25),
        new(112, 114, 26, 4.2, 5.6, 30)
    ]);

    public static int VisibleFruitCount(long inventory) => (int)Math.Clamp(inventory, 0, MaxVisibleFruit);

    public static long ExtraFruitCount(long inventory) => inventory > MaxVisibleFruit ? inventory - MaxVisibleFruit : 0;

    public static bool CanHarvest(int stage, long inventory, double x, double y) =>
        stage == 5 && inventory > 0 && x is >= 32 and <= 228 && y is >= 12 and <= 150;

    public static int FruitIndexAt(int visibleCount, double x, double y)
    {
        if (!double.IsFinite(x) || !double.IsFinite(y)) return -1;
        for (int i = Math.Min(visibleCount, MaxVisibleFruit) - 1; i >= 0; i--)
        {
            (double left, double top) = FruitPositions[i];
            if (x >= left && x <= left + 24 && y >= top && y <= top + 28) return i;
        }
        return -1;
    }
}

internal readonly record struct FallingLeaf(
    double X, double Y, double Drift, double DelaySeconds, double DurationSeconds, double InitialRotation);
