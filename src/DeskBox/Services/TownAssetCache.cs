using System.Buffers.Binary;
using System.Security.Cryptography;

namespace DeskBox.Services;

internal static class TownAssetCache
{
    internal static void Validate(byte[] bytes, string hash, int width, int height)
    {
        ReadOnlySpan<byte> signature = [137, 80, 78, 71, 13, 10, 26, 10];
        if (bytes.Length < 33 || bytes.Length > 64 * 1024 * 1024 ||
            !bytes.AsSpan(0, 8).SequenceEqual(signature) ||
            !bytes.AsSpan(12, 4).SequenceEqual("IHDR"u8) ||
            BinaryPrimitives.ReadUInt32BigEndian(bytes.AsSpan(16, 4)) != width ||
            BinaryPrimitives.ReadUInt32BigEndian(bytes.AsSpan(20, 4)) != height ||
            !string.Equals(Convert.ToHexString(SHA256.HashData(bytes)), hash, StringComparison.OrdinalIgnoreCase))
            throw new InvalidDataException("Town sheet PNG dimensions or SHA-256 did not match the published catalog.");
    }

    internal static async Task<string> StoreAsync(string root, byte[] bytes, string hash, int width, int height, CancellationToken token)
    {
        Validate(bytes, hash, width, height);
        Directory.CreateDirectory(root);
        string path = Path.Combine(root, hash.ToLowerInvariant() + ".png");
        string temporary = path + "." + Guid.NewGuid().ToString("N") + ".tmp";
        try
        {
            await File.WriteAllBytesAsync(temporary, bytes, token);
            token.ThrowIfCancellationRequested();
            File.Move(temporary, path, true);
            return path;
        }
        finally { if (File.Exists(temporary)) File.Delete(temporary); }
    }
}
