#if DEBUG
using System.Text;

namespace DeskBox.Core.Persistence;

internal static class TownPrototypeDraftFileStore
{
    internal static void Write(string destinationPath, string json)
    {
        Directory.CreateDirectory(Path.GetDirectoryName(destinationPath)!);
        string temporaryPath = destinationPath + ".tmp";
        File.WriteAllText(temporaryPath, json, new UTF8Encoding(false));
        File.Move(temporaryPath, destinationPath, overwrite: true);
    }
}
#endif
