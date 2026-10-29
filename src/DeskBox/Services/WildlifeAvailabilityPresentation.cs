namespace DeskBox.Services;

internal static class WildlifeAvailabilityPresentation
{
    internal static string Seasons(WildlifeCatalogItem species) => Format("季节", species.Seasons, code => code switch
    {
        "spring" => "春季", "summer" => "夏季", "autumn" => "秋季", "winter" => "冬季", _ => code
    });

    internal static string Periods(WildlifeCatalogItem species) => Format("时段", species.Periods, code => code switch
    {
        "morning" => "清晨", "day" => "白天", "dusk" => "傍晚", "night" => "夜间", _ => code
    });

    internal static string Weathers(WildlifeCatalogItem species) => Format("天气", species.Weathers, code => code switch
    {
        "clear" => "晴", "cloudy" => "多云", "fog" => "雾", "drizzle" => "毛毛雨",
        "rain" => "雨", "snow" => "雪", "thunderstorm" => "雷暴", _ => code
    });

    internal static string EncyclopediaDetails(WildlifeCatalogItem species) => string.Join("\n",
        Seasons(species), Periods(species), Weathers(species));

    internal static string Placement(WildlifeCatalogItem species) => "地点：" + (species.Placement switch
    {
        "tree" => "树上", "grass" => "草地", "sky" => "天空", _ => species.Placement
    });

    private static string Format(string label, IReadOnlyList<string> codes, Func<string, string> name) =>
        $"{label}：{(codes.Count == 0 ? "待同步" : string.Join("、", codes.Select(name)))}";
}
