using System.Net.Http.Headers;

namespace DeskBox.Services;

/// <summary>
/// Sends an account-scoped request without exposing bearer credentials to feature services.
/// Implementations refresh and replay an unauthorized request at most once.
/// </summary>
internal interface IAuthenticatedAccountApi
{
    Task<HttpResponseMessage> SendAsync(
        HttpMethod method,
        string relativePath,
        byte[]? jsonBody,
        string? contentEncoding,
        CancellationToken cancellationToken = default);

    /// <summary>Uploads an arbitrary binary body while keeping bearer-token refresh inside the session.</summary>
    async Task<HttpResponseMessage> SendBinaryAsync(
        HttpMethod method,
        string relativePath,
        byte[] body,
        IReadOnlyDictionary<string, string>? headers = null,
        CancellationToken cancellationToken = default)
    {
        // The default keeps test doubles and older feature implementations source compatible.
        // Binary endpoints do not interpret the media type, so the existing request path is safe.
        return await SendAsync(method, relativePath, body, null, cancellationToken);
    }

    /// <summary>Uploads a small multipart form while keeping authentication refresh inside the session.</summary>
    async Task<HttpResponseMessage> SendMultipartAsync(
        HttpMethod method,
        string relativePath,
        IReadOnlyDictionary<string, string> fields,
        string fileField,
        string fileName,
        string mediaType,
        byte[] fileBytes,
        CancellationToken cancellationToken = default)
    {
        string boundary = "----DeskBoxTown" + Guid.NewGuid().ToString("N");
        using var form = new MultipartFormDataContent(boundary);
        foreach (var field in fields)
            form.Add(new StringContent(field.Value ?? string.Empty), field.Key);
        var file = new ByteArrayContent(fileBytes);
        file.Headers.ContentType = new MediaTypeHeaderValue(mediaType);
        form.Add(file, fileField, fileName);
        using var stream = new MemoryStream();
        await form.CopyToAsync(stream, cancellationToken);
        return await SendAsync(method, relativePath, stream.ToArray(), $"multipart/form-data; boundary={boundary}", cancellationToken);
    }
}
