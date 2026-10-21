using System.Net;
using System.Text;
using System.Text.Json;
using DeskBox.Contracts;
using DeskBox.Services;

namespace DeskBox.Tests;

public sealed class TreeCloudSyncServiceTests : IDisposable
{
    private readonly string _root = Path.Combine(Path.GetTempPath(), "DeskBoxTreeCloudTests", Guid.NewGuid().ToString("N"));

    [Fact]
    public async Task SyncUploadsPendingDeltaAndAppliesAuthoritativeState()
    {
        var api = new FakeApi
        {
            State = State(version: 0, energy: 0, growth: 0, stage: 1, fruitEnergy: 0, fruitInventory: 0, harvested: 0),
            UploadState = State(version: 1, energy: 500, growth: 20, stage: 1, fruitEnergy: 10, fruitInventory: 2, harvested: 3)
        };
        var auth = new FakeAuth("user-1", "Simon");
        TreeProgress local = Progress(100, 10, 1, 1);
        TreeProgress? applied = null;
        await using var service = new TreeCloudSyncService(
            api, auth, () => local, _root, () => DateTimeOffset.Parse("2026-10-14T08:00:00Z"),
            (state, _) => { applied = Progress(state.TotalEnergy, state.TodayGrowth, state.FruitInventory, state.HarvestedTotal, state); return true; });

        Assert.True(await service.SyncNowAsync());
        using JsonDocument body = JsonDocument.Parse(Assert.Single(api.Bodies));
        Assert.Equal("POST", api.Methods[1]);
        Assert.Equal(100, body.RootElement.GetProperty("energy_delta").GetInt64());
        Assert.Equal(10, body.RootElement.GetProperty("day_growth_delta").GetInt64());
        Assert.Equal(500, applied!.TotalEnergy);
        Assert.Equal(2, applied.FruitInventory);
    }

    [Fact]
    public async Task FailedUpload_PersistsAndReusesSameOperationId()
    {
        var auth = new FakeAuth("user-1", "Simon");
        var offlineApi = new FakeApi { PostFailure = new HttpRequestException("offline") };
        TreeProgress progress = Progress(120, 40, 3, 1);
        await using (var first = new TreeCloudSyncService(offlineApi, auth, () => progress, _root))
            await Assert.ThrowsAsync<HttpRequestException>(() => first.SyncNowAsync());
        string firstOperation = ReadOperation(Assert.Single(offlineApi.Bodies));

        var onlineApi = new FakeApi();
        await using (var second = new TreeCloudSyncService(onlineApi, auth, () => progress, _root))
            Assert.True(await second.SyncNowAsync());

        Assert.Equal(firstOperation, ReadOperation(Assert.Single(onlineApi.Bodies)));
    }

    [Fact]
    public async Task ExistingCloudStateIsPulledBeforeLocalUpload()
    {
        var api = new FakeApi
        {
            State = State(version: 7, energy: 900, growth: 90, stage: 1, fruitEnergy: 0, fruitInventory: 4, harvested: 2)
        };
        var auth = new FakeAuth("user-1", "Simon");
        TreeProgress local = Progress(100, 10, 1, 0);
        TreeProgress? applied = null;
        await using var service = new TreeCloudSyncService(
            api, auth, () => local, _root, () => DateTimeOffset.Parse("2026-10-14T08:00:00Z"),
            (state, _) => { applied = Progress(state.TotalEnergy, state.TodayGrowth, state.FruitInventory, state.HarvestedTotal, state); return true; });

        Assert.True(await service.SyncNowAsync());
        Assert.Equal(900, applied!.TotalEnergy);
        Assert.Equal(2, applied.HarvestedFruit);
        Assert.Single(api.Methods);
        Assert.Equal("GET", api.Methods[0]);
        Assert.False(service.HasConflict);
    }

    [Fact]
    public async Task SyncFallsBackToLegacyStateEndpointWhenProgressReadIsUnsupported()
    {
        var api = new FakeApi { RejectProgressRead = true };
        var auth = new FakeAuth("user-1", "Simon");
        TreeProgress local = Progress(100, 10, 1, 0);
        await using var service = new TreeCloudSyncService(api, auth, () => local, _root);

        Assert.True(await service.SyncNowAsync());
        Assert.Equal(
            [
                "GET ../tree/progress.php",
                "GET ../tree/state.php",
                "POST ../tree/progress.php",
                "GET ../tree/leaderboard.php"
            ],
            api.Requests);
    }

    [Fact]
    public async Task OfflineRetryKeepsTheSameOperationId()
    {
        var auth = new FakeAuth("user-1", "Simon");
        TreeProgress local = Progress(100, 10, 1, 0);
        var offlineApi = new FakeApi { PostFailure = new HttpRequestException("offline") };
        await using (var first = new TreeCloudSyncService(offlineApi, auth, () => local, _root,
            () => DateTimeOffset.Parse("2026-10-14T08:00:00Z")))
            await Assert.ThrowsAsync<HttpRequestException>(() => first.SyncNowAsync());
        string firstOperation = ReadOperation(Assert.Single(offlineApi.Bodies));

        local = Progress(150, 60, 1, 0);
        var conflictApi = new FakeApi { State = State(version: 4, energy: 0, growth: 0, stage: 1, fruitEnergy: 0, fruitInventory: 0, harvested: 0) };
        await using (var second = new TreeCloudSyncService(conflictApi, auth, () => local, _root,
            () => DateTimeOffset.Parse("2026-10-14T08:00:00Z")))
            Assert.True(await second.SyncNowAsync());

        Assert.Equal(firstOperation, ReadOperation(Assert.Single(conflictApi.Bodies)));
    }

    [Fact]
    public async Task VersionConflictPreservesLocalSnapshotAndAdoptsCloudState()
    {
        var api = new FakeApi
        {
            State = State(version: 1, energy: 10, growth: 1, stage: 1, fruitEnergy: 10, fruitInventory: 0, harvested: 0),
            ConflictState = State(version: 2, energy: 900, growth: 90, stage: 2, fruitEnergy: 500, fruitInventory: 3, harvested: 4),
            Conflict = true
        };
        var auth = new FakeAuth("user-1", "Simon");
        TreeProgress local = Progress(100, 10, 1, 0);
        TreeStateDto? applied = null;
        await using var service = new TreeCloudSyncService(
            api, auth, () => local, _root,
            applyAuthoritativeCloudState: (state, preserve) =>
            {
                preserve(local);
                applied = state;
            });

        Assert.True(await service.SyncNowAsync());

        Assert.True(service.HasConflict);
        Assert.Equal(900, applied!.TotalEnergy);
        Assert.Equal(3, applied.FruitInventory);
        Assert.Equal(["GET", "POST", "GET"], api.Methods);
        Assert.Single(api.Bodies);
        Assert.Single(Directory.GetFiles(_root, "conflict-*.json", SearchOption.AllDirectories));
    }

    [Fact]
    public async Task VersionConflictAcknowledgesCloudBaselineForTheNextCycle()
    {
        var auth = new FakeAuth("user-1", "Simon");
        TreeProgress local = Progress(100, 10, 1, 0);
        var initialApi = new FakeApi
        {
            State = State(version: 1, energy: 0, growth: 0, stage: 1, fruitEnergy: 0, fruitInventory: 0, harvested: 0),
            UploadState = State(version: 2, energy: 100, growth: 10, stage: 1, fruitEnergy: 0, fruitInventory: 1, harvested: 0)
        };
        await using (var initial = new TreeCloudSyncService(
            initialApi, auth, () => local, _root,
            applyAuthoritativeCloudState: (state, preserve) =>
            {
                preserve(local);
                local = Progress(state.TotalEnergy, state.TodayGrowth, state.FruitInventory,
                    state.HarvestedTotal, state);
            },
            applyAuthoritativeIfUnchanged: (state, _) =>
            {
                local = Progress(state.TotalEnergy, state.TodayGrowth, state.FruitInventory,
                    state.HarvestedTotal, state);
                return true;
            }))
            Assert.True(await initial.SyncNowAsync());

        local = Progress(150, 20, 1, 0);
        var conflictApi = new FakeApi
        {
            State = State(version: 3, energy: 120, growth: 12, stage: 1, fruitEnergy: 0, fruitInventory: 1, harvested: 0),
            ConflictState = State(version: 4, energy: 900, growth: 90, stage: 2, fruitEnergy: 500, fruitInventory: 3, harvested: 4),
            Conflict = true
        };
        await using var service = new TreeCloudSyncService(
            conflictApi, auth, () => local, _root,
            applyAuthoritativeCloudState: (state, preserve) =>
            {
                preserve(local);
                local = Progress(state.TotalEnergy, state.TodayGrowth, state.FruitInventory,
                    state.HarvestedTotal, state);
            },
            applyAuthoritativeIfUnchanged: (state, _) =>
            {
                local = Progress(state.TotalEnergy, state.TodayGrowth, state.FruitInventory,
                    state.HarvestedTotal, state);
                return true;
            });

        Assert.True(await service.SyncNowAsync());
        Assert.True(await service.SyncNowAsync());
        Assert.Equal(1, conflictApi.Methods.Count(method => method == "POST"));
        Assert.Equal(4, ReadAcknowledgedVersion(_root));
    }

    private static TreeProgress Progress(long energy, long today, long inventory, long harvested, TreeStateDto? state = null) => new()
    {
        LocalDay = state?.Day ?? "2026-10-14", TotalEnergy = energy, TodayKeys = today,
        HighestStage = state?.Stage ?? 1, FruitEnergy = state?.FruitEnergy ?? 0,
        FruitInventory = inventory, HarvestedFruit = harvested
    };

    private static TreeStateDto State(long version, long energy, long growth, int stage, long fruitEnergy, long fruitInventory, long harvested) => new()
    {
        StateVersion = version, Day = "2026-10-14", TotalEnergy = energy, TodayGrowth = growth,
        Stage = stage, FruitEnergy = fruitEnergy, FruitInventory = fruitInventory,
        FruitTotal = fruitInventory + harvested, HarvestedTotal = harvested
    };

    private static string ReadOperation(byte[] json)
    {
        using JsonDocument body = JsonDocument.Parse(json);
        return body.RootElement.GetProperty("operation_id").GetString()!;
    }

    private static long ReadAcknowledgedVersion(string root)
    {
        string path = Directory.GetFiles(root, "state-*.json", SearchOption.AllDirectories).Single();
        using JsonDocument document = JsonDocument.Parse(File.ReadAllText(path));
        return document.RootElement.GetProperty("acknowledged").GetProperty("state_version").GetInt64();
    }

    public void Dispose()
    {
        if (Directory.Exists(_root)) Directory.Delete(_root, recursive: true);
    }

    private sealed class FakeAuth(string id, string username) : IAuthSession
    {
        public event Action? SessionChanged;
        public bool IsAuthenticated => true;
        public AuthUser? CurrentUser { get; } = new(id, username);
        public Task<AuthUser> RegisterAsync(string username, string password, CancellationToken cancellationToken = default) => throw new NotSupportedException();
        public Task<AuthUser> SignInAsync(string username, string password, CancellationToken cancellationToken = default) => throw new NotSupportedException();
        public Task RefreshAsync(CancellationToken cancellationToken = default) => Task.CompletedTask;
        public Task RestoreAsync(CancellationToken cancellationToken = default) => Task.CompletedTask;
        public Task SignOutAsync(CancellationToken cancellationToken = default) => Task.CompletedTask;
    }

    private sealed class FakeApi : IAuthenticatedAccountApi
    {
        internal Exception? PostFailure { get; set; }
        internal bool Conflict { get; set; }
        internal bool RejectProgressRead { get; set; }
        internal TreeStateDto State { get; set; } = new() { Day = "2026-10-14", Stage = 1 };
        internal TreeStateDto? ConflictState { get; set; }
        internal TreeStateDto? UploadState { get; set; }
        internal List<byte[]> Bodies { get; } = [];
        internal List<string> Methods { get; } = [];
        internal List<string> Requests { get; } = [];
        private bool _postAttempted;

        public Task<HttpResponseMessage> SendAsync(HttpMethod method, string relativePath, byte[]? jsonBody, string? contentEncoding, CancellationToken cancellationToken = default)
        {
            Methods.Add(method.Method);
            Requests.Add($"{method.Method} {relativePath}");
            if (method == HttpMethod.Post)
            {
                if (jsonBody is not null) Bodies.Add(jsonBody);
                _postAttempted = true;
                if (PostFailure is not null) throw PostFailure;
                if (Conflict)
                    return Response(HttpStatusCode.Conflict, "{\"code\":\"state_conflict\",\"message\":\"State version conflict.\"}");
                return Response(HttpStatusCode.OK, JsonSerializer.Serialize(new TreeProgressUploadResponse { State = UploadState ?? State }));
            }

            if (RejectProgressRead && relativePath == "../tree/progress.php")
                return Response(HttpStatusCode.MethodNotAllowed,
                    "{\"code\":\"method_not_allowed\",\"message\":\"Method not allowed.\"}");

            TreeStateDto state = _postAttempted && ConflictState is not null ? ConflictState : State;
            return Response(HttpStatusCode.OK, JsonSerializer.Serialize(new TreeStateResponse { State = state }));
        }

        private static Task<HttpResponseMessage> Response(HttpStatusCode statusCode, string json) => Task.FromResult(new HttpResponseMessage(statusCode)
        {
            Content = new StringContent(json, Encoding.UTF8, "application/json")
        });
    }
}
