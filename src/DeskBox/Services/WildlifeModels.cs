using System.Text.Json.Serialization;

namespace DeskBox.Services;

internal sealed record WildlifeCatalogItem(string Id, string Name, string Category);
internal sealed record WildlifeInventoryItem(string SpeciesId, long Quantity, bool Discovered);
internal sealed record WildlifeEncounter(string Id, string SpeciesId, DateTimeOffset ExpiresAtUtc);

internal sealed class WildlifeCacheDto
{
    public string OwnerUserId { get; set; } = string.Empty;
    public WildlifeInventoryItem[] Inventory { get; set; } = [];
}

internal sealed record WildlifeOfferRequest(
    [property: JsonPropertyName("weather_code")] int WeatherCode,
    [property: JsonPropertyName("weather_fetched_at_utc")] string WeatherFetchedAtUtc,
    [property: JsonPropertyName("utc_offset_minutes")] int UtcOffsetMinutes,
    [property: JsonPropertyName("resume_after_hidden")] bool ResumeAfterHidden);

internal sealed record WildlifeCaptureRequest(
    [property: JsonPropertyName("encounter_id")] string EncounterId,
    [property: JsonPropertyName("operation_id")] string OperationId);

internal sealed record WildlifeRestoreRequest(
    [property: JsonPropertyName("owner_user_id")] string OwnerUserId,
    [property: JsonPropertyName("inventory")] WildlifeRestoreItem[] Inventory);

internal sealed record WildlifeRestoreItem(
    [property: JsonPropertyName("species_id")] string SpeciesId,
    [property: JsonPropertyName("quantity")] long Quantity,
    [property: JsonPropertyName("discovered")] bool Discovered);

[JsonSourceGenerationOptions(WriteIndented = false)]
[JsonSerializable(typeof(WildlifeOfferRequest))]
[JsonSerializable(typeof(WildlifeCaptureRequest))]
[JsonSerializable(typeof(WildlifeRestoreRequest))]
[JsonSerializable(typeof(WildlifeCacheDto))]
internal partial class WildlifeJsonContext : JsonSerializerContext { }
