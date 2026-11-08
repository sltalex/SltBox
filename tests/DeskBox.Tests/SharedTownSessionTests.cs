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
        Assert.Equal(192, world.RootElement.GetProperty("map_width").GetInt32());
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

    private sealed class FakeApi : IAuthenticatedAccountApi
    {
        internal readonly List<string> Paths = [];
        internal readonly List<Request> Requests = [];

        public Task<HttpResponseMessage> SendAsync(HttpMethod method, string path, byte[]? jsonBody,
            string? contentEncoding, CancellationToken cancellationToken = default)
        {
            Paths.Add(path);
            string body = jsonBody is null ? string.Empty : Encoding.UTF8.GetString(jsonBody);
            Requests.Add(new Request(path, body));
            if (path.EndsWith("/v2/town/world.php", StringComparison.Ordinal))
                return Task.FromResult(Json("{\"ok\":true,\"world\":{\"world_id\":\"main\",\"world_version\":2,\"map_width\":192,\"map_height\":192,\"tile_size\":16,\"chunk_size\":24},\"catalog\":[]}"));
            if (path.EndsWith("/tree/decorations/state.php", StringComparison.Ordinal))
                return Task.FromResult(Json("{\"inventory\":[{\"item_id\":\"flora-fern\",\"quantity\":2},{\"item_id\":\"prop-lamp\",\"quantity\":1}]}"));
            if (path.Contains("/v2/town/chunks.php", StringComparison.Ordinal))
            {
                string[] query = path.Split('?', 2)[1].Split('&');
                string x = query.Single(value => value.StartsWith("chunk_x=", StringComparison.Ordinal))[8..];
                string y = query.Single(value => value.StartsWith("chunk_y=", StringComparison.Ordinal))[8..];
                return Task.FromResult(Json($"{{\"ok\":true,\"world\":{{\"world_version\":2}},\"chunks\":[{{\"chunk_x\":{x},\"chunk_y\":{y},\"terrain\":[],\"objects\":[]}}]}}"));
            }
            if (path.Contains("/v2/town/changes.php", StringComparison.Ordinal))
                return Task.FromResult(Json("{\"ok\":true,\"next_cursor\":7,\"changes\":[]}"));
            if (path.EndsWith("/v2/town/actions.php", StringComparison.Ordinal))
                return Task.FromResult(Json("{\"ok\":true,\"action\":\"place\",\"change_cursor\":3,\"object\":{}}"));
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
