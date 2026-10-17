using System.Numerics;
using DeskBox.Controls.WidgetContents;

namespace DeskBox.Tests;

public sealed class AquariumFishMotionPlannerTests
{
    [Fact]
    public void EachTurnHoldsTranslationUntilFacingHasChanged()
    {
        var input = new AquariumFishMotionInput("fish-1", "clownfish", .3, .5);
        AquariumFishMotionPlan plan = AquariumFishMotionPlanner.Build([input], 840, 600, 17)["fish-1"];

        int turnIndex = -1;
        for (int index = 1; index < plan.Facing.Count; index++)
        {
            if (plan.Facing[index].Value != plan.Facing[0].Value)
            {
                turnIndex = index;
                break;
            }
        }
        Assert.True(turnIndex > 0);
        float turnProgress = plan.Facing[turnIndex].Progress;
        int translationIndex = plan.Translation.ToList().FindIndex(frame => frame.Progress == turnProgress);
        Assert.True(translationIndex > 0 && translationIndex + 1 < plan.Translation.Count);
        Assert.Equal(plan.Translation[translationIndex - 1].Value, plan.Translation[translationIndex].Value);
        Assert.True(plan.Translation[translationIndex + 1].Progress > turnProgress);
    }

    [Fact]
    public void SpeciesHaveDifferentBaseSpeeds()
    {
        var clown = new AquariumFishMotionInput("clown-1", "clownfish", .3, .5);
        var puffer = new AquariumFishMotionInput("puffer-1", "pufferfish", .3, .5);
        var plans = AquariumFishMotionPlanner.Build([clown, puffer], 840, 600, 17);

        Assert.NotEqual(plans["clown-1"].SpeedMultiplier, plans["puffer-1"].SpeedMultiplier);
        Assert.True(plans["clown-1"].DurationSeconds < plans["puffer-1"].DurationSeconds);
    }

    [Fact]
    public void SameSpeciesAreGroupedInSchoolsOfThreeToFive()
    {
        var fish = Enumerable.Range(1, 8)
            .Select(index => new AquariumFishMotionInput($"fish-{index}", "blue-tang", .1 + index * .08, .5))
            .ToArray();
        IReadOnlyDictionary<string, AquariumFishMotionPlan> plans = AquariumFishMotionPlanner.Build(fish, 840, 600, 17);

        int[] schoolSizes = plans.Values.GroupBy(plan => plan.SchoolId).Select(group => group.Count()).OrderBy(size => size).ToArray();
        Assert.Equal(new[] { 4, 4 }, schoolSizes);
    }

    [Fact]
    public void AStragglerIsAllowedToLagBehindItsSchool()
    {
        AquariumFishMotionInput[] fish = Enumerable.Range(1, 12)
            .Select(index => new AquariumFishMotionInput($"fish-{index}", "angelfish", .1 + index * .05, .5))
            .ToArray();
        IReadOnlyDictionary<string, AquariumFishMotionPlan> plans = AquariumFishMotionPlanner.Build(fish, 840, 600, 17);

        Assert.Contains(plans.Values, plan => plan.IsStraggler);
        Assert.All(plans.Values, plan => Assert.NotEmpty(plan.Translation));
    }

    [Fact]
    public void PlansCloseTheirLoopAndReturnToInitialFacing()
    {
        var input = new AquariumFishMotionInput("fish-1", "clownfish", .3, .5);
        AquariumFishMotionPlan plan = AquariumFishMotionPlanner.Build([input], 840, 600, 17)["fish-1"];

        Assert.Equal(Vector3.Zero, plan.Translation[^1].Value);
        Assert.Equal(plan.Facing[0].Value, plan.Facing[^1].Value);
    }

    [Fact]
    public void SwimmingStartsWithAnAccelerationRampAfterTheTurn()
    {
        var input = new AquariumFishMotionInput("fish-1", "clownfish", .3, .5);
        AquariumFishMotionPlan plan = AquariumFishMotionPlanner.Build([input], 840, 600, 17)["fish-1"];

        double firstStep = plan.Translation[3].Value.Length();
        double secondStep = (plan.Translation[4].Value - plan.Translation[3].Value).Length();
        double cruiseStep = (plan.Translation[5].Value - plan.Translation[4].Value).Length();
        Assert.True(firstStep > 0);
        Assert.True(secondStep > firstStep);
        Assert.True(cruiseStep > secondStep);
    }
}
