using System.Text.Json;
using System.Text.Json.Serialization;
using DeskBox.Services;

namespace DeskBox.Core.Persistence;

[JsonSerializable(typeof(TreeProgress))]
internal sealed partial class TreeProgressJsonContext : JsonSerializerContext;

/// <summary>Owns atomic commits and fallback recovery for the local tree aggregate.</summary>
internal sealed class TreeProgressFileStore(string path)
{
    public TreeProgress Load()
    {
        foreach (string candidate in new[] { path, path + ".bak" })
        {
            try
            {
                if (!File.Exists(candidate)) continue;
                TreeProgress? progress = JsonSerializer.Deserialize(File.ReadAllText(candidate), TreeProgressJsonContext.Default.TreeProgress);
                if (progress is
                    {
                        DailyGoal: >= 1 and <= 1000000,
                        TotalEnergy: >= 0,
                        TodayKeys: >= 0,
                        HighestStage: >= 1 and <= 5,
                        FruitCost: > 0,
                        FruitInventory: >= 0,
                        HarvestedFruit: >= 0,
                        FruitEnergy: >= 0
                    } && progress.FruitEnergy < progress.FruitCost)
                    return progress;
            }
            catch (Exception ex) { App.Log($"[Tree] Damaged progress copy: {candidate}: {ex.Message}"); }
        }
        return new TreeProgress();
    }

    public void Save(TreeProgress state)
    {
        Directory.CreateDirectory(Path.GetDirectoryName(path)!);
        string temp = path + ".tmp";
        File.WriteAllText(temp, JsonSerializer.Serialize(state, TreeProgressJsonContext.Default.TreeProgress));
        if (!File.Exists(path)) { File.Move(temp, path); return; }
        try
        {
            File.Replace(temp, path, path + ".bak", ignoreMetadataErrors: true);
        }
        catch (IOException ex) when (ex.HResult == ResilientJsonStore.UnableToRemoveReplacedFileHResult &&
                                     File.Exists(temp) && File.Exists(path))
        {
            // Packaged installs can reject Replace after writing neither file.
            // Preserve a verified prior copy before the in-place fallback.
            byte[] oldBytes = File.ReadAllBytes(path);
            byte[] newBytes = File.ReadAllBytes(temp);
            File.WriteAllBytes(path + ".bak", oldBytes);
            if (!File.ReadAllBytes(path + ".bak").AsSpan().SequenceEqual(oldBytes)) throw;
            File.WriteAllBytes(path, newBytes);
            if (!File.ReadAllBytes(path).AsSpan().SequenceEqual(newBytes)) throw;
            File.Delete(temp);
        }
    }
}
