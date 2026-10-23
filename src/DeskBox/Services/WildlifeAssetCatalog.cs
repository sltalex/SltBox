using System.Text.Json;
using Microsoft.UI.Xaml.Media;
using Microsoft.UI.Xaml.Media.Imaging;

namespace DeskBox.Services;

/// <summary>Stable per-species art: replaceable PNGs take priority over the bundled SVGs.</summary>
internal static class WildlifeAssetCatalog
{
    private static readonly Dictionary<(string Id, bool Blurred), ImageSource> Images = new();
    private static readonly WildlifeAssetEntry[] Entries = LoadEntries();
    internal static readonly IReadOnlyList<WildlifeCatalogItem> LocalCatalog = Entries
        .Select(item => new WildlifeCatalogItem(item.Id, item.Name, item.Category)).ToArray();
    internal static readonly string[] SpeciesIds = Entries.Select(item => item.Id).ToArray();
    internal static readonly IReadOnlyDictionary<string, string> MotionCodes = Entries
        .Where(item => !string.IsNullOrEmpty(item.Motion))
        .ToDictionary(item => item.Id, item => item.Motion, StringComparer.Ordinal);
    private static readonly Lazy<bool> CompleteAssets = new(() => SpeciesIds.All(id =>
        File.Exists(AssetPath(id, false, "png")) || File.Exists(AssetPath(id, false, "svg"))) &&
        SpeciesIds.All(id => File.Exists(AssetPath(id, true, "png")) || File.Exists(AssetPath(id, true, "svg"))));

    internal static bool HasCompleteAssets => CompleteAssets.Value;

    internal static ImageSource? GetImage(string id, bool blurred = false)
    {
        if (!SpeciesIds.Contains(id, StringComparer.Ordinal)) return null;
        bool png = File.Exists(AssetPath(id, blurred, "png"));
        if (!png && !File.Exists(AssetPath(id, blurred, "svg"))) return null;
        lock (Images)
        {
            if (!Images.TryGetValue((id, blurred), out ImageSource? image))
            {
                string folder = blurred ? "Blur" : "Source";
                string extension = png ? "png" : "svg";
                var uri = new Uri($"ms-appx:///Assets/Tree/Wildlife/{folder}/{id}.{extension}");
                image = png ? new BitmapImage(uri) : new SvgImageSource(uri);
                Images[(id, blurred)] = image;
            }
            return image;
        }
    }

    internal static string AssetPath(string id, bool blurred, string extension = "svg") => Path.Combine(
        AppContext.BaseDirectory, "Assets", "Tree", "Wildlife", blurred ? "Blur" : "Source", id + "." + extension);

    private static WildlifeAssetEntry[] LoadEntries()
    {
        string path = Path.Combine(AppContext.BaseDirectory, "Assets", "Tree", "Wildlife", "catalog.json");
        if (!File.Exists(path)) return [];
        using JsonDocument document = JsonDocument.Parse(File.ReadAllText(path));
        return document.RootElement.GetProperty("items").EnumerateArray().Select(item => new WildlifeAssetEntry(
            item.GetProperty("id").GetString() ?? string.Empty,
            item.GetProperty("name").GetString() ?? string.Empty,
            item.GetProperty("category").GetString() ?? string.Empty,
            item.TryGetProperty("motion", out JsonElement motion) ? motion.GetString() ?? string.Empty : string.Empty))
            .ToArray();
    }

    private sealed record WildlifeAssetEntry(string Id, string Name, string Category, string Motion);
}
