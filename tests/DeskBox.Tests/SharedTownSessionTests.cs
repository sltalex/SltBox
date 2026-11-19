using System.Net;
using System.Text;
using System.Text.Json;
using DeskBox.Contracts;
using DeskBox.Services;

namespace DeskBox.Tests;

public sealed class SharedTownSessionTests
{
    private const string AssetHash = "0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef";

    [Fact]
    public async Task ResidentReadsUseAuthenticatedGetAndKeepSessionIdentity()
    {
        var api = new FakeApi();
        using var session = new SharedTownSession(new FakeAuth(new AuthUser("account-a", "A")), api);
        Assert.True(await session.LoadWorldAsync());
        string id = new('a', 32);
        using JsonDocument detail = JsonDocument.Parse((await session.LoadResidentDetailAsync(id, session.Epoch))!);
        using JsonDocument history = JsonDocument.Parse((await session.LoadResidentConversationsAsync(id, 123, session.Epoch))!);
        Assert.Equal("account-a", detail.RootElement.GetProperty("account_id").GetString());
        Assert.Equal(session.Epoch, history.RootElement.GetProperty("session_epoch").GetInt64());
        Assert.Contains(api.Paths, path => path.EndsWith($"/npcs/detail.php?npc_id={id}", StringComparison.Ordinal));
        Assert.Contains(api.Paths, path => path.EndsWith($"/npcs/conversations.php?npc_id={id}&cursor=123&limit=30", StringComparison.Ordinal));
        Assert.All(api.Requests.Where(r => r.Path.Contains("/npcs/", StringComparison.Ordinal)), r => Assert.Empty(r.Body));
    }

    [Fact]
    public async Task IdempotentWorldReadRetriesOneTransientFailure()
    {
        var api = new FakeApi { FailFirstWorldRead = true };
        using var session = new SharedTownSession(new FakeAuth(new AuthUser("account-a", "A")), api);

        Assert.True(await session.LoadWorldAsync());
        Assert.Equal(2, api.Paths.Count(path => path.EndsWith("/v2/town/world.php", StringComparison.Ordinal)));
    }

    [Fact]
    public async Task ResidentResponseFromPreviousAccountIsDiscarded()
    {
        var completion = new TaskCompletionSource<HttpResponseMessage>(TaskCreationOptions.RunContinuationsAsynchronously);
        var api = new FakeApi { ResidentResponse = completion.Task };
        var auth = new FakeAuth(new AuthUser("account-a", "A"));
        using var session = new SharedTownSession(auth, api);
        Assert.True(await session.LoadWorldAsync());
        long epoch = session.Epoch;
        Task<string?> pending = session.LoadResidentDetailAsync(new string('a', 32), epoch);
        auth.Switch(new AuthUser("account-b", "B"));
        completion.SetResult(new HttpResponseMessage(HttpStatusCode.OK) { Content = new StringContent("{\"resident\":{\"name\":\"old account\"}}") });
        Assert.Null(await pending);
        Assert.Null(await session.LoadResidentConversationsAsync(new string('a', 32), 0, epoch));
    }

    [Fact]
    public async Task ResidentReadFailureIsRecoverableWithoutChangingWorldState()
    {
        var api = new FakeApi { ResidentResponse = Task.FromResult(new HttpResponseMessage(HttpStatusCode.Forbidden) { Content = new StringContent("{\"code\":\"town_not_open\"}") }) };
        using var session = new SharedTownSession(new FakeAuth(new AuthUser("account-a", "A")), api);
        Assert.True(await session.LoadWorldAsync());
        string world = session.WorldJson();
        using JsonDocument response = JsonDocument.Parse((await session.LoadResidentDetailAsync(new string('a', 32), session.Epoch))!);
        Assert.Equal("resident_unavailable", response.RootElement.GetProperty("code").GetString());
        Assert.Equal(world, session.WorldJson());
    }

    [Fact]
    public async Task ResidentPhotoUsesAuthenticatedMultipartAndKeepsSessionIdentity()
    {
        var api = new FakeApi();
        using var session = new SharedTownSession(new FakeAuth(new AuthUser("account-a", "A")), api);
        Assert.True(await session.LoadWorldAsync());

        string id = new('b', 32);
        byte[] image = Enumerable.Repeat((byte)'a', 4 * 1024 * 1024).ToArray();
        Encoding.UTF8.GetBytes("image-data").CopyTo(image, 0);
        using JsonDocument response = JsonDocument.Parse(await session.UploadResidentPhotoAsync(
            id, null, "sample.png", "image/png", image, session.Epoch));

        Assert.Equal(session.Epoch, response.RootElement.GetProperty("session_epoch").GetInt64());
        Request request = Assert.Single(api.Requests.Where(r => r.Path.EndsWith("/npcs/conversations/photo.php", StringComparison.Ordinal)));
        Assert.Contains("npc_id", request.Body, StringComparison.Ordinal);
        Assert.Contains("sample.png", request.Body, StringComparison.Ordinal);
        Assert.Contains("Content-Type: image/png", request.Body, StringComparison.Ordinal);
        Assert.Contains("image-data", request.Body, StringComparison.Ordinal);
        Assert.True(request.Body.Length > 4 * 1024 * 1024, "The full 4 MiB payload must reach multipart serialization.");
    }

    [Theory]
    [InlineData("text/plain", 16)]
    [InlineData("image/png", 4194305)]
    public async Task InvalidResidentPhotosDoNotReachTheServer(string mediaType, int byteCount)
    {
        var api = new FakeApi();
        using var session = new SharedTownSession(new FakeAuth(new AuthUser("account-a", "A")), api);
        Assert.True(await session.LoadWorldAsync());

        await Assert.ThrowsAsync<InvalidDataException>(() => session.UploadResidentPhotoAsync(
            new string('c', 32), null, "sample.bin", mediaType, new byte[byteCount], session.Epoch));

        Assert.DoesNotContain(api.Paths, path => path.EndsWith("/npcs/conversations/photo.php", StringComparison.Ordinal));
    }

    [Theory]
    [InlineData("bad", 0)]
    [InlineData("AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA", 0)]
    [InlineData("aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa", -1)]
    public async Task InvalidResidentQueriesDoNotReachTheServer(string id, long cursor)
    {
        var api = new FakeApi();
        using var session = new SharedTownSession(new FakeAuth(new AuthUser("account-a", "A")), api);
        Assert.True(await session.LoadWorldAsync());
        await Assert.ThrowsAsync<InvalidDataException>(() => session.LoadResidentConversationsAsync(id, cursor, session.Epoch));
        Assert.DoesNotContain(api.Paths, path => path.Contains("/npcs/", StringComparison.Ordinal));
    }

    [Fact]
    public async Task NestedWorldResponseIsNormalizedAndInventoryIsLoaded()
    {
        var api = new FakeApi();
        var auth = new FakeAuth(new AuthUser("account-a", "A"));
        using var session = new SharedTownSession(auth, api);

        Assert.True(await session.LoadWorldAsync());
        using JsonDocument world = JsonDocument.Parse(session.WorldJson());
        Assert.True(world.RootElement.GetProperty("enabled").GetBoolean());
        Assert.Equal(768, world.RootElement.GetProperty("map_width").GetInt32());
        Assert.Equal(4808.25, world.RootElement.GetProperty("player").GetProperty("pixel_x").GetDouble());
        using JsonDocument bridge = JsonDocument.Parse(session.BridgeJson());
        Assert.Equal("account-a", bridge.RootElement.GetProperty("account_id").GetString());
        Assert.Equal(2, bridge.RootElement.GetProperty("inventory").GetProperty("items").GetArrayLength());
        Assert.Contains(api.Paths, path => path.EndsWith("/v2/town/world.php", StringComparison.Ordinal));
        Assert.Contains(api.Paths, path => path.EndsWith("/tree/decorations/state.php", StringComparison.Ordinal));
    }

    [Fact]
    public async Task ViewportLoadsOnlyRequestedChunksAndActionsGenerateOperationId()
    {
        var api = new FakeApi();
        using var session = new SharedTownSession(new FakeAuth(new AuthUser("account-a", "A")), api);
        Assert.True(await session.LoadWorldAsync());

        Assert.True(await session.LoadChunksAsync(
            "{\"chunks\":[{\"chunk_x\":0,\"chunk_y\":1},{\"chunk_x\":1,\"chunk_y\":1}]}", session.Epoch));
        using JsonDocument chunks = JsonDocument.Parse(session.ChunksJson());
        Assert.Equal(2, chunks.RootElement.GetProperty("chunks").GetArrayLength());

        await session.ExecuteActionAsync("{\"action\":\"place\",\"asset_id\":\"flora-fern\",\"tile_x\":2,\"tile_y\":2}", session.Epoch);
        string command = api.Requests.Single(value => value.Path.EndsWith("/v2/town/actions.php", StringComparison.Ordinal)).Body;
        using JsonDocument action = JsonDocument.Parse(command);
        Assert.Equal("place", action.RootElement.GetProperty("action").GetString());
        Assert.False(string.IsNullOrWhiteSpace(action.RootElement.GetProperty("operation_id").GetString()));
    }

    [Fact]
    public async Task ExpiredSessionStopsRepeatedChunkRequests()
    {
        var api = new FakeApi { ChunkResponse = Task.FromResult(new HttpResponseMessage(HttpStatusCode.Unauthorized)
        {
            Content = new StringContent("{\"code\":\"invalid_session\"}")
        }) };
        using var session = new SharedTownSession(new FakeAuth(new AuthUser("account-a", "A")), api);
        Assert.True(await session.LoadWorldAsync());

        Assert.False(await session.LoadChunksAsync("{\"chunk_x\":0,\"chunk_y\":0}", session.Epoch));
        Assert.False(session.IsEnabled);
        using (JsonDocument bridge = JsonDocument.Parse(session.BridgeJson()))
            Assert.Contains("登录已过期", bridge.RootElement.GetProperty("status").GetString(), StringComparison.Ordinal);
        Assert.False(await session.LoadChunksAsync("{\"chunk_x\":1,\"chunk_y\":0}", session.Epoch));
        Assert.Single(api.Paths.Where(path => path.Contains("/v2/town/chunks.php", StringComparison.Ordinal)));
    }

    [Fact]
    public async Task AccountSwitchClearsWorldAndRejectsOldEpoch()
    {
        var auth = new FakeAuth(new AuthUser("account-a", "A"));
        using var session = new SharedTownSession(auth, new FakeApi());
        Assert.True(await session.LoadWorldAsync());
        long oldEpoch = session.Epoch;
        auth.Switch(new AuthUser("account-b", "B"));
        Assert.NotEqual(oldEpoch, session.Epoch);
        Assert.False(await session.LoadChunksAsync("{\"chunk_x\":0,\"chunk_y\":0}", oldEpoch));
    }

    [Fact]
    public async Task WideViewportBatchesAllChunksWithoutDroppingTheRemainder()
    {
        var api = new FakeApi();
        using var session = new SharedTownSession(new FakeAuth(new AuthUser("account-a", "A")), api);
        Assert.True(await session.LoadWorldAsync());
        var chunks = Enumerable.Range(0, 25).Select(i => new { chunk_x = i % 5, chunk_y = i / 5 });
        Assert.True(await session.LoadChunksAsync(JsonSerializer.Serialize(new { chunks }), session.Epoch));
        using var loaded = JsonDocument.Parse(session.ChunksJson());
        Assert.Equal(25, loaded.RootElement.GetProperty("chunks").GetArrayLength());
        Assert.Equal(2, api.Paths.Count(path => path.Contains("/v2/town/chunks.php?", StringComparison.Ordinal)));
    }

    [Fact]
    public async Task ConflictResponseKeepsSessionIdentityForSafeRollback()
    {
        var api = new FakeApi { FailActions = true };
        using var session = new SharedTownSession(new FakeAuth(new AuthUser("account-a", "A")), api);
        Assert.True(await session.LoadWorldAsync());
        using var result = JsonDocument.Parse(await session.ExecuteActionAsync(
            "{\"action\":\"delete\",\"object_id\":\"old-object\"}", session.Epoch));
        Assert.Equal("object_conflict", result.RootElement.GetProperty("code").GetString());
        Assert.Equal(session.Epoch, result.RootElement.GetProperty("session_epoch").GetInt64());
    }

    [Fact]
    public async Task ConcurrentCatalogLoadsShareOneAuthenticatedRequest()
    {
        var completion = new TaskCompletionSource<HttpResponseMessage>(TaskCreationOptions.RunContinuationsAsynchronously);
        var api = new FakeApi { CatalogResponse = completion.Task };
        using var session = new SharedTownSession(new FakeAuth(new AuthUser("account-a", "A")), api);
        Assert.True(await session.LoadWorldAsync());
        Task<bool> first = session.LoadAssetCatalogAsync(session.Epoch);
        Task<bool> second = session.LoadAssetCatalogAsync(session.Epoch);
        Assert.Same(first, second);
        completion.SetResult(new HttpResponseMessage(HttpStatusCode.OK)
        { Content = new StringContent("{\"release_version\":0,\"sheets\":[],\"items\":[]}") });
        Assert.True(await first);
        Assert.True(await second);
        Assert.Single(api.Paths.Where(path => path.EndsWith("/v2/town/assets/catalog.php", StringComparison.Ordinal)));
    }

    [Fact]
    public async Task MatchingCatalogCacheAvoidsAuthenticatedCatalogRequest()
    {
        string root = Path.Combine(Path.GetTempPath(), "deskbox-town-cache-" + Guid.NewGuid().ToString("N"));
        string catalog = $"{{\"release_version\":7,\"config_sha256\":\"{AssetHash}\",\"sheets\":[],\"items\":[]}}";
        try
        {
            await TownAssetCache.StoreCatalogAsync(root, catalog, 7, AssetHash, CancellationToken.None);
            var api = new FakeApi { AssetReleaseHash = AssetHash, AssetReleaseVersion = 7 };
            using var session = new SharedTownSession(new FakeAuth(new AuthUser("account-a", "A")), api);
            Assert.True(await session.LoadWorldAsync());
            Assert.True(await session.LoadAssetCatalogAsync(session.Epoch, root));
            Assert.DoesNotContain(api.Paths, path => path.EndsWith("/v2/town/assets/catalog.php", StringComparison.Ordinal));
            Assert.Contains(AssetHash, session.AssetCatalogJson(), StringComparison.OrdinalIgnoreCase);
        }
        finally { if (Directory.Exists(root)) Directory.Delete(root, true); }
    }

    [Fact]
    public async Task ChangedCatalogReleaseFetchesAndStoresNewMetadata()
    {
        string root = Path.Combine(Path.GetTempPath(), "deskbox-town-cache-" + Guid.NewGuid().ToString("N"));
        string oldHash = new string('a', 64);
        string newHash = new string('b', 64);
        string oldCatalog = $"{{\"release_version\":6,\"config_sha256\":\"{oldHash}\",\"sheets\":[],\"items\":[]}}";
        try
        {
            await TownAssetCache.StoreCatalogAsync(root, oldCatalog, 6, oldHash, CancellationToken.None);
            var api = new FakeApi
            {
                AssetReleaseHash = newHash,
                AssetReleaseVersion = 7,
                CatalogResponse = Task.FromResult(new HttpResponseMessage(HttpStatusCode.OK)
                {
                    Content = new StringContent($"{{\"release_version\":7,\"config_sha256\":\"{newHash}\",\"sheets\":[],\"items\":[]}}")
                })
            };
            using var session = new SharedTownSession(new FakeAuth(new AuthUser("account-a", "A")), api);
            Assert.True(await session.LoadWorldAsync());
            Assert.True(await session.LoadAssetCatalogAsync(session.Epoch, root));
            Assert.Single(api.Paths.Where(path => path.EndsWith("/v2/town/assets/catalog.php", StringComparison.Ordinal)));
            Assert.True(File.Exists(Path.Combine(root, $"catalog-7-{newHash}.json")));
        }
        finally { if (Directory.Exists(root)) Directory.Delete(root, true); }
    }

    [Fact]
    public async Task CatalogCacheWriteFailureDoesNotHideRemoteCatalog()
    {
        string cachePath = Path.Combine(Path.GetTempPath(), "deskbox-town-cache-file-" + Guid.NewGuid().ToString("N"));
        string hash = new('c', 64);
        string catalog = $"{{\"release_version\":7,\"config_sha256\":\"{hash}\",\"sheets\":[],\"items\":[]}}";
        try
        {
            // A file at the cache root makes directory creation fail while the
            // authenticated catalog request itself remains valid.
            await File.WriteAllTextAsync(cachePath, "cache-root-is-not-a-directory");
            var api = new FakeApi
            {
                AssetReleaseHash = hash,
                AssetReleaseVersion = 7,
                CatalogResponse = Task.FromResult(new HttpResponseMessage(HttpStatusCode.OK)
                {
                    Content = new StringContent(catalog)
                })
            };
            using var session = new SharedTownSession(new FakeAuth(new AuthUser("account-a", "A")), api);
            Assert.True(await session.LoadWorldAsync());
            Assert.True(await session.LoadAssetCatalogAsync(session.Epoch, cachePath));
            Assert.Contains(hash, session.AssetCatalogJson(), StringComparison.OrdinalIgnoreCase);
            Assert.Single(api.Paths.Where(path => path.EndsWith("/v2/town/assets/catalog.php", StringComparison.Ordinal)));
        }
        finally
        {
            if (File.Exists(cachePath)) File.Delete(cachePath);
        }
    }

    [Fact]
    public async Task ActionDoesNotSkipChangesThatPrecedeItsResponseCursor()
    {
        var api = new FakeApi();
        using var session = new SharedTownSession(new FakeAuth(new AuthUser("account-a", "A")), api);
        Assert.True(await session.LoadWorldAsync());
        Assert.True(await session.LoadChunksAsync("{\"chunks\":[{\"chunk_x\":0,\"chunk_y\":0}]}", session.Epoch));

        await session.ExecuteActionAsync(
            "{\"action\":\"place\",\"asset_id\":\"flora-fern\",\"tile_x\":2,\"tile_y\":2}",
            session.Epoch);
        Assert.Equal(0, session.Cursor);

        await session.LoadChangesAsync(session.Epoch);
        Assert.Equal(7, session.Cursor);
        Assert.Contains(api.Paths, path => path.Contains("/v2/town/changes.php?cursor=0&chunks=0%3A0", StringComparison.Ordinal));
    }

    [Fact]
    public async Task PresenceWritesAreSerializedSoFinalPositionCannotBeOvertaken()
    {
        var completion = new TaskCompletionSource<HttpResponseMessage>(TaskCreationOptions.RunContinuationsAsynchronously);
        var api = new FakeApi { PresenceResponse = completion.Task };
        using var session = new SharedTownSession(new FakeAuth(new AuthUser("account-a", "A")), api);
        Assert.True(await session.LoadWorldAsync());
        Task<string> first = session.SendPresenceHeartbeatAsync("{\"tile_x\":300,\"tile_y\":300,\"pixel_x\":4808.25,\"pixel_y\":4815.5}", session.Epoch);
        Task<string> final = session.SendPresenceHeartbeatAsync("{\"tile_x\":301,\"tile_y\":300,\"pixel_x\":4824.5,\"pixel_y\":4815.5}", session.Epoch);
        Assert.Single(api.Requests.Where(value => value.Path.EndsWith("/presence.php", StringComparison.Ordinal)));
        completion.SetResult(new HttpResponseMessage(HttpStatusCode.OK) { Content = new StringContent("{\"ok\":true}") });
        await Task.WhenAll(first, final);
        Request[] writes = api.Requests.Where(value => value.Path.EndsWith("/presence.php", StringComparison.Ordinal)).ToArray();
        Assert.Equal(2, writes.Length);
        using JsonDocument last = JsonDocument.Parse(writes[1].Body);
        Assert.Equal(4824.5, last.RootElement.GetProperty("pixel_x").GetDouble());
    }

    private sealed class FakeApi : IAuthenticatedAccountApi
    {
        internal bool FailActions;
        internal bool FailFirstWorldRead;
        internal Task<HttpResponseMessage>? CatalogResponse;
        internal Task<HttpResponseMessage>? PresenceResponse;
        internal Task<HttpResponseMessage>? ResidentResponse;
        internal Task<HttpResponseMessage>? ChunkResponse;
        internal long AssetReleaseVersion;
        internal string? AssetReleaseHash;
        internal readonly List<string> Paths = [];
        internal readonly List<Request> Requests = [];

        public Task<HttpResponseMessage> SendAsync(HttpMethod method, string path, byte[]? jsonBody,
            string? contentEncoding, CancellationToken cancellationToken = default)
        {
            Paths.Add(path);
            string body = jsonBody is null ? string.Empty : Encoding.UTF8.GetString(jsonBody);
            Requests.Add(new Request(path, body));
            if (FailFirstWorldRead && path.EndsWith("/v2/town/world.php", StringComparison.Ordinal))
            {
                FailFirstWorldRead = false;
                throw new HttpRequestException("transient test failure");
            }
            if (path.Contains("/npcs/", StringComparison.Ordinal) && ResidentResponse is not null)
                return ResidentResponse;
            if (PresenceResponse is not null && path.EndsWith("/presence.php", StringComparison.Ordinal))
            {
                Task<HttpResponseMessage> response = PresenceResponse;
                PresenceResponse = null;
                return response;
            }
            if (CatalogResponse is not null && path.EndsWith("/v2/town/assets/catalog.php", StringComparison.Ordinal))
                return CatalogResponse;
            if (path.EndsWith("/v2/town/world.php", StringComparison.Ordinal))
                return Task.FromResult(Json($"{{\"ok\":true,\"world\":{{\"world_id\":\"main\",\"world_version\":2,\"map_width\":768,\"map_height\":768,\"tile_size\":16,\"chunk_size\":24}},\"asset_release\":{{\"release_version\":{AssetReleaseVersion},\"config_sha256\":{(AssetReleaseHash is null ? "null" : $"\"{AssetReleaseHash}\"")}}},\"player\":{{\"pixel_x\":4808.25,\"pixel_y\":4815.5,\"tile_x\":300,\"tile_y\":300}},\"catalog\":[]}}"));
            if (path.EndsWith("/tree/decorations/state.php", StringComparison.Ordinal))
                return Task.FromResult(Json("{\"inventory\":[{\"item_id\":\"flora-fern\",\"quantity\":2},{\"item_id\":\"prop-lamp\",\"quantity\":1}]}"));
            if (path.Contains("/v2/town/chunks.php", StringComparison.Ordinal))
            {
                if (ChunkResponse is not null)
                {
                    Task<HttpResponseMessage> response = ChunkResponse;
                    ChunkResponse = null;
                    return response;
                }
                string pairs = Uri.UnescapeDataString(path.Split("chunks=")[1]);
                string chunks = string.Join(',', pairs.Split(',').Select(pair => {
                    string[] xy = pair.Split(':');
                    return $"{{\"chunk_x\":{xy[0]},\"chunk_y\":{xy[1]},\"terrain\":[],\"objects\":[]}}";
                }));
                return Task.FromResult(Json($"{{\"ok\":true,\"world\":{{\"world_version\":2}},\"chunks\":[{chunks}]}}"));
            }
            if (path.Contains("/v2/town/changes.php", StringComparison.Ordinal))
                return Task.FromResult(Json("{\"ok\":true,\"next_cursor\":7,\"changes\":[]}"));
            if (path.EndsWith("/v2/town/actions.php", StringComparison.Ordinal))
                return Task.FromResult(FailActions
                    ? new HttpResponseMessage(HttpStatusCode.Conflict) { Content = new StringContent("{\"code\":\"object_conflict\"}") }
                    : Json("{\"ok\":true,\"action\":\"place\",\"change_cursor\":3,\"object\":{}}"));
            return Task.FromResult(Json("{\"ok\":true}"));
        }

        private static HttpResponseMessage Json(string body) => new(HttpStatusCode.OK)
        { Content = new StringContent(body, Encoding.UTF8, "application/json") };
    }

    private sealed record Request(string Path, string Body);

    private sealed class FakeAuth(AuthUser? user) : IAuthSession
    {
        public event Action? SessionChanged;
        public bool IsAuthenticated => CurrentUser is not null;
        public AuthUser? CurrentUser { get; private set; } = user;
        internal void Switch(AuthUser? next) { CurrentUser = next; SessionChanged?.Invoke(); }
        public Task<AuthUser> RegisterAsync(string username, string password, CancellationToken token = default) => throw new NotSupportedException();
        public Task<AuthUser> SignInAsync(string username, string password, CancellationToken token = default) => throw new NotSupportedException();
        public Task RefreshAsync(CancellationToken token = default) => Task.CompletedTask;
        public Task RestoreAsync(CancellationToken token = default) => Task.CompletedTask;
        public Task SignOutAsync(CancellationToken token = default) { Switch(null); return Task.CompletedTask; }
    }
}
