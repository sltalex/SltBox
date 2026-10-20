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
    public async Task SyncUploadsFullSnapshotAndAppliesAuthoritativeState()
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
        Assert.Equal(10, body.RootElement.GetProperty("today_growth").GetInt64());
        Assert.Equal(500, applied!.TotalEnergy);
        Assert.Equal(2, applied.FruitInventory);
    }

    [Fact]
    public async Task FailedUpload_PersistsAndReusesSameOperationId()
    {
        var auth = new FakeAuth("user-1", "Simon");
        var offlineApi = new FakeApi { Failure = new HttpRequestException("offline") };
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
    public async Task OfflineRetryKeepsTheSameOperationId()
    {
        var auth = new FakeAuth("user-1", "Simon");
        TreeProgress local = Progress(100, 10, 1, 0);
        var offlineApi = new FakeApi { Failure = new HttpRequestException("offline") };
        await using (var first = new TreeCloudSyncService(offlineApi, auth, () => local, _root,
            () => DateTimeOffset.Parse("2026-10-14T08:00:00Z")))
            await Assert.ThrowsAsync<HttpRequestException>(() => first.SyncNowAsync());

        local = Progress(150, 60, 1, 0);
        var conflictApi = new FakeApi { State = State(version: 4, energy: 0, growth: 0, stage: 1, fruitEnergy: 0, fruitInventory: 0, harvested: 0) };
        await using (var second = new TreeCloudSyncService(conflictApi, auth, () => local, _root,
            () => DateTimeOffset.Parse("2026-10-14T08:00:00Z")))
            Assert.True(await second.SyncNowAsync());

        Assert.Equal(firstOperation, ReadOperation(Assert.Single(conflictApi.Bodies)));
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
        internal Exception? Failure { get; set; }
        internal bool Conflict { get; set; }
        internal TreeStateDto State { get; set; } = new() { Day = "2026-10-14", Stage = 1 };
        internal TreeStateDto? UploadState { get; set; }
        internal List<byte[]> Bodies { get; } = [];
        internal List<string> Methods { get; } = [];

        public Task<HttpResponseMessage> SendAsync(HttpMethod method, string relativePath, byte[]? jsonBody, string? contentEncoding, CancellationToken cancellationToken = default)
        {
            Methods.Add(method.Method);
            if (jsonBody is not null) Bodies.Add(jsonBody);
            if (Failure is not null) throw Failure;
            if (Conflict && method == HttpMethod.Post)
                return Task.FromResult(new HttpResponseMessage(HttpStatusCode.Conflict));
            return Response(JsonSerializer.Serialize(new TreeProgressUploadResponse { State = method == HttpMethod.Post ? UploadState ?? State : State }));
        }

        private static Task<HttpResponseMessage> Response(string json) => Task.FromResult(new HttpResponseMessage(HttpStatusCode.OK)
        {
            Content = new StringContent(json, Encoding.UTF8, "application/json")
        });
    }
}
