using DeskBox.Services;

namespace DeskBox.Controls.WidgetContents;

// Short, looping trajectories keep the animal inside the tree artwork and leave
// fruit hit targets unobstructed. Each species has its own recognizable rhythm.
internal static class WildlifeMotionCatalog
{
    private const double VisitorDisplayScale = 1.64;
    private static WildlifeMotionFrame F(double at, double x = 0, double y = 0,
        double rotation = 0, double scaleX = 1, double scaleY = 1, double opacity = 1) =>
        new(at, x, y, rotation, scaleX, scaleY, opacity);

    private static WildlifeMotionProfile M(string behavior, double x, double y, double seconds,
        params WildlifeMotionFrame[] frames) => new(behavior, x, y, seconds, frames);

    internal static readonly IReadOnlyDictionary<string, WildlifeMotionProfile> Profiles =
        new Dictionary<string, WildlifeMotionProfile>(StringComparer.Ordinal)
        {
            ["butterfly"] = M("花间折返", 198, 100, 5.2,
                F(0), F(.18, -8, -8, -12, 1.08, .82), F(.43, -18, 5, 14, .88, 1.1),
                F(.7, -6, -4, -9, 1.12, .86), F(1)),
            ["honeybee"] = M("急促悬停", 197, 110, 2.1,
                F(0), F(.18, -5, -2, 5), F(.36, 3, 2, -5), F(.55, -9, 0, 7),
                F(.77, 2, -3, -6), F(1)),
            ["ladybug"] = M("叶缘慢爬", 190, 117, 7.1,
                F(0), F(.2, -4, -1, -5), F(.43, -4, -1, -5), F(.72, -14, -2, -10), F(1)),
            ["swallow"] = M("掠空俯冲", 199, 31, 5.8,
                F(0), F(.2, -15, -9, -13, 1.05, .88), F(.45, -29, 10, 15, .96, 1.1),
                F(.76, -10, -3, -7), F(1)),
            ["dragonfly"] = M("悬停急转", 195, 103, 4.0,
                F(0), F(.27, 1, -2, 2), F(.34, -23, -5, -17, 1.06),
                F(.61, -23, -5, -17), F(.78, -2, 2, 10), F(1)),
            ["firefly"] = M("闪光飘游", 194, 106, 6.4,
                F(0, opacity: .55), F(.26, -8, -7, -5, opacity: 1),
                F(.52, -14, 2, 4, opacity: .42), F(.78, -5, -5, -3, opacity: .95),
                F(1, opacity: .55)),
            ["frog"] = M("蹲伏跃起", 197, 167, 5.0,
                F(0, scaleY: .92), F(.36, scaleY: .92), F(.5, -10, -17, -7, 1.04, 1.08),
                F(.64, -18, 0, 3, 1.02, .86), F(.81, -18, 0), F(1, scaleY: .92)),
            ["cricket"] = M("短促连跳", 194, 160, 4.2,
                F(0), F(.23), F(.34, -8, -12, -9), F(.46, -13, 0, 4),
                F(.57, -19, -10, -7), F(.7, -24, 0), F(1)),
            ["squirrel"] = M("枝下疾跑", 197, 157, 4.7,
                F(0), F(.2, -9, -4, -6, 1.08, .95), F(.36, -20, 0, 6),
                F(.57, -24, -4, -4), F(.75, -9, 0, 2), F(1)),
            ["hedgehog"] = M("低头缓行", 194, 170, 7.8,
                F(0), F(.29, -5, 1, 2, 1.03, .96), F(.48, -5, 1, 2),
                F(.8, -13, 0, -2), F(1)),
            ["bullfinch"] = M("枝头轻跳", 199, 37, 4.4,
                F(0), F(.28), F(.4, -6, -7, -7), F(.55, -10, 0, 6),
                F(.7, -10), F(1)),
            ["fox"] = M("伏身潜行", 196, 167, 6.6,
                F(0), F(.22, -6, 1, -2, 1.05, .9), F(.44, -13, 1, -2, 1.05, .9),
                F(.68, -21, 0, 4), F(.82, -21), F(1)),
            ["sparrow"] = M("点头觅食", 198, 49, 3.7,
                F(0), F(.27, rotation: 13, scaleY: .94), F(.41),
                F(.58, -4, 0, 17, 1, .9), F(.74, -4), F(1)),
            ["magpie"] = M("侧步摆尾", 198, 34, 5.1,
                F(0), F(.23, -8, -2, -9), F(.43, -8, 0, 11),
                F(.68, 4, -3, -8), F(.82, 4, 0, 9), F(1)),
            ["owl"] = M("静夜滑翔", 197, 25, 8.8,
                F(0), F(.3, -11, -7, -4, 1.04, 1.02), F(.58, -21, 1, 4),
                F(.82, -8, 5, 3), F(1)),
            ["rabbit"] = M("双段蹦跳", 196, 167, 5.4,
                F(0), F(.22, -6, -12, -5, 1, 1.08), F(.36, -12, 0, 3, 1.04, .9),
                F(.55, -19, -19, -7, 1, 1.12), F(.72, -26, 0, 4, 1.04, .9), F(1)),
            ["mantis"] = M("举臂探步", 190, 112, 6.9,
                F(0), F(.27, -4, -2, -7, .98, 1.04), F(.46, -4, -2, 6, 1.04),
                F(.72, -11, 0, -4), F(1)),
            ["cicada"] = M("树旁振翅", 200, 91, 3.3,
                F(0), F(.2, 0, -2, -3, 1.08, .8), F(.4, 0, 1, 3, .92, 1.1),
                F(.62, -3, -3, -4, 1.1, .78), F(.82, -3, 0, 2), F(1)),
            ["moth"] = M("绕光盘旋", 193, 94, 6.0,
                F(0), F(.22, -11, -6, -12, .88, 1.14), F(.46, -18, 4, 9, 1.12, .88),
                F(.72, -6, 8, 14, .9, 1.1), F(1)),
            ["grasshopper"] = M("后腿弹射", 192, 158, 4.8,
                F(0), F(.4, scaleY: .9), F(.51, -18, -21, -14, 1.07, 1.1),
                F(.66, -28, 0, 6, 1.03, .88), F(.86, -28), F(1)),
            ["robin"] = M("晨间小步", 198, 42, 4.6,
                F(0), F(.18, -4, -3, -5), F(.32, -8, 0, 3), F(.54, -8),
                F(.68, -12, -4, -6), F(.82, -16), F(1)),
            ["kingfisher"] = M("俯冲折返", 200, 20, 6.2,
                F(0), F(.23, -8, -8, -11, 1.04, .92), F(.43, -29, 21, 21, .94, 1.08),
                F(.62, -20, 8, -8), F(.82, -5, -5, -9), F(1)),
            ["woodpecker"] = M("节奏啄击", 202, 49, 3.8,
                F(0), F(.21, rotation: 10), F(.29, 3, 0, 18, 1.04, .96),
                F(.39), F(.53, 4, 0, 21, 1.04, .96), F(.66), F(1)),
            ["heron"] = M("缓步涉水", 197, 34, 8.4,
                F(0), F(.23, -5, -1, -3), F(.43, -5), F(.69, -15, 1, 3), F(1)),
            ["crow"] = M("振翅巡视", 197, 25, 5.6,
                F(0), F(.18, -8, -7, -7, 1.12, .83), F(.35, -19, 1, 5, .96, 1.1),
                F(.58, -16, -6, -4, 1.13, .85), F(.78, -5, 2, 4), F(1)),
            ["deer"] = M("警觉探头", 194, 164, 7.6,
                F(0), F(.3, rotation: -5, scaleY: 1.03), F(.48, -4, 0, 4),
                F(.66, -4), F(.83, -9, 0, -3), F(1)),
            ["raccoon"] = M("夜行摇摆", 193, 166, 6.1,
                F(0), F(.24, -7, 1, -6, 1.03, .95), F(.45, -14, 0, 6),
                F(.63, -14, 0, -4), F(.81, -21, 1, 5), F(1)),
            ["turtle"] = M("缩头慢爬", 191, 169, 9.6,
                F(0), F(.2, -3, 1, 0, .95, .96), F(.4, -3, 1, 0, .95, .96),
                F(.71, -10, 0), F(1)),
            ["field-mouse"] = M("贴地穿梭", 193, 170, 4.3,
                F(0), F(.2, -10, 0, -4, 1.06, .92), F(.35, -20, 0, 3),
                F(.53, -20), F(.72, -28, 0, -3, 1.06, .92), F(1)),
            ["badger"] = M("沉稳踱步", 191, 167, 8.1,
                F(0), F(.21, -5, 1, -2), F(.37, -5), F(.62, -12, 1, 2, 1.04, .96), F(1)),
            ["otter"] = M("滑行翻身", 191, 166, 5.9,
                F(0), F(.22, -9, -3, -10, 1.06, .9), F(.41, -18, -5, 15, .96, 1.08),
                F(.63, -23, 0, -5), F(.79, -8, -2, 7), F(1)),
            ["snail"] = M("缓慢伸缩", 190, 170, 10.8,
                F(0), F(.3, -3, 0, 0, 1.04, .97), F(.51, -3, 0, 0, .97, 1.02),
                F(.78, -8, 0, 0, 1.05, .96), F(1))
        };

    private static readonly HashSet<string> SkyFlightIds = new(
        WildlifeAssetCatalog.LocalCatalog.Where(species => species.Placement == "sky").Select(species => species.Id),
        StringComparer.Ordinal);

    static WildlifeMotionCatalog()
    {
        var profiles = (Dictionary<string, WildlifeMotionProfile>)Profiles;
        foreach (string obsolete in profiles.Keys.Where(id => !WildlifeAssetCatalog.SpeciesIds.Contains(
                     id, StringComparer.Ordinal)).ToArray())
            profiles.Remove(obsolete);
        foreach (WildlifeCatalogItem species in WildlifeAssetCatalog.LocalCatalog)
        {
            double y = species.Placement == "grass" ? 165 : species.Category switch
            {
                "飞鸟" => 52,
                "蝴蝶" => 92,
                _ => 112
            };
            if (!profiles.ContainsKey(species.Id) &&
                WildlifeAssetCatalog.MotionCodes.TryGetValue(species.Id, out string? motion))
                profiles.Add(species.Id, CreateSpeciesMotion(species, motion, y));
            if (profiles.TryGetValue(species.Id, out WildlifeMotionProfile? profile))
            {
                // Placement also applies to the original hand-authored trajectories.
                profiles[species.Id] = profile with
                {
                    X = species.Placement == "tree" ? 165 : profile.X,
                    Y = y
                };
            }
        }
        foreach (WildlifeCatalogItem species in WildlifeAssetCatalog.LocalCatalog.Where(
                     species => species.Placement == "sky"))
            profiles[species.Id] = CreateSkyFlight(species);
    }

    internal static bool FliesAcrossSky(string id) => SkyFlightIds.Contains(id);

    internal static WildlifeMotionProfile ForStage(string id, int stage)
    {
        WildlifeMotionProfile profile = Profiles[id];
        if (!WildlifeAssetCatalog.LocalCatalog.Any(species => species.Id == id && species.Placement == "tree"))
            return profile;
        double ratio = TreeIllustrationLayout.ArtworkWidth(stage) / TreeIllustrationLayout.ArtworkWidth(5);
        return profile with
        {
            X = 130 + (profile.X - 130) * ratio,
            Y = 116 + (profile.Y - 116) * ratio
        };
    }

    private static WildlifeMotionProfile CreateSkyFlight(WildlifeCatalogItem species)
    {
        uint hash = 2166136261;
        foreach (char character in species.Id) hash = unchecked((hash ^ character) * 16777619);
        bool rightward = (hash & 1) == 0;
        double direction = rightward ? 1 : -1;
        double center = TreeIllustrationLayout.Width / 2 - 18;
        double offscreen = VisualSize(species.Id, species.Category, 5) + 6;
        double start = rightward ? -offscreen : TreeIllustrationLayout.Width + 6;
        double finish = rightward ? TreeIllustrationLayout.Width + 6 : -offscreen;
        double distance = finish - start;
        double bob = 2 + hash % 3;
        double y = 9 + hash % 7;
        return M(species.Name + "·掠空飞越", center, y, 7.2 + hash % 12 * .22,
            F(0, start - center, 0, -3 * direction, direction),
            F(.22, start + distance * .22 - center, -bob, 2 * direction, direction, .82),
            F(.5, start + distance * .5 - center, bob, -2 * direction, direction, 1.06),
            F(.78, start + distance * .78 - center, -bob, 2 * direction, direction, .84),
            F(1, finish - center, 0, -3 * direction, direction));
    }

    internal static double VisualSize(string id, string category, int treeStage)
    {
        double baseSize;
        if (WildlifeAssetCatalog.MotionCodes.TryGetValue(id, out string? motion))
            baseSize = motion switch
            {
                "ant" or "fly" or "waterbug" or "stick" => 27,
                "butterfly" or "moth" or "bee" or "dragonfly" => 31,
                "raptor" or "wader" or "deer" => 40,
                "snail" or "rodent" or "lizard" => 30,
                _ => category == "蝴蝶" ? 30 : category == "飞鸟" ? 35 : 37
            };
        else
            baseSize = category == "蝴蝶" ? 31 : category == "飞鸟" ? 35 : 38;

        // The illustration's drawn width changes with growth. A fixed visitor
        // size makes animals dwarf seedlings even though the canvas is fixed.
        return baseSize * VisitorDisplayScale *
            TreeIllustrationLayout.ArtworkWidth(treeStage) / TreeIllustrationLayout.ArtworkWidth(5);
    }

    internal static (double Left, double Top) HitTargetPosition(WildlifeMotionProfile motion,
        double visualSize, bool skyFlight)
    {
        double hitSize = Math.Max(visualSize, 56);
        double inset = (hitSize - visualSize) / 2;
        double left = motion.X - inset;
        double top = motion.Y - inset + (skyFlight ? 0 : TreeIllustrationLayout.TreeVerticalOffset);
        double minimumLeft = 0;
        double maximumLeft = TreeIllustrationLayout.Width - hitSize;
        double minimumTop = 0;
        double maximumTop = TreeIllustrationLayout.Height - hitSize;
        foreach (WildlifeMotionFrame frame in motion.Frames)
        {
            double radians = frame.Rotation * Math.PI / 180;
            double cosine = Math.Abs(Math.Cos(radians));
            double sine = Math.Abs(Math.Sin(radians));
            double overhangX = Math.Max(0, hitSize / 2 *
                (Math.Abs(frame.ScaleX) * cosine + Math.Abs(frame.ScaleY) * sine - 1));
            double overhangY = Math.Max(0, hitSize / 2 *
                (Math.Abs(frame.ScaleX) * sine + Math.Abs(frame.ScaleY) * cosine - 1));
            minimumTop = Math.Max(minimumTop, overhangY - frame.Y);
            maximumTop = Math.Min(maximumTop,
                TreeIllustrationLayout.Height - hitSize - overhangY - frame.Y);
            if (skyFlight) continue;
            minimumLeft = Math.Max(minimumLeft, overhangX - frame.X);
            maximumLeft = Math.Min(maximumLeft,
                TreeIllustrationLayout.Width - hitSize - overhangX - frame.X);
        }
        top = Math.Clamp(top, minimumTop, maximumTop);
        if (!skyFlight) left = Math.Clamp(left, minimumLeft, maximumLeft);
        return (left, top);
    }

    private static WildlifeMotionProfile CreateSpeciesMotion(WildlifeCatalogItem species, string motion, double y)
    {
        uint hash = 2166136261;
        foreach (char character in species.Id) hash = unchecked((hash ^ character) * 16777619);
        double variation = (int)(hash % 7) - 3;
        double stride = 12 + (hash % 8);
        double seconds = 3.5 + (hash % 13) * .31;
        double x = 188 + (hash % 9);
        string label = species.Name + "·" + motion;

        // Similar anatomy shares a movement family; cadence, distance and
        // direction vary stably by species so no two animals use one track.
        return motion switch
        {
            "butterfly" or "moth" or "bat" => M(label, x, y, seconds + 1.4,
                F(0), F(.23, -stride * .45, -9 - variation, -9, 1.08, .82),
                F(.48, -stride, 4 + variation, 11, .88, 1.12),
                F(.73, -stride * .32, -5, -6, 1.1, .85), F(1)),
            "bee" or "nectar" or "fly" => M(label, x, y, Math.Max(2.2, seconds - 1),
                F(0), F(.22, -5, -3, 5), F(.39, -stride, 1 + variation, -7),
                F(.64, -stride, -3, 6), F(.83, -3, 2, -4), F(1)),
            "dragonfly" or "waterbug" or "lizard" => M(label, x, y, seconds,
                F(0), F(.27, -2, -2), F(.39, -stride, -5, -12),
                F(.66, -stride, -5, -12), F(.82, -4, 1, 5), F(1)),
            "grasshopper" or "rabbit" or "frog" or "groundbird" => M(label, x, y, seconds + 1,
                F(0), F(.28, scaleY: .92), F(.42, -stride * .5, -16 - variation, -8, 1.05, 1.1),
                F(.57, -stride, 0, 4, 1.02, .89), F(.76, -stride), F(1)),
            "raptor" or "owl" or "wader" or "longtail" => M(label, x, y, seconds + 2,
                F(0), F(.25, -stride * .4, -5, -5, 1.05, .95),
                F(.52, -stride, 2 + variation, 6, .96, 1.05),
                F(.78, -stride * .35, -3, -4), F(1)),
            "songbird" or "crested" or "corvid" or "duck" => M(label, x, y, seconds + .6,
                F(0), F(.22, -5, -4, -6), F(.42, -stride * .6, 0, 7),
                F(.63, -stride * .6, 0, -4), F(.81, -stride, -3, 5), F(1)),
            "fox" or "mustelid" or "raccoon" or "deer" or "beaver" => M(label, x, y, seconds + 2,
                F(0), F(.24, -stride * .32, 1, -3, 1.04, .95),
                F(.46, -stride * .67, 0, 3), F(.7, -stride, 1, -2, 1.03, .97), F(1)),
            "snake" or "snail" or "turtle" or "stick" => M(label, x, y, seconds + 4,
                F(0), F(.26, -stride * .25, 0, 3, 1.04, .96),
                F(.5, -stride * .5, 0, -3, .97, 1.04),
                F(.76, -stride, 0, 2, 1.04, .97), F(1)),
            _ => M(label, x, y, seconds + 1.4,
                F(0), F(.2, -stride * .3, -2, -4), F(.43, -stride * .5, 0, 5),
                F(.7, -stride, -2, -3), F(1))
        };
    }
}

internal sealed record WildlifeMotionProfile(string Behavior, double X, double Y, double Seconds,
    IReadOnlyList<WildlifeMotionFrame> Frames);

internal readonly record struct WildlifeMotionFrame(double At, double X, double Y,
    double Rotation, double ScaleX, double ScaleY, double Opacity);
