using System.Net;
using System.Text;
using System.Text.Json;
using DeskBox.Contracts;
using DeskBox.Services;

namespace DeskBox.Tests;

public sealed class SharedTownSessionTests
{
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
        internal Task<HttpResponseMessage>? CatalogResponse;
        internal Task<HttpResponseMessage>? PresenceResponse;
        internal readonly List<string> Paths = [];
        internal readonly List<Request> Requests = [];

        public Task<HttpResponseMessage> SendAsync(HttpMethod method, string path, byte[]? jsonBody,
            string? contentEncoding, CancellationToken cancellationToken = default)
        {
            Paths.Add(path);
            string body = jsonBody is null ? string.Empty : Encoding.UTF8.GetString(jsonBody);
            Requests.Add(new Request(path, body));
            if (PresenceResponse is not null && path.EndsWith("/presence.php", StringComparison.Ordinal))
            {
                Task<HttpResponseMessage> response = PresenceResponse;
                PresenceResponse = null;
                return response;
            }
            if (CatalogResponse is not null && path.EndsWith("/v2/town/assets/catalog.php", StringComparison.Ordinal))
                return CatalogResponse;
            if (path.EndsWith("/v2/town/world.php", StringComparison.Ordinal))
                return Task.FromResult(Json("{\"ok\":true,\"world\":{\"world_id\":\"main\",\"world_version\":2,\"map_width\":768,\"map_height\":768,\"tile_size\":16,\"chunk_size\":24},\"player\":{\"pixel_x\":4808.25,\"pixel_y\":4815.5,\"tile_x\":300,\"tile_y\":300},\"catalog\":[]}"));
            if (path.EndsWith("/tree/decorations/state.php", StringComparison.Ordinal))
                return Task.FromResult(Json("{\"inventory\":[{\"item_id\":\"flora-fern\",\"quantity\":2},{\"item_id\":\"prop-lamp\",\"quantity\":1}]}"));
            if (path.Contains("/v2/town/chunks.php", StringComparison.Ordinal))
            {
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
