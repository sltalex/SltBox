using System.Net;
using System.Text;
using System.Text.Json;
using DeskBox.Contracts;
using DeskBox.Services;

namespace DeskBox.Tests;

public sealed class DingTalkGroupDocumentCloudTests : IDisposable
{
    private readonly string _root = Path.Combine(Path.GetTempPath(), "DeskBox.Tests", Guid.NewGuid().ToString("N"));

    [Fact]
    public async Task NewComputerLoadsSavedIndexBeforeDwsAuthorizationAndKeepsAccountsSeparate()
    {
        var auth = new FakeAuth();
        var api = new FakeApi(auth);
        var dws = new FakeDws { TokenValid = false };
        var document = new DingTalkGroupDocument("项目群", "预算.xlsx",
            "https://alidocs.dingtalk.com/i/nodes/budget", DateTimeOffset.UtcNow, "group-a");
        api.Documents["first"] = new DingTalkGroupDocumentCloudSnapshot
        {
            Documents = [document], SyncIntervalHours = 12,
            LastSyncCompletedAt = DateTimeOffset.UtcNow
        };
        var service = new DingTalkGroupDocumentService(dws, Path.Combine(_root, "index"),
            new DingTalkGroupDocumentCloudStore(api, auth), auth);

        Assert.Equal(document, Assert.Single(await service.LoadAsync()));
        Assert.Equal(12, service.SyncIntervalHours);
        dws.TokenValid = true;
        Assert.Equal(document, Assert.Single(await service.LoadAsync()));

        auth.Switch("second");
        Assert.Empty(await service.LoadAsync());
        Assert.Equal(6, service.SyncIntervalHours);
        auth.Switch("first");
        Assert.Equal(document, Assert.Single(await service.LoadAsync()));
        Assert.Equal(12, service.SyncIntervalHours);
    }

    [Fact]
    public async Task FavoritesSyncKeepsOfflineEditsAndDoesNotRestoreRemovedStars()
    {
        var auth = new FakeAuth();
        var api = new FakeApi(auth);
        var dws = new FakeDws { TokenValid = false };
        var starred = new DingTalkGroupDocument("项目群", "原收藏",
            "https://alidocs.dingtalk.com/i/nodes/old", DateTimeOffset.UtcNow, "group-a");
        var other = new DingTalkGroupDocument("其他群", "其他设备收藏",
            "https://alidocs.dingtalk.com/i/nodes/other", DateTimeOffset.UtcNow, "group-b");
        api.Favorites["first"] = new DingTalkFavoritesSyncPayload { Documents = [starred] };
        using var favorites = new DingTalkGroupDocumentFavorites(dws,
            Path.Combine(_root, "favorites"), auth: auth);
        var sync = new DingTalkGroupDocumentFavoritesCloudSyncService(api, auth, favorites,
            Path.Combine(_root, "cloud"));

        Assert.True(await sync.SyncNowAsync());
        Assert.True(favorites.IsFavorite(starred));
        await sync.SetFavoriteAndSyncAsync(starred, false);
        Assert.Empty(api.Favorites["first"].Documents);

        api.Favorites["first"] = new DingTalkFavoritesSyncPayload { Documents = [other] };
        api.Offline = true;
        await sync.AddManualAndSyncAsync("离线文档", "https://example.com/manual");
        api.Offline = false;
        Assert.True(await sync.SyncNowAsync());
        Assert.False(favorites.IsFavorite(starred));
        Assert.Equal(2, favorites.FavoriteDocuments.Count);
        Assert.Equal(2, api.Favorites["first"].Documents.Length);

        auth.Switch("second");
        Assert.True(await sync.SyncNowAsync());
        Assert.Empty(favorites.FavoriteDocuments);
        auth.Switch("first");
        Assert.True(await sync.SyncNowAsync());
        Assert.Equal(2, favorites.FavoriteDocuments.Count);
    }

    public void Dispose()
    {
        if (Directory.Exists(_root)) Directory.Delete(_root, recursive: true);
    }

    private sealed class FakeAuth : IAuthSession
    {
        public event Action? SessionChanged;
        public AuthUser? CurrentUser { get; private set; } = new("first", "first");
        public bool IsAuthenticated => CurrentUser is not null;
        public void Switch(string? id)
        {
            CurrentUser = id is null ? null : new(id, id);
            SessionChanged?.Invoke();
        }
        public Task<AuthUser> RegisterAsync(string username, string password, CancellationToken token = default) => throw new NotSupportedException();
        public Task<AuthUser> SignInAsync(string username, string password, CancellationToken token = default) => throw new NotSupportedException();
        public Task RefreshAsync(CancellationToken token = default) => Task.CompletedTask;
        public Task RestoreAsync(CancellationToken token = default) => Task.CompletedTask;
        public Task SignOutAsync(CancellationToken token = default) { Switch(null); return Task.CompletedTask; }
    }

    private sealed class FakeApi(FakeAuth auth) : IAuthenticatedAccountApi
    {
        public Dictionary<string, DingTalkGroupDocumentCloudSnapshot> Documents { get; } = [];
        public Dictionary<string, DingTalkFavoritesSyncPayload> Favorites { get; } = [];
        public bool Offline { get; set; }

        public Task<HttpResponseMessage> SendAsync(HttpMethod method, string path, byte[]? body,
            string? encoding, CancellationToken token = default)
        {
            if (Offline) throw new HttpRequestException("Offline");
            string owner = auth.CurrentUser?.Id ?? throw new InvalidOperationException();
            string json;
            if (path == "../dingtalk/documents.php")
            {
                DingTalkGroupDocumentCloudSnapshot current = Documents.GetValueOrDefault(owner) ?? new();
                if (method == HttpMethod.Put)
                {
                    var incoming = JsonSerializer.Deserialize(body!,
                        DingTalkGroupDocumentCloudJsonContext.Default.DingTalkGroupDocumentCloudSnapshot)!;
                    current.Documents = [.. current.Documents.Concat(incoming.Documents)
                        .DistinctBy(DingTalkGroupDocumentFavorites.CanonicalKey)];
                    current.SyncIntervalHours = incoming.SyncIntervalHours ?? current.SyncIntervalHours ?? 6;
                    Documents[owner] = current;
                }
                json = JsonSerializer.Serialize(current,
                    DingTalkGroupDocumentCloudJsonContext.Default.DingTalkGroupDocumentCloudSnapshot);
            }
            else if (path == "../dingtalk/favorites.php")
            {
                if (method == HttpMethod.Put)
                    Favorites[owner] = JsonSerializer.Deserialize(body!,
                        DingTalkFavoritesCloudJsonContext.Default.DingTalkFavoritesSyncPayload)!;
                json = JsonSerializer.Serialize(Favorites.GetValueOrDefault(owner) ?? new(),
                    DingTalkFavoritesCloudJsonContext.Default.DingTalkFavoritesSyncPayload);
            }
            else throw new NotSupportedException(path);
            return Task.FromResult(new HttpResponseMessage(HttpStatusCode.OK)
            {
                Content = new StringContent(json, Encoding.UTF8, "application/json")
            });
        }
    }

    private sealed class FakeDws : IDingTalkDwsClient
    {
        public bool TokenValid { get; set; }
        public Task<DingTalkDwsAuthStatus> GetAuthStatusAsync(CancellationToken token = default) =>
            Task.FromResult(new DingTalkDwsAuthStatus(TokenValid, TokenValid, TokenValid,
                "corp", "Corp", "linked-user", "Linked"));
        public Task<DingTalkProfile> GetCurrentUserAsync(CancellationToken token = default) => throw new NotSupportedException();
        public Task<DingTalkDwsAuthStatus> LoginAsync(CancellationToken token = default) => throw new NotSupportedException();
        public Task<string?> GetDocumentTitleAsync(string url, CancellationToken token = default) => Task.FromResult<string?>(null);
        public Task<IReadOnlyList<DingTalkCalendarEvent>> ListCurrentWeekCalendarAsync(CancellationToken token = default) =>
            Task.FromResult<IReadOnlyList<DingTalkCalendarEvent>>([]);
        public Task<DingTalkGroupMessagePage> ListGroupMessagesAsync(DateTimeOffset start, DateTimeOffset end,
            string? cursor = null, int limit = 50, CancellationToken token = default) =>
            Task.FromResult(new DingTalkGroupMessagePage([], false, null));
    }
}
