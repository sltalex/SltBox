using System.Net;
using System.Text;
using System.Text.Json;
using DeskBox.Contracts;
using DeskBox.Core.Persistence;

namespace DeskBox.Services;

internal sealed class TreeCloudSyncService : IAsyncDisposable
{
    private readonly IAuthenticatedAccountApi _api;
    private readonly IAuthSession _auth;
    private readonly Func<TreeProgress> _readProgress;
    private readonly Func<TreeStateDto, TreeProgress, bool> _applyCloudState;
    private readonly Action<TreeStateDto, Action<TreeProgress>> _applyAuthoritativeCloudState;
    private readonly Func<TreeStateDto, TreeProgress, bool> _applyAuthoritativeIfUnchanged;
    private readonly string _stateDirectory;
    private readonly Func<DateTimeOffset> _clock;
    private readonly SemaphoreSlim _gate = new(1, 1);
    private readonly CancellationTokenSource _lifetime = new();
    private PeriodicTimer? _timer;
    private Task? _timerTask;
    private int _disposed;

    internal TreeCloudSyncService(
        IAuthenticatedAccountApi api,
        IAuthSession auth,
        Func<TreeProgress>? readProgress = null,
        string? stateDirectory = null,
        Func<DateTimeOffset>? clock = null,
        Func<TreeStateDto, TreeProgress, bool>? applyCloudState = null,
        Action<TreeStateDto, Action<TreeProgress>>? applyAuthoritativeCloudState = null,
        Func<TreeStateDto, TreeProgress, bool>? applyAuthoritativeIfUnchanged = null)
    {
        _api = api;
        _auth = auth;
        bool usesProcessCounter = readProgress is null;
        _readProgress = readProgress ?? (() => TreeCounterService.Instance.Snapshot);
        _applyCloudState = applyCloudState ?? ((state, expected) => TreeCounterService.Instance.TryApplyCloudState(state, expected));
        _applyAuthoritativeCloudState = applyAuthoritativeCloudState
            ?? (usesProcessCounter && applyCloudState is null
                ? (state, preserve) => TreeCounterService.Instance.ApplyAuthoritativeCloudState(state, preserve)
                : (state, preserve) => { TreeProgress latest = _readProgress(); preserve(latest); _applyCloudState(state, latest); });
        _applyAuthoritativeIfUnchanged = applyAuthoritativeIfUnchanged
            ?? (usesProcessCounter ? TreeCounterService.Instance.TryApplyAuthoritativeCloudState :
                (state, expected) => { _applyCloudState(state, expected); return true; });
        _stateDirectory = stateDirectory ?? Path.Combine(DeskBoxDataPathService.Current.DataDirectory, "sync", "tree");
        _clock = clock ?? (() => DateTimeOffset.Now);
        _auth.SessionChanged += OnSessionChanged;
    }

    internal event Action? LeaderboardChanged;
    internal TreeLeaderboardEntryDto[] Leaderboard { get; private set; } = [];
    internal TreeLeaderboardCurrentUserDto CurrentUserRank { get; private set; } = new();
    internal DateTimeOffset? LastLeaderboardSuccess { get; private set; }
    internal bool IsOffline { get; private set; }
    internal bool HasConflict { get; private set; }

    internal void Start()
    {
        if (_timerTask is not null) return;
        _timer = new PeriodicTimer(TimeSpan.FromMinutes(5));
        _timerTask = RunTimerAsync(_lifetime.Token);
        if (_auth.IsAuthenticated) _ = RunCycleSafelyAsync(_lifetime.Token);
    }

    internal async Task<bool> SyncNowAsync(CancellationToken cancellationToken = default)
    {
        if (_auth.CurrentUser is not { } user) return false;
        await _gate.WaitAsync(cancellationToken);
        try
        {
            Directory.CreateDirectory(_stateDirectory);
            string statePath = StatePath(user.Id);
            TreeCloudState state = await LoadStateAsync(statePath, user.Id, cancellationToken);
            HasConflict = state.ConflictSnapshot is not null;
            TreeStateDto cloud = await ReadStateAsync(cancellationToken);
            TreeProgress local = _readProgress();
            if (state.Acknowledged is null && IsEmpty(local) && cloud.TotalEnergy > 0)
            {
                _applyAuthoritativeCloudState(cloud, _ => { });
                state.Acknowledged = cloud;
                state.StateVersion = cloud.StateVersion;
                await SaveStateAsync(statePath, state, cancellationToken);
                IsOffline = false;
                return true;
            }
            TreeStateDto baseline = state.Acknowledged ?? InitialBaseline(cloud);
            if (IsBehind(local, baseline))
            {
                _applyAuthoritativeCloudState(cloud, _ => { });
                state.Acknowledged = cloud;
                state.StateVersion = cloud.StateVersion;
                state.Pending = null;
                await SaveStateAsync(statePath, state, cancellationToken);
                IsOffline = false;
                HasConflict = false;
                return true;
            }
            TreeProgressDelta delta = CalculateDelta(local, baseline);
            if (!delta.HasChanges)
            {
                _applyAuthoritativeIfUnchanged(cloud, local);
                state.Acknowledged = cloud;
                state.StateVersion = cloud.StateVersion;
                state.Pending = null;
                state.RefreshRequired = false;
                await SaveStateAsync(statePath, state, cancellationToken);
                IsOffline = false;
                HasConflict = false;
                return true;
            }

            TreeProgressUploadRequest request = new()
            {
                OperationId = state.Pending?.OperationId ?? Guid.NewGuid().ToString("N"),
                DeviceId = DeviceIdentity.Id,
                Day = cloud.Day,
                EnergyDelta = delta.Energy,
                DayGrowthDelta = delta.TodayGrowth,
                FruitDelta = delta.Fruit,
                HarvestedDelta = delta.Harvested
            };
            state.Pending = new TreeStateSyncRequest { OperationId = request.OperationId, BaseVersion = cloud.StateVersion };
            await SaveStateAsync(statePath, state, cancellationToken);
            byte[] json = JsonSerializer.SerializeToUtf8Bytes(request, AccountCloudJsonContext.Default.TreeProgressUploadRequest);
            HttpResponseMessage? response = null;
            try
            {
                response = await _api.SendAsync(HttpMethod.Post, "../tree/progress.php", json, null, cancellationToken);
                await EnsureSuccessAsync(response, cancellationToken);
            }
            catch (AuthApiException error) when (error.StatusCode == HttpStatusCode.Conflict || error.Code == "state_conflict")
            {
                response?.Dispose();
                TreeStateDto latest = await ReadStateAsync(cancellationToken);
                await AdoptCloudAfterConflictAsync(statePath, state, latest, cancellationToken);
                return true;
            }
            if (response is null) throw new InvalidDataException("The tree progress response is empty.");
            using (response)
            {
                TreeProgressUploadResponse body = await JsonSerializer.DeserializeAsync(
                    await response.Content.ReadAsStreamAsync(cancellationToken),
                    AccountCloudJsonContext.Default.TreeProgressUploadResponse, cancellationToken)
                    ?? throw new InvalidDataException("The tree progress response is empty.");
            bool appliedToLocal = _applyAuthoritativeIfUnchanged(body.State, local);
            state.Acknowledged = appliedToLocal ? body.State : ProjectedAcknowledgement(baseline, delta, body.State);
            state.StateVersion = body.State.StateVersion;
            state.Pending = null;
            state.ConflictSnapshot = null;
            state.RefreshRequired = false;
            await SaveStateAsync(statePath, state, cancellationToken);
            IsOffline = false;
            HasConflict = false;
            _ = RefreshLeaderboardAfterUploadAsync();
            return true;
            }
        }
        catch (Exception ex) when (ex is HttpRequestException or TaskCanceledException)
        {
            IsOffline = true;
            RaiseLeaderboardChanged();
            throw;
        }
        finally { _gate.Release(); }
    }

    internal Task<bool> RefreshStateAsync(CancellationToken cancellationToken = default) => SyncNowAsync(cancellationToken);

    private static TreeStateDto InitialBaseline(TreeStateDto cloud) =>
        cloud.TotalEnergy == 0 && cloud.FruitTotal == 0 && cloud.HarvestedTotal == 0
            ? new TreeStateDto { Day = cloud.Day, TodayGrowth = 0, TotalEnergy = 0, FruitEnergy = 0, FruitTotal = 0, HarvestedTotal = 0 }
            : cloud;

    private static bool IsEmpty(TreeProgress progress) => progress.TotalEnergy == 0 && progress.TodayKeys == 0
        && progress.FruitInventory == 0 && progress.HarvestedFruit == 0 && progress.FruitEnergy == 0;

    private static bool IsBehind(TreeProgress local, TreeStateDto baseline) =>
        local.TotalEnergy < baseline.TotalEnergy || local.TodayKeys < baseline.TodayGrowth
        || local.FruitInventory + local.HarvestedFruit < baseline.FruitTotal
        || local.HarvestedFruit < baseline.HarvestedTotal;

    private static TreeStateDto ProjectedAcknowledgement(TreeStateDto baseline, TreeProgressDelta delta, TreeStateDto cloud) => new()
    {
        Day = cloud.Day,
        StateVersion = cloud.StateVersion,
        TodayGrowth = baseline.TodayGrowth + delta.TodayGrowth,
        TotalEnergy = baseline.TotalEnergy + delta.Energy,
        Stage = TreeProgress.StageFor(baseline.TotalEnergy + delta.Energy),
        FruitEnergy = cloud.FruitEnergy,
        FruitTotal = baseline.FruitTotal + delta.Fruit,
        HarvestedTotal = baseline.HarvestedTotal + delta.Harvested,
        FruitInventory = Math.Max(0, baseline.FruitTotal + delta.Fruit - baseline.HarvestedTotal - delta.Harvested)
    };

    private static TreeProgressDelta CalculateDelta(TreeProgress local, TreeStateDto baseline)
    {
        long localFruitTotal = local.FruitInventory + local.HarvestedFruit;
        long baseGrowth = string.Equals(local.LocalDay, baseline.Day, StringComparison.Ordinal) ? baseline.TodayGrowth : 0;
        return new TreeProgressDelta(
            Math.Max(0, local.TotalEnergy - baseline.TotalEnergy),
            Math.Max(0, local.TodayKeys - baseGrowth),
            Math.Max(0, localFruitTotal - baseline.FruitTotal),
            Math.Max(0, local.HarvestedFruit - baseline.HarvestedTotal));
    }

    private readonly record struct TreeProgressDelta(long Energy, long TodayGrowth, long Fruit, long Harvested)
    {
        public bool HasChanges => Energy != 0 || TodayGrowth != 0 || Fruit != 0 || Harvested != 0;
    }

    private TreeStateSyncRequest CreateRequest(TreeCloudState state, TreeProgress local) => new()
    {
        OperationId = Guid.NewGuid().ToString("N"),
        DeviceId = DeviceIdentity.Id,
        BaseVersion = state.StateVersion,
        // The server's UTC date is the only authoritative day. LocalDay is a
        // display/cache field and must never make a request target yesterday.
        Day = _clock().ToUniversalTime().ToString("yyyy-MM-dd", System.Globalization.CultureInfo.InvariantCulture),
        TodayGrowth = local.TodayKeys,
        TotalEnergy = local.TotalEnergy,
        Stage = local.Stage,
        FruitEnergy = local.FruitEnergy,
        FruitTotal = checked(local.FruitInventory + local.HarvestedFruit),
        HarvestedTotal = local.HarvestedFruit
    };

    private void RefreshPendingSnapshot(TreeStateSyncRequest pending, TreeProgress local)
    {
        // Keep the operation id and base version stable for idempotent retry,
        // but submit all local growth accumulated while the request was offline.
        TreeStateSyncRequest latest = CreateRequest(new TreeCloudState { StateVersion = pending.BaseVersion }, local);
        pending.DeviceId = latest.DeviceId;
        pending.Day = latest.Day;
        pending.TodayGrowth = latest.TodayGrowth;
        pending.TotalEnergy = latest.TotalEnergy;
        pending.Stage = latest.Stage;
        pending.FruitEnergy = latest.FruitEnergy;
        pending.FruitTotal = latest.FruitTotal;
        pending.HarvestedTotal = latest.HarvestedTotal;
    }

    private TreeStateSyncRequest CreateConflictSnapshot(TreeCloudState state, TreeProgress local)
    {
        TreeStateSyncRequest snapshot = CreateRequest(state, local);
        if (state.Pending is { } pending)
        {
            // Retain the queued operation identity/version so the snapshot
            // documents exactly which optimistic write conflicted.
            snapshot.OperationId = pending.OperationId;
            snapshot.BaseVersion = pending.BaseVersion;
        }
        return snapshot;
    }

    private async Task AdoptCloudAfterConflictAsync(string path, TreeCloudState state, TreeStateDto cloud, CancellationToken cancellationToken)
    {
        _applyAuthoritativeCloudState(cloud, latest =>
        {
            state.ConflictSnapshot = JsonSerializer.Serialize(
                CreateConflictSnapshot(state, latest), AccountCloudJsonContext.Default.TreeStateSyncRequest);
            // Run inside the counter's lock: every overwritten field is durably
            // preserved before the atomic replacement, including in-flight keys.
            string conflictPath = Path.Combine(_stateDirectory,
                $"conflict-{AccountBackupFileCatalog.SafeAccountId(state.UserId)}-{Guid.NewGuid():N}.json");
            File.WriteAllText(conflictPath, state.ConflictSnapshot);
        });
        state.Pending = null;
        state.StateVersion = cloud.StateVersion;
        state.RefreshRequired = false;
        await SaveStateAsync(path, state, cancellationToken);
        IsOffline = false;
        HasConflict = true;
        App.Log("[TreeCloud] Cloud conflict: local snapshot preserved; adopted MySQL state.");
        RaiseLeaderboardChanged();
    }

    private static TreeProgress ProgressFromRequest(TreeStateSyncRequest request) => new()
    {
        LocalDay = request.Day, TodayKeys = request.TodayGrowth, TotalEnergy = request.TotalEnergy,
        HighestStage = request.Stage, FruitEnergy = request.FruitEnergy,
        FruitInventory = request.FruitTotal - request.HarvestedTotal, HarvestedFruit = request.HarvestedTotal
    };

    private static bool MatchesSubmittedState(TreeStateDto cloud, TreeStateSyncRequest request) =>
        cloud.StateVersion == request.BaseVersion + 1 && cloud.Day == request.Day &&
        cloud.TotalEnergy == request.TotalEnergy && cloud.TodayGrowth == request.TodayGrowth &&
        cloud.Stage == request.Stage && cloud.FruitEnergy == request.FruitEnergy &&
        cloud.FruitTotal == request.FruitTotal && cloud.HarvestedTotal == request.HarvestedTotal;

    private async Task<TreeStateDto> ReadStateAsync(CancellationToken cancellationToken)
    {
        using HttpResponseMessage response = await _api.SendAsync(
            HttpMethod.Get, "../tree/progress.php", null, null, cancellationToken);
        await EnsureSuccessAsync(response, cancellationToken);
        TreeStateResponse body = await JsonSerializer.DeserializeAsync(
            await response.Content.ReadAsStreamAsync(cancellationToken),
            AccountCloudJsonContext.Default.TreeStateResponse,
            cancellationToken) ?? throw new InvalidDataException("The tree state response is empty.");
        return body.State;
    }

    internal async Task<TreeLeaderboardEntryDto[]> RefreshLeaderboardAsync(CancellationToken cancellationToken = default)
    {
        if (!_auth.IsAuthenticated) return Leaderboard;
        try
        {
            using HttpResponseMessage response = await _api.SendAsync(
                HttpMethod.Get, "../tree/leaderboard.php", null, null, cancellationToken);
            await EnsureSuccessAsync(response, cancellationToken);
            await using Stream stream = await response.Content.ReadAsStreamAsync(cancellationToken);
            TreeLeaderboardResponse body = await JsonSerializer.DeserializeAsync(
                stream, AccountCloudJsonContext.Default.TreeLeaderboardResponse, cancellationToken)
                ?? throw new InvalidDataException("The tree leaderboard response is empty.");
            Leaderboard = body.Entries;
            CurrentUserRank = body.CurrentUser;
            LastLeaderboardSuccess = DateTimeOffset.UtcNow;
            IsOffline = false;
            RaiseLeaderboardChanged();
            return Leaderboard;
        }
        catch (Exception ex) when (ex is HttpRequestException or TaskCanceledException)
        {
            IsOffline = true;
            RaiseLeaderboardChanged();
            return Leaderboard;
        }
    }

    internal async Task SetParticipationAsync(bool participating, CancellationToken cancellationToken = default)
    {
        byte[] json = JsonSerializer.SerializeToUtf8Bytes(
            new TreeProfileUpdateRequest { Participating = participating },
            AccountCloudJsonContext.Default.TreeProfileUpdateRequest);
        using HttpResponseMessage response = await _api.SendAsync(
            HttpMethod.Post, "../tree/profile.php", json, null, cancellationToken);
        await EnsureSuccessAsync(response, cancellationToken);
        CurrentUserRank.Participating = participating;
        await RefreshLeaderboardAsync(cancellationToken);
    }

    private async Task RunTimerAsync(CancellationToken cancellationToken)
    {
        try
        {
            while (_timer is not null && await _timer.WaitForNextTickAsync(cancellationToken))
                await RunCycleSafelyAsync(cancellationToken);
        }
        catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested) { }
    }

    private async Task RunCycleSafelyAsync(CancellationToken cancellationToken)
    {
        try { await SyncNowAsync(cancellationToken); } catch (Exception ex) when (ex is not OperationCanceledException) { App.Log($"[TreeCloud] Progress sync failed: {ex.GetType().Name}"); }
        try { await RefreshLeaderboardAsync(cancellationToken); } catch (Exception ex) when (ex is not OperationCanceledException) { App.Log($"[TreeCloud] Leaderboard refresh failed: {ex.GetType().Name}"); }
    }

    private void OnSessionChanged()
    {
        RaiseLeaderboardChanged();
        if (_auth.IsAuthenticated) _ = RunCycleSafelyAsync(_lifetime.Token);
    }

    private async Task RefreshLeaderboardAfterUploadAsync()
    {
        try { await RefreshLeaderboardAsync(_lifetime.Token); }
        catch (Exception ex) when (ex is not OperationCanceledException) { App.Log($"[TreeCloud] Post-upload ranking refresh failed: {ex.GetType().Name}"); }
    }

    private async Task<TreeCloudState> LoadStateAsync(string path, string userId, CancellationToken cancellationToken)
    {
        if (!File.Exists(path)) return new TreeCloudState { UserId = userId };
        try
        {
            string json = await File.ReadAllTextAsync(path, cancellationToken);
            TreeCloudState? state = JsonSerializer.Deserialize(json, AccountCloudJsonContext.Default.TreeCloudState);
            if (state is not null && string.Equals(state.UserId, userId, StringComparison.Ordinal)
                && (state.Pending is not null || state.StateVersion != 0 || state.ConflictSnapshot is not null || state.RefreshRequired))
                return state;

            // Migrate the former delta queue without silently dropping its
            // operation. The next sync refreshes the snapshot from the current
            // local counter while retaining a stable operation identity.
            using JsonDocument document = JsonDocument.Parse(json);
            if (document.RootElement.TryGetProperty("pending", out JsonElement legacyPending)
                && legacyPending.TryGetProperty("request", out JsonElement request))
            {
                TreeStateSyncRequest? oldRequest = request.Deserialize(AccountCloudJsonContext.Default.TreeProgressUploadRequest) is { } delta
                    ? new TreeStateSyncRequest
                    {
                        OperationId = delta.OperationId,
                        DeviceId = delta.DeviceId,
                        BaseVersion = 0,
                        Day = delta.Day,
                        TodayGrowth = 0,
                        TotalEnergy = 0,
                        Stage = 1,
                        FruitEnergy = 0,
                        FruitTotal = 0,
                        HarvestedTotal = 0
                    } : null;
                if (oldRequest is not null)
                {
                    if (legacyPending.TryGetProperty("capturedEnergy", out JsonElement energy)) oldRequest.TotalEnergy = energy.GetInt64();
                    if (legacyPending.TryGetProperty("capturedDayGrowth", out JsonElement growth)) oldRequest.TodayGrowth = growth.GetInt64();
                    if (legacyPending.TryGetProperty("capturedFruit", out JsonElement fruit)) oldRequest.FruitTotal = fruit.GetInt64();
                    if (legacyPending.TryGetProperty("capturedHarvested", out JsonElement harvested)) oldRequest.HarvestedTotal = harvested.GetInt64();
                    oldRequest.Stage = TreeProgress.StageFor(oldRequest.TotalEnergy);
                    return new TreeCloudState { UserId = userId, Pending = oldRequest };
                }
            }
            return new TreeCloudState { UserId = userId };
        }
        catch (JsonException) { return new TreeCloudState { UserId = userId }; }
    }

    private static Task SaveStateAsync(string path, TreeCloudState state, CancellationToken cancellationToken)
    {
        cancellationToken.ThrowIfCancellationRequested();
        return ResilientJsonStore.SaveAsync(
            path,
            JsonSerializer.Serialize(state, AccountCloudJsonContext.Default.TreeCloudState));
    }

    private string StatePath(string userId) => Path.Combine(_stateDirectory, $"state-{AccountBackupFileCatalog.SafeAccountId(userId)}.json");
    private static async Task EnsureSuccessAsync(HttpResponseMessage response, CancellationToken cancellationToken)
    {
        if (response.IsSuccessStatusCode) return;
        AuthErrorResponse? error = null;
        try
        {
            await using Stream stream = await response.Content.ReadAsStreamAsync(cancellationToken);
            error = await JsonSerializer.DeserializeAsync(stream, AuthJsonContext.Default.AuthErrorResponse, cancellationToken);
        }
        catch (JsonException) { }
        throw new AuthApiException(error?.Code ?? "server_error", error?.Message ?? "Tree cloud request failed.", response.StatusCode);
    }

    private void RaiseLeaderboardChanged()
    {
        foreach (Action observer in LeaderboardChanged?.GetInvocationList() ?? [])
        {
            try { observer(); } catch (Exception ex) { App.Log($"[TreeCloud] Observer failed: {ex.GetType().Name}"); }
        }
    }

    public async ValueTask DisposeAsync()
    {
        if (Interlocked.Exchange(ref _disposed, 1) != 0)
            return;

        _auth.SessionChanged -= OnSessionChanged;
        _lifetime.Cancel();
        _timer?.Dispose();
        if (_timerTask is not null) { try { await _timerTask; } catch { } }
        _lifetime.Dispose();
        _gate.Dispose();
    }
}
