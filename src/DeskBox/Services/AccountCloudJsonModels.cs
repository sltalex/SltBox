using System.Text.Json.Serialization;

namespace DeskBox.Services;

internal sealed class AccountBackupFileDto
{
    [JsonPropertyName("path")]
    public string Path { get; set; } = string.Empty;
    [JsonPropertyName("content")]
    public string Content { get; set; } = string.Empty;
    [JsonPropertyName("bytes")]
    public int Bytes { get; set; }
    [JsonPropertyName("sha256")]
    public string Sha256 { get; set; } = string.Empty;
}

internal sealed class AccountBackupCreateRequest
{
    [JsonPropertyName("device_id")]
    public string DeviceId { get; set; } = string.Empty;
    [JsonPropertyName("app_version")]
    public string AppVersion { get; set; } = string.Empty;
    [JsonPropertyName("files")]
    public AccountBackupFileDto[] Files { get; set; } = [];
}

internal class AccountBackupSnapshotDto
{
    [JsonPropertyName("id")]
    public string Id { get; set; } = string.Empty;
    [JsonPropertyName("device_id")]
    public string DeviceId { get; set; } = string.Empty;
    [JsonPropertyName("created_at")]
    public DateTimeOffset CreatedAt { get; set; }
    [JsonPropertyName("app_version")]
    public string AppVersion { get; set; } = string.Empty;
    [JsonPropertyName("file_count")]
    public int FileCount { get; set; }
    [JsonPropertyName("total_bytes")]
    public long TotalBytes { get; set; }
    [JsonPropertyName("sha256")]
    public string Sha256 { get; set; } = string.Empty;
}

internal sealed class AccountBackupSnapshotDownloadDto : AccountBackupSnapshotDto
{
    [JsonPropertyName("files")]
    public AccountBackupFileDto[] Files { get; set; } = [];
}

internal sealed class AccountBackupCreateResponse
{
    [JsonPropertyName("snapshot")]
    public AccountBackupSnapshotDto Snapshot { get; set; } = new();
}

internal sealed class AccountBackupUploadInitRequest
{
    [JsonPropertyName("device_id")] public string DeviceId { get; set; } = string.Empty;
    [JsonPropertyName("app_version")] public string AppVersion { get; set; } = string.Empty;
    [JsonPropertyName("total_bytes")] public long TotalBytes { get; set; }
    [JsonPropertyName("sha256")] public string Sha256 { get; set; } = string.Empty;
}

internal sealed class AccountBackupUploadInitResponse
{
    [JsonPropertyName("upload_id")] public string UploadId { get; set; } = string.Empty;
    [JsonPropertyName("chunk_size")] public int ChunkSize { get; set; }
    [JsonPropertyName("received")] public long[][] Received { get; set; } = [];
}

internal sealed class AccountBackupUploadCompleteRequest
{
    [JsonPropertyName("upload_id")] public string UploadId { get; set; } = string.Empty;
}

internal sealed class AccountBackupListResponse
{
    [JsonPropertyName("snapshots")]
    public AccountBackupSnapshotDto[] Snapshots { get; set; } = [];
}

internal sealed class AccountBackupDownloadResponse
{
    [JsonPropertyName("snapshot")]
    public AccountBackupSnapshotDownloadDto Snapshot { get; set; } = new();
}

internal sealed class AccountRestoreMarker
{
    public string SnapshotId { get; set; } = string.Empty;
    public string OwnerUserId { get; set; } = string.Empty;
    public string StagingDirectory { get; set; } = string.Empty;
}

internal sealed class TreeProgressUploadRequest
{
    [JsonPropertyName("operation_id")]
    public string OperationId { get; set; } = string.Empty;
    [JsonPropertyName("device_id")]
    public string DeviceId { get; set; } = string.Empty;
    [JsonPropertyName("day")]
    public string Day { get; set; } = string.Empty;
    [JsonPropertyName("energy_delta")]
    public long EnergyDelta { get; set; }
    [JsonPropertyName("day_growth_delta")]
    public long DayGrowthDelta { get; set; }
    [JsonPropertyName("fruit_delta")]
    public long FruitDelta { get; set; }
    [JsonPropertyName("harvested_delta")]
    public long HarvestedDelta { get; set; }
}

internal sealed class TreeStateSyncRequest
{
    [JsonPropertyName("operation_id")]
    public string OperationId { get; set; } = string.Empty;
    [JsonPropertyName("device_id")]
    public string DeviceId { get; set; } = string.Empty;
    [JsonPropertyName("base_version")]
    public long BaseVersion { get; set; }
    [JsonPropertyName("day")]
    public string Day { get; set; } = string.Empty;
    [JsonPropertyName("today_growth")]
    public long TodayGrowth { get; set; }
    [JsonPropertyName("total_energy")]
    public long TotalEnergy { get; set; }
    [JsonPropertyName("stage")]
    public int Stage { get; set; }
    [JsonPropertyName("fruit_energy")]
    public long FruitEnergy { get; set; }
    [JsonPropertyName("fruit_total")]
    public long FruitTotal { get; set; }
    [JsonPropertyName("harvested_total")]
    public long HarvestedTotal { get; set; }
}

internal sealed class TreeStateDto
{
    [JsonPropertyName("day")]
    public string Day { get; set; } = string.Empty;
    [JsonPropertyName("state_version")]
    public long StateVersion { get; set; }
    [JsonPropertyName("fruit_cost")]
    public int FruitCost { get; set; } = TreeProgress.CloudFruitCost;
    [JsonPropertyName("today_growth")]
    public long TodayGrowth { get; set; }
    [JsonPropertyName("total_energy")]
    public long TotalEnergy { get; set; }
    [JsonPropertyName("stage")]
    public int Stage { get; set; }
    [JsonPropertyName("fruit_energy")]
    public long FruitEnergy { get; set; }
    [JsonPropertyName("fruit_inventory")]
    public long FruitInventory { get; set; }
    [JsonPropertyName("fruit_total")]
    public long FruitTotal { get; set; }
    [JsonPropertyName("harvested_total")]
    public long HarvestedTotal { get; set; }
}

internal sealed class TreeStateResponse
{
    [JsonPropertyName("state")]
    public TreeStateDto State { get; set; } = new();
}

internal sealed class TreeProfileUpdateRequest
{
    [JsonPropertyName("participating")]
    public bool Participating { get; set; }
}

internal sealed class TreeProgressUploadResponse
{
    [JsonPropertyName("duplicate")]
    public bool Duplicate { get; set; }
    [JsonPropertyName("state")]
    public TreeStateDto State { get; set; } = new();
}

internal sealed class TreeLeaderboardResponse
{
    [JsonPropertyName("day")]
    public string Day { get; set; } = string.Empty;
    [JsonPropertyName("entries")]
    public TreeLeaderboardEntryDto[] Entries { get; set; } = [];
    [JsonPropertyName("current_user")]
    public TreeLeaderboardCurrentUserDto CurrentUser { get; set; } = new();
}

internal sealed class TreeLeaderboardEntryDto
{
    [JsonPropertyName("rank")]
    public int Rank { get; set; }
    [JsonPropertyName("account")]
    public string Account { get; set; } = string.Empty;
    [JsonPropertyName("username")]
    public string Username { get; set; } = string.Empty;
    [JsonPropertyName("stage")]
    public int Stage { get; set; }
    [JsonPropertyName("today_growth")]
    public long TodayGrowth { get; set; }
    [JsonPropertyName("total_energy")]
    public long TotalEnergy { get; set; }
    [JsonPropertyName("fruit_total")]
    public long FruitTotal { get; set; }
    [JsonPropertyName("is_current_user")]
    public bool IsCurrentUser { get; set; }
}

internal sealed class TreeLeaderboardCurrentUserDto
{
    [JsonPropertyName("rank")]
    public int? Rank { get; set; }
    [JsonPropertyName("participating")]
    public bool Participating { get; set; }
}

internal sealed class TreeCloudState
{
    public string UserId { get; set; } = string.Empty;
    public long StateVersion { get; set; }
    public TreeStateSyncRequest? Pending { get; set; }
    public string? ConflictSnapshot { get; set; }
    public bool RefreshRequired { get; set; }
    public TreeStateDto? Acknowledged { get; set; }
}

[JsonSourceGenerationOptions(GenerationMode = JsonSourceGenerationMode.Metadata, PropertyNamingPolicy = JsonKnownNamingPolicy.CamelCase)]
[JsonSerializable(typeof(AccountBackupCreateRequest))]
[JsonSerializable(typeof(AccountBackupCreateResponse))]
[JsonSerializable(typeof(AccountBackupUploadInitRequest))]
[JsonSerializable(typeof(AccountBackupUploadInitResponse))]
[JsonSerializable(typeof(AccountBackupUploadCompleteRequest))]
[JsonSerializable(typeof(AccountBackupListResponse))]
[JsonSerializable(typeof(AccountBackupDownloadResponse))]
[JsonSerializable(typeof(AccountRestoreMarker))]
[JsonSerializable(typeof(TreeProgressUploadRequest))]
[JsonSerializable(typeof(TreeStateSyncRequest))]
[JsonSerializable(typeof(TreeStateDto))]
[JsonSerializable(typeof(TreeStateResponse))]
[JsonSerializable(typeof(TreeProfileUpdateRequest))]
[JsonSerializable(typeof(TreeProgressUploadResponse))]
[JsonSerializable(typeof(TreeLeaderboardResponse))]
[JsonSerializable(typeof(TreeCloudState))]
[JsonSerializable(typeof(string[]))]
internal sealed partial class AccountCloudJsonContext : JsonSerializerContext
{
}
