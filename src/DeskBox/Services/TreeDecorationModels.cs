using System.Text.Json;

namespace DeskBox.Services;

internal sealed record TreeDecorationCatalogItem(
    string Id, string Name, string Category, string Rarity, string AssetKey);

internal sealed record TreeDecorationCatalog(
    int Version,
    long SinglePrice,
    long TenPrice,
    IReadOnlyList<TreeDecorationCatalogItem> Items);

internal sealed record TreeDecorationPlacement(
    string ItemId,
    double X,
    double Y,
    double Scale,
    double Rotation,
    int Layer);

internal sealed record TreeDecorationCardPack(
    string Id,
    int DrawCount,
    string CreatedAt);

internal sealed record TreeDecorationState(
    bool Mature,
    long Coins,
    long Shards,
    long SceneVersion,
    IReadOnlyList<string> OwnedIds,
    IReadOnlyList<TreeDecorationCardPack> CardPacks,
    IReadOnlyList<TreeDecorationPlacement> Placements,
    string? ActiveThemeId);

internal sealed record TreeDecorationDrawResult(
    string ItemId,
    string Rarity,
    bool IsNew,
    long ShardsAwarded);

internal static class TreeDecorationPlacementRules
{
    internal const int MaximumPlacements = 20;
    internal const double MinimumScale = .5;
    internal const double MaximumScale = 1.5;
    internal const double MinimumRotation = -45;
    internal const double MaximumRotation = 45;
    internal const int MinimumLayer = -2;
    internal const int MaximumLayer = 2;

    internal static bool IsValid(TreeDecorationPlacement placement) =>
        !string.IsNullOrWhiteSpace(placement.ItemId) &&
        double.IsFinite(placement.X) && placement.X is >= 0 and <= 1 &&
        double.IsFinite(placement.Y) && placement.Y is >= 0 and <= 1 &&
        double.IsFinite(placement.Scale) && placement.Scale is >= MinimumScale and <= MaximumScale &&
        double.IsFinite(placement.Rotation) && placement.Rotation is >= MinimumRotation and <= MaximumRotation &&
        placement.Layer is >= MinimumLayer and <= MaximumLayer;
}

internal static class TreeDecorationJson
{
    internal static TreeDecorationCatalog ReadCatalog(JsonElement root) => new(
        root.GetProperty("catalog_version").GetInt32(),
        root.GetProperty("single_price").GetInt64(),
        root.GetProperty("ten_price").GetInt64(),
        Array.AsReadOnly(root.GetProperty("items").EnumerateArray().Select(item =>
            new TreeDecorationCatalogItem(
                Text(item, "id"),
                Text(item, "name"),
                Text(item, "category"),
                Text(item, "rarity"),
                Text(item, "asset_key"))).ToArray()));

    internal static TreeDecorationState ReadState(JsonElement root) => new(
        root.GetProperty("mature").GetBoolean(),
        root.GetProperty("coins").GetInt64(),
        root.GetProperty("shards").GetInt64(),
        root.GetProperty("scene_version").GetInt64(),
        Array.AsReadOnly(root.GetProperty("owned_ids").EnumerateArray()
            .Select(item => item.GetString() ?? string.Empty).ToArray()),
        Array.AsReadOnly(root.GetProperty("card_packs").EnumerateArray()
            .Select(item => new TreeDecorationCardPack(
                Text(item, "id"), item.GetProperty("draw_count").GetInt32(),
                Text(item, "created_at"))).ToArray()),
        ReadPlacements(root.GetProperty("placements")),
        OptionalText(root, "active_theme_id"));

    internal static IReadOnlyList<TreeDecorationDrawResult> ReadDrawResults(JsonElement root)
    {
        if (!root.TryGetProperty("draw_results", out JsonElement results) ||
            results.ValueKind != JsonValueKind.Array)
            return Array.Empty<TreeDecorationDrawResult>();
        return Array.AsReadOnly(results.EnumerateArray().Select(item => new TreeDecorationDrawResult(
            Text(item, "item_id"), Text(item, "rarity"),
            item.GetProperty("is_new").GetBoolean(),
            item.GetProperty("shards_awarded").GetInt64())).ToArray());
    }

    internal static IReadOnlyList<TreeDecorationPlacement> ReadPlacements(JsonElement source) => Array.AsReadOnly(
        source.EnumerateArray().Select(item => new TreeDecorationPlacement(
            Text(item, "item_id"),
            item.GetProperty("x").GetDouble(),
            item.GetProperty("y").GetDouble(),
            item.GetProperty("scale").GetDouble(),
            item.GetProperty("rotation").GetDouble(),
            item.GetProperty("layer").GetInt32())).ToArray());

    internal static byte[] Write(Action<Utf8JsonWriter> write)
    {
        using var stream = new MemoryStream();
        using (var writer = new Utf8JsonWriter(stream)) write(writer);
        return stream.ToArray();
    }

    internal static void WritePlacements(Utf8JsonWriter writer,
        IReadOnlyList<TreeDecorationPlacement> placements)
    {
        writer.WriteStartArray();
        foreach (TreeDecorationPlacement placement in placements)
        {
            writer.WriteStartObject();
            writer.WriteString("item_id", placement.ItemId);
            writer.WriteNumber("x", placement.X);
            writer.WriteNumber("y", placement.Y);
            writer.WriteNumber("scale", placement.Scale);
            writer.WriteNumber("rotation", placement.Rotation);
            writer.WriteNumber("layer", placement.Layer);
            writer.WriteEndObject();
        }
        writer.WriteEndArray();
    }

    internal static void WriteState(Utf8JsonWriter writer, TreeDecorationState state)
    {
        writer.WriteStartObject();
        writer.WriteBoolean("mature", state.Mature);
        writer.WriteNumber("coins", state.Coins);
        writer.WriteNumber("shards", state.Shards);
        writer.WriteNumber("scene_version", state.SceneVersion);
        writer.WriteStartArray("owned_ids");
        foreach (string id in state.OwnedIds) writer.WriteStringValue(id);
        writer.WriteEndArray();
        writer.WriteStartArray("card_packs");
        foreach (TreeDecorationCardPack pack in state.CardPacks)
        {
            writer.WriteStartObject();
            writer.WriteString("id", pack.Id);
            writer.WriteNumber("draw_count", pack.DrawCount);
            writer.WriteString("created_at", pack.CreatedAt);
            writer.WriteEndObject();
        }
        writer.WriteEndArray();
        writer.WritePropertyName("placements");
        WritePlacements(writer, state.Placements);
        if (state.ActiveThemeId is null) writer.WriteNull("active_theme_id");
        else writer.WriteString("active_theme_id", state.ActiveThemeId);
        writer.WriteEndObject();
    }

    internal static void WriteCatalog(Utf8JsonWriter writer, TreeDecorationCatalog catalog)
    {
        writer.WriteStartObject();
        writer.WriteNumber("catalog_version", catalog.Version);
        writer.WriteNumber("single_price", catalog.SinglePrice);
        writer.WriteNumber("ten_price", catalog.TenPrice);
        writer.WriteStartArray("items");
        foreach (TreeDecorationCatalogItem item in catalog.Items)
        {
            writer.WriteStartObject();
            writer.WriteString("id", item.Id);
            writer.WriteString("name", item.Name);
            writer.WriteString("category", item.Category);
            writer.WriteString("rarity", item.Rarity);
            writer.WriteString("asset_key", item.AssetKey);
            writer.WriteEndObject();
        }
        writer.WriteEndArray();
        writer.WriteEndObject();
    }

    private static string Text(JsonElement item, string property) =>
        item.GetProperty(property).GetString()
        ?? throw new InvalidDataException($"Missing tree decoration field: {property}.");

    private static string? OptionalText(JsonElement item, string property) =>
        item.TryGetProperty(property, out JsonElement value) && value.ValueKind == JsonValueKind.String
            ? value.GetString() : null;
}
