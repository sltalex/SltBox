using System.Text.Json;
using DeskBox.Services;

namespace DeskBox.Core.Persistence;

internal static class WildlifeCacheFileStore
{
    internal static void Save(string path, WildlifeCacheDto cache)
    {
        Directory.CreateDirectory(Path.GetDirectoryName(path)!);
        string temporary = path + ".tmp";
        File.WriteAllText(temporary, JsonSerializer.Serialize(cache, WildlifeJsonContext.Default.WildlifeCacheDto));
        File.Move(temporary, path, true);
    }

    internal static void RestoreMerged(string stagedDataDirectory, string dataDirectory)
    {
        string stagedWildlife = Path.Combine(stagedDataDirectory, "wildlife");
        if (!Directory.Exists(stagedWildlife)) return;
        foreach (string source in Directory.EnumerateFiles(stagedWildlife,
                     "wildlife-state-account-*.json", SearchOption.TopDirectoryOnly))
        {
            string name = Path.GetFileName(source);
            if (!CloudBackupDomains.IsInDomain(CloudBackupDomain.WildlifeData, "wildlife/" + name)) continue;
            WildlifeCacheDto staged = ReadOwned(source, name);
            if (staged.CatalogVersion != WildlifeCatalogVersions.Current) continue;
            string destination = Path.Combine(dataDirectory, "wildlife", name);
            if (!File.Exists(destination))
            {
                Save(destination, staged);
                continue;
            }
            WildlifeCacheDto live = ReadOwned(destination, name);
            if (live.OwnerUserId != staged.OwnerUserId)
                throw new InvalidDataException("Wildlife backup account does not match the live account.");
            if (live.CatalogVersion != WildlifeCatalogVersions.Current)
            {
                Save(destination, staged);
                continue;
            }
            var merged = live.Inventory.ToDictionary(item => item.SpeciesId, StringComparer.Ordinal);
            foreach (WildlifeInventoryItem item in staged.Inventory)
            {
                merged[item.SpeciesId] = merged.TryGetValue(item.SpeciesId, out WildlifeInventoryItem? current)
                    ? current with { Quantity = Math.Max(current.Quantity, item.Quantity),
                        Discovered = current.Discovered || item.Discovered }
                    : item;
            }
            Save(destination, new WildlifeCacheDto
            {
                CatalogVersion = WildlifeCatalogVersions.Current,
                OwnerUserId = live.OwnerUserId,
                Inventory = merged.Values.ToArray()
            });
        }
    }

    private static WildlifeCacheDto ReadOwned(string path, string name)
    {
        WildlifeCacheDto cache = JsonSerializer.Deserialize(File.ReadAllText(path),
            WildlifeJsonContext.Default.WildlifeCacheDto)
            ?? throw new InvalidDataException("Wildlife backup cache is invalid.");
        if (string.IsNullOrWhiteSpace(cache.OwnerUserId) || cache.Inventory is null ||
            cache.Inventory.Length > WildlifeAssetCatalog.SpeciesIds.Length ||
            cache.Inventory.Any(item => item is null || item.Quantity is < 0 or > 1000000000))
            throw new InvalidDataException("Wildlife backup cache owner or entries are invalid.");
        string expected = "wildlife-state-account-" + AccountBackupFileCatalog.SafeAccountId(cache.OwnerUserId) + ".json";
        if (!string.Equals(name, expected, StringComparison.OrdinalIgnoreCase) ||
            cache.Inventory.Select(item => item.SpeciesId).Distinct(StringComparer.Ordinal).Count() != cache.Inventory.Length)
            throw new InvalidDataException("Wildlife backup cache owner or entries are invalid.");
        if (cache.CatalogVersion == WildlifeCatalogVersions.Current && cache.Inventory.Any(item =>
                !WildlifeAssetCatalog.SpeciesIds.Contains(item.SpeciesId, StringComparer.Ordinal)))
            throw new InvalidDataException("Wildlife backup cache contains an unknown current-catalog species.");
        return cache;
    }
}
