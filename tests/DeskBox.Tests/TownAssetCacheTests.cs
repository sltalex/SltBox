using System.Security.Cryptography;
using DeskBox.Services;

namespace DeskBox.Tests;

public sealed class TownAssetCacheTests
{
    private static readonly byte[] Png = Convert.FromBase64String("iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAYAAAAf8/9hAAAAFklEQVR4nGP4TyFgGDVg1IBRA4aLAQBdePwur/3haQAAAABJRU5ErkJggg==");

    [Fact]
    public async Task VerifiedPngIsWrittenAtomicallyAndCorruptionIsRejected()
    {
        string root = Path.Combine(Path.GetTempPath(), "deskbox-town-cache-" + Guid.NewGuid().ToString("N"));
        try
        {
            string hash = Convert.ToHexString(SHA256.HashData(Png));
            string file = await TownAssetCache.StoreAsync(root, Png, hash, 16, 16, CancellationToken.None);
            Assert.Equal(Png, await File.ReadAllBytesAsync(file));
            Assert.Single(Directory.GetFiles(root));
            Assert.Throws<InvalidDataException>(() => TownAssetCache.Validate(Png, hash, 32, 16));
            byte[] altered = Png.ToArray(); altered[^1] ^= 1;
            Assert.Throws<InvalidDataException>(() => TownAssetCache.Validate(altered, hash, 16, 16));
            Assert.Throws<InvalidDataException>(() => TownAssetCache.Validate("not a PNG"u8.ToArray(), hash, 16, 16));
            using var cancel = new CancellationTokenSource(); cancel.Cancel();
            await Assert.ThrowsAnyAsync<OperationCanceledException>(() => TownAssetCache.StoreAsync(root, Png, hash, 16, 16, cancel.Token));
            Assert.Single(Directory.GetFiles(root));
        }
        finally { if (Directory.Exists(root)) Directory.Delete(root, true); }
    }
}
