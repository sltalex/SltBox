using System.Text.Json;
using Microsoft.UI.Xaml.Media;
using Microsoft.UI.Xaml.Media.Imaging;

namespace DeskBox.Services;

/// <summary>The paired original PNG artwork for the current wildlife catalog.</summary>
internal static class WildlifeAssetCatalog
{
    private static readonly Dictionary<(string Id, bool Blurred), ImageSource> Images = new();
    private static readonly WildlifeAssetEntry[] Entries = LoadEntries();
    internal static readonly IReadOnlyList<WildlifeCatalogItem> LocalCatalog = Entries
        .Select(item => new WildlifeCatalogItem(item.Id, item.Name, item.Category, item.Placement)).ToArray();
    internal static readonly string[] SpeciesIds = Entries.Select(item => item.Id).ToArray();
    internal static readonly IReadOnlyDictionary<string, string> MotionCodes = Entries
        .Where(item => !string.IsNullOrEmpty(item.Motion))
        .ToDictionary(item => item.Id, item => item.Motion, StringComparer.Ordinal);
    private static readonly Lazy<bool> CompleteAssets = new(() => SpeciesIds.All(id =>
        File.Exists(AssetPath(id, false))) && SpeciesIds.All(id => File.Exists(AssetPath(id, true))));

    internal static bool HasCompleteAssets => CompleteAssets.Value;

    internal static ImageSource? GetImage(string id, bool blurred = false)
    {
        if (!SpeciesIds.Contains(id, StringComparer.Ordinal)) return null;
        if (!File.Exists(AssetPath(id, blurred))) return null;
        lock (Images)
        {
            if (!Images.TryGetValue((id, blurred), out ImageSource? image))
            {
                string folder = blurred ? "Blur" : "Source";
                var uri = new Uri($"ms-appx:///Assets/Tree/Wildlife/{folder}/{id}.png");
                image = new BitmapImage(uri);
                Images[(id, blurred)] = image;
            }
            return image;
        }
    }

    internal static string AssetPath(string id, bool blurred) => Path.Combine(
        AppContext.BaseDirectory, "Assets", "Tree", "Wildlife", blurred ? "Blur" : "Source", id + ".png");

    private static WildlifeAssetEntry[] LoadEntries()
    {
        string path = Path.Combine(AppContext.BaseDirectory, "Assets", "Tree", "Wildlife", "catalog.json");
        if (!File.Exists(path)) return [];
        using JsonDocument document = JsonDocument.Parse(File.ReadAllText(path));
        return document.RootElement.GetProperty("items").EnumerateArray().Select(item => new WildlifeAssetEntry(
            item.GetProperty("id").GetString() ?? string.Empty,
            item.GetProperty("name").GetString() ?? string.Empty,
            item.GetProperty("category").GetString() ?? string.Empty,
            item.GetProperty("placement").GetString() ?? string.Empty,
            item.TryGetProperty("motion", out JsonElement motion) ? motion.GetString() ?? string.Empty : string.Empty))
            .ToArray();
    }

    private sealed record WildlifeAssetEntry(string Id, string Name, string Category, string Placement, string Motion);
}
