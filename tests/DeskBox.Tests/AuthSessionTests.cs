using System.Net;
using System.Text;
using DeskBox.Contracts;
using DeskBox.Services;

namespace DeskBox.Tests;

public sealed class AuthSessionTests
{
    [Fact]
    public async Task Register_PersistsSessionAndAuthenticatesUser()
    {
        var credentials = new MemoryCredentialStore();
        using var http = Client((request, _) => Json(HttpStatusCode.Created, SessionJson("user-1", "Simon", "access-1", "refresh-1")));
        var session = new DeskBoxAuthSession(credentials, http);

        AuthUser user = await session.RegisterAsync("Simon", "secret1");

        Assert.True(session.IsAuthenticated);
        Assert.Equal("Simon", user.Username);
        string stored = Assert.Single(credentials.Secrets).Value;
        Assert.Contains("refresh-1", stored, StringComparison.Ordinal);
        Assert.DoesNotContain("secret1", stored, StringComparison.Ordinal);
    }

    [Fact]
    public async Task AdminRole_IsVisibleOnlyAfterServerVerification()
    {
        var credentials = new MemoryCredentialStore();
        await credentials.SetSecretAsync(DeskBoxAuthSession.SessionCredentialKey,
            """
            {"user":{"id":"user-1","username":"Simon","role":"admin"},"accessToken":"access-1","refreshToken":"refresh-1","accessExpiresAt":"2026-10-15T00:00:00Z","refreshExpiresAt":"2026-11-14T00:00:00Z"}
            """);
        string? roleDuringRequest = null;
        DeskBoxAuthSession? session = null;
        using var http = Client((_, _) =>
        {
            roleDuringRequest = session!.CurrentUser?.Role;
            return Json(HttpStatusCode.OK, "{\"user\":{\"id\":\"user-1\",\"username\":\"Simon\",\"role\":\"admin\"}}");
        });
        session = new DeskBoxAuthSession(credentials, http,
            () => new DateTimeOffset(2026, 10, 14, 0, 0, 0, TimeSpan.Zero));

        await session.RestoreAsync();

        Assert.Equal("user", roleDuringRequest);
        Assert.Equal("admin", session.CurrentUser?.Role);
    }

    [Fact]
    public async Task AdminRole_RemainsHiddenWhenOfflineRestoreCannotValidate()
    {
        var credentials = new MemoryCredentialStore();
        await credentials.SetSecretAsync(DeskBoxAuthSession.SessionCredentialKey,
            """
            {"user":{"id":"user-1","username":"Simon","role":"admin"},"accessToken":"access-1","refreshToken":"refresh-1","accessExpiresAt":"2026-10-15T00:00:00Z","refreshExpiresAt":"2026-11-14T00:00:00Z"}
            """);
        using var http = Client((_, _) => throw new HttpRequestException("offline"));
        var session = new DeskBoxAuthSession(credentials, http,
            () => new DateTimeOffset(2026, 10, 14, 0, 0, 0, TimeSpan.Zero));

        await session.RestoreAsync();

        Assert.True(session.IsAuthenticated);
        Assert.Equal("user", session.CurrentUser?.Role);
    }

    [Fact]
    public async Task Restore_RetriesMeOnceAfterRefreshingUnauthorizedAccessToken()
    {
        var credentials = new MemoryCredentialStore();
        await credentials.SetSecretAsync(
            DeskBoxAuthSession.SessionCredentialKey,
            """
            {"user":{"id":"user-1","username":"Simon"},"accessToken":"expired","refreshToken":"refresh-1","accessExpiresAt":"2026-10-14T00:00:00Z","refreshExpiresAt":"2026-11-14T00:00:00Z"}
            """);
        int meCalls = 0;
        int refreshCalls = 0;
        using var http = Client((request, _) =>
        {
            if (request.RequestUri!.AbsolutePath.EndsWith("/refresh.php", StringComparison.Ordinal))
            {
                refreshCalls++;
                return Json(HttpStatusCode.OK, SessionJson("user-1", "Simon", "access-2", "refresh-2"));
            }

            meCalls++;
            return meCalls == 1
                ? Json(HttpStatusCode.Unauthorized, "{\"code\":\"invalid_session\",\"message\":\"Invalid session.\"}")
                : Json(HttpStatusCode.OK, "{\"user\":{\"id\":\"user-1\",\"username\":\"Simon\"}}");
        });
        var session = new DeskBoxAuthSession(credentials, http, () => new DateTimeOffset(2026, 10, 14, 0, 0, 0, TimeSpan.Zero));

        await session.RestoreAsync();

        Assert.True(session.IsAuthenticated);
        Assert.Equal(2, meCalls);
        Assert.Equal(1, refreshCalls);
        Assert.Contains("refresh-2", Assert.Single(credentials.Secrets).Value, StringComparison.Ordinal);
    }

    [Fact]
    public async Task Restore_NetworkFailureKeepsLocallyPersistedSession()
    {
        var credentials = new MemoryCredentialStore();
        await credentials.SetSecretAsync(
            DeskBoxAuthSession.SessionCredentialKey,
            """
            {"user":{"id":"user-1","username":"Simon"},"accessToken":"access-1","refreshToken":"refresh-1","accessExpiresAt":"2026-10-15T00:00:00Z","refreshExpiresAt":"2026-11-14T00:00:00Z"}
            """);
        using var http = Client((_, _) => throw new HttpRequestException("offline"));
        var session = new DeskBoxAuthSession(credentials, http, () => new DateTimeOffset(2026, 10, 14, 0, 0, 0, TimeSpan.Zero));

        await session.RestoreAsync();

        Assert.True(session.IsAuthenticated);
        Assert.Equal("Simon", session.CurrentUser!.Username);
        Assert.Single(credentials.Secrets);
    }

    [Fact]
    public async Task AuthenticatedApi_RefreshesAndReplaysExactlyOnce()
    {
        var credentials = new MemoryCredentialStore();
        int protectedCalls = 0;
        int refreshCalls = 0;
        using var http = Client((request, _) =>
        {
            if (request.RequestUri!.AbsolutePath.EndsWith("/login.php", StringComparison.Ordinal))
                return Json(HttpStatusCode.OK, SessionJson("user-1", "Simon", "access-1", "refresh-1"));
            if (request.RequestUri.AbsolutePath.EndsWith("/refresh.php", StringComparison.Ordinal))
            {
                refreshCalls++;
                return Json(HttpStatusCode.OK, SessionJson("user-1", "Simon", "access-2", "refresh-2"));
            }

            protectedCalls++;
            string token = request.Headers.Authorization?.Parameter ?? string.Empty;
            return token == "access-2"
                ? Json(HttpStatusCode.OK, "{\"snapshots\":[]}")
                : Json(HttpStatusCode.Unauthorized, "{\"code\":\"invalid_session\",\"message\":\"Expired.\"}");
        });
        var session = new DeskBoxAuthSession(credentials, http);
        await session.SignInAsync("Simon", "secret1");

        using HttpResponseMessage response = await ((IAuthenticatedAccountApi)session).SendAsync(
            HttpMethod.Get, "../backup/list.php", null, null);

        Assert.Equal(HttpStatusCode.OK, response.StatusCode);
        Assert.Equal(2, protectedCalls);
        Assert.Equal(1, refreshCalls);
    }

    [Fact]
    public async Task AuthenticatedApi_SecondUnauthorizedClearsSessionWithoutLooping()
    {
        var credentials = new MemoryCredentialStore();
        int protectedCalls = 0;
        int refreshCalls = 0;
        using var http = Client((request, _) =>
        {
            if (request.RequestUri!.AbsolutePath.EndsWith("/login.php", StringComparison.Ordinal))
                return Json(HttpStatusCode.OK, SessionJson("user-1", "Simon", "access-1", "refresh-1"));
            if (request.RequestUri.AbsolutePath.EndsWith("/refresh.php", StringComparison.Ordinal))
            {
                refreshCalls++;
                return Json(HttpStatusCode.OK, SessionJson("user-1", "Simon", "access-2", "refresh-2"));
            }
            protectedCalls++;
            return Json(HttpStatusCode.Unauthorized, "{\"code\":\"invalid_session\",\"message\":\"Expired.\"}");
        });
        var session = new DeskBoxAuthSession(credentials, http);
        await session.SignInAsync("Simon", "secret1");

        await Assert.ThrowsAsync<AuthApiException>(() => ((IAuthenticatedAccountApi)session).SendAsync(
            HttpMethod.Get, "../backup/list.php", null, null));

        Assert.Equal(2, protectedCalls);
        Assert.Equal(1, refreshCalls);
        Assert.False(session.IsAuthenticated);
    }

    [Fact]
    public async Task SignOut_ClearsLocalSessionEvenWhenServerIsUnavailable()
    {
        var credentials = new MemoryCredentialStore();
        bool offline = false;
        using var http = Client((_, _) => offline
            ? throw new HttpRequestException("offline")
            : Json(HttpStatusCode.Created, SessionJson("user-1", "Simon", "access-1", "refresh-1")));
        var session = new DeskBoxAuthSession(credentials, http);
        await session.RegisterAsync("Simon", "secret1");
        offline = true;

        await session.SignOutAsync();

        Assert.False(session.IsAuthenticated);
        Assert.Null(session.CurrentUser);
        Assert.Empty(credentials.Secrets);
    }

    [Fact]
    public async Task Register_UsernameConflictPreservesServerErrorCode()
    {
        var credentials = new MemoryCredentialStore();
        using var http = Client((_, _) => Json(
            HttpStatusCode.Conflict,
            "{\"code\":\"username_taken\",\"message\":\"Already registered.\"}"));
        var session = new DeskBoxAuthSession(credentials, http);

        AuthApiException error = await Assert.ThrowsAsync<AuthApiException>(
            () => session.RegisterAsync("Simon", "secret1"));

        Assert.Equal("username_taken", error.Code);
        Assert.False(session.IsAuthenticated);
        Assert.Empty(credentials.Secrets);
    }

    [Fact]
    public async Task SignIn_NetworkFailureDoesNotCreateOrPersistSession()
    {
        var credentials = new MemoryCredentialStore();
        using var http = Client((_, _) => throw new HttpRequestException("offline"));
        var session = new DeskBoxAuthSession(credentials, http);

        await Assert.ThrowsAsync<HttpRequestException>(() => session.SignInAsync("Simon", "secret1"));

        Assert.False(session.IsAuthenticated);
        Assert.Empty(credentials.Secrets);
    }

    [Fact]
    public async Task Reauthentication_PersistenceFailureKeepsPreviousMemoryAndVaultSession()
    {
        var credentials = new MemoryCredentialStore();
        int response = 0;
        using var http = Client((_, _) => ++response == 1
            ? Json(HttpStatusCode.Created, SessionJson("user-1", "Simon", "access-1", "refresh-1"))
            : Json(HttpStatusCode.OK, SessionJson("user-2", "Alex", "access-2", "refresh-2")));
        var session = new DeskBoxAuthSession(credentials, http);
        await session.RegisterAsync("Simon", "secret1");
        string originalSecret = Assert.Single(credentials.Secrets).Value;
        credentials.FailSet = true;

        await Assert.ThrowsAsync<IOException>(() => session.SignInAsync("Alex", "secret2"));

        Assert.Equal("Simon", session.CurrentUser!.Username);
        Assert.Equal(originalSecret, Assert.Single(credentials.Secrets).Value);
        Assert.Equal(0, credentials.RemoveCalls);
    }

    [Fact]
    public async Task Refresh_PersistenceFailureInvalidatesRotatedLocalSession()
    {
        var credentials = new MemoryCredentialStore();
        await credentials.SetSecretAsync(
            DeskBoxAuthSession.SessionCredentialKey,
            """
            {"user":{"id":"user-1","username":"Simon"},"accessToken":"expired","refreshToken":"refresh-1","accessExpiresAt":"2026-10-14T00:00:00Z","refreshExpiresAt":"2026-11-14T00:00:00Z"}
            """);
        int calls = 0;
        using var http = Client((request, _) =>
        {
            if (request.RequestUri!.AbsolutePath.EndsWith("/me.php", StringComparison.Ordinal))
            {
                return Json(HttpStatusCode.Unauthorized, "{\"code\":\"invalid_session\",\"message\":\"Expired.\"}");
            }
            calls++;
            credentials.FailSet = true;
            return Json(HttpStatusCode.OK, SessionJson("user-1", "Simon", "access-2", "refresh-2"));
        });
        var session = new DeskBoxAuthSession(credentials, http, () => new DateTimeOffset(2026, 10, 14, 0, 0, 0, TimeSpan.Zero));

        AuthApiException error = await Assert.ThrowsAsync<AuthApiException>(() => session.RestoreAsync());

        Assert.Equal("invalid_session", error.Code);
        Assert.Equal(1, calls);
        Assert.False(session.IsAuthenticated);
        Assert.Empty(credentials.Secrets);
        Assert.Equal(1, credentials.RemoveCalls);
    }

    private static string SessionJson(string id, string username, string access, string refresh) =>
        "{\"user\":{\"id\":\"" + id + "\",\"username\":\"" + username +
        "\"},\"session\":{\"access_token\":\"" + access + "\",\"refresh_token\":\"" + refresh +
        "\",\"access_expires_at\":\"2026-10-15T00:00:00Z\",\"refresh_expires_at\":\"2026-11-14T00:00:00Z\"}}";

    private static HttpResponseMessage Json(HttpStatusCode status, string body) => new(status)
    {
        Content = new StringContent(body, Encoding.UTF8, "application/json")
    };

    private static HttpClient Client(Func<HttpRequestMessage, CancellationToken, HttpResponseMessage> handler) =>
        new(new Handler(handler)) { BaseAddress = new Uri(DeskBoxAuthSession.DefaultApiBaseUrl) };

    private sealed class Handler(Func<HttpRequestMessage, CancellationToken, HttpResponseMessage> callback) : HttpMessageHandler
    {
        protected override Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken) =>
            Task.FromResult(callback(request, cancellationToken));
    }

    private sealed class MemoryCredentialStore : ICredentialStore
    {
        internal Dictionary<string, string> Secrets { get; } = new(StringComparer.Ordinal);
        internal bool FailSet { get; set; }
        internal int RemoveCalls { get; private set; }
        public Task<string?> GetSecretAsync(string key, CancellationToken cancellationToken = default) =>
            Task.FromResult(Secrets.TryGetValue(key, out string? value) ? value : null);
        public Task SetSecretAsync(string key, string secret, CancellationToken cancellationToken = default)
        {
            if (FailSet)
            {
                throw new IOException("Credential Locker write failed.");
            }
            Secrets[key] = secret;
            return Task.CompletedTask;
        }
        public Task RemoveSecretAsync(string key, CancellationToken cancellationToken = default)
        {
            RemoveCalls++;
            Secrets.Remove(key);
            return Task.CompletedTask;
        }
        public Task<IReadOnlyList<string>> ListKeysAsync(CancellationToken cancellationToken = default) =>
            Task.FromResult<IReadOnlyList<string>>(Secrets.Keys.ToArray());
    }
}
