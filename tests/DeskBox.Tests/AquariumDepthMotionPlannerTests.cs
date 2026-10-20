using DeskBox.Controls.WidgetContents;
using DeskBox.Services;

namespace DeskBox.Tests;

public sealed class AquariumDepthMotionPlannerTests
{
    [Theory]
    [InlineData("clownfish", .08, 40)]
    [InlineData("blue-tang", .92, 100)]
    [InlineData("whale-shark", .5, 310)]
    [InlineData("cherry-shrimp", .5, 108)]
    [InlineData("hermit-crab", .5, 126)]
    [InlineData("moon-jellyfish", .5, 100)]
    public void CrossingTheViewportUsesNormalSpeciesSpeed(string species, double x, double width)
    {
        var input = new AquariumFishMotionInput("creature-1", species, x, .28, AquariumLayer.Min);
        var swim = AquariumFishMotionPlanner.Build([input], 840, 600, 17)[input.InstanceId];
        var route = AquariumDepthMotionPlanner.Build(swim,
            new AquariumPlacement(input.InstanceId, x, .28, .55, AquariumLayer.Min),
            width, 840, 600, 23);
        int crossings = 0;
        foreach (var pair in route.Translation.Zip(route.Translation.Skip(1)))
        {
            double startX = x * 840 + pair.First.Value.X;
            double endX = x * 840 + pair.Second.Value.X;
            if (!((startX < 0 && endX >= 0) || (endX < 0 && startX >= 0) ||
                  (startX > 840 && endX <= 840) || (endX > 840 && startX <= 840))) continue;
            crossings++;
            double seconds = (pair.Second.Progress - pair.First.Progress) * route.DurationSeconds;
            double speed = (pair.Second.Value - pair.First.Value).Length() / seconds;
            Assert.True(speed <= 35 * Math.Max(.35, swim.SpeedMultiplier),
                $"{species} crossed the viewport at {speed:F2} scene units/s (normal limit {35 * swim.SpeedMultiplier:F2}).");
        }
        Assert.True(crossings >= 8);
    }

    [Fact]
    public void CreatureChangesDepthOnlyWhileFullyOutsideTheAquarium()
    {
        var input = new AquariumFishMotionInput("fish-1", "clownfish", .5, .28, AquariumLayer.Min);
        AquariumFishMotionPlan swim = AquariumFishMotionPlanner.Build([input], 840, 600, 17)[input.InstanceId];
        AquariumDepthMotionPlan route = AquariumDepthMotionPlanner.Build(
            swim, new AquariumPlacement(input.InstanceId, input.X, input.Y, .55, AquariumLayer.Min),
            creatureWidth: 100, sceneWidth: 840, sceneHeight: 600, seed: 23);

        Assert.True(route.LayerChanges.Select(change => change.Layer).Distinct().Count() > 1);
        Assert.Equal(AquariumLayer.Min, route.LayerChanges[0].Layer);
        Assert.All(route.Translation.Zip(route.Translation.Skip(1)), pair =>
            Assert.True(pair.First.Progress < pair.Second.Progress));
        Assert.All(route.LayerChanges.Zip(route.LayerChanges.Skip(1)), pair =>
            Assert.NotEqual(pair.First.Layer, pair.Second.Layer));
        foreach (var pair in swim.Translation.Zip(route.Translation))
        {
            // The depth planner must not clamp waypoints to the visible edge:
            // that would change velocity or pin a creature against the border.
            Assert.Equal(pair.First.Value.X, pair.Second.Value.X);
            Assert.InRange(pair.Second.Progress * route.DurationSeconds,
                pair.First.Progress * swim.DurationSeconds - .001,
                pair.First.Progress * swim.DurationSeconds + .001);
        }
        foreach (var change in route.LayerChanges.Skip(1))
        {
            float x = route.Translation.Last(frame => frame.Progress <= change.Progress).Value.X +
                (float)(input.X * 840);
            Assert.True(x < -100 || x > 940, $"Layer changed while visible at x={x}.");
            double after = (change.Progress + .001) * route.DurationSeconds;
            Assert.Equal(change.Layer, route.LayerAt(after));
            float size = route.VerticalScale.Last(frame => frame.Progress <= change.Progress + .001).Value;
            double expected = AquariumSwimDepth.ScaleFactor(change.Layer) /
                AquariumSwimDepth.ScaleFactor(AquariumLayer.Min);
            Assert.InRange(size, expected - .001, expected + .001);
        }
        Assert.Equal(0, route.Translation[^1].Value.X);
        Assert.Equal(route.LayerChanges[^1].Layer, route.LayerAt(route.DurationSeconds - .001));
        AquariumDepthMotionPlan continuation = AquariumDepthMotionPlanner.Build(swim,
            new AquariumPlacement(input.InstanceId, input.X, input.Y, .55, AquariumLayer.Min),
            creatureWidth: 100, sceneWidth: 840, sceneHeight: 600, seed: 24,
            initialLayer: route.LayerChanges[^1].Layer);
        Assert.Equal(route.Translation[^1].Value, continuation.Translation[0].Value);
        Assert.Equal(route.VerticalScale[^1].Value, continuation.VerticalScale[0].Value);
        Assert.Equal(route.LayerChanges[^1].Layer, continuation.LayerChanges[0].Layer);
        Assert.NotEqual(continuation.LayerChanges[0].Layer, continuation.LayerChanges[1].Layer);
    }

    [Fact]
    public void ReplannedCyclesDoNotForceTheCreatureBackToItsOriginalLayer()
    {
        var input = new AquariumFishMotionInput("fish-1", "clownfish", .5, .28, AquariumLayer.Min);
        AquariumFishMotionPlan swim = AquariumFishMotionPlanner.Build([input], 840, 600, 17)[input.InstanceId];
        var placement = new AquariumPlacement(input.InstanceId, input.X, input.Y, .55, AquariumLayer.Min);
        int layer = placement.Layer;
        var endingLayers = new HashSet<int>();

        for (int seed = 0; seed < 20; seed++)
        {
            AquariumDepthMotionPlan cycle = AquariumDepthMotionPlanner.Build(swim, placement,
                creatureWidth: 100, sceneWidth: 840, sceneHeight: 600, seed, initialLayer: layer);
            Assert.Equal(layer, cycle.LayerChanges[0].Layer);
            layer = cycle.LayerChanges[^1].Layer;
            endingLayers.Add(layer);
        }

        Assert.True(endingLayers.Count > 4);
        Assert.Contains(endingLayers, candidate => candidate != placement.Layer);
    }
}
