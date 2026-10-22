using System.Collections.Concurrent;
using System.Text.Json;
using Microsoft.UI.Xaml.Media;
using Microsoft.UI.Xaml.Media.Imaging;

namespace DeskBox.Services;

/// <summary>Resolves the reviewed individual fruit PNGs without atlas cropping.</summary>
internal static class FruitAssetCatalog
{
    private const string ManifestFileName = "fruits-manifest.json";
    private static readonly object LoadGate = new();
    private static readonly ConcurrentDictionary<(string Id, bool Blurred), ImageSource> ImageCache = new();
    private static IReadOnlyDictionary<string, FruitAssetFiles>? _files;

    internal static ImageSource GetImageSource(string fruitId, bool blurred = false)
    {
        if (string.IsNullOrWhiteSpace(fruitId)) return Fallback();
        return ImageCache.GetOrAdd((fruitId, blurred), key =>
        {
            Uri? uri = ResolveUri(key.Id, key.Blurred);
            return uri is null ? Fallback() : new BitmapImage(uri);
        });
    }

    internal static string? ResolveRelativePath(string fruitId, bool blurred = false)
    {
        EnsureLoaded();
        if (_files is null || !_files.TryGetValue(fruitId, out FruitAssetFiles? files)) return null;
        string relative = blurred ? files.BlurAssetFile : files.AssetFile;
        return string.IsNullOrWhiteSpace(relative) ? null : relative.Replace('/', Path.DirectorySeparatorChar);
    }

    private static Uri? ResolveUri(string fruitId, bool blurred)
    {
        string? relative = ResolveRelativePath(fruitId, blurred);
        if (relative is null) return null;
        string filePath = Path.Combine(AppContext.BaseDirectory, "Assets", "Tree", relative);
        if (!File.Exists(filePath))
        {
            App.Log($"[Fruit] Asset missing for {fruitId}: {filePath}");
            return null;
        }
        string appPath = relative.Replace(Path.DirectorySeparatorChar, '/');
        return new Uri($"ms-appx:///Assets/Tree/{appPath}");
    }

    private static void EnsureLoaded()
    {
        if (_files is not null) return;
        lock (LoadGate)
        {
            if (_files is not null) return;
            var loaded = new Dictionary<string, FruitAssetFiles>(StringComparer.Ordinal);
            try
            {
                string path = Path.Combine(AppContext.BaseDirectory, "Assets", "Tree", ManifestFileName);
                using JsonDocument document = JsonDocument.Parse(File.ReadAllText(path));
                if (document.RootElement.TryGetProperty("items", out JsonElement items))
                {
                    foreach (JsonElement item in items.EnumerateArray())
                    {
                        string id = Text(item, "id");
                        string assetFile = Text(item, "assetFile");
                        string blurAssetFile = Text(item, "blurAssetFile");
                        if (id.Length == 0 || assetFile.Length == 0 || blurAssetFile.Length == 0)
                            continue;
                        if (!IsSafeAssetPath(assetFile, "Fruits/Source/") ||
                            !IsSafeAssetPath(blurAssetFile, "Fruits/Blur/"))
                        {
                            App.Log($"[Fruit] Ignoring unsafe asset mapping for {id}");
                            continue;
                        }
                        if (!loaded.TryAdd(id, new FruitAssetFiles(assetFile, blurAssetFile)))
                            App.Log($"[Fruit] Ignoring duplicate asset mapping for {id}");
                    }
                }
            }
            catch (Exception ex) when (ex is IOException or JsonException or InvalidDataException)
            {
                App.Log($"[Fruit] Asset manifest unavailable: {ex.Message}");
            }
            _files = loaded;
        }
    }

    private static ImageSource Fallback() => new SvgImageSource(new Uri("ms-appx:///Assets/Tree/fruit.svg"));
    private static bool IsSafeAssetPath(string value, string expectedPrefix)
    {
        string normalized = value.Replace('\\', '/');
        return normalized.StartsWith(expectedPrefix, StringComparison.Ordinal) &&
               !Path.IsPathRooted(normalized) &&
               !normalized.Split('/').Any(segment => segment is "" or "." or "..");
    }
    private static string Text(JsonElement item, string name) =>
        item.TryGetProperty(name, out JsonElement value) && value.ValueKind == JsonValueKind.String
            ? value.GetString() ?? string.Empty : string.Empty;

    private sealed record FruitAssetFiles(string AssetFile, string BlurAssetFile);
}
