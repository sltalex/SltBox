using System.Numerics;
using DeskBox.Controls.WidgetContents;
using DeskBox.Services;

namespace DeskBox.Tests;

public sealed class AquariumFishMotionPlannerTests
{
    [Fact]
    public void LongReturnLegsAndSchoolCatchupsNeverExceedNormalCruiseSpeed()
    {
        for (int seed = 0; seed < 100; seed++)
        {
            AquariumFishMotionInput[] inputs = Enumerable.Range(0, 5)
                .Select(index => new AquariumFishMotionInput($"fish-{index}", "clownfish",
                    .05 + index * .22, .28, AquariumLayer.Min)).ToArray();
            foreach (var plan in AquariumFishMotionPlanner.Build(inputs, 840, 600, seed).Values)
            {
                foreach (var pair in plan.Translation.Zip(plan.Translation.Skip(1)))
                {
                    double seconds = (pair.Second.Progress - pair.First.Progress) * plan.DurationSeconds;
                    Assert.True(seconds > 0, $"Non-increasing frame time for seed {seed}.");
                    double speed = (pair.Second.Value - pair.First.Value).Length() / seconds;
                    Assert.True(speed <= 35 * Math.Max(.35, plan.SpeedMultiplier),
                        $"Seed {seed}: speed {speed:F2} exceeds normal cruise speed.");
                }
            }
        }
    }

    [Fact]
    public void FishStayWithinTheirAssignedSwimmingDepthThroughoutTheRoute()
    {
        AquariumFishMotionInput[] fish =
        [
            new("back", "clownfish", .3, .28, AquariumLayer.Min),
            new("front", "pufferfish", .7, .70, AquariumLayer.Max)
        ];
        IReadOnlyDictionary<string, AquariumFishMotionPlan> plans =
            AquariumFishMotionPlanner.Build(fish, 840, 600, 17);

        foreach (AquariumFishMotionInput member in fish)
        {
            (double minY, double maxY) = AquariumSwimDepth.Band(member.SwimLayer!.Value);
            Assert.All(plans[member.InstanceId].Translation, frame =>
                Assert.InRange(member.Y * 600 + frame.Value.Y, minY * 600 - .01, maxY * 600 + .01));
        }
    }

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
    public void SpeciesPaceUsesBehaviorBandsAndBreaksProfileTiesDeterministically()
    {
        AquariumFishMotionInput[] fish =
        [
            new("school-1", "sardine", .2, .4),
            new("cruiser-1", "blue-tang", .35, .4),
            new("slow-1", "pufferfish", .5, .4),
            new("tie-a", "fairy-wrasse", .65, .4),
            new("tie-b", "flame-angelfish", .8, .4)
        ];
        var plans = AquariumFishMotionPlanner.Build(fish, 840, 600, 17);

        Assert.True(plans["school-1"].SpeedMultiplier > plans["slow-1"].SpeedMultiplier);
        Assert.NotEqual(plans["tie-a"].SpeedMultiplier, plans["tie-b"].SpeedMultiplier);
        Assert.Equal(plans["school-1"].SpeedMultiplier,
            AquariumFishMotionPlanner.Build(fish, 840, 600, 17)["school-1"].SpeedMultiplier);
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
