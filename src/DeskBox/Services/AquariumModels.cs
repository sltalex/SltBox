using System.Text.Json;

namespace DeskBox.Services;

internal sealed record AquariumCatalogItem(string Id, string Name, string Category, long Price, string AssetKey,
    string? Habitat = null);
internal sealed record AquariumInventoryItem(string Id, string ItemId, string CreatedAt);
/// <summary>
/// The server-selected item awarded by opening an aquarium card pack.
/// </summary>
internal sealed record AquariumOpenedItem(
    string ItemId,
    string? InventoryId,
    string Name,
    string Category,
    string AssetKey);
internal static class AquariumLayer
{
    // Keep the original -1/0/1 values stable while adding seven more layers
    // above the former foreground. This preserves existing scenes verbatim.
    internal const int Min = -1;
    internal const int Max = 8;
    internal const int Count = Max - Min + 1;
}

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
    IReadOnlyList<AquariumWalletEntry> Wallet,
    long FruitBackpack = 0,
    long FruitRecyclePrice = 1,
    IReadOnlyList<string>? Discovered = null);

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
            Text(item, "reason"), OptionalText(item, "item_id"), Text(item, "created_at"))).ToArray()),
        OptionalLong(root, "fruit_backpack"), OptionalLong(root, "fruit_recycle_price", 1),
        root.TryGetProperty("discovered", out JsonElement discovered) && discovered.ValueKind == JsonValueKind.Array
            ? Array.AsReadOnly(discovered.EnumerateArray().Select(x => x.GetString() ?? string.Empty).ToArray())
            : null);

    internal static IReadOnlyList<AquariumCatalogItem> ReadCatalog(JsonElement items) => Array.AsReadOnly(
        items.EnumerateArray().Select(item => new AquariumCatalogItem(Text(item, "id"), Text(item, "name"),
            Text(item, "category"), item.GetProperty("price").GetInt64(), Text(item, "asset_key"),
            OptionalText(item, "habitat"))).ToArray());

    /// <summary>
    /// Reads the optional result attached to an <c>open_pack</c> response.
    /// Accepts either an object in <c>opened_item</c>
    /// or the compact <c>opened_item_id</c> form. Missing fields are filled
    /// from the current catalog so a successful opening remains displayable.
    /// </summary>
    internal static AquariumOpenedItem? ReadOpenedItem(JsonElement root,
        IReadOnlyList<AquariumCatalogItem> catalog)
    {
        JsonElement raw;
        string? inventoryId = null;
        string? itemId = null;
        string? name = null;
        string? category = null;
        string? assetKey = null;

        if (root.TryGetProperty("opened_item", out raw))
        {
            if (raw.ValueKind == JsonValueKind.String)
                itemId = raw.GetString();
            else if (raw.ValueKind == JsonValueKind.Object)
            {
                itemId = OptionalText(raw, "item_id") ?? OptionalText(raw, "product_id");
                string? rawId = OptionalId(raw, "id");
                if (itemId is null && rawId is not null &&
                    (raw.TryGetProperty("category", out _) || catalog.Any(item =>
                        string.Equals(item.Id, rawId, StringComparison.Ordinal))))
                    itemId = rawId;
                else
                    inventoryId = rawId;
                inventoryId ??= OptionalId(raw, "inventory_id");
                name = OptionalText(raw, "name");
                category = OptionalText(raw, "category");
                assetKey = OptionalText(raw, "asset_key");
            }
        }

        if (string.IsNullOrWhiteSpace(itemId) && root.TryGetProperty("opened_item_id", out raw))
            itemId = raw.ValueKind == JsonValueKind.String ? raw.GetString() :
                raw.ValueKind == JsonValueKind.Number ? raw.GetRawText() : null;

        if (string.IsNullOrWhiteSpace(itemId)) return null;
        AquariumCatalogItem? product = catalog.FirstOrDefault(item =>
            string.Equals(item.Id, itemId, StringComparison.Ordinal));
        itemId = product?.Id ?? itemId;
        return new AquariumOpenedItem(itemId, inventoryId,
            name ?? product?.Name ?? itemId,
            category ?? product?.Category ?? "",
            assetKey ?? product?.AssetKey ?? itemId);
    }

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
            if (item.Habitat is not null) writer.WriteString("habitat", item.Habitat);
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
        writer.WriteNumber("fruit_backpack", state.FruitBackpack);
        writer.WriteNumber("fruit_recycle_price", state.FruitRecyclePrice);
        writer.WriteStartArray("discovered");
        foreach (string id in state.Discovered ?? Array.Empty<string>()) writer.WriteStringValue(id);
        writer.WriteEndArray();
        writer.WriteEndObject();
    }

    internal static string Text(JsonElement item, string property) => item.GetProperty(property).GetString()
        ?? throw new InvalidDataException($"Missing aquarium field: {property}.");

    private static string? OptionalId(JsonElement item, string property)
    {
        if (!item.TryGetProperty(property, out JsonElement value)) return null;
        return value.ValueKind switch
        {
            JsonValueKind.String => value.GetString(),
            JsonValueKind.Number => value.GetRawText(),
            _ => null
        };
    }

    private static string Id(JsonElement item, string property)
    {
        JsonElement value = item.GetProperty(property);
        return value.ValueKind == JsonValueKind.Number ? value.GetRawText() : Text(item, property);
    }

    private static string? OptionalText(JsonElement item, string property) =>
        item.TryGetProperty(property, out JsonElement value) && value.ValueKind == JsonValueKind.String ? value.GetString() : null;

    private static long OptionalLong(JsonElement item, string property, long fallback = 0) =>
        item.TryGetProperty(property, out JsonElement value) && value.TryGetInt64(out long number) ? number : fallback;
}
