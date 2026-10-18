using System.Text;

namespace DeskBox.Core.Persistence;

/// <summary>Owns the atomic local-file mutations used while migrating DingTalk stars.</summary>
internal static class DingTalkFavoritesFileMigration
{
    internal static void Commit(string legacyPath, string managedPath, string? managedJson)
    {
        if (managedJson is not null)
        {
            Directory.CreateDirectory(Path.GetDirectoryName(managedPath)!);
            string temporary = managedPath + "." + Guid.NewGuid().ToString("N") + ".tmp";
            try
            {
                File.WriteAllText(temporary, managedJson, new UTF8Encoding(false));
                File.Move(temporary, managedPath, overwrite: true);
            }
            finally { if (File.Exists(temporary)) File.Delete(temporary); }
        }

        if (File.Exists(legacyPath))
            File.Move(legacyPath, legacyPath + ".migrated-" + Guid.NewGuid().ToString("N"));
    }
}
