#if DEBUG
using System.Net;
using System.Text;
using System.Text.Json;
using DeskBox.Contracts;
using DeskBox.Services;

namespace DeskBox.Tests;

public sealed class TownPrototypeSessionTests : IDisposable
{
    private const string Default = "{\"template_version\":1,\"catalog_version\":1,\"demo_stage\":5,\"placements\":[]}";
    private readonly string _cache = Path.Combine(Path.GetTempPath(), "DeskBoxTownPrototype", Guid.NewGuid().ToString("N"));
    private static string Stage(int stage) => Default.Replace("\"demo_stage\":5", $"\"demo_stage\":{stage}");
    private static JsonDocument State(TownPrototypeSession session) => JsonDocument.Parse(session.BridgeJson());

    [Fact]
    public async Task GuestCanEditButCannotSendCloudSave()
    {
        var auth = new FakeAuth(null);
        var api = new FakeApi();
        using var session = new TownPrototypeSession(auth, api, Default, _cache);
        Assert.True(session.UpdateLayout(Stage(2), session.Epoch));
        Assert.False(await session.SaveAsync(session.Epoch));
        Assert.Empty(api.Commands);
        Assert.True(session.IsDirty);
    }

    [Fact]
    public async Task ResponseLossSurvivesReopenAndReplaysSameOperation()
    {
        var auth = new FakeAuth(new AuthUser("a", "Account A"));
        var api = new FakeApi { LoseNextSave = true };
        using (var first = new TownPrototypeSession(auth, api, Default, _cache))
        {
            await first.LoadAsync();
            first.UpdateLayout(Stage(2), first.Epoch);
            Assert.False(await first.SaveAsync(first.Epoch));
        }
        using var restored = new TownPrototypeSession(auth, api, Default, _cache);
        await restored.LoadAsync();
        using (JsonDocument state = State(restored)) Assert.False(state.RootElement.GetProperty("conflict").GetBoolean());
        Assert.True(await restored.SaveAsync(restored.Epoch));
        Assert.Equal(2, api.Commands.Count);
        Assert.Equal(api.Commands[0], api.Commands[1]);
        Assert.Equal(1, api.Commits);
        Assert.False(restored.IsDirty);
        using JsonDocument command = JsonDocument.Parse(api.Commands[0]);
        Assert.False(command.RootElement.TryGetProperty("user_id", out _));
        Assert.False(command.RootElement.TryGetProperty("total_energy", out _));
    }

    [Fact]
    public async Task NewEditsDuringSaveRemainDirtyAfterAcknowledgement()
    {
        var auth = new FakeAuth(new AuthUser("a", "Account A"));
        var api = new FakeApi();
        var response = new TaskCompletionSource<HttpResponseMessage>(TaskCreationOptions.RunContinuationsAsynchronously);
        api.Override = (method, body, token) => method == HttpMethod.Post ? response.Task : Task.FromResult(Reply(Default, 0));
        using var session = new TownPrototypeSession(auth, api, Default, _cache);
        session.UpdateLayout(Stage(2), session.Epoch);
        Task<bool> saving = session.SaveAsync(session.Epoch);
        session.UpdateLayout(Stage(3), session.Epoch);
        response.SetResult(Reply(Stage(2), 1));
        Assert.True(await saving);
        using JsonDocument state = State(session);
        Assert.True(state.RootElement.GetProperty("dirty").GetBoolean());
        Assert.Equal(3, state.RootElement.GetProperty("layout").GetProperty("demo_stage").GetInt32());
        Assert.Equal(1, state.RootElement.GetProperty("scene_version").GetInt64());
    }

    [Fact]
    public async Task AccountSwitchRejectsOldBrowserMessagesAndLateResponses()
    {
        var auth = new FakeAuth(new AuthUser("a", "Account A"));
        var response = new TaskCompletionSource<HttpResponseMessage>(TaskCreationOptions.RunContinuationsAsynchronously);
        var api = new FakeApi();
        int requests = 0;
        api.Override = (_, _, _) => Interlocked.Increment(ref requests) == 1 ? response.Task : Task.FromResult(Reply(Default, 0));
        using var session = new TownPrototypeSession(auth, api, Default, _cache);
        long oldEpoch = session.Epoch;
        Task reading = session.LoadAsync();
        auth.Switch(new AuthUser("b", "Account B"));
        Assert.False(session.UpdateLayout(Stage(1), oldEpoch));
        response.SetResult(Reply(Stage(2), 8));
        await reading;
        using JsonDocument state = State(session);
        Assert.Equal("Account B", state.RootElement.GetProperty("account_label").GetString());
        Assert.Equal(5, state.RootElement.GetProperty("layout").GetProperty("demo_stage").GetInt32());
        Assert.Equal(0, state.RootElement.GetProperty("scene_version").GetInt64());
    }

    [Fact]
    public async Task TimeoutStopsWaitingEvenWhenTransportIgnoresCancellation()
    {
        var response = new TaskCompletionSource<HttpResponseMessage>(TaskCreationOptions.RunContinuationsAsynchronously);
        var api = new FakeApi { Override = (_, _, _) => response.Task };
        using var session = new TownPrototypeSession(new FakeAuth(new AuthUser("a", "A")), api,
            Default, _cache, TimeSpan.FromMilliseconds(40));
        session.UpdateLayout(Stage(2), session.Epoch);
        Assert.False(await session.SaveAsync(session.Epoch).WaitAsync(TimeSpan.FromSeconds(2)));
        using JsonDocument state = State(session);
        Assert.True(state.RootElement.GetProperty("can_save").GetBoolean());
        Assert.True(state.RootElement.GetProperty("dirty").GetBoolean());
        response.SetResult(Reply(Stage(2), 1));
    }

    [Fact]
    public async Task ConflictKeepsDraftAndRequiresExplicitReload()
    {
        var api = new FakeApi();
        api.Override = (method, _, _) => Task.FromResult(method == HttpMethod.Post
            ? new HttpResponseMessage(HttpStatusCode.Conflict) { Content = new StringContent("{\"code\":\"scene_conflict\"}") }
            : Reply(Stage(4), 9));
        using var session = new TownPrototypeSession(new FakeAuth(new AuthUser("a", "A")), api, Default, _cache);
        session.UpdateLayout(Stage(2), session.Epoch);
        Assert.False(await session.SaveAsync(session.Epoch));
        using (JsonDocument state = State(session))
        {
            Assert.True(state.RootElement.GetProperty("conflict").GetBoolean());
            Assert.Equal(2, state.RootElement.GetProperty("layout").GetProperty("demo_stage").GetInt32());
        }
        await session.LoadAsync(discardDraft: true);
        using JsonDocument loaded = State(session);
        Assert.False(loaded.RootElement.GetProperty("dirty").GetBoolean());
        Assert.Equal(4, loaded.RootElement.GetProperty("layout").GetProperty("demo_stage").GetInt32());
        Assert.Equal(9, loaded.RootElement.GetProperty("scene_version").GetInt64());
    }

    [Fact]
    public void DraftCacheIsIsolatedByAccount()
    {
        var auth = new FakeAuth(new AuthUser("a", "A"));
        var api = new FakeApi();
        using (var first = new TownPrototypeSession(auth, api, Default, _cache)) first.UpdateLayout(Stage(2), first.Epoch);
        auth.Switch(new AuthUser("b", "B"));
        using (var other = new TownPrototypeSession(auth, api, Default, _cache))
        {
            using JsonDocument state = State(other);
            Assert.Equal(5, state.RootElement.GetProperty("layout").GetProperty("demo_stage").GetInt32());
        }
        auth.Switch(new AuthUser("a", "A"));
        using var restored = new TownPrototypeSession(auth, api, Default, _cache);
        using JsonDocument loaded = State(restored);
        Assert.True(restored.IsDirty);
        Assert.Equal(2, loaded.RootElement.GetProperty("layout").GetProperty("demo_stage").GetInt32());
    }

    [Fact]
    public async Task ReloadDoesNotDiscardEditsMadeWhileWaiting()
    {
        var response = new TaskCompletionSource<HttpResponseMessage>(TaskCreationOptions.RunContinuationsAsynchronously);
        var api = new FakeApi { Override = (_, _, _) => response.Task };
        using var session = new TownPrototypeSession(new FakeAuth(new AuthUser("a", "A")), api, Default, _cache);
        session.UpdateLayout(Stage(2), session.Epoch);
        Task loading = session.LoadAsync(discardDraft: true);
        session.UpdateLayout(Stage(3), session.Epoch);
        response.SetResult(Reply(Stage(4), 9));
        await loading;
        using JsonDocument state = State(session);
        Assert.True(session.IsDirty);
        Assert.Equal(3, state.RootElement.GetProperty("layout").GetProperty("demo_stage").GetInt32());
        Assert.True(state.RootElement.GetProperty("conflict").GetBoolean());
    }

    [Fact]
    public void InvalidDraftVersionDoesNotPartiallyRestoreLayoutOrPreventOpening()
    {
        var auth = new FakeAuth(new AuthUser("a", "A"));
        var api = new FakeApi();
        using (var original = new TownPrototypeSession(auth, api, Default, _cache))
            original.UpdateLayout(Stage(2), original.Epoch);
        string path = Directory.GetFiles(_cache, "*.json").Single();
        string corrupted = File.ReadAllText(path).Replace("\"scene_version\":0", "\"scene_version\":18446744073709551616");
        File.WriteAllText(path, corrupted);
        using var restored = new TownPrototypeSession(auth, api, Default, _cache);
        using JsonDocument state = State(restored);
        Assert.False(restored.IsDirty);
        Assert.Equal(5, state.RootElement.GetProperty("layout").GetProperty("demo_stage").GetInt32());
    }

    private static HttpResponseMessage Reply(string layout, long version) => new(HttpStatusCode.OK)
    { Content = new StringContent($"{{\"scene_version\":{version},\"layout\":{layout}}}", Encoding.UTF8, "application/json") };

    public void Dispose()
    {
        if (Directory.Exists(_cache)) Directory.Delete(_cache, true);
    }

    private sealed class FakeApi : IAuthenticatedAccountApi
    {
        public readonly List<string> Commands = [];
        public Func<HttpMethod, byte[]?, CancellationToken, Task<HttpResponseMessage>>? Override;
        public bool LoseNextSave;
        public int Commits;
        private string _layout = Default;
        private string? _lastCommand;
        public Task<HttpResponseMessage> SendAsync(HttpMethod method, string path, byte[]? jsonBody,
            string? contentEncoding, CancellationToken token = default)
        {
            Assert.StartsWith("../tree/town-prototype/", path);
            if (Override is not null) return Override(method, jsonBody, token);
            if (method == HttpMethod.Get) return Task.FromResult(Reply(_layout, Commits));
            string command = Encoding.UTF8.GetString(jsonBody!);
            Commands.Add(command);
            if (_lastCommand != command)
            {
                using JsonDocument doc = JsonDocument.Parse(command);
                _layout = doc.RootElement.GetProperty("layout").GetRawText();
                _lastCommand = command;
                Commits++;
            }
            if (LoseNextSave) { LoseNextSave = false; throw new HttpRequestException("Response lost"); }
            return Task.FromResult(Reply(_layout, Commits));
        }
    }

    private sealed class FakeAuth(AuthUser? user) : IAuthSession
    {
        public event Action? SessionChanged;
        public bool IsAuthenticated => CurrentUser is not null;
        public AuthUser? CurrentUser { get; private set; } = user;
        public void Switch(AuthUser? next) { CurrentUser = next; SessionChanged?.Invoke(); }
        public Task<AuthUser> RegisterAsync(string username, string password, CancellationToken token = default) => throw new NotSupportedException();
        public Task<AuthUser> SignInAsync(string username, string password, CancellationToken token = default) => throw new NotSupportedException();
        public Task RefreshAsync(CancellationToken token = default) => Task.CompletedTask;
        public Task RestoreAsync(CancellationToken token = default) => Task.CompletedTask;
        public Task SignOutAsync(CancellationToken token = default) { Switch(null); return Task.CompletedTask; }
    }
}
#endif
