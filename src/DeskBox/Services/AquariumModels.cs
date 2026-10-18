using System.Text.Json;

namespace DeskBox.Services;

internal sealed record AquariumCatalogItem(string Id, string Name, string Category, long Price, string AssetKey);
internal sealed record AquariumInventoryItem(string Id, string ItemId, string CreatedAt);
// Layer is a stable scene z-order. Older cached/server scenes omit it and
// deserialize to the default middle layer (0).
internal sealed record AquariumPlacement(string InstanceId, double X, double Y, double Scale, int Layer = 0);
internal sealed record AquariumWalletEntry(string Id, long Delta, long Balance, string Reason, string? ItemId, string CreatedAt);
internal sealed record AquariumState(
    bool Initialized,
    long Coins,
    long SceneVersion,
    IReadOnlyList<AquariumInventoryItem> Inventory,
    IReadOnlyList<AquariumPlacement> Scene,
    IReadOnlyList<AquariumWalletEntry> Wallet);

/// <summary>Explicit JSON contracts keep aquarium requests compatible with the native AOT client.</summary>
internal static class AquariumJson
{
    internal static AquariumState ReadState(JsonElement root) => new(
        root.GetProperty("initialized").GetBoolean(),
        root.GetProperty("coins").GetInt64(),
        root.GetProperty("scene_version").GetInt64(),
        Array.AsReadOnly(root.GetProperty("inventory").EnumerateArray().Select(item => new AquariumInventoryItem(
            Id(item, "id"), Text(item, "item_id"), Text(item, "created_at"))).ToArray()),
        ReadScene(root.GetProperty("scene")),
        Array.AsReadOnly(root.GetProperty("wallet").EnumerateArray().Select(item => new AquariumWalletEntry(
            Id(item, "id"), item.GetProperty("delta").GetInt64(), item.GetProperty("balance").GetInt64(),
            Text(item, "reason"), OptionalText(item, "item_id"), Text(item, "created_at"))).ToArray()));

    internal static IReadOnlyList<AquariumCatalogItem> ReadCatalog(JsonElement items) => Array.AsReadOnly(
        items.EnumerateArray().Select(item => new AquariumCatalogItem(Text(item, "id"), Text(item, "name"),
            Text(item, "category"), item.GetProperty("price").GetInt64(), Text(item, "asset_key"))).ToArray());

    internal static IReadOnlyList<AquariumPlacement> ReadScene(JsonElement scene) => Array.AsReadOnly(
        scene.EnumerateArray().Select(item => new AquariumPlacement(Id(item, "instance_id"),
            item.GetProperty("x").GetDouble(), item.GetProperty("y").GetDouble(), item.GetProperty("scale").GetDouble(),
            item.TryGetProperty("layer", out JsonElement layer) && layer.TryGetInt32(out int value) ? value : 0)).ToArray());

    internal static byte[] Write(Action<Utf8JsonWriter> write)
    {
        using var stream = new MemoryStream();
        using (var writer = new Utf8JsonWriter(stream)) write(writer);
        return stream.ToArray();
    }

    internal static void WriteScene(Utf8JsonWriter writer, IReadOnlyList<AquariumPlacement> scene)
    {
        writer.WriteStartArray();
        foreach (AquariumPlacement item in scene)
        {
            writer.WriteStartObject();
            writer.WriteString("instance_id", item.InstanceId);
            writer.WriteNumber("x", item.X);
            writer.WriteNumber("y", item.Y);
            writer.WriteNumber("scale", item.Scale);
            writer.WriteNumber("layer", item.Layer);
            writer.WriteEndObject();
        }
        writer.WriteEndArray();
    }

    internal static void WriteCatalog(Utf8JsonWriter writer, IReadOnlyList<AquariumCatalogItem> catalog)
    {
        writer.WriteStartArray();
        foreach (AquariumCatalogItem item in catalog)
        {
            writer.WriteStartObject();
            writer.WriteString("id", item.Id);
            writer.WriteString("name", item.Name);
            writer.WriteString("category", item.Category);
            writer.WriteNumber("price", item.Price);
            writer.WriteString("asset_key", item.AssetKey);
            writer.WriteEndObject();
        }
        writer.WriteEndArray();
    }

    internal static void WriteState(Utf8JsonWriter writer, AquariumState state)
    {
        writer.WriteStartObject();
        writer.WriteBoolean("initialized", state.Initialized);
        writer.WriteNumber("coins", state.Coins);
        writer.WriteNumber("scene_version", state.SceneVersion);
        writer.WriteStartArray("inventory");
        foreach (AquariumInventoryItem item in state.Inventory)
        {
            writer.WriteStartObject();
            writer.WriteString("id", item.Id);
            writer.WriteString("item_id", item.ItemId);
            writer.WriteString("created_at", item.CreatedAt);
            writer.WriteEndObject();
        }
        writer.WriteEndArray();
        writer.WritePropertyName("scene");
        WriteScene(writer, state.Scene);
        writer.WriteStartArray("wallet");
        foreach (AquariumWalletEntry entry in state.Wallet)
        {
            writer.WriteStartObject();
            writer.WriteString("id", entry.Id);
            writer.WriteNumber("delta", entry.Delta);
            writer.WriteNumber("balance", entry.Balance);
            writer.WriteString("reason", entry.Reason);
            writer.WriteString("item_id", entry.ItemId);
            writer.WriteString("created_at", entry.CreatedAt);
            writer.WriteEndObject();
        }
        writer.WriteEndArray();
        writer.WriteEndObject();
    }

    internal static string Text(JsonElement item, string property) => item.GetProperty(property).GetString()
        ?? throw new InvalidDataException($"Missing aquarium field: {property}.");

    private static string Id(JsonElement item, string property)
    {
        JsonElement value = item.GetProperty(property);
        return value.ValueKind == JsonValueKind.Number ? value.GetRawText() : Text(item, property);
    }

    private static string? OptionalText(JsonElement item, string property) =>
        item.TryGetProperty(property, out JsonElement value) && value.ValueKind == JsonValueKind.String ? value.GetString() : null;
}
