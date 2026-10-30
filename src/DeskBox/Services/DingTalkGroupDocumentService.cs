using System.Text.Json;
using System.Text.RegularExpressions;
using System.Security.Cryptography;
using System.Diagnostics;
using DeskBox.Core.Persistence;

namespace DeskBox.Services;

public sealed record DingTalkGroupDocument(
    string GroupName,
    string Title,
    string Url,
    DateTimeOffset LastSeenAt,
    string ConversationId = "");

public sealed record DingTalkGroupDocumentSyncProgress(
    int CompletedSegments,
    int TotalSegments,
    bool IsInitialSync,
    bool IsRunning,
    int PagesFetched = 0,
    int CurrentSegmentPages = 0,
    int DocumentsFound = 0,
    DateTimeOffset? WindowStart = null,
    DateTimeOffset? WindowEnd = null)
{
    // The total page count is unknown until each window ends. Keep this
    // estimate below the next completed-window boundary while pages arrive.
    public double EstimatedCompletion => TotalSegments <= 0 ? 0 : Math.Clamp(
        (CompletedSegments + Math.Min(0.9, CurrentSegmentPages / (CurrentSegmentPages + 2.0))) /
        TotalSegments, 0, 1);

    public string FormatDetails(LocalizationService localization) => localization.Format(
        "DingTalkGroupDocs.Sync.Details", CompletedSegments, TotalSegments,
        PagesFetched, DocumentsFound,
        (WindowStart ?? WindowEnd ?? DateTimeOffset.Now).ToLocalTime(),
        (WindowEnd ?? WindowStart ?? DateTimeOffset.Now).ToLocalTime());
}

public sealed class DingTalkGroupDocumentService
{
    private const int CurrentIndexVersion = 2;
    private static readonly Regex MarkdownLinkRegex = new(
        @"\[(?<title>[^\]]+)\]\((?<url>https://(?:alidocs|dlink)\.dingtalk\.com/[^\s\)<>]+)\)",
        RegexOptions.Compiled | RegexOptions.CultureInvariant | RegexOptions.IgnoreCase);
    private static readonly Regex PlainLinkRegex = new(
        @"(?<url>https://(?:alidocs|dlink)\.dingtalk\.com/[^\s\]\)<>]+)",
        RegexOptions.Compiled | RegexOptions.CultureInvariant | RegexOptions.IgnoreCase);
    private static readonly Regex OfficeFileTitleRegex = new(
        @"(?m)^\s*(?:\[[^\]\r\n]{1,20}\]\s*)?(?<title>[^\r\n]{1,255}?\.(?:docx?|xlsx?|pptx?))\s+fileId:\s*(?<fileId>[^\s\r\n]+)",
        RegexOptions.Compiled | RegexOptions.CultureInvariant | RegexOptions.IgnoreCase);

    private readonly IDingTalkDwsClient _dws;
    private readonly DingTalkGroupDocumentCloudStore? _cloud;
    private readonly DeskBox.Contracts.IAuthSession? _auth;
    private readonly DingTalkAccountService? _account;
    private readonly string _stateDirectory;
    private readonly string _settingsPath;
    private readonly SemaphoreSlim _gate = new(1, 1);
    private DingTalkGroupDocument[] _documents = [];
    private string? _loadedAccountKey;
    private string? _stateOwnerUserId;
    private string? _statePath;
    private bool _stateLoaded;
    private string? _cloudOwnerId;
    private DingTalkGroupDocumentCloudSnapshot? _cloudSnapshot;
    private string? _activeSltboxUserId;
    private bool _cloudUploadPending;
    private DateTimeOffset? _initialScanStart;
    private DateTimeOffset? _initialScanEnd;
    private DateTimeOffset? _initialScanNextEnd;
    private int _initialScanCompletedSegments;
    private DateTimeOffset? _pendingWindowStart;
    private DateTimeOffset? _pendingWindowEnd;
    private string? _pendingCursor;
    private int _pendingPages;
    private DingTalkGroupDocument[] _pendingDocuments = [];
    private Dictionary<string, DateTimeOffset> _titleRetryAfter = new(StringComparer.OrdinalIgnoreCase);

    public DingTalkGroupDocumentService(IDingTalkDwsClient dws, string? stateDirectory = null)
        : this(dws, stateDirectory, null, null, null) { }

    internal DingTalkGroupDocumentService(IDingTalkDwsClient dws, string? stateDirectory,
        DingTalkGroupDocumentCloudStore? cloud = null, DeskBox.Contracts.IAuthSession? auth = null,
        DingTalkAccountService? account = null)
    {
        _dws = dws;
        _cloud = cloud;
        _auth = auth;
        _account = account;
        _stateDirectory = stateDirectory ?? Path.Combine(
            DeskBoxDataPathService.Current.DataDirectory, "sync", "dingtalk");
        _settingsPath = Path.Combine(_stateDirectory, "group-documents-settings.json");
        if (_auth is null) LoadIntervalSettingsFromDisk();
    }

    public IReadOnlyList<DingTalkGroupDocument> Documents => _documents;
    public DateTimeOffset? LastRefresh { get; private set; }
    public DateTimeOffset? LastSyncStartedAt { get; private set; }
    public DateTimeOffset? LastSyncCompletedAt { get; private set; }
    public bool HasCompletedInitialSync { get; private set; }
    public int SyncIntervalHours { get; private set; } = 6;
    public bool IsSyncing { get; private set; }

    public event Action<DingTalkGroupDocumentSyncProgress>? ProgressChanged;

    public async Task<IReadOnlyList<DingTalkGroupDocument>> LoadAsync(
        CancellationToken cancellationToken = default)
    {
        await _gate.WaitAsync(cancellationToken);
        try
        {
            if (_auth is not null && _activeSltboxUserId != _auth.CurrentUser?.Id)
            {
                _documents = [];
                _cloudOwnerId = null;
                _cloudSnapshot = null;
                _activeSltboxUserId = _auth.CurrentUser?.Id;
                _stateLoaded = false;
                _loadedAccountKey = null;
                _statePath = null;
                _stateOwnerUserId = null;
                LastSyncCompletedAt = null;
                HasCompletedInitialSync = false;
                SyncIntervalHours = 6;
                _cloudUploadPending = false;
            }
            try { await LoadCoreAsync(cancellationToken); }
            catch (DingTalkDwsAuthorizationRequiredException) when (_cloud is not null && _auth?.IsAuthenticated == true)
            {
                // A new computer can display the account's saved index before DWS is authorized.
            }
            await HydrateCloudAsync(cancellationToken);
            return _documents;
        }
        finally { _gate.Release(); }
    }

    private async Task HydrateCloudAsync(CancellationToken cancellationToken)
    {
        if (_cloud is null || _auth?.CurrentUser is not { } user) return;
        DingTalkGroupDocumentCloudSnapshot? remote = _cloudOwnerId == user.Id ? _cloudSnapshot : null;
        if (_cloudOwnerId != user.Id)
        {
            try { remote = await _cloud.ReadAsync(cancellationToken); }
            catch (Exception error) when (error is HttpRequestException or AuthApiException or InvalidDataException or TaskCanceledException)
            {
                App.Log($"[DingTalkGroupDocs] Cloud index load deferred: {error.GetType().Name}");
                return;
            }
        }
        if (_auth.CurrentUser?.Id != user.Id || remote is null) return;
        _cloudOwnerId = user.Id;
        _cloudSnapshot = remote;
        DingTalkGroupDocument[] merged = DingTalkGroupDocumentRepair.Normalize(
            _documents.Concat(remote.Documents));
        _cloudUploadPending = !merged.SequenceEqual(DingTalkGroupDocumentRepair.Normalize(remote.Documents)) ||
            (LastSyncCompletedAt is { } localTime &&
             (remote.LastSyncCompletedAt is null || localTime > remote.LastSyncCompletedAt));
        bool changed = !_documents.SequenceEqual(merged);
        _documents = merged;
        if (remote.LastSyncCompletedAt is { } cloudTime &&
            (LastSyncCompletedAt is null || cloudTime > LastSyncCompletedAt))
        {
            LastSyncCompletedAt = cloudTime;
            HasCompletedInitialSync = true;
            changed = true;
        }
        if (!File.Exists(SettingsPath())) SyncIntervalHours = NormalizeInterval(remote.SyncIntervalHours ?? 6);
        if (changed && _statePath is not null)
            await SaveAsync(CurrentStore(), cancellationToken);
    }

    internal async Task<bool> UploadCloudAsync(CancellationToken cancellationToken = default)
    {
        if (_cloud is null || _auth?.CurrentUser is null) return false;
        await _gate.WaitAsync(cancellationToken);
        try
        {
            if (!_cloudUploadPending) return true;
            string owner = _auth.CurrentUser.Id;
            DingTalkGroupDocumentCloudSnapshot saved = await _cloud.MergeAsync(
                _documents, LastSyncCompletedAt, null, cancellationToken);
            if (_auth.CurrentUser?.Id != owner) return false;
            _documents = DingTalkGroupDocumentRepair.Normalize(_documents.Concat(saved.Documents));
            _cloudSnapshot = saved;
            _cloudUploadPending = false;
            if (_statePath is not null) await SaveAsync(CurrentStore(), cancellationToken);
            return true;
        }
        finally { _gate.Release(); }
    }

    private DingTalkGroupDocumentStore CurrentStore() => new()
    {
        LastRefresh = LastRefresh, LastSyncStartedAt = LastSyncStartedAt,
        LastSyncCompletedAt = LastSyncCompletedAt, HasCompletedInitialSync = HasCompletedInitialSync,
        InitialScanStart = _initialScanStart, InitialScanEnd = _initialScanEnd,
        InitialScanNextEnd = _initialScanNextEnd,
        InitialScanCompletedSegments = _initialScanCompletedSegments,
        PendingWindowStart = _pendingWindowStart, PendingWindowEnd = _pendingWindowEnd,
        PendingCursor = _pendingCursor, PendingPages = _pendingPages,
        PendingDocuments = _pendingDocuments, TitleRetryAfter = _titleRetryAfter,
        SyncIntervalHours = SyncIntervalHours, Documents = _documents
    };

    private async Task<IReadOnlyList<DingTalkGroupDocument>> LoadCoreAsync(
        CancellationToken cancellationToken)
    {
        await EnsureAuthenticatedStatePathAsync(cancellationToken);
        if (_stateLoaded) return _documents;
        if (_statePath is null || !File.Exists(_statePath))
        {
            await LoadIntervalSettingsAsync(cancellationToken);
            _stateLoaded = true;
            _cloudUploadPending = _documents.Length > 0;
            return _documents;
        }
        try
        {
            string json = await File.ReadAllTextAsync(_statePath, cancellationToken);
            DingTalkGroupDocumentStore? store = JsonSerializer.Deserialize(
                json, DingTalkGroupDocumentJsonContext.Default.DingTalkGroupDocumentStore);
            bool needsBackfill = store is not null && store.IndexVersion < CurrentIndexVersion;
            if (needsBackfill)
            {
                store!.HasCompletedInitialSync = false;
                store.LastSyncCompletedAt = null;
                store.InitialScanStart = null;
                store.InitialScanEnd = null;
                store.InitialScanNextEnd = null;
                store.InitialScanCompletedSegments = 0;
                store.PendingWindowStart = null;
                store.PendingWindowEnd = null;
                store.PendingCursor = null;
                store.PendingPages = 0;
                store.PendingDocuments = [];
            }
            _documents = DingTalkGroupDocumentRepair.Normalize(store?.Documents ?? []);
            _cloudUploadPending = _documents.Length > 0;
            LastRefresh = store?.LastRefresh;
            LastSyncStartedAt = store?.LastSyncStartedAt;
            HasCompletedInitialSync = !needsBackfill && store?.HasCompletedInitialSync == true;
            LastSyncCompletedAt = HasCompletedInitialSync
                ? store?.LastSyncCompletedAt ?? store?.LastRefresh
                : null;
            _initialScanStart = store?.InitialScanStart;
            _initialScanEnd = store?.InitialScanEnd;
            _initialScanNextEnd = store?.InitialScanNextEnd;
            _initialScanCompletedSegments = store?.InitialScanCompletedSegments ?? 0;
            _pendingWindowStart = store?.PendingWindowStart;
            _pendingWindowEnd = store?.PendingWindowEnd;
            _pendingCursor = store?.PendingCursor;
            _pendingPages = store?.PendingPages ?? 0;
            _pendingDocuments = DingTalkGroupDocumentRepair.Normalize(store?.PendingDocuments ?? []);
            _titleRetryAfter = store?.TitleRetryAfter is { } retryAfter
                ? new Dictionary<string, DateTimeOffset>(retryAfter, StringComparer.OrdinalIgnoreCase)
                : new Dictionary<string, DateTimeOffset>(StringComparer.OrdinalIgnoreCase);
            SyncIntervalHours = NormalizeInterval(store?.SyncIntervalHours ?? 6);
            await LoadIntervalSettingsAsync(cancellationToken);
            if (store is not null && (needsBackfill || !store.Documents.SequenceEqual(_documents) ||
                !store.PendingDocuments.SequenceEqual(_pendingDocuments)))
            {
                store.Documents = _documents;
                store.PendingDocuments = _pendingDocuments;
                await SaveAsync(store, cancellationToken);
            }
            _stateLoaded = true;
        }
        catch (Exception ex) when (ex is IOException or JsonException)
        {
            App.Log($"[DingTalkGroupDocs] Local index load failed: {ex.GetType().Name}");
            _stateLoaded = true;
        }
        return _documents;
    }

    public async Task<IReadOnlyList<DingTalkGroupDocument>> RefreshAsync(
        CancellationToken cancellationToken = default)
        => await SyncAsync(canCommit: null, retryUnavailableTitles: true, cancellationToken);

    public async Task<IReadOnlyList<DingTalkGroupDocument>> SyncAsync(
        CancellationToken cancellationToken = default)
        => await SyncAsync(canCommit: null, retryUnavailableTitles: false, cancellationToken);

    internal async Task<IReadOnlyList<DingTalkGroupDocument>> SyncAsync(
        Func<bool>? canCommit, bool retryUnavailableTitles, CancellationToken cancellationToken)
    {
        await _gate.WaitAsync(cancellationToken);
        try
        {
            await EnsureAuthenticatedStatePathAsync(cancellationToken);
            await LoadCoreAsync(cancellationToken);
            string syncAccountKey = _loadedAccountKey!;

            bool initialSync = !HasCompletedInitialSync;
            DateTimeOffset now = DateTimeOffset.Now;
            DateTimeOffset sixMonthFloor = now.AddMonths(-6);
            DateTimeOffset scanStart = initialSync
                ? Max(_initialScanStart ?? sixMonthFloor, sixMonthFloor)
                : Max((LastSyncCompletedAt ?? now.AddDays(-2)).AddDays(-2), sixMonthFloor);
            DateTimeOffset scanEnd = initialSync ? _initialScanEnd ?? now : now;
            DateTimeOffset nextEnd = initialSync ? _initialScanNextEnd ?? scanEnd : scanEnd;
            if (initialSync && nextEnd <= scanStart)
            {
                // A very old interrupted scan no longer covers the rolling
                // six-month range. Start a fresh backfill without deleting cache.
                scanStart = sixMonthFloor;
                scanEnd = now;
                nextEnd = now;
                _initialScanCompletedSegments = 0;
                ClearPendingWindow();
            }
            if (!initialSync && scanStart >= scanEnd)
                scanStart = scanEnd.AddHours(-1);

            // Scan newest first. A saved page cursor is retried once; if DWS has
            // expired it, the current window is safely reread from its start.
            var segments = new List<(DateTimeOffset Start, DateTimeOffset End)>();
            DateTimeOffset remainingEnd = nextEnd;
            if (initialSync && _pendingWindowEnd == nextEnd &&
                _pendingWindowStart is { } pendingStart && pendingStart >= scanStart && pendingStart < nextEnd)
            {
                segments.Add((pendingStart, nextEnd));
                remainingEnd = pendingStart;
            }
            for (DateTimeOffset segmentEnd = remainingEnd; segmentEnd > scanStart;)
            {
                DateTimeOffset segmentStart = Max(segmentEnd.AddDays(-14), scanStart);
                segments.Add((segmentStart, segmentEnd));
                segmentEnd = segmentStart;
            }
            int completed = initialSync ? _initialScanCompletedSegments : 0;
            int total = completed + segments.Count;

            var byGroupAndUrl = _documents.ToDictionary(
                document => MakeKey(document.ConversationId, document.GroupName, document.Url),
                document => document,
                StringComparer.OrdinalIgnoreCase);
            var titleLookups = new Dictionary<string, string?>(StringComparer.OrdinalIgnoreCase);
            var titleLookupBudget = new Stopwatch();
            IsSyncing = true;
            LastSyncStartedAt = now;
            int pagesFetched = 0;
            ProgressChanged?.Invoke(new(completed, total, initialSync, true, 0, 0, byGroupAndUrl.Count,
                segments[0].Start, segments[0].End));

            async Task VerifyIdentityAsync()
            {
                DingTalkDwsAuthStatus status = await _dws.GetAuthStatusAsync(cancellationToken);
                string accountKey = $"{status.CorpId.Trim()}\u001f{status.UserId.Trim()}";
                if (!status.Authenticated || !status.TokenValid ||
                    !string.Equals(syncAccountKey, accountKey, StringComparison.Ordinal))
                    throw new DingTalkDwsAuthorizationRequiredException("The DWS identity changed during synchronization.");
                if (canCommit is not null && !canCommit())
                    throw new OperationCanceledException("The linked account changed during synchronization.", cancellationToken);
            }

            async Task SavePendingWindowAsync(DateTimeOffset windowStart, DateTimeOffset windowEnd,
                string? nextCursor, int windowPages,
                Dictionary<string, DingTalkGroupDocument> windowDocuments)
            {
                await VerifyIdentityAsync();
                DingTalkGroupDocument[] pendingDocuments = SortDocuments(windowDocuments);
                await SaveAsync(new DingTalkGroupDocumentStore
                {
                    LastRefresh = LastRefresh,
                    LastSyncStartedAt = LastSyncStartedAt,
                    LastSyncCompletedAt = LastSyncCompletedAt,
                    HasCompletedInitialSync = false,
                    InitialScanStart = scanStart,
                    InitialScanEnd = scanEnd,
                    InitialScanNextEnd = windowEnd,
                    InitialScanCompletedSegments = completed,
                    PendingWindowStart = windowStart,
                    PendingWindowEnd = windowEnd,
                    PendingCursor = nextCursor,
                    PendingPages = windowPages,
                    PendingDocuments = pendingDocuments,
                    TitleRetryAfter = _titleRetryAfter,
                    SyncIntervalHours = SyncIntervalHours,
                    Documents = _documents
                }, cancellationToken);
                _initialScanStart = scanStart;
                _initialScanEnd = scanEnd;
                _initialScanNextEnd = windowEnd;
                _initialScanCompletedSegments = completed;
                _pendingWindowStart = windowStart;
                _pendingWindowEnd = windowEnd;
                _pendingCursor = nextCursor;
                _pendingPages = windowPages;
                _pendingDocuments = pendingDocuments;
            }

            for (int segmentIndex = 0; segmentIndex < segments.Count; segmentIndex++)
            {
                (DateTimeOffset segmentStart, DateTimeOffset segmentEnd) = segments[segmentIndex];
                bool pendingWindow = initialSync && _pendingWindowStart == segmentStart &&
                    _pendingWindowEnd == segmentEnd;
                var windowDocuments = pendingWindow
                    ? _pendingDocuments.ToDictionary(document => MakeKey(
                        document.ConversationId, document.GroupName, document.Url),
                        document => document, StringComparer.OrdinalIgnoreCase)
                    : new Dictionary<string, DingTalkGroupDocument>(StringComparer.OrdinalIgnoreCase);
                string? cursor = pendingWindow ? _pendingCursor : null;
                int pages = pendingWindow ? _pendingPages : 0;
                bool retryExpiredCursor = !string.IsNullOrWhiteSpace(cursor);
                while (true)
                {
                    DingTalkGroupMessagePage page;
                    try
                    {
                        page = await _dws.ListGroupMessagesAsync(
                            segmentStart, segmentEnd, cursor, 50, cancellationToken);
                    }
                    catch (Exception ex) when (retryExpiredCursor &&
                        ex is InvalidOperationException or InvalidDataException)
                    {
                        // The stored cursor can expire. Retry this one window
                        // from page one; other windows and links remain intact.
                        cursor = null;
                        pages = 0;
                        windowDocuments.Clear();
                        retryExpiredCursor = false;
                        continue;
                    }
                    retryExpiredCursor = false;
                    foreach (DingTalkGroupMessage message in page.Messages)
                    {
                        if (message.IsSingleChat) continue;
                        AddDocuments(windowDocuments, message);
                    }

                    if (page.HasMore && string.IsNullOrWhiteSpace(page.NextCursor))
                        throw new InvalidDataException("DWS returned an incomplete group-message page.");
                    cursor = page.HasMore ? page.NextCursor : null;
                    pages++;
                    pagesFetched++;
                    if (initialSync && cursor is not null && pages % 10 == 0)
                        await SavePendingWindowAsync(segmentStart, segmentEnd, cursor, pages, windowDocuments);
                    ProgressChanged?.Invoke(new(completed, total, initialSync, true,
                        pagesFetched, pages, byGroupAndUrl.Count + windowDocuments.Count,
                        segmentStart, segmentEnd));
                    if (cursor is null || pages >= 200) break;
                }

                if (cursor is not null)
                {
                    TimeSpan duration = segmentEnd - segmentStart;
                    if (duration <= TimeSpan.FromMinutes(1))
                        throw new InvalidDataException("DWS group-message pagination exceeded the safety limit.");
                    DateTimeOffset middle = segmentStart + TimeSpan.FromTicks(duration.Ticks / 2);
                    segments[segmentIndex] = (middle, segmentEnd);
                    segments.Insert(segmentIndex + 1, (segmentStart, middle));
                    total++;
                    segmentIndex--;
                    if (initialSync)
                        await SavePendingWindowAsync(middle, segmentEnd, null, 0,
                            new Dictionary<string, DingTalkGroupDocument>(StringComparer.OrdinalIgnoreCase));
                    ProgressChanged?.Invoke(new(completed, total, initialSync, true,
                        pagesFetched, 0, byGroupAndUrl.Count, middle, segmentEnd));
                    continue;
                }

                foreach ((string key, DingTalkGroupDocument document) in windowDocuments)
                    byGroupAndUrl[key] = MergeDocument(
                        byGroupAndUrl.GetValueOrDefault(key), document);
                if (byGroupAndUrl.Count >= 50000)
                    throw new InvalidDataException("The group-document index exceeded the safety limit.");

                await VerifyIdentityAsync();
                titleLookupBudget.Start();
                try
                {
                    await ResolveGenericTitlesAsync(byGroupAndUrl, titleLookups, titleLookupBudget,
                        retryUnavailableTitles, cancellationToken);
                }
                finally
                {
                    titleLookupBudget.Stop();
                }
                await VerifyIdentityAsync();

                DingTalkGroupDocument[] checkpointDocuments = SortDocuments(byGroupAndUrl);
                bool finished = completed + 1 == total;
                DateTimeOffset? completedAt = finished ? scanEnd : LastSyncCompletedAt;
                DateTimeOffset? refreshedAt = finished ? DateTimeOffset.Now : LastRefresh;
                await SaveAsync(new DingTalkGroupDocumentStore
                {
                    LastRefresh = refreshedAt,
                    LastSyncStartedAt = LastSyncStartedAt,
                    LastSyncCompletedAt = completedAt,
                    HasCompletedInitialSync = !initialSync || finished,
                    InitialScanStart = initialSync && !finished ? scanStart : null,
                    InitialScanEnd = initialSync && !finished ? scanEnd : null,
                    InitialScanNextEnd = initialSync && !finished ? segmentStart : null,
                    InitialScanCompletedSegments = initialSync && !finished ? completed + 1 : 0,
                    TitleRetryAfter = _titleRetryAfter,
                    SyncIntervalHours = SyncIntervalHours,
                    Documents = checkpointDocuments
                }, cancellationToken);
                _documents = checkpointDocuments;
                _cloudUploadPending = true;
                LastRefresh = refreshedAt;
                LastSyncCompletedAt = completedAt;
                HasCompletedInitialSync = !initialSync || finished;
                _initialScanStart = initialSync && !finished ? scanStart : null;
                _initialScanEnd = initialSync && !finished ? scanEnd : null;
                _initialScanNextEnd = initialSync && !finished ? segmentStart : null;
                _initialScanCompletedSegments = initialSync && !finished ? completed + 1 : 0;
                ClearPendingWindow();
                completed++;
                ProgressChanged?.Invoke(new(completed, total, initialSync, true,
                    pagesFetched, 0, _documents.Length, segmentStart, segmentEnd));
            }
            return _documents;
        }
        finally
        {
            IsSyncing = false;
            try { ProgressChanged?.Invoke(new(0, 0, false, false)); }
            finally { _gate.Release(); }
        }
    }

    public async Task SetSyncIntervalHoursAsync(int hours, CancellationToken cancellationToken = default)
    {
        int interval = NormalizeInterval(hours);
        Directory.CreateDirectory(_stateDirectory);
        cancellationToken.ThrowIfCancellationRequested();
        await ResilientJsonStore.SaveAsync(
            SettingsPath(),
            $"{{\"syncIntervalHours\":{interval.ToString(System.Globalization.CultureInfo.InvariantCulture)}}}");
        SyncIntervalHours = interval;
        if (_cloud is not null && _auth?.IsAuthenticated == true)
            await _cloud.MergeAsync([], null, interval, cancellationToken);
    }

    private static int NormalizeInterval(int hours) => Math.Clamp(hours, 1, 168);

    private static void AddDocuments(
        Dictionary<string, DingTalkGroupDocument> byGroupAndUrl,
        DingTalkGroupMessage message)
    {
        foreach (Match match in MarkdownLinkRegex.Matches(message.Content))
        {
            AddDocument(byGroupAndUrl, message, match.Groups["title"].Value, match.Groups["url"].Value);
        }

        foreach (Match match in PlainLinkRegex.Matches(message.Content))
        {
            AddDocument(byGroupAndUrl, message, string.Empty, match.Groups["url"].Value);
        }

        MatchCollection officeTitles = OfficeFileTitleRegex.Matches(message.Content);
        if (officeTitles.Count == 0) return;
        foreach (DingTalkGroupMessageResource resource in message.Resources)
        {
            Match? officeTitle = officeTitles.Cast<Match>().FirstOrDefault(match =>
                string.Equals(match.Groups["fileId"].Value, resource.ResourceId, StringComparison.Ordinal));
            if (officeTitle is null && string.IsNullOrWhiteSpace(resource.ResourceId) &&
                officeTitles.Count == 1 && message.Resources.Count == 1)
                officeTitle = officeTitles[0];
            if (officeTitle is null) continue;
            if (!string.Equals(resource.ResourceType, "file", StringComparison.OrdinalIgnoreCase) ||
                !Uri.TryCreate(resource.Url, UriKind.Absolute, out Uri? uri) ||
                uri.Scheme != Uri.UriSchemeHttps ||
                !string.Equals(uri.Host, "alidocs.dingtalk.com", StringComparison.OrdinalIgnoreCase) ||
                !uri.AbsolutePath.StartsWith("/i/nodes/", StringComparison.OrdinalIgnoreCase))
                continue;
            AddDocument(byGroupAndUrl, message, officeTitle.Groups["title"].Value, resource.Url);
        }
    }

    private static void AddDocument(
        Dictionary<string, DingTalkGroupDocument> byGroupAndUrl,
        DingTalkGroupMessage message,
        string title,
        string url)
    {
        url = url.TrimEnd('.', ',', ';', '。', '，', '；');
        if (!Uri.TryCreate(url, UriKind.Absolute, out _)) return;
        title = string.IsNullOrWhiteSpace(title) || title.StartsWith("http", StringComparison.OrdinalIgnoreCase)
            ? "群文档"
            : title.Trim();
        string groupName = string.IsNullOrWhiteSpace(message.GroupName) ? "未命名群" : message.GroupName.Trim();
        var document = new DingTalkGroupDocument(
            groupName,
            title,
            url,
            message.CreatedAt,
            message.ConversationId);
        string legacyKey = MakeKey(string.Empty, groupName, url);
        if (!string.IsNullOrWhiteSpace(message.ConversationId) &&
            byGroupAndUrl.Remove(legacyKey, out DingTalkGroupDocument? legacy))
            document = MergeDocument(legacy, document);
        string key = MakeKey(message.ConversationId, groupName, url);
        byGroupAndUrl[key] = MergeDocument(byGroupAndUrl.GetValueOrDefault(key), document);
    }

    private static string MakeKey(string conversationId, string groupName, string url)
    {
        string groupKey = string.IsNullOrWhiteSpace(conversationId)
            ? "name:" + groupName.ToUpperInvariant()
            : "id:" + conversationId.Trim();
        return $"{groupKey}\u001f{url}";
    }

    private static bool IsGenericTitle(string title) =>
        string.Equals(title, "群文档", StringComparison.Ordinal);

    private static DingTalkGroupDocument MergeDocument(
        DingTalkGroupDocument? existing, DingTalkGroupDocument incoming)
    {
        if (existing is null) return incoming;
        bool incomingIsNewer = existing.LastSeenAt < incoming.LastSeenAt;
        DingTalkGroupDocument latest = incomingIsNewer ? incoming : existing;
        DingTalkGroupDocument other = incomingIsNewer ? existing : incoming;
        return IsGenericTitle(latest.Title) && !IsGenericTitle(other.Title)
            ? latest with { Title = other.Title }
            : latest;
    }

    private async Task ResolveGenericTitlesAsync(
        Dictionary<string, DingTalkGroupDocument> documents,
        Dictionary<string, string?> titleLookups,
        Stopwatch lookupBudget,
        bool retryUnavailableTitles,
        CancellationToken cancellationToken)
    {
        var urlsByDocument = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase);
        foreach (DingTalkGroupDocument document in documents.Values)
        {
            if (!IsGenericTitle(document.Title) ||
                !Uri.TryCreate(document.Url, UriKind.Absolute, out Uri? uri) ||
                uri.Scheme != Uri.UriSchemeHttps ||
                !string.Equals(uri.Host, "alidocs.dingtalk.com", StringComparison.OrdinalIgnoreCase))
                continue;
            string key = uri.GetLeftPart(UriPartial.Path);
            if (!titleLookups.ContainsKey(key) &&
                (retryUnavailableTitles ||
                 !_titleRetryAfter.TryGetValue(key, out DateTimeOffset retryAfter) ||
                 retryAfter <= DateTimeOffset.UtcNow))
                urlsByDocument.TryAdd(key, document.Url);
        }
        if (urlsByDocument.Count > 0)
        {
            async Task<(string Key, string? Title)> LookupAsync(string key, string url)
            {
                try
                {
                    return (key, await _dws.GetDocumentTitleAsync(url, cancellationToken));
                }
                catch (Exception ex) when (!cancellationToken.IsCancellationRequested &&
                    (ex is IOException or InvalidDataException or InvalidOperationException or
                        OperationCanceledException or System.ComponentModel.Win32Exception))
                {
                    return (key, null);
                }
            }

            KeyValuePair<string, string>[] candidates = urlsByDocument
                .Take(Math.Max(0, 120 - titleLookups.Count)).ToArray();
            for (int offset = 0; offset < candidates.Length &&
                 lookupBudget.Elapsed < TimeSpan.FromSeconds(60); offset += 3)
            {
                cancellationToken.ThrowIfCancellationRequested();
                (string Key, string? Title)[] results = await Task.WhenAll(
                    candidates.Skip(offset).Take(3).Select(item => LookupAsync(item.Key, item.Value)));
                foreach ((string key, string? title) in results)
                {
                    titleLookups[key] = title;
                    if (string.IsNullOrWhiteSpace(title) || IsGenericTitle(title))
                        _titleRetryAfter[key] = DateTimeOffset.UtcNow.AddHours(24);
                    else
                        _titleRetryAfter.Remove(key);
                }
            }
        }

        foreach ((string key, DingTalkGroupDocument document) in documents.ToArray())
        {
            if (!IsGenericTitle(document.Title) ||
                !Uri.TryCreate(document.Url, UriKind.Absolute, out Uri? uri))
                continue;
            if (titleLookups.TryGetValue(uri.GetLeftPart(UriPartial.Path), out string? title) &&
                !string.IsNullOrWhiteSpace(title) && !IsGenericTitle(title))
                documents[key] = document with { Title = title };
        }
    }

    private static DateTimeOffset Max(DateTimeOffset left, DateTimeOffset right)
        => left >= right ? left : right;

    private static DingTalkGroupDocument[] SortDocuments(
        Dictionary<string, DingTalkGroupDocument> documents) => DingTalkGroupDocumentRepair.Normalize(documents.Values)
        .OrderByDescending(document => document.LastSeenAt)
        .ThenBy(document => document.GroupName, StringComparer.CurrentCultureIgnoreCase)
        .ThenBy(document => document.Title, StringComparer.CurrentCultureIgnoreCase)
        .ToArray();

    private void ClearPendingWindow()
    {
        _pendingWindowStart = null;
        _pendingWindowEnd = null;
        _pendingCursor = null;
        _pendingPages = 0;
        _pendingDocuments = [];
    }

    private async Task SaveAsync(DingTalkGroupDocumentStore store, CancellationToken cancellationToken)
    {
        if (_statePath is null)
            throw new InvalidOperationException("DWS account state is not initialized.");
        store.IndexVersion = CurrentIndexVersion;
        Directory.CreateDirectory(Path.GetDirectoryName(_statePath)!);
        string json = JsonSerializer.Serialize(
            store, DingTalkGroupDocumentJsonContext.Default.DingTalkGroupDocumentStore);
        cancellationToken.ThrowIfCancellationRequested();
        await ResilientJsonStore.SaveAsync(_statePath, json);
    }

    private async Task LoadIntervalSettingsAsync(CancellationToken cancellationToken)
    {
        string path = SettingsPath();
        if (!File.Exists(path)) return;
        try
        {
            string value = await File.ReadAllTextAsync(path, cancellationToken);
            ApplyIntervalSettings(value);
        }
        catch (Exception ex) when (ex is IOException or JsonException)
        {
            App.Log($"[DingTalkGroupDocs] Sync settings load failed: {ex.GetType().Name}");
        }
    }

    private void LoadIntervalSettingsFromDisk()
    {
        if (!File.Exists(_settingsPath)) return;
        try { ApplyIntervalSettings(File.ReadAllText(_settingsPath)); }
        catch (Exception ex) when (ex is IOException or JsonException)
        {
            App.Log($"[DingTalkGroupDocs] Sync settings load failed: {ex.GetType().Name}");
        }
    }

    private string SettingsPath()
    {
        if (_auth is null) return _settingsPath;
        string userId = _auth.CurrentUser?.Id ??
            throw new DingTalkDwsAuthorizationRequiredException("Sign in to SltBox to set document sync interval.");
        string digest = Convert.ToHexString(SHA256.HashData(System.Text.Encoding.UTF8.GetBytes(userId)))
            .ToLowerInvariant();
        return Path.Combine(_stateDirectory, $"group-documents-settings-account-{digest}.json");
    }

    private void ApplyIntervalSettings(string value)
    {
        using JsonDocument document = JsonDocument.Parse(value);
        if (document.RootElement.TryGetProperty("syncIntervalHours", out JsonElement hours) &&
            hours.TryGetInt32(out int interval))
            SyncIntervalHours = NormalizeInterval(interval);
    }

    private async Task EnsureAuthenticatedStatePathAsync(CancellationToken cancellationToken)
    {
        if (_auth is not null && (!_auth.IsAuthenticated || _auth.CurrentUser is null))
            throw new DingTalkDwsAuthorizationRequiredException("Sign in to SltBox to use group documents.");
        DingTalkDwsAuthStatus status = await _dws.GetAuthStatusAsync(cancellationToken);
        if (!status.Authenticated || !status.TokenValid ||
            string.IsNullOrWhiteSpace(status.CorpId) || string.IsNullOrWhiteSpace(status.UserId))
        {
            throw new DingTalkDwsAuthorizationRequiredException("DWS authorization is required.");
        }

        if (_account is not null)
        {
            DingTalkIdentityDto? linked = _account.CurrentIdentity ?? await _account.GetAsync(cancellationToken);
            if (linked is null || linked.CorpId != status.CorpId ||
                linked.DingtalkUserId != status.UserId)
                throw new DingTalkDwsAuthorizationRequiredException("The DWS account is not linked to this SltBox account.");
        }

        string accountKey = $"{status.CorpId.Trim()}\u001f{status.UserId.Trim()}";
        string? ownerUserId = _auth?.CurrentUser?.Id;
        if (string.Equals(_loadedAccountKey, accountKey, StringComparison.Ordinal) &&
            string.Equals(_stateOwnerUserId, ownerUserId, StringComparison.Ordinal)) return;

        byte[] digest = SHA256.HashData(System.Text.Encoding.UTF8.GetBytes(accountKey));
        string legacyFileName = $"group-documents-{Convert.ToHexString(digest).ToLowerInvariant()}.json";
        string fileName = ownerUserId is null ? legacyFileName :
            $"group-documents-account-{Convert.ToHexString(SHA256.HashData(System.Text.Encoding.UTF8.GetBytes(ownerUserId))).ToLowerInvariant()}.json";
        _statePath = Path.Combine(_stateDirectory, fileName);
        if (ownerUserId is not null && !File.Exists(_statePath))
        {
            string legacyPath = Path.Combine(_stateDirectory, legacyFileName);
            if (File.Exists(legacyPath))
                DingTalkGroupDocumentFileMigration.CopyIfAbsent(legacyPath, _statePath);
        }
        _loadedAccountKey = accountKey;
        _stateOwnerUserId = ownerUserId;
        _documents = [];
        LastRefresh = null;
        LastSyncStartedAt = null;
        LastSyncCompletedAt = null;
        HasCompletedInitialSync = false;
        _initialScanStart = _initialScanEnd = _initialScanNextEnd = null;
        _initialScanCompletedSegments = 0;
        ClearPendingWindow();
        _titleRetryAfter = new Dictionary<string, DateTimeOffset>(StringComparer.OrdinalIgnoreCase);
        SyncIntervalHours = 6;
        _stateLoaded = false;
    }
}

internal sealed class DingTalkGroupDocumentStore
{
    public int IndexVersion { get; set; }
    public DateTimeOffset? LastRefresh { get; set; }
    public DateTimeOffset? LastSyncStartedAt { get; set; }
    public DateTimeOffset? LastSyncCompletedAt { get; set; }
    public bool HasCompletedInitialSync { get; set; }
    public DateTimeOffset? InitialScanStart { get; set; }
    public DateTimeOffset? InitialScanEnd { get; set; }
    public DateTimeOffset? InitialScanNextEnd { get; set; }
    public int InitialScanCompletedSegments { get; set; }
    public DateTimeOffset? PendingWindowStart { get; set; }
    public DateTimeOffset? PendingWindowEnd { get; set; }
    public string? PendingCursor { get; set; }
    public int PendingPages { get; set; }
    public DingTalkGroupDocument[] PendingDocuments { get; set; } = [];
    public Dictionary<string, DateTimeOffset> TitleRetryAfter { get; set; } = new();
    public int SyncIntervalHours { get; set; } = 6;
    public DingTalkGroupDocument[] Documents { get; set; } = [];
}

[System.Text.Json.Serialization.JsonSerializable(typeof(DingTalkGroupDocumentStore))]
[System.Text.Json.Serialization.JsonSerializable(typeof(DingTalkGroupDocument[]))]
internal sealed partial class DingTalkGroupDocumentJsonContext :
    System.Text.Json.Serialization.JsonSerializerContext
{
}
