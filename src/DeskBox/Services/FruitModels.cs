using System.Text.Json;
using System.Text.Json.Serialization;

namespace DeskBox.Services;

internal sealed record FruitCatalogItem(string Id, string Name, string Category, int AtlasIndex,
    double Probability, int MinPrice, int MaxPrice, int CurrentPrice, bool Enabled = true);
internal sealed record FruitInventoryItem(string FruitId, int Quantity, bool Discovered);
internal sealed record FruitPricePoint(string Day, int Price);
internal sealed record FruitOnTreeItem(long Id, string FruitId);
internal sealed record FruitSellRequest(
    [property: JsonPropertyName("fruit_id")] string FruitId,
    int Quantity,
    [property: JsonPropertyName("operation_id")] string OperationId);
internal sealed record FruitPickRequest(
    [property: JsonPropertyName("pending_id")] long PendingId,
    [property: JsonPropertyName("operation_id")] string OperationId);
internal sealed class FruitCacheDto
{
    public string OwnerUserId { get; set; } = string.Empty;
    public FruitInventoryItem[] Inventory { get; set; } = [];
    public Dictionary<string, FruitPricePoint[]> History { get; set; } = new(StringComparer.Ordinal);
    public FruitOnTreeItem[] OnTree { get; set; } = [];
}

internal static class FruitJson
{
    internal static IReadOnlyList<FruitCatalogItem> ReadCatalog(JsonElement root)
    {
        JsonElement items = root.ValueKind == JsonValueKind.Array ? root : root.GetProperty("items");
        return items.EnumerateArray().Select(x => new FruitCatalogItem(
            Text(x, "id"), Text(x, "name"), Text(x, "category"), Int(x, "atlas_index", Int(x, "atlasIndex", 0)),
            Number(x, "probability", 1), Int(x, "min_price", Int(x, "minPrice", 15)),
            Int(x, "max_price", Int(x, "maxPrice", 20)),
            Int(x, "current_price", Int(x, "min_price", Int(x, "minPrice", 15))), Bool(x, "enabled", true))).ToArray();
    }

    internal static IReadOnlyList<FruitInventoryItem> ReadInventory(JsonElement root)
    {
        if (!root.TryGetProperty("inventory", out var items)) return Array.Empty<FruitInventoryItem>();
        return items.EnumerateArray().Select(x => new FruitInventoryItem(
            Text(x, "fruit_id"), Int(x, "quantity", 0), Bool(x, "discovered", false))).ToArray();
    }

    internal static IReadOnlyList<FruitPricePoint> ReadHistory(JsonElement root)
    {
        JsonElement items;
        if (root.ValueKind == JsonValueKind.Array) items = root;
        else if (root.ValueKind == JsonValueKind.Object && root.TryGetProperty("history", out var nested) && nested.ValueKind == JsonValueKind.Array)
            items = nested;
        else return Array.Empty<FruitPricePoint>();
        return items.EnumerateArray().Select(x => new FruitPricePoint(Text(x, "day"), Int(x, "price", 0))).ToArray();
    }

    internal static IReadOnlyList<FruitOnTreeItem> ReadOnTree(JsonElement root)
    {
        if (!root.TryGetProperty("on_tree", out var items)) return Array.Empty<FruitOnTreeItem>();
        return items.EnumerateArray().Select(x => new FruitOnTreeItem(
            x.TryGetProperty("id", out var id) && id.TryGetInt64(out var n) ? n : 0,
            Text(x, "fruit_id"))).Where(x => x.Id > 0 && x.FruitId.Length > 0).ToArray();
    }

    private static string Text(JsonElement x, string p) => x.TryGetProperty(p, out var v) && v.ValueKind == JsonValueKind.String ? v.GetString()! : "";
    private static int Int(JsonElement x, string p, int d) => x.TryGetProperty(p, out var v) && v.TryGetInt32(out var n) ? n : d;
    private static double Number(JsonElement x, string p, double d) => x.TryGetProperty(p, out var v) && v.TryGetDouble(out var n) ? n : d;
    private static bool Bool(JsonElement x, string p, bool d) => x.TryGetProperty(p, out var v) && v.ValueKind == JsonValueKind.True ? true : v.ValueKind == JsonValueKind.False ? false : d;
}

[JsonSourceGenerationOptions(WriteIndented = false)]
[JsonSerializable(typeof(FruitSellRequest))]
[JsonSerializable(typeof(FruitPickRequest))]
internal partial class FruitRequestJsonContext : JsonSerializerContext
{
}
