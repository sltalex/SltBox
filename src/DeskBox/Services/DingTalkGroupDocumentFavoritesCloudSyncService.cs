using System.Text.Json;
using System.Text.Json.Serialization;
using System.Security.Cryptography;
using System.Text;
using DeskBox.Contracts;
using DeskBox.Core.Persistence;

namespace DeskBox.Services;

/// <summary>
/// Account sync channel for DingTalk group-document favorites. Favorites are
/// merged by URL/document identity so two devices can sync independently.
/// </summary>
internal sealed class DingTalkGroupDocumentFavoritesCloudSyncService
{
    private readonly IAuthenticatedAccountApi _api;
    private readonly IAuthSession _auth;
    private readonly DingTalkGroupDocumentFavorites _favorites;
    private readonly string _stateDirectory;
    private readonly SemaphoreSlim _gate = new(1, 1);

    internal DingTalkGroupDocumentFavoritesCloudSyncService(
        IAuthenticatedAccountApi api,
        IAuthSession auth,
        DingTalkGroupDocumentFavorites favorites,
        string? stateDirectory = null)
    {
        _api = api;
        _auth = auth;
        _favorites = favorites;
        _stateDirectory = stateDirectory ?? Path.Combine(DeskBoxDataPathService.Current.DataDirectory, "dingtalk");
    }

    internal async Task<bool> SyncNowAsync(CancellationToken cancellationToken = default)
    {
        if (!_auth.IsAuthenticated || _auth.CurrentUser is null) return false;
        await _gate.WaitAsync(cancellationToken);
        try
        {
            return await SyncCoreAsync(cancellationToken);
        }
        finally { _gate.Release(); }
    }

    internal async Task SetFavoriteAndSyncAsync(DingTalkGroupDocument document, bool favorite,
        CancellationToken cancellationToken = default)
    {
        await _gate.WaitAsync(cancellationToken);
        try
        {
            await _favorites.SetFavoriteAsync(document, favorite, cancellationToken);
            await TrySyncAfterEditAsync(cancellationToken);
        }
        finally { _gate.Release(); }
    }

    internal async Task AddManualAndSyncAsync(string title, string url,
        CancellationToken cancellationToken = default)
    {
        await _gate.WaitAsync(cancellationToken);
        try
        {
            await _favorites.AddManualAsync(title, url, cancellationToken);
            await TrySyncAfterEditAsync(cancellationToken);
        }
        finally { _gate.Release(); }
    }

    private async Task TrySyncAfterEditAsync(CancellationToken token)
    {
        try { await SyncCoreAsync(token); }
        catch (Exception error) when (error is AuthApiException or HttpRequestException or
            IOException or InvalidDataException or TaskCanceledException)
        {
            App.Log($"[DingTalkGroupDocs] Favorite cloud sync deferred: {error.GetType().Name}");
        }
    }

    private async Task<bool> SyncCoreAsync(CancellationToken token)
    {
        if (!_auth.IsAuthenticated || _auth.CurrentUser is not { } user) return false;
        string owner = user.Id;
        string baselinePath = BaselinePath(owner);
        DingTalkGroupDocumentFavoritesStore local = await _favorites.ExportSnapshotAsync(token);
        DingTalkGroupDocumentFavoritesStore? baseline = null;
        if (File.Exists(baselinePath))
        {
            string json = await File.ReadAllTextAsync(baselinePath, token);
            baseline = JsonSerializer.Deserialize(json,
                DingTalkGroupDocumentFavoritesJsonContext.Default.DingTalkGroupDocumentFavoritesStore)
                ?? throw new InvalidDataException("Favorite cloud baseline is empty.");
        }

        using HttpResponseMessage get = await _api.SendAsync(
            HttpMethod.Get, "../dingtalk/favorites.php", null, null, token);
        await EnsureSuccessAsync(get, token);
        DingTalkFavoritesSyncPayload remote = await JsonSerializer.DeserializeAsync(
            await get.Content.ReadAsStreamAsync(token),
            DingTalkFavoritesCloudJsonContext.Default.DingTalkFavoritesSyncPayload,
            token) ?? new();
        if (_auth.CurrentUser?.Id != owner) return false;

        Dictionary<string, DingTalkGroupDocument?> remoteMap = Map(remote.DocumentUrls, remote.Documents);
        Dictionary<string, DingTalkGroupDocument?> localMap = Map(local.DocumentUrls, local.Documents);
        Dictionary<string, DingTalkGroupDocument?> baselineMap = baseline is null
            ? new(StringComparer.Ordinal)
            : Map(baseline.DocumentUrls, baseline.Documents);
        foreach (string key in baselineMap.Keys.Except(localMap.Keys)) remoteMap.Remove(key);
        foreach ((string key, DingTalkGroupDocument? document) in localMap)
            if (!baselineMap.TryGetValue(key, out DingTalkGroupDocument? prior) || prior != document)
                remoteMap[key] = document;

        DingTalkGroupDocumentFavoritesStore merged = Snapshot(remoteMap);
        if (!MapsEqual(remoteMap, Map(remote.DocumentUrls, remote.Documents)))
        {
            byte[] body = JsonSerializer.SerializeToUtf8Bytes(
                new DingTalkFavoritesSyncPayload
                {
                    DocumentUrls = merged.DocumentUrls,
                    Documents = merged.Documents
                }, DingTalkFavoritesCloudJsonContext.Default.DingTalkFavoritesSyncPayload);
            using HttpResponseMessage put = await _api.SendAsync(
                HttpMethod.Put, "../dingtalk/favorites.php", body, null, token);
            await EnsureSuccessAsync(put, token);
        }
        if (_auth.CurrentUser?.Id != owner) return false;
        await _favorites.ReplaceSnapshotAsync(merged, token);
        if (_auth.CurrentUser?.Id != owner) return false;
        Directory.CreateDirectory(_stateDirectory);
        await ResilientJsonStore.SaveAsync(baselinePath,
            JsonSerializer.Serialize(merged,
                DingTalkGroupDocumentFavoritesJsonContext.Default.DingTalkGroupDocumentFavoritesStore));
        return true;
    }

    private string BaselinePath(string userId) => Path.Combine(_stateDirectory,
        "group-document-favorites-cloud-" +
        Convert.ToHexString(SHA256.HashData(Encoding.UTF8.GetBytes(userId))).ToLowerInvariant() + ".json");

    private static Dictionary<string, DingTalkGroupDocument?> Map(
        IEnumerable<string> urls, IEnumerable<DingTalkGroupDocument> documents)
    {
        var result = new Dictionary<string, DingTalkGroupDocument?>(StringComparer.Ordinal);
        foreach (string url in urls)
        {
            try { result[DingTalkGroupDocumentFavorites.CanonicalKey(url)] = null; }
            catch (ArgumentException) { }
        }
        foreach (DingTalkGroupDocument document in documents)
        {
            try { result[DingTalkGroupDocumentFavorites.CanonicalKey(document)] = document; }
            catch (ArgumentException) { }
        }
        return result;
    }

    private static DingTalkGroupDocumentFavoritesStore Snapshot(
        Dictionary<string, DingTalkGroupDocument?> map) => new()
    {
        DocumentUrls = [.. map.Where(pair => pair.Value is null).Select(pair => pair.Key)
            .Order(StringComparer.Ordinal)],
        Documents = [.. map.Values.OfType<DingTalkGroupDocument>()
            .OrderBy(DingTalkGroupDocumentFavorites.CanonicalKey, StringComparer.Ordinal)]
    };

    private static bool MapsEqual(Dictionary<string, DingTalkGroupDocument?> first,
        Dictionary<string, DingTalkGroupDocument?> second) =>
        first.Count == second.Count && first.All(pair => second.TryGetValue(pair.Key, out var value) && value == pair.Value);

    private static async Task EnsureSuccessAsync(HttpResponseMessage response,
        CancellationToken cancellationToken)
    {
        if (response.IsSuccessStatusCode) return;
        string? code = null;
        try
        {
            await using Stream stream = await response.Content.ReadAsStreamAsync(cancellationToken);
            using JsonDocument document = await JsonDocument.ParseAsync(stream, cancellationToken: cancellationToken);
            if (document.RootElement.TryGetProperty("code", out JsonElement value))
                code = value.GetString();
        }
        catch (JsonException) { }
        throw new AuthApiException(code ?? "favorites_sync_failed",
            $"Favorites sync failed ({(int)response.StatusCode}).", response.StatusCode);
    }
}

internal sealed class DingTalkFavoritesSyncPayload
{
    [JsonPropertyName("document_urls")]
    public string[] DocumentUrls { get; set; } = [];

    [JsonPropertyName("documents")]
    public DingTalkGroupDocument[] Documents { get; set; } = [];
}

[JsonSourceGenerationOptions(PropertyNamingPolicy = JsonKnownNamingPolicy.SnakeCaseLower)]
[JsonSerializable(typeof(DingTalkFavoritesSyncPayload))]
internal sealed partial class DingTalkFavoritesCloudJsonContext : JsonSerializerContext
{
}
