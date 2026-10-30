namespace DeskBox.Services;

internal sealed record TreeDecorationOwnedProduct(
    TreeDecorationCatalogItem Product,
    int OwnedCount,
    int PlacedCount)
{
    internal bool CanPlace => Product.Category != "theme" && PlacedCount < OwnedCount;
    internal bool CanRecall => Product.Category != "theme" && PlacedCount > 0;
}

internal static class TreeDecorationInventoryPresentation
{
    private static readonly IReadOnlyDictionary<string, string> FallbackSubcategoryNames =
        new Dictionary<string, string>(StringComparer.Ordinal)
        {
            // Legacy catalog entries do not have a subcategory. Use the existing
            // placement category as a readable fallback until a refreshed catalog
            // supplies the new field.
            ["tree"] = "树上", ["ground"] = "地面", ["large"] = "大型",
            ["lanterns"] = "灯饰", ["hanging_garden"] = "悬挂花园",
            ["bird_home"] = "鸟类居所", ["wind_chime"] = "风铃挂饰",
            ["ribbons_signs"] = "丝带铭牌", ["fruit_seed"] = "果实种子",
            ["carving_masks"] = "雕刻面具", ["bird_cages"] = "鸟笼",
            ["celestial"] = "星月饰物", ["glass_charms"] = "玻璃挂件",
            ["flora"] = "花草植物", ["stones"] = "石景",
            ["water"] = "水景", ["containers"] = "容器",
            ["furniture"] = "庭院家具", ["lighting"] = "地面灯饰",
            ["signs"] = "标牌", ["paths"] = "路径",
            ["play"] = "游乐设施", ["craft"] = "手作摆件",
            ["gates"] = "拱门", ["pavilions"] = "亭台",
            ["bridges"] = "桥梁", ["seating"] = "座椅",
            ["shelters"] = "棚屋", ["greenhouses"] = "温室",
            ["carts"] = "推车", ["towers"] = "高塔",
            ["water_features"] = "大型水景", ["sculptures"] = "雕塑"
        };

    internal static string DisplayName(TreeDecorationCatalogItem product, Func<string, string> localize)
    {
        string key = "Tree.Decorations.Item." + product.Id;
        string translated = localize(key);
        return translated == key || string.IsNullOrWhiteSpace(translated) ? product.Name : translated;
    }

    internal static string SubcategoryName(TreeDecorationCatalogItem product, Func<string, string> localize)
    {
        string key = "Tree.Decorations.Subcategory." + product.Subcategory;
        string translated = localize(key);
        return translated == key || string.IsNullOrWhiteSpace(translated)
            ? FallbackSubcategoryNames.GetValueOrDefault(product.Subcategory, product.Subcategory) : translated;
    }

    internal static string SubcategoryName(string subcategory, Func<string, string> localize)
    {
        string key = "Tree.Decorations.Subcategory." + subcategory;
        string translated = localize(key);
        return translated == key || string.IsNullOrWhiteSpace(translated)
            ? FallbackSubcategoryNames.GetValueOrDefault(subcategory, subcategory) : translated;
    }

    internal static bool MatchesFilters(TreeDecorationCatalogItem product, string category, string subcategory) =>
        (string.IsNullOrEmpty(category) || product.Category == category) &&
        (string.IsNullOrEmpty(subcategory) || product.Subcategory == subcategory);

    internal static string PackResultLabel(TreeDecorationCatalogItem product, Func<string, string> localize) =>
        $"{DisplayName(product, localize)} · {SubcategoryName(product, localize)}";

    internal static IReadOnlyList<TreeDecorationOwnedProduct> Summarize(
        IEnumerable<TreeDecorationInventoryItem> inventory,
        IEnumerable<TreeDecorationPlacement> placements,
        IEnumerable<TreeDecorationCatalogItem> catalog)
    {
        Dictionary<string, int> placed = placements
            .GroupBy(item => item.ItemId, StringComparer.Ordinal)
            .ToDictionary(group => group.Key, group => group.Count(), StringComparer.Ordinal);
        Dictionary<string, TreeDecorationCatalogItem> products = catalog
            .Where(item => item.Category != "theme")
            .ToDictionary(item => item.Id, StringComparer.Ordinal);
        return Array.AsReadOnly(inventory.Where(item => item.Quantity > 0)
            .Select(item => products.TryGetValue(item.ItemId, out TreeDecorationCatalogItem? product)
                ? new TreeDecorationOwnedProduct(product, item.Quantity,
                    placed.GetValueOrDefault(item.ItemId)) : null)
            .Where(item => item is not null)
            .Select(item => item!)
            .ToArray());
    }
}
