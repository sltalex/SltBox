using System.Net;
using System.Text;
using System.Text.Json;
using DeskBox.Contracts;
using DeskBox.Services;

namespace DeskBox.Tests;

public sealed class TreeDecorationServiceTests : IDisposable
{
    private const string PackId = "11111111111111111111111111111111";
    private readonly string _root = Path.Combine(Path.GetTempPath(), "DeskBoxTreeDecor", Guid.NewGuid().ToString("N"));

    [Fact]
    public async Task LostOpenResponseReplaysExactCommandWithoutSecondReward()
    {
        var auth = new FakeAuth();
        var api = new FakeApi { LoseNextMutationResponse = true };
        byte[] firstCommand;
        await using (var first = new TreeDecorationService(auth, api, _root))
        {
            Assert.True(await first.RefreshAsync());
            Assert.False(await first.OpenPackAsync(PackId));
            Assert.True(first.HasPendingOperation);
            firstCommand = Assert.Single(api.Commands);
        }

        await using var restored = new TreeDecorationService(auth, api, _root);
        Assert.True(await restored.RefreshAsync());
        Assert.Equal(firstCommand, api.Commands.Last());
        Assert.Equal(1, api.CommittedMutations);
        Assert.Equal(1000, restored.State!.Coins);
        Assert.Single(restored.LastDrawResults);
        Assert.Empty(restored.State.CardPacks);
        Assert.False(restored.HasPendingOperation);
    }

    [Fact]
    public async Task BuyPackSendsCatalogVersionAndRequestedDrawCount()
    {
        var api = new FakeApi();
        await using var service = new TreeDecorationService(new FakeAuth(), api, _root);
        Assert.True(await service.RefreshAsync());

        Assert.True(await service.BuyPackAsync(10));

        using JsonDocument command = JsonDocument.Parse(Assert.Single(api.Commands));
        Assert.Equal("buy_pack", command.RootElement.GetProperty("action").GetString());
        Assert.Equal(10, command.RootElement.GetProperty("draw_count").GetInt32());
        Assert.Equal(2, command.RootElement.GetProperty("catalog_version").GetInt32());
    }

    [Fact]
    public async Task SceneConflictKeepsDraftUntilUserClearsIt()
    {
        var api = new FakeApi { RejectSceneAsConflict = true };
        await using var service = new TreeDecorationService(new FakeAuth(), api, _root);
        Assert.True(await service.RefreshAsync());
        var draft = new[] { new TreeDecorationPlacement("red-lantern", .4, .3, 1, 12, 1) };

        Assert.False(await service.SaveSceneAsync(draft, null, 0));
        Assert.Equal("scene_conflict", service.ErrorCode);
        Assert.Equal(draft, service.PendingScene);

        Assert.True(await service.ClearConflictDraftAsync());
        Assert.Null(service.PendingScene);
    }

    [Fact]
    public async Task SceneConflictAcceptsAuthoritativeStateFromConflictResponse()
    {
        var api = new FakeApi { RejectSceneAsConflict = true, ConflictIncludesState = true };
        await using var service = new TreeDecorationService(new FakeAuth(), api, _root);
        Assert.True(await service.RefreshAsync());

        Assert.False(await service.SaveSceneAsync(
            [new("red-lantern", .4, .3, 1, 12, 1)], null, 0));

        Assert.Equal("scene_conflict", service.ErrorCode);
        Assert.Equal(7, service.State!.SceneVersion);
        Assert.Equal(1, api.StateRequests);
    }

    public void Dispose()
    {
        if (Directory.Exists(_root)) Directory.Delete(_root, recursive: true);
    }

    private sealed class FakeAuth : IAuthSession
    {
        public event Action? SessionChanged;
        public bool IsAuthenticated => CurrentUser is not null;
        public AuthUser? CurrentUser { get; private set; } = new("tree-owner", "Tree Owner", "user");
        public Task<AuthUser> RegisterAsync(string username, string password, CancellationToken cancellationToken = default) => throw new NotSupportedException();
        public Task<AuthUser> SignInAsync(string username, string password, CancellationToken cancellationToken = default) => throw new NotSupportedException();
        public Task RefreshAsync(CancellationToken cancellationToken = default) => Task.CompletedTask;
        public Task RestoreAsync(CancellationToken cancellationToken = default) => Task.CompletedTask;
        public Task SignOutAsync(CancellationToken cancellationToken = default) => Task.CompletedTask;
    }

    private sealed class FakeApi : IAuthenticatedAccountApi
    {
        private readonly HashSet<string> _committed = new(StringComparer.Ordinal);
        internal bool LoseNextMutationResponse { get; set; }
        internal bool RejectSceneAsConflict { get; set; }
        internal bool ConflictIncludesState { get; set; }
        internal int StateRequests { get; private set; }
        internal int CommittedMutations { get; private set; }
        internal List<byte[]> Commands { get; } = [];
        private long _coins = 1000;
        private bool _packOwned = true;

        public Task<HttpResponseMessage> SendAsync(HttpMethod method, string relativePath, byte[]? jsonBody,
            string? contentEncoding, CancellationToken cancellationToken = default)
        {
            if (relativePath.EndsWith("catalog.php", StringComparison.Ordinal))
                return Task.FromResult(Response("""
                    {"catalog_version":2,"single_price":200,"ten_price":1800,
                     "rates":{"common":70,"rare":25,"theme":5},"items":[
                      {"id":"red-lantern","name":"红灯笼","category":"tree","rarity":"common","asset_key":"red-lantern"}]}
                    """));
            if (relativePath.EndsWith("state.php", StringComparison.Ordinal))
            {
                StateRequests++;
                return Task.FromResult(Response(StateJson()));
            }

            Commands.Add(jsonBody!.ToArray());
            using JsonDocument document = JsonDocument.Parse(jsonBody);
            string action = document.RootElement.GetProperty("action").GetString()!;
            string operation = document.RootElement.GetProperty("operation_id").GetString()!;
            if (action == "save_scene" && RejectSceneAsConflict)
                return Task.FromResult(Response(ConflictIncludesState
                    ? $$"""{"code":"scene_conflict","state":{{StateJson(sceneVersion: 7)}}}"""
                    : "{\"code\":\"scene_conflict\"}", HttpStatusCode.Conflict));
            bool replay = !_committed.Add(operation);
            if (!replay)
            {
                CommittedMutations++;
                if (action == "buy_pack") _coins -= 1800;
                if (action == "open_pack") _packOwned = false;
            }
            string result = action == "open_pack"
                ? StateJson(",\"draw_results\":[{\"item_id\":\"red-lantern\",\"rarity\":\"common\",\"is_new\":true,\"shards_awarded\":0}]")
                : StateJson();
            if (LoseNextMutationResponse)
            {
                LoseNextMutationResponse = false;
                throw new HttpRequestException("Response lost after commit.");
            }
            return Task.FromResult(Response(result));
        }

        private string StateJson(string extra = "", long sceneVersion = 0) => $$"""
            {"mature":true,"coins":{{_coins}},"shards":0,"scene_version":{{sceneVersion}},
             "owned_ids":["red-lantern"],"card_packs":{{(_packOwned ? "[{\"id\":\"" + PackId + "\",\"draw_count\":1,\"created_at\":\"now\"}]" : "[]")}},
             "placements":[],"active_theme_id":null{{extra}}}
            """;

        private static HttpResponseMessage Response(string json, HttpStatusCode status = HttpStatusCode.OK) => new(status)
        {
            Content = new StringContent(json, Encoding.UTF8, "application/json")
        };
    }
}
