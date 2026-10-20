namespace DeskBox.Services;

internal sealed class AdminMutationRequest
{
    public string Action { get; set; } = string.Empty;
    public string TargetId { get; set; } = string.Empty;
    public string Reason { get; set; } = string.Empty;
    public string? SessionId { get; set; }
    public string? SnapshotId { get; set; }
    public string? ConfirmUsername { get; set; }
    public string? AdminPassword { get; set; }
    public string? Day { get; set; }
    public int? BaseVersion { get; set; }
    public long? TotalEnergy { get; set; }
    public long? TodayGrowth { get; set; }
    public long? FruitEnergy { get; set; }
    public long? FruitTotal { get; set; }
    public long? HarvestedTotal { get; set; }
    public long? Coins { get; set; }
    public long? PricePerFruit { get; set; }
}
