namespace DeskBox.Core.Persistence;

internal static class TreeDecorationCacheFileStore
{
    internal static void Commit(string temporaryPath, string destinationPath) =>
        File.Move(temporaryPath, destinationPath, overwrite: true);
}
