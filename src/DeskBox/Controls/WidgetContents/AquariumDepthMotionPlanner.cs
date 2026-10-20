using System.Numerics;
using DeskBox.Services;

namespace DeskBox.Controls.WidgetContents;

internal sealed record AquariumDepthMotionPlan(
    double DurationSeconds,
    IReadOnlyList<(float Progress, Vector3 Value)> Translation,
    IReadOnlyList<(float Progress, float Value)> HorizontalScale,
    IReadOnlyList<(float Progress, float Value)> VerticalScale,
    IReadOnlyList<(float Progress, int Layer)> LayerChanges)
{
    internal int LayerAt(double elapsedSeconds)
    {
        float progress = (float)((Math.Max(0, elapsedSeconds) % DurationSeconds) / DurationSeconds);
        return LayerChanges.Last(change => change.Progress <= progress).Layer;
    }
}

/// <summary>Moves a creature between depth bands only while it is outside the canvas.</summary>
internal static class AquariumDepthMotionPlanner
{
    private const int RouteCount = 4;
    private const double TurnSeconds = .72;
    private const double OutsideSeconds = 1;

    internal static AquariumDepthMotionPlan Build(AquariumFishMotionPlan swim,
        AquariumPlacement placement, double creatureWidth, double sceneWidth, double sceneHeight, int seed,
        int? initialLayer = null)
    {
        var random = new Random(seed ^ (int)AquariumSwimDepth.StableHash(placement.InstanceId));
        int[] layers = new int[RouteCount + 1];
        layers[0] = initialLayer ?? placement.Layer;
        for (int index = 1; index < layers.Length; index++)
            layers[index] = NextLayer(random, layers[index - 1]);

        double initialScale = AquariumSwimDepth.ScaleFactor(placement.Layer);
        float initialFacing = swim.Facing[0].Value;
        double outsideX = initialFacing >= 0 ? -creatureWidth * 3 : sceneWidth + creatureWidth * 3;
        float outsideOffsetX = (float)(outsideX - placement.X * sceneWidth);
        // Crossing the edge is ordinary swimming, not a fixed-duration transition.
        // Longer distances take longer, for every size and species of creature.
        double crossingSeconds = Math.Abs(outsideOffsetX) / AquariumFishMotionPlanner.CruiseSpeed(swim.SpeedMultiplier);
        double transitionSeconds = TurnSeconds + crossingSeconds * 2 + OutsideSeconds;
        double total = RouteCount * (swim.DurationSeconds + transitionSeconds);
        float initialRelativeScale = (float)(AquariumSwimDepth.ScaleFactor(layers[0]) / initialScale);
        var path = new List<(double Time, Vector3 Value)>();
        var horizontal = new List<(double Time, float Value)>();
        var vertical = new List<(double Time, float Value)> { (0, initialRelativeScale) };
        var changes = new List<(double Time, int Layer)> { (0, layers[0]) };

        for (int route = 0; route < RouteCount; route++)
        {
            int layer = layers[route];
            int nextLayer = layers[route + 1];
            double routeStart = route * (swim.DurationSeconds + transitionSeconds);
            double routeEnd = routeStart + swim.DurationSeconds;
            double routeY = CenterY(layer, placement);
            double nextY = CenterY(nextLayer, placement);
            float offsetY = (float)((routeY - placement.Y) * sceneHeight);
            float nextOffsetY = (float)((nextY - placement.Y) * sceneHeight);
            float scale = (float)(AquariumSwimDepth.ScaleFactor(layer) / initialScale);
            float nextScale = (float)(AquariumSwimDepth.ScaleFactor(nextLayer) / initialScale);
            (double minY, double maxY) = AquariumSwimDepth.Band(layer);

            foreach (var frame in swim.Translation)
            {
                // The preceding transition already owns the shared boundary frame.
                if (route > 0 && frame.Progress == 0) continue;
                double y = Math.Clamp(routeY + frame.Value.Y / sceneHeight, minY, maxY);
                path.Add((routeStart + frame.Progress * swim.DurationSeconds,
                    new Vector3(frame.Value.X,
                        (float)((y - placement.Y) * sceneHeight), 0)));
            }
            foreach (var frame in swim.Facing)
                horizontal.Add((routeStart + frame.Progress * swim.DurationSeconds, frame.Value * scale));

            double exitStart = routeEnd + TurnSeconds;
            double exitEnd = exitStart + crossingSeconds;
            double switchTime = exitEnd + OutsideSeconds / 2;
            double enterStart = exitEnd + OutsideSeconds;
            double enterEnd = enterStart + crossingSeconds;
            path.Add((exitStart, new Vector3(0, offsetY, 0)));
            horizontal.Add((exitStart, -initialFacing * scale));
            path.Add((exitEnd, new Vector3(outsideOffsetX, offsetY, 0)));
            path.Add((switchTime - .03, new Vector3(outsideOffsetX, offsetY, 0)));
            path.Add((switchTime + .03, new Vector3(outsideOffsetX, nextOffsetY, 0)));
            path.Add((enterStart, new Vector3(outsideOffsetX, nextOffsetY, 0)));
            path.Add((enterEnd, new Vector3(0, nextOffsetY, 0)));
            horizontal.Add((switchTime - .03, -initialFacing * scale));
            horizontal.Add((switchTime + .03, initialFacing * nextScale));
            horizontal.Add((enterStart, initialFacing * nextScale));
            vertical.Add((switchTime - .03, scale));
            vertical.Add((switchTime + .03, nextScale));
            changes.Add((switchTime, nextLayer));
        }

        return new AquariumDepthMotionPlan(total,
            path.Select(frame => ((float)(frame.Time / total), frame.Value)).ToArray(),
            horizontal.Select(frame => ((float)(frame.Time / total), frame.Value)).ToArray(),
            vertical.Select(frame => ((float)(frame.Time / total), frame.Value)).ToArray(),
            changes.Select(change => ((float)(change.Time / total), change.Layer)).ToArray());
    }

    private static double CenterY(int layer, AquariumPlacement placement)
    {
        if (layer == placement.Layer) return placement.Y;
        (double minY, double maxY) = AquariumSwimDepth.Band(layer);
        return (minY + maxY) / 2;
    }

    private static int NextLayer(Random random, int current)
    {
        int next = AquariumLayer.Min + random.Next(AquariumLayer.Count - 1);
        return next >= current ? next + 1 : next;
    }
}
