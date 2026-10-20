using DeskBox.Services;
using DeskBox.Core.Persistence;
using System.Runtime.InteropServices;

namespace DeskBox.Tests;

public sealed class TreeProgressTests
{
    private static readonly DateTime Day = new(2026, 10, 10, 12, 0, 0);

    [Theory]
    [InlineData(0, 1)]
    [InlineData(35999, 1)]
    [InlineData(36000, 2)]
    [InlineData(71999, 2)]
    [InlineData(72000, 3)]
    [InlineData(107999, 3)]
    [InlineData(108000, 4)]
    [InlineData(143999, 4)]
    [InlineData(144000, 5)]
    public void EnergyAloneDeterminesTheFiveStages(long energy, int expected)
    {
        Assert.Equal(expected, TreeProgress.StageFor(energy));
    }

    [Theory]
    [InlineData(0, 0)]
    [InlineData(36000, 25)]
    [InlineData(72000, 50)]
    [InlineData(108000, 75)]
    [InlineData(144000, 100)]
    [InlineData(147000, 100)]
    public void MaturityBarShowsOnlyOverallPercentage(long energy, double expected)
    {
        var tree = new TreeProgress { TotalEnergy = energy };
        Assert.Equal(expected, tree.MaturityPercentage);
    }

    [Theory]
    [InlineData(0, 0, 36000, 36000)]
    [InlineData(18000, 50, 36000, 18000)]
    [InlineData(36000, 0, 72000, 36000)]
    [InlineData(54000, 50, 72000, 18000)]
    [InlineData(143999, 99.99722222222222, 144000, 1)]
    [InlineData(144000, 100, 144000, 0)]
    public void NextStageProgressReportsCurrentStageWindow(
        long energy, double expectedPercentage, long expectedThreshold, long expectedRemaining)
    {
        var tree = new TreeProgress { TotalEnergy = energy };
        Assert.Equal(expectedPercentage, tree.NextStagePercentage);
        Assert.Equal(expectedThreshold, tree.NextStageThreshold);
        Assert.Equal(expectedRemaining, tree.NextStageRemainingEnergy);
    }

    [Fact]
    public void PickingOneFruitCreditsRandomRewardAndLeavesOtherTreeFruit()
    {
        var tree = new TreeProgress();
        tree.AddEnergy(143999, Day);
        tree.AddEnergy(6002, Day);
        Assert.Equal(5, tree.Stage);
        Assert.Equal(2, tree.FruitInventory);
        Assert.Equal(1, tree.FruitEnergy);
        Assert.Equal(5, tree.Harvest(new FixedRandom(5)));
        Assert.Equal(1, tree.FruitInventory);
        Assert.Equal(5, tree.HarvestedFruit);
        Assert.Equal(1, tree.Harvest(new FixedRandom(1)));
        Assert.Equal(0, tree.FruitInventory);
    }

    [Fact]
    public void HarvestRewardIsAlwaysBetweenOneAndFive()
    {
        for (int seed = 0; seed < 20; seed++)
        {
            var tree = new TreeProgress { FruitInventory = 1 };
            long reward = tree.Harvest(new Random(seed));
            Assert.InRange(reward, 1, 5);
            Assert.Equal(0, tree.FruitInventory);
            Assert.Equal(reward, tree.HarvestedFruit);
        }
    }

    [Fact]
    public void ChangedGoalKeepsMatureStageAndUnpickedFruit()
    {
        var tree = new TreeProgress();
        tree.AddEnergy(147500, Day);
        tree.SetGoal(20000);
        Assert.Equal(5, tree.Stage);
        Assert.Equal(1, tree.FruitInventory);
        Assert.Equal(500, tree.FruitEnergy);
        tree.AddEnergy(4500, Day.AddDays(1));
        Assert.Equal(4500, tree.TodayKeys);
        Assert.Equal(2, tree.FruitInventory);
        Assert.Equal(2000, tree.FruitEnergy);
    }

    [Fact]
    public void NonDivisibleDailyGoalRoundsFruitCostUp()
    {
        var tree = new TreeProgress();
        tree.SetGoal(12001);
        Assert.Equal(144000L, tree.MaturityThreshold);
        Assert.Equal(3000, tree.FruitCost);
        tree.AddEnergy((int)tree.MaturityThreshold, Day);
        tree.AddEnergy(3000, Day);
        Assert.Equal(1, tree.FruitInventory);
        tree.AddEnergy(1, Day);
        Assert.Equal(1, tree.FruitInventory);
    }

    [Fact]
    public void PausingAndCrossingDaysPreservesGrowth()
    {
        var tree = new TreeProgress();
        tree.AddEnergy(10, Day);
        tree.Paused = true;
        tree.AddEnergy(10, Day);
        Assert.Equal(10, tree.TotalEnergy);
        tree.Paused = false;
        tree.AddEnergy(5, Day.AddDays(1));
        Assert.Equal(5, tree.TodayKeys);
        Assert.Equal(15, tree.TotalEnergy);
    }

    [Fact]
    public void KeyFilterExcludesRepeatModifiersShortcutsAndInjection()
    {
        var filter = new TreeKeyFilter();
        Assert.False(filter.Process(0x11, true, false)); // Ctrl
        Assert.False(filter.Process(0x41, true, false)); // Ctrl+A
        Assert.False(filter.Process(0x41, false, false));
        Assert.False(filter.Process(0x11, false, false));
        Assert.True(filter.Process(0x41, true, false));
        Assert.False(filter.Process(0x41, true, false)); // auto-repeat
        Assert.False(filter.Process(0x41, false, false));
        Assert.True(filter.Process(0x41, true, false));
        Assert.False(filter.Process(0x42, true, true));
        Assert.False(filter.Process(0x70, true, false)); // F1
    }

    [Fact]
    public void KeyboardHookObserverExceptionDoesNotEscapeCallbackBoundary()
    {
        var wrongThread = new COMException("Wrong UI thread", unchecked((int)0x8001010E));
        Exception? reported = null;
        TreeCounterService.NotifyChangedSafely(
            () => throw wrongThread,
            error => reported = error);
        Assert.Same(wrongThread, reported);
    }

    [Fact]
    public void ProgressSurvivesRestartAndRecoversFromDamagedPrimary()
    {
        string directory = Path.Combine(Path.GetTempPath(), "deskbox-tree-test-" + Guid.NewGuid().ToString("N"));
        string path = Path.Combine(directory, "progress.json");
        try
        {
            var store = new TreeProgressFileStore(path);
            var state = new TreeProgress();
            state.AddEnergy(144001, Day);
            store.Save(state);
            state.AddEnergy(3000, Day.AddDays(1));
            store.Save(state);
            Assert.Equal(1, new TreeProgressFileStore(path).Load().FruitInventory);
            File.WriteAllText(path, "{broken");
            TreeProgress recovered = new TreeProgressFileStore(path).Load();
            Assert.Equal(144001, recovered.TotalEnergy);
            Assert.Equal(1, recovered.FruitEnergy);
        }
        finally
        {
            if (Directory.Exists(directory)) Directory.Delete(directory, recursive: true);
        }
    }

    private sealed class FixedRandom(int value) : Random
    {
        public override int Next(int minValue, int maxValue) => Math.Clamp(value, minValue, maxValue - 1);
    }
}
