namespace DeskBox.Core.Persistence;

internal static class SevenPawsFileStore
{
    internal static void CopyLegacy(string source, string target) => File.Copy(source, target);
    internal static void DeleteCommand(string path) => File.Delete(path);
    internal static void AtomicWrite(string path, string json)
    {
        string temp = path + "." + Guid.NewGuid().ToString("N") + ".tmp";
        File.WriteAllText(temp, json);
        File.Move(temp, path, overwrite: true);
    }
}
