namespace DeskBox.Core.Persistence;

/// <summary>Owns repair-time deletion of tree-sync protocol state, not tree progress.</summary>
internal static class TreeCloudSyncStateFileStore
{
    internal static int DeleteStateFiles(string directory)
    {
        if (!Directory.Exists(directory)) return 0;

        int deleted = 0;
        foreach (string path in Directory.EnumerateFiles(directory, "*", SearchOption.TopDirectoryOnly))
        {
            if (!Path.GetFileName(path).StartsWith("state", StringComparison.OrdinalIgnoreCase) ||
                !string.Equals(Path.GetExtension(path), ".json", StringComparison.OrdinalIgnoreCase))
                continue;

            File.Delete(path);
            deleted++;
        }

        return deleted;
    }
}
