using System.Text.Json;
using System.Text.Json.Serialization;
using DeskBox.Contracts;

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
    private readonly SemaphoreSlim _gate = new(1, 1);

    internal DingTalkGroupDocumentFavoritesCloudSyncService(
        IAuthenticatedAccountApi api,
        IAuthSession auth,
        DingTalkGroupDocumentFavorites favorites)
    {
        _api = api;
        _auth = auth;
        _favorites = favorites;
    }

    internal async Task<bool> SyncNowAsync(CancellationToken cancellationToken = default)
    {
        if (!_auth.IsAuthenticated || _auth.CurrentUser is null) return false;
        await _gate.WaitAsync(cancellationToken);
        try
        {
            DingTalkGroupDocumentFavoritesStore local =
                await _favorites.ExportSnapshotAsync(cancellationToken);

            using HttpResponseMessage get = await _api.SendAsync(
                HttpMethod.Get, "../dingtalk/favorites.php", null, null, cancellationToken);
            await EnsureSuccessAsync(get, cancellationToken);
            DingTalkFavoritesSyncPayload remote = await JsonSerializer.DeserializeAsync(
                await get.Content.ReadAsStreamAsync(cancellationToken),
                DingTalkFavoritesCloudJsonContext.Default.DingTalkFavoritesSyncPayload,
                cancellationToken) ?? new();

            await _favorites.MergeSnapshotAsync(new DingTalkGroupDocumentFavoritesStore
            {
                DocumentUrls = remote.DocumentUrls ?? [],
                Documents = remote.Documents ?? []
            }, cancellationToken);

            local = await _favorites.ExportSnapshotAsync(cancellationToken);
            byte[] body = JsonSerializer.SerializeToUtf8Bytes(
                new DingTalkFavoritesSyncPayload
                {
                    DocumentUrls = local.DocumentUrls,
                    Documents = local.Documents
                }, DingTalkFavoritesCloudJsonContext.Default.DingTalkFavoritesSyncPayload);
            using HttpResponseMessage put = await _api.SendAsync(
                HttpMethod.Put, "../dingtalk/favorites.php", body, null, cancellationToken);
            await EnsureSuccessAsync(put, cancellationToken);
            return true;
        }
        finally { _gate.Release(); }
    }

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
