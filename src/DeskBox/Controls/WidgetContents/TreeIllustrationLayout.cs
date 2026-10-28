namespace DeskBox.Controls.WidgetContents;

/// <summary>Coordinates shared by the tree artwork, fruit overlays and harvest hit target.</summary>
internal static class TreeIllustrationLayout
{
    public const double Width = 260;
    public const double ArtworkHeight = 220;
    public const double Height = 260;
    public const double DisplayHeight = 130;
    // Shift the complete illustration down without reducing the tree artwork.
    public const double TreeArtworkScale = 1;
    public const double TreeVerticalOffset = 50;
    public const double GroundVerticalOffset = 49;
    public static bool ShowsStageProgress(int stage) => stage < 5;
    // Visible widths measured from each stage's trunk and canopy SVGs in the
    // shared 260 x 220 coordinate space. Visitors follow the growing tree.
    public static double ArtworkWidth(int stage) => Math.Clamp(stage, 1, 5) switch
    {
        1 => 96,
        2 => 118,
        3 => 127,
        4 => 167,
        _ => 198
    };
    public const double LeafCycleSeconds = 10;

    private static readonly List<(double X, double Y)> GeneratedFruitPositions = [];
    private static readonly object FruitPositionGate = new();

    /// <summary>
    /// Returns a stable pseudo-random point inside the mature tree crown.
    /// Stability keeps a fruit from jumping when the widget refreshes, while
    /// the low-discrepancy sequence spreads nearby fruits across the leaves.
    /// </summary>
    public static (double X, double Y) FruitPositionAt(int index)
    {
        if (index < 0) throw new ArgumentOutOfRangeException(nameof(index));
        lock (FruitPositionGate)
        {
            while (GeneratedFruitPositions.Count <= index)
                GeneratedFruitPositions.Add(NextFruitPosition(GeneratedFruitPositions.Count));
            return GeneratedFruitPositions[index];
        }
    }

    private static (double X, double Y) NextFruitPosition(int index)
    {
        // Give the first fruits room by choosing the most separated of several
        // deterministic candidates. Crowded crowns may naturally overlap.
        if (index >= 32) return FruitCandidate(index, 0);
        const int attempts = 96;
        (double X, double Y) best = default;
        double bestDistance = double.NegativeInfinity;
        for (int attempt = 0; attempt < attempts; attempt++)
        {
            (double x, double y) = FruitCandidate(index, attempt);
            double nearest = double.PositiveInfinity;
            foreach ((double priorX, double priorY) in GeneratedFruitPositions)
            {
                double dx = (x - priorX) / 24;
                double dy = (y - priorY) / 28;
                nearest = Math.Min(nearest, dx * dx + dy * dy);
            }
            if (nearest <= bestDistance) continue;
            best = (x, y);
            bestDistance = nearest;
        }
        return best;
    }

    private static (double X, double Y) FruitCandidate(int index, int attempt)
    {
        const double centerX = 130;
        const double centerY = 79;
        const double radiusX = 91;
        const double radiusY = 64;
        double angle = 2 * Math.PI * Fraction((index + 1) * 0.6180339887498949 +
            attempt * 0.7548776662466927 + 0.137);
        double radius = Math.Sqrt(Fraction((index + 1) * 0.4142135623730951 +
            attempt * 0.5698402909980532 + 0.271));
        double x = centerX + Math.Cos(angle) * radiusX * radius - 12;
        double y = centerY + Math.Sin(angle) * radiusY * radius - 14;
        return (Math.Clamp(x, 8, Width - 32), Math.Clamp(y, 4, ArtworkHeight - 32));
    }

    private static double Fraction(double value) => value - Math.Floor(value);

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

    public static int VisibleFruitCount(long inventory) => inventory <= 0 ? 0 :
        inventory >= int.MaxValue ? int.MaxValue : (int)inventory;

    public static bool CanHarvest(int stage, long inventory, double x, double y) =>
        stage == 5 && inventory > 0 && x is >= 32 and <= 228 && y is >= 12 and <= 150;

    public static int FruitIndexAt(int visibleCount, double x, double y)
    {
        if (!double.IsFinite(x) || !double.IsFinite(y)) return -1;
        const double padding = 10;
        int nearest = -1;
        double nearestDistance = double.PositiveInfinity;
        for (int i = visibleCount - 1; i >= 0; i--)
        {
            (double left, double top) = FruitPositionAt(i);
            if (x < left - padding || x > left + 24 + padding ||
                y < top - padding || y > top + 28 + padding) continue;
            double dx = x - (left + 12);
            double dy = y - (top + 14);
            double distance = dx * dx + dy * dy;
            if (distance < nearestDistance) (nearest, nearestDistance) = (i, distance);
        }
        return nearest;
    }
}

internal readonly record struct FallingLeaf(
    double X, double Y, double Drift, double DelaySeconds, double DurationSeconds, double InitialRotation);
