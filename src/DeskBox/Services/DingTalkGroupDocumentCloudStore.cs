using System.IO.Compression;
using System.Net;
using System.Text.Json;
using System.Text.Json.Serialization;
using DeskBox.Contracts;

namespace DeskBox.Services;

internal sealed class DingTalkGroupDocumentCloudStore(IAuthenticatedAccountApi api, IAuthSession auth)
{
    private const string Endpoint = "../dingtalk/documents.php";

    internal async Task<DingTalkGroupDocumentCloudSnapshot?> ReadAsync(CancellationToken token = default)
    {
        if (!auth.IsAuthenticated || auth.CurrentUser is null) return null;
        using HttpResponseMessage response = await api.SendAsync(HttpMethod.Get, Endpoint, null, null, token);
        if (response.StatusCode == HttpStatusCode.NotFound) return null;
        await EnsureSuccessAsync(response, token);
        await using Stream stream = await response.Content.ReadAsStreamAsync(token);
        return await JsonSerializer.DeserializeAsync(stream,
            DingTalkGroupDocumentCloudJsonContext.Default.DingTalkGroupDocumentCloudSnapshot, token)
            ?? throw new InvalidDataException("The DingTalk document cloud response is empty.");
    }

    internal async Task<DingTalkGroupDocumentCloudSnapshot> MergeAsync(
        IReadOnlyList<DingTalkGroupDocument> documents, DateTimeOffset? lastSync,
        int? intervalHours, CancellationToken token = default)
    {
        if (!auth.IsAuthenticated || auth.CurrentUser is null)
            throw new InvalidOperationException("Sign in to sync DingTalk documents.");
        var request = new DingTalkGroupDocumentCloudSnapshot
        {
            Documents = [.. documents],
            LastSyncCompletedAt = lastSync,
            SyncIntervalHours = intervalHours
        };
        byte[] bytes = JsonSerializer.SerializeToUtf8Bytes(request,
            DingTalkGroupDocumentCloudJsonContext.Default.DingTalkGroupDocumentCloudSnapshot);
        string? encoding = null;
        if (bytes.Length > 8192)
        {
            using var buffer = new MemoryStream();
            using (var gzip = new GZipStream(buffer, CompressionLevel.Fastest, leaveOpen: true))
                await gzip.WriteAsync(bytes, token);
            bytes = buffer.ToArray();
            encoding = "gzip";
        }
        using HttpResponseMessage response = await api.SendAsync(HttpMethod.Put, Endpoint, bytes, encoding, token);
        await EnsureSuccessAsync(response, token);
        await using Stream stream = await response.Content.ReadAsStreamAsync(token);
        return await JsonSerializer.DeserializeAsync(stream,
            DingTalkGroupDocumentCloudJsonContext.Default.DingTalkGroupDocumentCloudSnapshot, token)
            ?? throw new InvalidDataException("The DingTalk document cloud response is empty.");
    }

    private static async Task EnsureSuccessAsync(HttpResponseMessage response, CancellationToken token)
    {
        if (response.IsSuccessStatusCode) return;
        string code = "document_cloud_failed";
        try
        {
            await using Stream stream = await response.Content.ReadAsStreamAsync(token);
            AuthErrorResponse? error = await JsonSerializer.DeserializeAsync(
                stream, AuthJsonContext.Default.AuthErrorResponse, token);
            if (!string.IsNullOrWhiteSpace(error?.Code)) code = error.Code;
        }
        catch (JsonException) { }
        throw new AuthApiException(code, "DingTalk document cloud operation failed.", response.StatusCode);
    }
}

internal sealed class DingTalkGroupDocumentCloudSnapshot
{
    [JsonPropertyName("documents")]
    public DingTalkGroupDocument[] Documents { get; set; } = [];

    [JsonPropertyName("last_sync_completed_at")]
    public DateTimeOffset? LastSyncCompletedAt { get; set; }

    [JsonPropertyName("sync_interval_hours")]
    public int? SyncIntervalHours { get; set; }
}

[JsonSerializable(typeof(DingTalkGroupDocumentCloudSnapshot))]
internal sealed partial class DingTalkGroupDocumentCloudJsonContext : JsonSerializerContext;
