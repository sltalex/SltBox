namespace DeskBox.Core.Persistence;

/// <summary>Copies a legacy DingTalk index into its account-scoped data file.</summary>
internal static class DingTalkGroupDocumentFileMigration
{
    internal static void CopyIfAbsent(string legacyPath, string accountPath)
    {
        Directory.CreateDirectory(Path.GetDirectoryName(accountPath)!);
        try { File.Copy(legacyPath, accountPath, overwrite: false); }
        catch (IOException) when (File.Exists(accountPath)) { }
    }
}
