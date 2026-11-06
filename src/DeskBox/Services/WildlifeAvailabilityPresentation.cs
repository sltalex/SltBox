namespace DeskBox.Services;

internal static class WildlifeAvailabilityPresentation
{
    internal static string Seasons(WildlifeCatalogItem species) => Format("季节", species.Seasons, SeasonName);

    internal static string Periods(WildlifeCatalogItem species) => Format("时段", species.Periods, PeriodName);

    internal static string Weathers(WildlifeCatalogItem species) => Format("天气", species.Weathers, WeatherName);

    internal static string PreferredWeathers(WildlifeCatalogItem species) =>
        Format("偏好天气", species.PreferredWeathers, WeatherName);

    internal static string Activity(WildlifeCatalogItem species) =>
        "活动方式：" + (species.Motion.Length == 0 ? "待同步" : MotionName(species.Motion));

    internal static string Placement(WildlifeCatalogItem species) => "地点：" + PlacementName(species.Placement);

    internal static string Description(WildlifeCatalogItem species)
    {
        if (species.Seasons.Count == 0 || species.Periods.Count == 0 || species.Weathers.Count == 0)
            return "详细出没资料待同步。";

        string seasons = string.Join("、", species.Seasons.Select(SeasonName));
        string periods = string.Join("、", species.Periods.Select(PeriodName));
        string weathers = string.Join("、", species.Weathers.Select(WeatherName));
        string placement = PlacementName(species.Placement);
        string preferred = species.PreferredWeathers.Count == 0 ? string.Empty
            : $"其中{string.Join("、", species.PreferredWeathers.Select(WeatherName))}时更容易遇见。";
        string activity = species.Motion.Length == 0 ? string.Empty
            : $"活动时常见{MotionName(species.Motion)}的姿态。";
        return $"{species.Name}通常在{seasons}的{periods}出现在{placement}。可能遇见的天气包括{weathers}。{preferred}{activity}";
    }

    private static string SeasonName(string code) => code switch
    {
        "spring" => "春季", "summer" => "夏季", "autumn" => "秋季", "winter" => "冬季", _ => code
    };

    private static string PeriodName(string code) => code switch
    {
        "morning" => "清晨", "day" => "白天", "dusk" => "傍晚", "night" => "夜间", _ => code
    };

    private static string WeatherName(string code) => code switch
    {
        "clear" => "晴", "cloudy" => "多云", "fog" => "雾", "drizzle" => "毛毛雨",
        "rain" => "雨", "snow" => "雪", "thunderstorm" => "雷暴", _ => code
    };

    private static string PlacementName(string code) => code switch
    {
        "tree" => "树上", "grass" => "草地", "sky" => "天空", _ => code
    };

    internal static string EncyclopediaDetails(WildlifeCatalogItem species) => string.Join("\n",
        Seasons(species), Periods(species), Weathers(species));

    private static string MotionName(string code) => code switch
    {
        "bat" or "glide" or "owl" or "longtail" => "掠空飞行",
        "butterfly" or "nectar" => "轻盈飞舞",
        "dive" => "俯冲",
        "raptor" => "盘旋飞行",
        "climb" => "攀爬",
        "corvid" or "crested" or "songbird" or "perch" => "轻快跳动",
        "duck" or "wader" or "wade" or "walk" => "缓步行走",
        "forage" or "graze" or "peck" => "觅食",
        "groundbird" or "hop" or "rabbit" => "跳跃",
        "beaver" or "hedgehog" or "raccoon" => "缓步移动",
        "fox" or "mustelid" or "rodent" or "scurry" or "stalk" => "灵活穿行",
        "slide" => "滑行",
        _ => "移动"
    };

    private static string Format(string label, IReadOnlyList<string> codes, Func<string, string> name) =>
        $"{label}：{(codes.Count == 0 ? "待同步" : string.Join("、", codes.Select(name)))}";
}
