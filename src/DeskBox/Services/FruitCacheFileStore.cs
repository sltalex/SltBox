using System.Text.Json;
using DeskBox.Services;

namespace DeskBox.Core.Persistence;

internal static class FruitCacheFileStore
{
    internal static void Save(string path, FruitCacheDto cache)
    {
        Directory.CreateDirectory(Path.GetDirectoryName(path)!);
        string temporary = path + ".tmp";
        File.WriteAllText(temporary, JsonSerializer.Serialize(cache, AccountCloudJsonContext.Default.FruitCacheDto));
        File.Move(temporary, path, true);
    }

    internal static void RestoreMissing(string stagedDataDirectory, string dataDirectory)
    {
        string stagedFruit = Path.Combine(stagedDataDirectory, "fruit");
        if (!Directory.Exists(stagedFruit)) return;
        foreach (string source in Directory.EnumerateFiles(stagedFruit, "fruit-state-account-*.json", SearchOption.TopDirectoryOnly))
        {
            string relative = "fruit/" + Path.GetFileName(source);
            if (!CloudBackupDomains.IsInDomain(CloudBackupDomain.FruitData, relative)) continue;
            string destination = Path.Combine(dataDirectory, "fruit", Path.GetFileName(source));
            Directory.CreateDirectory(Path.GetDirectoryName(destination)!);
            if (!File.Exists(destination)) File.Copy(source, destination);
        }
    }
}
