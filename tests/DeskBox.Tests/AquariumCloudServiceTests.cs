using System.Net;
using System.Text;
using System.Text.Json;
using DeskBox.Contracts;
using DeskBox.Core.Persistence;
using DeskBox.Services;

namespace DeskBox.Tests;

public sealed class AquariumCloudServiceTests : IDisposable
{
    private readonly string _root = Path.Combine(Path.GetTempPath(), "DeskBoxAquariumTests", Guid.NewGuid().ToString("N"));

    [Fact]
    public async Task RefreshDoesNotInitializeAndRepeatedInitializationUsesServerBalance()
    {
        var auth = new FakeAuth();
        var api = new FakeApi();
        await using (var first = new AquariumService(auth, api, _root))
        {
            Assert.True(await first.RefreshAsync());
            Assert.False(first.State!.Initialized);
            Assert.Empty(api.Commands);
            Assert.True(await first.InitializeAsync());
            Assert.Equal(1000, first.State!.Coins);
            Assert.True(await first.BuyAsync("clownfish"));
            Assert.Equal(900, first.State!.Coins);
            Assert.Empty(first.State.Scene);
        }
        await using var second = new AquariumService(auth, api, Path.Combine(_root, "second-device"));
        Assert.True(await second.InitializeAsync());
        Assert.Equal(900, second.State!.Coins);
        Assert.Equal(1, api.GiftCount);
        Assert.Single(second.State.Inventory);
    }

    [Fact]
    public async Task CommittedPurchaseWithLostResponseReplaysSameBodyAfterRestart()
    {
        var auth = new FakeAuth();
        var api = new FakeApi();
        await using (var first = new AquariumService(auth, api, _root))
        {
            Assert.True(await first.InitializeAsync());
            api.LoseNextPurchaseResponse = true;
            Assert.False(await first.BuyAsync("clownfish"));
            Assert.True(first.HasPendingOperation);
            Assert.True(first.IsOffline);
        }
        byte[] original = api.Commands.Last();
        await using var restored = new AquariumService(auth, api, _root);
        Assert.True(await restored.RefreshAsync());
        Assert.Equal(original, api.Commands.Last());
        Assert.Equal(1, api.PurchaseCount);
        Assert.Equal(900, restored.State!.Coins);
        Assert.Single(restored.State.Inventory);
        Assert.False(restored.HasPendingOperation);
    }

    [Fact]
    public async Task UnknownPurchaseMustBeResolvedBeforeDifferentOperation()
    {
        var api = new FakeApi();
        await using var service = new AquariumService(new FakeAuth(), api, _root);
        Assert.True(await service.InitializeAsync());
        api.LoseNextPurchaseResponse = true;
        Assert.False(await service.BuyAsync("clownfish"));
        int commands = api.Commands.Count;
        Assert.False(await service.BuyAsync("blue-tang"));
        Assert.Equal("pending_operation", service.ErrorCode);
        Assert.Equal(commands, api.Commands.Count);
        Assert.True(await service.BuyAsync("clownfish"));
        Assert.Equal(1, api.PurchaseCount);
    }

    [Fact]
    public async Task SceneConflictPreservesDraftAndAdoptsLatestServerScene()
    {
        var api = new FakeApi();
        var auth = new FakeAuth();
        AquariumPlacement[] draft = [new("fish-1", 0.2, 0.7, 1.1)];
        await using (var service = new AquariumService(auth, api, _root))
        {
            Assert.True(await service.InitializeAsync());
            Assert.True(await service.BuyAsync("clownfish"));
            api.SceneVersion = 4;
            Assert.False(await service.SaveSceneAsync(draft, 0));
            Assert.Equal("scene_conflict", service.ErrorCode);
            Assert.Equal(draft, service.PendingScene);
            Assert.Equal(4, service.State!.SceneVersion);
            Assert.Empty(service.State.Scene);
            Assert.False(service.HasPendingOperation);
        }
        await using var restored = new AquariumService(auth, api, _root);
        Assert.True(await restored.RefreshAsync());
        Assert.Equal(draft, restored.PendingScene);
        Assert.True(await restored.SaveSceneAsync(draft, restored.State!.SceneVersion));
        Assert.Null(restored.PendingScene);
        Assert.Equal(5, restored.State!.SceneVersion);
        Assert.Equal(draft, restored.State.Scene);
    }

    [Fact]
    public async Task SceneLayerRoundTripsThroughSaveAndRefresh()
    {
        var api = new FakeApi();
        await using var service = new AquariumService(new FakeAuth(), api, _root);
        Assert.True(await service.InitializeAsync());
        Assert.True(await service.BuyAsync("clownfish"));
        AquariumPlacement[] scene = [new("fish-1", .4, .6, 1, -1)];

        Assert.True(await service.SaveSceneAsync(scene, 0));
        Assert.Equal(-1, Assert.Single(service.State!.Scene).Layer);
        Assert.True(await service.RefreshAsync());
        Assert.Equal(scene, service.State!.Scene);
        Assert.Contains("\"layer\":-1", Encoding.UTF8.GetString(api.Commands.Last()), StringComparison.Ordinal);
    }

    [Fact]
    public async Task SceneSaveRejectsLayersOutsideTheThreeVisibleChoices()
    {
        var api = new FakeApi();
        await using var service = new AquariumService(new FakeAuth(), api, _root);
        int requests = api.RequestCount;

        foreach (int layer in new[] { -2, 2 })
        {
            AquariumPlacement[] scene = [new("fish-1", .4, .6, 1, layer)];
            await Assert.ThrowsAsync<ArgumentException>(() => service.SaveSceneAsync(scene, 0));
        }

        Assert.Equal(requests, api.RequestCount);
    }

    [Fact]
    public async Task InsufficientCoinsIsNotMisreportedAsSceneConflict()
    {
        var api = new FakeApi();
        await using var service = new AquariumService(new FakeAuth(), api, _root);
        Assert.True(await service.InitializeAsync());
        api.Coins = 20;
        Assert.False(await service.BuyAsync("clownfish"));
        Assert.Equal("insufficient_coins", service.ErrorCode);
        Assert.Equal(20, service.State!.Coins);
        Assert.Null(service.PendingScene);
        Assert.False(service.HasPendingOperation);
    }

    [Fact]
    public async Task SignOutImmediatelyHidesCachedStateAndCannotUseAdminEndpoints()
    {
        var auth = new FakeAuth();
        var api = new FakeApi();
        await using var service = new AquariumService(auth, api, _root);
        Assert.True(await service.InitializeAsync());
        int requests = api.RequestCount;
        auth.SetUser(null);
        Assert.False(service.IsAvailable);
        Assert.Null(service.State);
        Assert.Null(service.CurrentUserId);
        Assert.Empty(service.Catalog);
        Assert.False(await service.RefreshAsync());
        Assert.False(await service.InitializeAsync());
        Assert.False(await service.BuyAsync("clownfish"));
        Assert.Equal(requests, api.RequestCount);
    }

    [Fact]
    public async Task OfflineStartupCannotRecoverAdministratorAccessFromCachedAssets()
    {
        var api = new FakeApi();
        await using (var service = new AquariumService(new FakeAuth(), api, _root))
            Assert.True(await service.InitializeAsync());
        api.Offline = true;
        int requests = api.RequestCount;
        await using var offline = new AquariumService(new FakeAuth(new("admin-1", "Simon", "user")), api, _root);
        Assert.False(await offline.RefreshAsync());
        Assert.Null(offline.State);
        Assert.False(offline.IsAvailable);
        Assert.Equal(requests, api.RequestCount);
    }

    [Fact]
    public async Task PreviouslyVerifiedAccountKeepsOfflineDisplayWithoutLeakingToAnotherAccount()
    {
        var auth = new FakeAuth();
        var api = new FakeApi();
        await using var service = new AquariumService(auth, api, _root);
        Assert.True(await service.InitializeAsync());
        api.Offline = true;
        Assert.False(await service.RefreshAsync());
        Assert.True(service.IsOffline);
        Assert.True(service.IsAvailable);
        Assert.Equal(1000, service.State!.Coins);
        auth.SetUser(new("admin-2", "Other", "admin"));
        Assert.False(await service.RefreshAsync());
        Assert.Null(service.State);
        auth.SetUser(new("admin-1", "Simon", "admin"));
        Assert.False(await service.RefreshAsync());
        Assert.Equal(1000, service.State!.Coins);
    }

    [Fact]
    public async Task LateResponseFromPreviousAccountCannotRepopulateState()
    {
        var auth = new FakeAuth();
        var api = new FakeApi { BlockNextState = true };
        await using var service = new AquariumService(auth, api, _root);
        Task<bool> refresh = service.RefreshAsync();
        await api.StateEntered.Task.WaitAsync(TimeSpan.FromSeconds(5));
        auth.SetUser(new("admin-2", "Other", "admin"));
        api.ReleaseState.TrySetResult();
        Assert.False(await refresh);
        Assert.Null(service.State);
        Assert.Empty(service.Catalog);
        Assert.Equal("admin-2", service.CurrentUserId);
        api.Coins = 83;
        Assert.True(await service.RefreshAsync());
        Assert.Equal(83, service.State!.Coins);
    }

    [Fact]
    public async Task StartAndVerifiedLoginRefreshImmediatelyAndPollingContinues()
    {
        var auth = new FakeAuth(null);
        var api = new FakeApi();
        await using var service = new AquariumService(auth, api, _root, TimeSpan.FromMilliseconds(60));
        var first = new TaskCompletionSource(TaskCreationOptions.RunContinuationsAsynchronously);
        var repeated = new TaskCompletionSource(TaskCreationOptions.RunContinuationsAsynchronously);
        int reads = 0;
        service.Changed += () =>
        {
            if (service.State is null) return;
            if (Interlocked.Increment(ref reads) == 1) first.TrySetResult();
            else repeated.TrySetResult();
        };
        service.Start();
        Assert.Equal(0, api.RequestCount);
        auth.SetUser(new("admin-1", "Simon", "admin"));
        await first.Task.WaitAsync(TimeSpan.FromSeconds(5));
        await repeated.Task.WaitAsync(TimeSpan.FromSeconds(5));
        Assert.True(api.StateReads >= 2);
        Assert.Empty(api.Commands);
    }

    [Fact]
    public async Task ReadCollectionsCannotBeMutatedByConsumers()
    {
        var api = new FakeApi();
        await using var service = new AquariumService(new FakeAuth(), api, _root);
        Assert.True(await service.InitializeAsync());
        Assert.True(await service.BuyAsync("clownfish"));
        var inventory = Assert.IsAssignableFrom<IList<AquariumInventoryItem>>(service.State!.Inventory);
        Assert.Throws<NotSupportedException>(() => inventory.Clear());
        var catalog = Assert.IsAssignableFrom<IList<AquariumCatalogItem>>(service.Catalog);
        Assert.Throws<NotSupportedException>(() => catalog.Clear());
    }

    [Fact]
    public async Task BackupCaptureExcludesAquariumAssetsAndPendingCommands()
    {
        var api = new FakeApi();
        string cache = Path.Combine(_root, "cache", "aquarium");
        await using var service = new AquariumService(new FakeAuth(), api, cache);
        Assert.True(await service.InitializeAsync());
        api.LoseNextPurchaseResponse = true;
        Assert.False(await service.BuyAsync("clownfish"));
        Assert.NotEmpty(Directory.GetFiles(cache));
        Assert.False(AccountBackupFileCatalog.IsEligibleRelativePath("cache/aquarium/admin-1.json"));
    }

    public void Dispose()
    {
        if (Directory.Exists(_root)) Directory.Delete(_root, recursive: true);
    }

    private sealed class FakeAuth : IAuthSession
    {
        internal FakeAuth() : this(new("admin-1", "Simon", "admin")) { }
        internal FakeAuth(AuthUser? user) => CurrentUser = user;
        public event Action? SessionChanged;
        public bool IsAuthenticated => CurrentUser is not null;
        public AuthUser? CurrentUser { get; private set; }
        internal void SetUser(AuthUser? user) { CurrentUser = user; SessionChanged?.Invoke(); }
        public Task<AuthUser> RegisterAsync(string username, string password, CancellationToken cancellationToken = default) => throw new NotSupportedException();
        public Task<AuthUser> SignInAsync(string username, string password, CancellationToken cancellationToken = default) => throw new NotSupportedException();
        public Task RefreshAsync(CancellationToken cancellationToken = default) => Task.CompletedTask;
        public Task RestoreAsync(CancellationToken cancellationToken = default) => Task.CompletedTask;
        public Task SignOutAsync(CancellationToken cancellationToken = default) { SetUser(null); return Task.CompletedTask; }
    }

    private sealed class FakeApi : IAuthenticatedAccountApi
    {
        private readonly HashSet<string> _operations = new(StringComparer.Ordinal);
        private bool _initialized;
        private string _scene = "[]";
        internal long Coins { get; set; }
        internal long SceneVersion { get; set; }
        internal int GiftCount { get; private set; }
        internal int PurchaseCount { get; private set; }
        internal int RequestCount { get; private set; }
        internal int StateReads { get; private set; }
        internal bool LoseNextPurchaseResponse { get; set; }
        internal bool Offline { get; set; }
        internal bool BlockNextState { get; set; }
        internal TaskCompletionSource StateEntered { get; } = new(TaskCreationOptions.RunContinuationsAsynchronously);
        internal TaskCompletionSource ReleaseState { get; } = new(TaskCreationOptions.RunContinuationsAsynchronously);
        internal List<byte[]> Commands { get; } = [];

        public async Task<HttpResponseMessage> SendAsync(HttpMethod method, string relativePath, byte[]? jsonBody,
            string? contentEncoding, CancellationToken cancellationToken = default)
        {
            RequestCount++;
            if (Offline) throw new HttpRequestException("offline");
            if (relativePath.EndsWith("catalog.php", StringComparison.Ordinal))
                return Response("""{"catalog_version":2,"items":[{"id":"clownfish","name":"小丑鱼","category":"fish","price":100,"asset_key":"clownfish"},{"id":"blue-tang","name":"蓝吊鱼","category":"fish","price":180,"asset_key":"blue-tang"}]}""");
            if (relativePath.EndsWith("state.php", StringComparison.Ordinal))
            {
                StateReads++;
                string state = StateJson();
                if (BlockNextState)
                {
                    BlockNextState = false;
                    StateEntered.TrySetResult();
                    await ReleaseState.Task.WaitAsync(cancellationToken);
                }
                return Response(state);
            }
            Assert.Equal(HttpMethod.Post, method);
            Assert.Equal("../aquarium/actions.php", relativePath);
            Commands.Add(jsonBody!.ToArray());
            using JsonDocument document = JsonDocument.Parse(jsonBody!);
            JsonElement body = document.RootElement;
            string operation = body.GetProperty("operation_id").GetString()!;
            if (_operations.Contains(operation)) return Response(StateJson());
            string action = body.GetProperty("action").GetString()!;
            if (action == "initialize" && !_initialized)
            {
                Coins = 1000;
                _initialized = true;
                GiftCount++;
            }
            if (action == "buy")
            {
                if (Coins < 100) return Error("insufficient_coins");
                Coins -= 100;
                PurchaseCount++;
            }
            if (action == "save_scene")
            {
                if (body.GetProperty("base_scene_version").GetInt64() != SceneVersion) return Error("scene_conflict");
                _scene = body.GetProperty("scene").GetRawText();
                SceneVersion++;
            }
            _operations.Add(operation);
            if (action == "buy" && LoseNextPurchaseResponse)
            {
                LoseNextPurchaseResponse = false;
                throw new HttpRequestException("Response lost after server commit.");
            }
            return Response(StateJson());
        }

        private string StateJson() => $$"""
            {"initialized":{{(_initialized ? "true" : "false")}},"coins":{{Coins}},"scene_version":{{SceneVersion}},
            "inventory":{{(PurchaseCount == 0 ? "[]" : "[{\"id\":\"fish-1\",\"item_id\":\"clownfish\",\"created_at\":\"2026-10-17T00:00:00Z\"}]")}},
            "scene":{{_scene}},"wallet":[{"id":"1","delta":1000,"balance":1000,"reason":"initial_grant","item_id":null,"created_at":"2026-10-17T00:00:00Z"}]}
            """;

        private static HttpResponseMessage Error(string code) => Response($$"""{"code":"{{code}}"}""", HttpStatusCode.Conflict);
        private static HttpResponseMessage Response(string json, HttpStatusCode status = HttpStatusCode.OK) => new(status)
        {
            Content = new StringContent(json, Encoding.UTF8, "application/json")
        };
    }
}
