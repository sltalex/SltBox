using System.Buffers.Binary;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;

namespace DeskBox.Services;

internal static class TownAssetCache
{
    private const long MaxCatalogBytes = 16 * 1024 * 1024;

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

    internal static async Task<string?> ReadCatalogAsync(
        string root, long releaseVersion, string? configHash, CancellationToken token)
    {
        if (!IsHash(configHash) || releaseVersion < 0) return null;
        string path = CatalogPath(root, releaseVersion, configHash!);
        try
        {
            FileInfo info = new(path);
            if (!info.Exists || info.Length <= 0 || info.Length > MaxCatalogBytes) return null;
            string json = await File.ReadAllTextAsync(path, token);
            using JsonDocument document = JsonDocument.Parse(json);
            JsonElement rootElement = document.RootElement;
            if (rootElement.ValueKind != JsonValueKind.Object ||
                !rootElement.TryGetProperty("release_version", out JsonElement version) ||
                !version.TryGetInt64(out long cachedVersion) || cachedVersion != releaseVersion ||
                !rootElement.TryGetProperty("config_sha256", out JsonElement hash) ||
                !string.Equals(hash.GetString(), configHash, StringComparison.OrdinalIgnoreCase) ||
                !rootElement.TryGetProperty("sheets", out JsonElement sheets) || sheets.ValueKind != JsonValueKind.Array ||
                !rootElement.TryGetProperty("items", out JsonElement items) || items.ValueKind != JsonValueKind.Array)
                return null;
            return json;
        }
        catch (Exception ex) when (ex is IOException or UnauthorizedAccessException or JsonException or OperationCanceledException)
        {
            return null;
        }
    }

    internal static async Task StoreCatalogAsync(
        string root, string json, long releaseVersion, string configHash, CancellationToken token)
    {
        if (!IsHash(configHash) || releaseVersion < 0 || Encoding.UTF8.GetByteCount(json) > MaxCatalogBytes)
            throw new InvalidDataException("The town asset catalog metadata is invalid.");
        using JsonDocument document = JsonDocument.Parse(json);
        JsonElement rootElement = document.RootElement;
        if (rootElement.ValueKind != JsonValueKind.Object ||
            !rootElement.TryGetProperty("release_version", out JsonElement version) ||
            !version.TryGetInt64(out long actualVersion) || actualVersion != releaseVersion ||
            !rootElement.TryGetProperty("config_sha256", out JsonElement hash) ||
            !string.Equals(hash.GetString(), configHash, StringComparison.OrdinalIgnoreCase) ||
            !rootElement.TryGetProperty("sheets", out JsonElement sheets) || sheets.ValueKind != JsonValueKind.Array ||
            !rootElement.TryGetProperty("items", out JsonElement items) || items.ValueKind != JsonValueKind.Array)
            throw new InvalidDataException("The town asset catalog metadata does not match the published release.");

        Directory.CreateDirectory(root);
        string path = CatalogPath(root, releaseVersion, configHash);
        string temporary = path + "." + Guid.NewGuid().ToString("N") + ".tmp";
        try
        {
            await File.WriteAllTextAsync(temporary, json, Encoding.UTF8, token);
            token.ThrowIfCancellationRequested();
            File.Move(temporary, path, true);
        }
        finally { if (File.Exists(temporary)) File.Delete(temporary); }
    }

    private static string CatalogPath(string root, long releaseVersion, string configHash) =>
        Path.Combine(root, $"catalog-{releaseVersion}-{configHash.ToLowerInvariant()}.json");

    private static bool IsHash(string? value) =>
        value?.Length == 64 && value.All(Uri.IsHexDigit) == true;

    internal static bool IsCacheMetadataUsable(long releaseVersion, string? configHash) =>
        releaseVersion >= 0 && IsHash(configHash);
}
