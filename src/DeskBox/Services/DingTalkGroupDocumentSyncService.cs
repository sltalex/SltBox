using DeskBox.Contracts;

namespace DeskBox.Services;

/// <summary>
/// Owns the background cadence for the DingTalk group-document index. The
/// widget can still invoke the same service directly; this coordinator keeps
/// the slow first scan off the UI thread and never runs two scans at once.
/// </summary>
internal sealed class DingTalkGroupDocumentSyncService : IAsyncDisposable
{
    private readonly DingTalkGroupDocumentService _documents;
    private readonly IDingTalkDwsClient _dws;
    private readonly DingTalkAccountService _account;
    private readonly IAuthSession _auth;
    private readonly SemaphoreSlim _gate = new(1, 1);
    private readonly CancellationTokenSource _lifetime = new();
    private readonly object _workersLock = new();
    private readonly HashSet<Task> _workers = [];
    private PeriodicTimer? _timer;
    private Task? _timerTask;
    private CancellationTokenSource? _activeSyncCancellation;
    private bool _accountStateKnown;
    private string? _linkedAccountId;
    private DingTalkIdentityDto? _linkedIdentity;
    private int _generation;
    private int _disposed;

    internal DingTalkGroupDocumentSyncService(
        DingTalkGroupDocumentService documents,
        IDingTalkDwsClient dws,
        DingTalkAccountService account,
        IAuthSession auth)
    {
        _documents = documents;
        _dws = dws;
        _account = account;
        _auth = auth;
        _auth.SessionChanged += OnSessionChanged;
        _account.IdentityChanged += OnIdentityChanged;
    }

    internal event Action<DingTalkGroupDocumentSyncProgress>? ProgressChanged;

    internal DingTalkGroupDocumentService Documents => _documents;

    internal void Start()
    {
        if (_timerTask is not null) return;
        _timer = new PeriodicTimer(TimeSpan.FromMinutes(1));
        _timerTask = RunTimerAsync(_lifetime.Token);
        if (_auth.IsAuthenticated) ScheduleSync(force: false);
    }

    internal Task<bool> SyncNowAsync(CancellationToken cancellationToken = default)
    {
        lock (_workersLock)
        {
            if (Volatile.Read(ref _disposed) != 0) return Task.FromResult(false);
            Task<bool> worker = Task.Run(async () =>
            {
                using var linked = CancellationTokenSource.CreateLinkedTokenSource(cancellationToken, _lifetime.Token);
                return await RunIfDueAsync(force: true, linked.Token, retryUnavailableTitles: true);
            });
            TrackWorker(worker);
            return worker;
        }
    }

    internal async Task<bool> SetIntervalHoursAsync(int hours, CancellationToken cancellationToken = default)
    {
        await _documents.SetSyncIntervalHoursAsync(hours, cancellationToken);
        return true;
    }

    private async Task RunTimerAsync(CancellationToken cancellationToken)
    {
        try
        {
            while (await _timer!.WaitForNextTickAsync(cancellationToken))
                await RunIfDueSafelyAsync(force: false, cancellationToken);
        }
        catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested) { }
    }

    private async Task<bool> RunIfDueSafelyAsync(
        bool force, CancellationToken cancellationToken, bool waitIfBusy = false)
    {
        try { return await RunIfDueAsync(force, cancellationToken, waitIfBusy); }
        catch (Exception ex)
        {
            if (!cancellationToken.IsCancellationRequested)
                App.Log($"[DingTalkGroupDocs] Background sync failed: {ex.GetType().Name}");
            return false;
        }
    }

    private async Task<bool> RunIfDueAsync(
        bool force, CancellationToken cancellationToken, bool waitIfBusy = false,
        bool retryUnavailableTitles = false)
    {
        if (!_auth.IsAuthenticated || _auth.CurrentUser is null) return false;
        string accountId = _auth.CurrentUser.Id;
        if (waitIfBusy) await _gate.WaitAsync(cancellationToken);
        else if (!await _gate.WaitAsync(0, cancellationToken)) return false;
        using var operationCancellation = CancellationTokenSource.CreateLinkedTokenSource(cancellationToken);
        _activeSyncCancellation = operationCancellation;
        int generation = Volatile.Read(ref _generation);
        CancellationToken operationToken = operationCancellation.Token;
        bool IsStillLinked() => generation == Volatile.Read(ref _generation) &&
            _auth.CurrentUser?.Id == accountId;
        try
        {
            // A DingTalk link is an account-level opt-in. The linked identity is
            // loaded once at startup/session change and then updated by the
            // account service's link/unlink event; do not poll the API every
            // minute just to check whether the link still exists.
            if (!_accountStateKnown)
            {
                DingTalkIdentityDto? identity = await _account.GetAsync(operationToken);
                if (!IsStillLinked()) return false;
                _linkedIdentity = identity;
                _linkedAccountId = identity is null ? null : accountId;
                _accountStateKnown = true;
            }
            DingTalkIdentityDto? linkedIdentity = _linkedIdentity;
            if (!IsStillLinked() || _linkedAccountId != accountId || linkedIdentity is null) return false;
            DingTalkDwsAuthStatus status = await _dws.GetAuthStatusAsync(operationToken);
            if (!IsStillLinked()) return false;
            if (!status.Authenticated || !status.TokenValid ||
                !string.Equals(status.CorpId, linkedIdentity.CorpId, StringComparison.Ordinal) ||
                !string.Equals(status.UserId, linkedIdentity.DingtalkUserId, StringComparison.Ordinal))
                return false;

            await _documents.LoadAsync(operationToken);
            if (!IsStillLinked()) return false;
            await _documents.UploadCloudAsync(operationToken);
            if (!IsStillLinked()) return false;
            DateTimeOffset? last = _documents.LastSyncCompletedAt;
            if (!force && last is not null &&
                DateTimeOffset.Now - last.Value < TimeSpan.FromHours(_documents.SyncIntervalHours))
                return false;

            _documents.ProgressChanged += ForwardProgress;
            try
            {
                await _documents.SyncAsync(IsStillLinked, retryUnavailableTitles, operationToken);
                if (IsStillLinked()) await _documents.UploadCloudAsync(operationToken);
            }
            finally
            {
                _documents.ProgressChanged -= ForwardProgress;
            }
            return true;
        }
        finally
        {
            _activeSyncCancellation = null;
            _gate.Release();
        }
    }

    private void ForwardProgress(DingTalkGroupDocumentSyncProgress progress) =>
        ProgressChanged?.Invoke(progress);

    private void OnSessionChanged()
    {
        Interlocked.Increment(ref _generation);
        CancelActiveSync();
        _accountStateKnown = false;
        _linkedAccountId = null;
        _linkedIdentity = null;
        _account.ClearCachedIdentity();
        if (_auth.IsAuthenticated && Volatile.Read(ref _disposed) == 0)
            ScheduleSync(force: true);
    }

    private void OnIdentityChanged()
    {
        Interlocked.Increment(ref _generation);
        CancelActiveSync();
        _accountStateKnown = true;
        _linkedIdentity = _account.CurrentIdentity;
        _linkedAccountId = _linkedIdentity is null ? null : _auth.CurrentUser?.Id;
        if (_auth.IsAuthenticated && Volatile.Read(ref _disposed) == 0)
            ScheduleSync(force: true);
    }

    private void ScheduleSync(bool force)
    {
        lock (_workersLock)
        {
            if (Volatile.Read(ref _disposed) != 0) return;
            Task worker = Task.Run(() => RunIfDueSafelyAsync(force, _lifetime.Token, waitIfBusy: force));
            TrackWorker(worker);
        }
    }

    private void TrackWorker(Task worker)
    {
        _workers.Add(worker);
        _ = worker.ContinueWith(completed =>
        {
            lock (_workersLock) _workers.Remove(completed);
        }, TaskScheduler.Default);
    }

    private void CancelActiveSync()
    {
        try { _activeSyncCancellation?.Cancel(); }
        catch (ObjectDisposedException) { }
    }

    public async ValueTask DisposeAsync()
    {
        Task[] workers;
        lock (_workersLock)
        {
            if (Interlocked.Exchange(ref _disposed, 1) != 0) return;
            workers = [.. _workers];
        }
        _auth.SessionChanged -= OnSessionChanged;
        _account.IdentityChanged -= OnIdentityChanged;
        CancelActiveSync();
        await _lifetime.CancelAsync();
        _timer?.Dispose();
        if (_timerTask is not null)
        {
            try { await _timerTask; } catch (OperationCanceledException) { }
        }
        try { await Task.WhenAll(workers); }
        catch (OperationCanceledException) { }
        finally
        {
            _lifetime.Dispose();
            _gate.Dispose();
        }
    }
}
