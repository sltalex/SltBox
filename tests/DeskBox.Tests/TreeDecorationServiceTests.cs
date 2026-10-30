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
    public async Task DecorationVisibilityIsAvailableToEverySignedInAccount()
    {
        var auth = new FakeAuth();
        await using var service = new TreeDecorationService(auth, new FakeApi(), _root);

        Assert.True(service.IsVisibleToCurrentUser);
        Assert.True(service.IsAvailable);
        Assert.True(await service.RefreshAsync());

        int changed = 0;
        service.Changed += () => changed++;
        auth.SetCurrentUser(new AuthUser("tree-owner", "SLTALEX"));
        Assert.True(service.IsVisibleToCurrentUser);
        Assert.Equal(1, changed);

        auth.SetCurrentUser(new AuthUser("other-owner", "other"));
        Assert.True(service.IsVisibleToCurrentUser);
        Assert.True(service.IsAvailable);

        auth.SetCurrentUser(null);
        Assert.False(service.IsVisibleToCurrentUser);
        Assert.False(service.IsAvailable);
    }

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
        Assert.Equal(TreeDecorationService.SupportedAssetVersion,
            command.RootElement.GetProperty("asset_version").GetInt32());
        Assert.Equal(TreeDecorationService.SupportedAssetVersion,
            command.RootElement.GetProperty("catalog_version").GetInt32());
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
        TreeDecorationPlacement pending = Assert.Single(service.PendingScene!);
        Assert.False(string.IsNullOrWhiteSpace(pending.PlacementId));
        Assert.Equal(draft[0] with { PlacementId = pending.PlacementId }, pending);

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

    [Fact]
    public async Task SavingMultipleInstancesPublishesTheAuthoritativeScene()
    {
        var api = new FakeApi();
        await using var service = new TreeDecorationService(new FakeAuth(), api, _root);
        Assert.True(await service.RefreshAsync());
        int changed = 0;
        service.Changed += () => changed++;
        TreeDecorationPlacement[] placements =
        [
            new("first-lantern", "red-lantern", .25, .35, 1, 0, 0),
            new("second-lantern", "red-lantern", .75, .65, 1, 0, 1)
        ];

        Assert.True(await service.SaveSceneAsync(placements, null, 0));

        Assert.Equal(placements, service.State!.Placements);
        Assert.True(changed > 0);
    }

    [Fact]
    public async Task SavedSceneReloadsWithEveryDecorationInstance()
    {
        var api = new FakeApi();
        TreeDecorationPlacement[] placements =
        [
            new("first-lantern", "red-lantern", .25, .35, 1, 0, 0),
            new("second-lantern", "red-lantern", .75, .65, 1, 0, 1)
        ];
        await using (var editor = new TreeDecorationService(new FakeAuth(), api, _root))
        {
            Assert.True(await editor.RefreshAsync());
            Assert.True(await editor.SaveSceneAsync(placements, null, 0));
        }

        await using var desktop = new TreeDecorationService(new FakeAuth(), api, _root);
        Assert.True(await desktop.RefreshAsync());
        Assert.Equal(1, desktop.State!.SceneVersion);
        Assert.Equal(placements, desktop.State.Placements);
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
        public void SetCurrentUser(AuthUser? user)
        {
            CurrentUser = user;
            SessionChanged?.Invoke();
        }
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
        private long _sceneVersion;
        private string _placements = "[]";

        public Task<HttpResponseMessage> SendAsync(HttpMethod method, string relativePath, byte[]? jsonBody,
            string? contentEncoding, CancellationToken cancellationToken = default)
        {
            if (relativePath.StartsWith("../tree/decorations/catalog.php?asset_version=3", StringComparison.Ordinal))
                return Task.FromResult(Response("""
                    {"catalog_version":3,"single_price":200,"ten_price":1800,
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
                if (action == "save_scene")
                {
                    _placements = document.RootElement.GetProperty("placements").GetRawText();
                    _sceneVersion++;
                }
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

        private string StateJson(string extra = "", long? sceneVersion = null) => $$"""
            {"mature":true,"coins":{{_coins}},"shards":0,"scene_version":{{sceneVersion ?? _sceneVersion}},
             "owned_ids":["red-lantern"],"card_packs":{{(_packOwned ? "[{\"id\":\"" + PackId + "\",\"draw_count\":1,\"created_at\":\"now\"}]" : "[]")}},
             "inventory":[{"item_id":"red-lantern","quantity":2}],
             "placements":{{_placements}},"active_theme_id":null{{extra}}}
            """;

        private static HttpResponseMessage Response(string json, HttpStatusCode status = HttpStatusCode.OK) => new(status)
        {
            Content = new StringContent(json, Encoding.UTF8, "application/json")
        };
    }
}
