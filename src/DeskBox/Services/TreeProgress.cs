namespace DeskBox.Services;

/// <summary>One machine's aggregate tree state. No individual key or text is retained.</summary>
public sealed class TreeProgress
{
    public const long StageEnergyInterval = 36_000;
    public const int CloudFruitCost = 3_000;
    public int DailyGoal { get; set; } = 12000;
    public bool Paused { get; set; }
    public string LocalDay { get; set; } = string.Empty;
    public long TodayKeys { get; set; }
    public long TotalEnergy { get; set; }
    public int HighestStage { get; set; } = 1;
    public long FruitEnergy { get; set; }
    public long FruitInventory { get; set; }
    public long HarvestedFruit { get; set; }

    public long MaturityThreshold => 4 * StageEnergyInterval;
    public double MaturityPercentage => Math.Clamp(100.0 * TotalEnergy / MaturityThreshold, 0, 100);
    public int FruitCost => CloudFruitCost;
    public int Stage => Math.Max(HighestStage, StageFor(TotalEnergy));
    public long NextStageThreshold => Stage >= 5 ? MaturityThreshold : (long)Stage * StageEnergyInterval;
    public long NextStageRemainingEnergy => Math.Max(0, NextStageThreshold - TotalEnergy);
    public double NextStagePercentage => Stage >= 5
        ? 100
        : Math.Clamp(100.0 * (TotalEnergy - (long)(Stage - 1) * StageEnergyInterval) / StageEnergyInterval, 0, 100);

    public void RollDay(DateTime localNow)
    {
        string day = localNow.ToString("yyyy-MM-dd", System.Globalization.CultureInfo.InvariantCulture);
        if (LocalDay != day) { LocalDay = day; TodayKeys = 0; }
    }

    public static int StageFor(long energy) => energy >= 4 * StageEnergyInterval ? 5
        : energy >= 3 * StageEnergyInterval ? 4
        : energy >= 2 * StageEnergyInterval ? 3
        : energy >= StageEnergyInterval ? 2 : 1;

    public void AddEnergy(int amount, DateTime localNow)
    {
        if (amount <= 0 || Paused) return;
        RollDay(localNow);
        long before = TotalEnergy;
        bool wasMature = HighestStage == 5 || StageFor(before) == 5;
        TotalEnergy = checked(TotalEnergy + amount);
        TodayKeys = checked(TodayKeys + amount);
        if (StageFor(TotalEnergy) > HighestStage)
            HighestStage = StageFor(TotalEnergy);
        if (HighestStage == 5)
        {
            long fruitGain = wasMature ? amount : Math.Max(0, TotalEnergy - MaturityThreshold);
            FruitEnergy = checked(FruitEnergy + fruitGain);
            FruitInventory = checked(FruitInventory + FruitEnergy / FruitCost);
            FruitEnergy %= FruitCost;
        }
    }

    public void SetGoal(int goal)
    {
        if (goal < 1 || goal > 1000000) throw new ArgumentOutOfRangeException(nameof(goal));
        DailyGoal = goal;
        HighestStage = Math.Max(HighestStage, StageFor(TotalEnergy));
        if (HighestStage == 5)
        {
            FruitInventory = checked(FruitInventory + FruitEnergy / FruitCost);
            FruitEnergy %= FruitCost;
        }
    }

    /// <summary>
    /// Picks exactly one fruit from the tree and credits a random 1-5 fruit
    /// reward to the account inventory.  The inventory count is the number of
    /// fruits still on the tree; HarvestedFruit is the account backpack total.
    /// </summary>
    public long Harvest(Random? random = null)
    {
        if (FruitInventory <= 0) return 0;
        int reward = (random ?? Random.Shared).Next(1, 6);
        FruitInventory--;
        HarvestedFruit = checked(HarvestedFruit + reward);
        return reward;
    }
}
