using System.Security.Cryptography;
using DeskBox.Services;

namespace DeskBox.Tests;

public sealed class TownAssetCacheTests
{
    private static readonly byte[] Png = Convert.FromBase64String("iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAYAAAAf8/9hAAAAFklEQVR4nGP4TyFgGDVg1IBRA4aLAQBdePwur/3haQAAAABJRU5ErkJggg==");
    private const string CatalogHash = "0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef";

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

    [Fact]
    public async Task CatalogMetadataIsReadOnlyWhenReleaseAndHashMatch()
    {
        string root = Path.Combine(Path.GetTempPath(), "deskbox-town-cache-" + Guid.NewGuid().ToString("N"));
        string json = $"{{\"release_version\":7,\"config_sha256\":\"{CatalogHash}\",\"sheets\":[],\"items\":[]}}";
        try
        {
            await TownAssetCache.StoreCatalogAsync(root, json, 7, CatalogHash, CancellationToken.None);
            Assert.Equal(json, await TownAssetCache.ReadCatalogAsync(root, 7, CatalogHash, CancellationToken.None));
            Assert.Null(await TownAssetCache.ReadCatalogAsync(root, 8, CatalogHash, CancellationToken.None));
            Assert.Null(await TownAssetCache.ReadCatalogAsync(root, 7, new string('a', 64), CancellationToken.None));

            string path = Directory.GetFiles(root, "catalog-*.json").Single();
            await File.WriteAllTextAsync(path, "{\"release_version\":7,\"config_sha256\":\"bad\"}");
            Assert.Null(await TownAssetCache.ReadCatalogAsync(root, 7, CatalogHash, CancellationToken.None));
            Assert.DoesNotContain(Directory.GetFiles(root), file => file.EndsWith(".tmp", StringComparison.Ordinal));
        }
        finally { if (Directory.Exists(root)) Directory.Delete(root, true); }
    }

    [Fact]
    public async Task InvalidCatalogMetadataCannotBeStored()
    {
        string root = Path.Combine(Path.GetTempPath(), "deskbox-town-cache-" + Guid.NewGuid().ToString("N"));
        try
        {
            await Assert.ThrowsAsync<InvalidDataException>(() => TownAssetCache.StoreCatalogAsync(
                root, "{\"release_version\":6,\"config_sha256\":\"bad\",\"sheets\":[],\"items\":[]}",
                7, CatalogHash, CancellationToken.None));
            Assert.False(Directory.Exists(root));
        }
        finally { if (Directory.Exists(root)) Directory.Delete(root, true); }
    }
}
