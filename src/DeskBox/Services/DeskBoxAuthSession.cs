using System.Net;
using System.Net.Http.Headers;
using System.Text.Json;
using DeskBox.Contracts;

namespace DeskBox.Services;

internal sealed class DeskBoxAuthSession : IAuthSession, IAuthenticatedAccountApi
{
    internal const string DefaultApiBaseUrl = "http://sltbox.2leaves.cn/api/v1/auth/";
    internal const string SessionCredentialKey = "deskbox-cloud:auth:session";

    private readonly ICredentialStore _credentialStore;
    private readonly Func<DateTimeOffset> _clock;
    private readonly SemaphoreSlim _operationGate = new(1, 1);
    private readonly HttpClient _httpClient;
    private StoredAuthSession? _session;
    private bool _restoreAttempted;
    private bool _serverVerified;

    internal DeskBoxAuthSession(
        ICredentialStore credentialStore,
        HttpClient? httpClient = null,
        Func<DateTimeOffset>? clock = null)
    {
        _credentialStore = credentialStore;
        _httpClient = httpClient ?? new HttpClient
        {
            BaseAddress = new Uri(DefaultApiBaseUrl),
            Timeout = TimeSpan.FromSeconds(20)
        };
        _clock = clock ?? (() => DateTimeOffset.UtcNow);
    }

    public event Action? SessionChanged;

    public bool IsAuthenticated => _session is not null;

    public AuthUser? CurrentUser => _session is null
        ? null
        : new AuthUser(_session.User.Id, _session.User.Username,
            _serverVerified && _session.User.Role == "admin" ? "admin" : "user");

    public Task<AuthUser> RegisterAsync(
        string username,
        string password,
        CancellationToken cancellationToken = default) =>
        AuthenticateAsync("register.php", username, password, cancellationToken);

    public Task<AuthUser> SignInAsync(
        string username,
        string password,
        CancellationToken cancellationToken = default) =>
        AuthenticateAsync("login.php", username, password, cancellationToken);

    public async Task RestoreAsync(CancellationToken cancellationToken = default)
    {
        await _operationGate.WaitAsync(cancellationToken);
        try
        {
            if (_restoreAttempted)
            {
                return;
            }

            _restoreAttempted = true;
            string? stored = await _credentialStore.GetSecretAsync(
                SessionCredentialKey,
                cancellationToken);
            if (string.IsNullOrWhiteSpace(stored))
            {
                return;
            }

            try
            {
                _session = JsonSerializer.Deserialize(
                    stored,
                    AuthJsonContext.Default.StoredAuthSession);
                ValidateStoredSession(_session);
                _serverVerified = false;
            }
            catch (Exception ex) when (ex is JsonException or InvalidDataException)
            {
                await ClearLocalSessionAsync(cancellationToken);
                return;
            }

            RaiseSessionChanged();
            try
            {
                await ValidateCurrentUserAsync(cancellationToken);
            }
            catch (Exception ex) when (ex is HttpRequestException or TaskCanceledException)
            {
                // Offline startup keeps the last locally authenticated identity.
                App.Log($"[Auth] Session validation deferred: {ex.GetType().Name}");
            }
        }
        finally
        {
            _operationGate.Release();
        }
    }

    public async Task RefreshAsync(CancellationToken cancellationToken = default)
    {
        await _operationGate.WaitAsync(cancellationToken);
        try
        {
            await RefreshCoreAsync(cancellationToken);
        }
        finally
        {
            _operationGate.Release();
        }
    }

    public async Task SignOutAsync(CancellationToken cancellationToken = default)
    {
        await _operationGate.WaitAsync(cancellationToken);
        try
        {
            string? accessToken = _session?.AccessToken;
            if (!string.IsNullOrEmpty(accessToken))
            {
                try
                {
                    using var request = new HttpRequestMessage(HttpMethod.Post, "logout.php");
                    request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", accessToken);
                    request.Content = new ByteArrayContent("{}"u8.ToArray());
                    request.Content.Headers.ContentType = new MediaTypeHeaderValue("application/json");
                    using HttpResponseMessage _ = await _httpClient.SendAsync(request, cancellationToken);
                }
                catch (Exception ex) when (ex is HttpRequestException or TaskCanceledException)
                {
                    App.Log($"[Auth] Remote sign-out deferred: {ex.GetType().Name}");
                }
            }

            await ClearLocalSessionAsync(cancellationToken);
        }
        finally
        {
            _operationGate.Release();
        }
    }

    async Task<HttpResponseMessage> IAuthenticatedAccountApi.SendAsync(
        HttpMethod method,
        string relativePath,
        byte[]? jsonBody,
        string? contentEncoding,
        CancellationToken cancellationToken)
    {
        await _operationGate.WaitAsync(cancellationToken);
        try
        {
            if (_session is null)
                throw new AuthApiException("invalid_session", "Sign in is required.", HttpStatusCode.Unauthorized);

            HttpResponseMessage response = await SendAuthenticatedAsync(
                method, relativePath, jsonBody, contentEncoding, _session.AccessToken, cancellationToken);
            if (response.StatusCode != HttpStatusCode.Unauthorized)
                return response;

            response.Dispose();
            await RefreshCoreAsync(cancellationToken);
            response = await SendAuthenticatedAsync(
                method, relativePath, jsonBody, contentEncoding, _session!.AccessToken, cancellationToken);
            if (response.StatusCode != HttpStatusCode.Unauthorized)
                return response;

            response.Dispose();
            await ClearLocalSessionAsync(cancellationToken);
            throw new AuthApiException(
                "invalid_session", "The saved session is no longer valid.", HttpStatusCode.Unauthorized);
        }
        finally
        {
            _operationGate.Release();
        }
    }

    async Task<HttpResponseMessage> IAuthenticatedAccountApi.SendBinaryAsync(
        HttpMethod method,
        string relativePath,
        byte[] body,
        IReadOnlyDictionary<string, string>? headers,
        CancellationToken cancellationToken)
    {
        ArgumentNullException.ThrowIfNull(body);
        string accessToken;
        await _operationGate.WaitAsync(cancellationToken);
        try
        {
            if (_session is null)
                throw new AuthApiException("invalid_session", "Sign in is required.", HttpStatusCode.Unauthorized);
            accessToken = _session.AccessToken;
        }
        finally { _operationGate.Release(); }

        HttpResponseMessage response = await SendRawAuthenticatedAsync(
            method, relativePath, body, headers, accessToken, cancellationToken);
        if (response.StatusCode != HttpStatusCode.Unauthorized)
            return response;
        response.Dispose();

        await _operationGate.WaitAsync(cancellationToken);
        try
        {
            if (_session is null)
                throw new AuthApiException("invalid_session", "Sign in is required.", HttpStatusCode.Unauthorized);
            await RefreshCoreAsync(cancellationToken);
            accessToken = _session!.AccessToken;
        }
        finally { _operationGate.Release(); }

        response = await SendRawAuthenticatedAsync(
            method, relativePath, body, headers, accessToken, cancellationToken);
        if (response.StatusCode != HttpStatusCode.Unauthorized)
            return response;
        response.Dispose();
        await _operationGate.WaitAsync(cancellationToken);
        try { await ClearLocalSessionAsync(cancellationToken); }
        finally { _operationGate.Release(); }
        throw new AuthApiException("invalid_session", "The saved session is no longer valid.", HttpStatusCode.Unauthorized);
    }

    private async Task<AuthUser> AuthenticateAsync(
        string endpoint,
        string username,
        string password,
        CancellationToken cancellationToken)
    {
        await _operationGate.WaitAsync(cancellationToken);
        try
        {
            var payload = new AuthCredentialsRequest
            {
                Username = username,
                Password = password,
                DeviceId = DeviceIdentity.Id
            };
            byte[] content = JsonSerializer.SerializeToUtf8Bytes(
                payload,
                AuthJsonContext.Default.AuthCredentialsRequest);
            using HttpResponseMessage response = await SendJsonAsync(
                endpoint,
                content,
                bearerToken: null,
                cancellationToken);
            AuthSessionResponse body = await ReadSessionResponseAsync(response, cancellationToken);
            await AdoptSessionAsync(body, cancellationToken);
            return CurrentUser!;
        }
        finally
        {
            _operationGate.Release();
        }
    }

    private async Task ValidateCurrentUserAsync(CancellationToken cancellationToken)
    {
        if (_session is null)
        {
            return;
        }

        using HttpResponseMessage first = await SendJsonAsync(
            "me.php",
            content: null,
            _session.AccessToken,
            cancellationToken,
            HttpMethod.Get);
        if (first.StatusCode == HttpStatusCode.Unauthorized)
        {
            await RefreshCoreAsync(cancellationToken);
            using HttpResponseMessage retried = await SendJsonAsync(
                "me.php",
                content: null,
                _session!.AccessToken,
                cancellationToken,
                HttpMethod.Get);
            await AdoptCurrentUserAsync(retried, cancellationToken);
            return;
        }

        await AdoptCurrentUserAsync(first, cancellationToken);
    }

    private async Task AdoptCurrentUserAsync(
        HttpResponseMessage response,
        CancellationToken cancellationToken)
    {
        if (response.StatusCode == HttpStatusCode.Unauthorized)
        {
            await ClearLocalSessionAsync(cancellationToken);
            throw new AuthApiException("invalid_session", "The saved session is no longer valid.", response.StatusCode);
        }

        await EnsureSuccessAsync(response, cancellationToken);
        await using Stream stream = await response.Content.ReadAsStreamAsync(cancellationToken);
        AuthUserResponse? body = await JsonSerializer.DeserializeAsync(
            stream,
            AuthJsonContext.Default.AuthUserResponse,
            cancellationToken);
        if (body?.User is null || string.IsNullOrWhiteSpace(body.User.Id))
        {
            throw new InvalidDataException("The account service returned an invalid user.");
        }

        StoredAuthSession candidate = CopySession(_session!, body.User);
        await PersistSessionAsync(candidate, cancellationToken);
        _session = candidate;
        _serverVerified = true;
        RaiseSessionChanged();
    }

    private async Task RefreshCoreAsync(CancellationToken cancellationToken)
    {
        if (_session is null || _session.RefreshExpiresAt <= _clock())
        {
            await ClearLocalSessionAsync(cancellationToken);
            throw new AuthApiException("invalid_session", "The saved session has expired.", HttpStatusCode.Unauthorized);
        }

        var payload = new AuthRefreshRequest { RefreshToken = _session.RefreshToken };
        byte[] content = JsonSerializer.SerializeToUtf8Bytes(
            payload,
            AuthJsonContext.Default.AuthRefreshRequest);
        using HttpResponseMessage response = await SendJsonAsync(
            "refresh.php",
            content,
            bearerToken: null,
            cancellationToken);
        if (response.StatusCode == HttpStatusCode.Unauthorized)
        {
            await ClearLocalSessionAsync(cancellationToken);
        }

        AuthSessionResponse body = await ReadSessionResponseAsync(response, cancellationToken);
        try
        {
            await AdoptSessionAsync(body, cancellationToken);
        }
        catch (Exception ex) when (ex is IOException or UnauthorizedAccessException or System.Runtime.InteropServices.COMException)
        {
            await InvalidateRotatedSessionAsync(cancellationToken);
            throw new AuthApiException(
                "invalid_session",
                "The refreshed session could not be saved. Please sign in again.",
                HttpStatusCode.Unauthorized);
        }
    }

    private async Task AdoptSessionAsync(
        AuthSessionResponse response,
        CancellationToken cancellationToken)
    {
        if (string.IsNullOrWhiteSpace(response.User.Id) ||
            string.IsNullOrWhiteSpace(response.User.Username) ||
            string.IsNullOrWhiteSpace(response.Session.AccessToken) ||
            string.IsNullOrWhiteSpace(response.Session.RefreshToken))
        {
            throw new InvalidDataException("The account service returned an incomplete session.");
        }

        var candidate = new StoredAuthSession
        {
            User = response.User,
            AccessToken = response.Session.AccessToken,
            RefreshToken = response.Session.RefreshToken,
            AccessExpiresAt = response.Session.AccessExpiresAt,
            RefreshExpiresAt = response.Session.RefreshExpiresAt
        };
        await PersistSessionAsync(candidate, cancellationToken);
        _session = candidate;
        _serverVerified = true;
        RaiseSessionChanged();
    }

    private async Task<AuthSessionResponse> ReadSessionResponseAsync(
        HttpResponseMessage response,
        CancellationToken cancellationToken)
    {
        await EnsureSuccessAsync(response, cancellationToken);
        await using Stream stream = await response.Content.ReadAsStreamAsync(cancellationToken);
        return await JsonSerializer.DeserializeAsync(
                   stream,
                   AuthJsonContext.Default.AuthSessionResponse,
                   cancellationToken)
               ?? throw new InvalidDataException("The account service returned an empty session.");
    }

    private static async Task EnsureSuccessAsync(
        HttpResponseMessage response,
        CancellationToken cancellationToken)
    {
        if (response.IsSuccessStatusCode)
        {
            return;
        }

        AuthErrorResponse? error = null;
        try
        {
            await using Stream stream = await response.Content.ReadAsStreamAsync(cancellationToken);
            error = await JsonSerializer.DeserializeAsync(
                stream,
                AuthJsonContext.Default.AuthErrorResponse,
                cancellationToken);
        }
        catch (JsonException)
        {
        }

        throw new AuthApiException(
            string.IsNullOrWhiteSpace(error?.Code) ? "server_error" : error.Code,
            string.IsNullOrWhiteSpace(error?.Message) ? "The account service request failed." : error.Message,
            response.StatusCode);
    }

    private async Task<HttpResponseMessage> SendJsonAsync(
        string endpoint,
        byte[]? content,
        string? bearerToken,
        CancellationToken cancellationToken,
        HttpMethod? method = null)
    {
        using var request = new HttpRequestMessage(method ?? HttpMethod.Post, endpoint);
        if (content is not null)
        {
            request.Content = new ByteArrayContent(content);
            request.Content.Headers.ContentType = new MediaTypeHeaderValue("application/json");
        }
        if (!string.IsNullOrEmpty(bearerToken))
        {
            request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", bearerToken);
        }
        return await _httpClient.SendAsync(request, HttpCompletionOption.ResponseHeadersRead, cancellationToken);
    }

    private async Task<HttpResponseMessage> SendAuthenticatedAsync(
        HttpMethod method,
        string relativePath,
        byte[]? jsonBody,
        string? contentEncoding,
        string accessToken,
        CancellationToken cancellationToken)
    {
        using var request = new HttpRequestMessage(method, relativePath);
        request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", accessToken);
        if (jsonBody is not null)
        {
            request.Content = new ByteArrayContent(jsonBody);
            request.Content.Headers.ContentType = new MediaTypeHeaderValue("application/json");
            if (!string.IsNullOrWhiteSpace(contentEncoding))
                request.Content.Headers.ContentEncoding.Add(contentEncoding);
        }
        return await _httpClient.SendAsync(request, HttpCompletionOption.ResponseHeadersRead, cancellationToken);
    }

    private async Task<HttpResponseMessage> SendRawAuthenticatedAsync(
        HttpMethod method,
        string relativePath,
        byte[] body,
        IReadOnlyDictionary<string, string>? headers,
        string accessToken,
        CancellationToken cancellationToken)
    {
        using var request = new HttpRequestMessage(method, relativePath);
        request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", accessToken);
        request.Content = new ByteArrayContent(body);
        request.Content.Headers.ContentType = new MediaTypeHeaderValue("application/octet-stream");
        if (headers is not null)
        {
            foreach (var header in headers)
            {
                if (!request.Headers.TryAddWithoutValidation(header.Key, header.Value) &&
                    !request.Content.Headers.TryAddWithoutValidation(header.Key, header.Value))
                    throw new InvalidOperationException($"Invalid request header: {header.Key}");
            }
        }
        return await _httpClient.SendAsync(request, HttpCompletionOption.ResponseHeadersRead, cancellationToken);
    }

    private async Task PersistSessionAsync(
        StoredAuthSession session,
        CancellationToken cancellationToken)
    {
        string serialized = JsonSerializer.Serialize(
            session,
            AuthJsonContext.Default.StoredAuthSession);
        await _credentialStore.SetSecretAsync(SessionCredentialKey, serialized, cancellationToken);
    }

    private static StoredAuthSession CopySession(StoredAuthSession session, AuthUserDto user) => new()
    {
        User = user,
        AccessToken = session.AccessToken,
        RefreshToken = session.RefreshToken,
        AccessExpiresAt = session.AccessExpiresAt,
        RefreshExpiresAt = session.RefreshExpiresAt
    };

    private async Task ClearLocalSessionAsync(CancellationToken cancellationToken)
    {
        bool changed = _session is not null;
        _session = null;
        _serverVerified = false;
        await _credentialStore.RemoveSecretAsync(SessionCredentialKey, cancellationToken);
        if (changed)
        {
            RaiseSessionChanged();
        }
    }

    private async Task InvalidateRotatedSessionAsync(CancellationToken cancellationToken)
    {
        bool changed = _session is not null;
        _session = null;
        _serverVerified = false;
        try
        {
            await _credentialStore.RemoveSecretAsync(SessionCredentialKey, cancellationToken);
        }
        catch (Exception ex) when (ex is IOException or UnauthorizedAccessException or System.Runtime.InteropServices.COMException)
        {
            App.Log($"[Auth] Invalid rotated session could not be removed: {ex.GetType().Name}");
        }
        if (changed)
        {
            RaiseSessionChanged();
        }
    }

    private static void ValidateStoredSession(StoredAuthSession? session)
    {
        if (session is null ||
            string.IsNullOrWhiteSpace(session.User.Id) ||
            string.IsNullOrWhiteSpace(session.User.Username) ||
            string.IsNullOrWhiteSpace(session.AccessToken) ||
            string.IsNullOrWhiteSpace(session.RefreshToken))
        {
            throw new InvalidDataException("The saved account session is incomplete.");
        }
    }

    private void RaiseSessionChanged()
    {
        foreach (Action observer in SessionChanged?.GetInvocationList() ?? [])
        {
            try
            {
                observer();
            }
            catch (Exception ex)
            {
                App.Log($"[Auth] Session observer failed: {ex.GetType().Name}");
            }
        }
    }
}

internal sealed class AuthApiException(
    string code,
    string message,
    HttpStatusCode statusCode) : Exception(message)
{
    internal string Code { get; } = code;
    internal HttpStatusCode StatusCode { get; } = statusCode;
}
