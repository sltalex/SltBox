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
    private static readonly Regex MarkdownLinkRegex = new(
        @"\[(?<title>[^\]]+)\]\((?<url>https://alidocs\.dingtalk\.com/[^\s\)<>]+)\)",
        RegexOptions.Compiled | RegexOptions.CultureInvariant | RegexOptions.IgnoreCase);
    private static readonly Regex PlainLinkRegex = new(
        @"(?<url>https://alidocs\.dingtalk\.com/[^\s\]\)<>]+)",
        RegexOptions.Compiled | RegexOptions.CultureInvariant | RegexOptions.IgnoreCase);

    private readonly IDingTalkDwsClient _dws;
    private readonly string _stateDirectory;
    private readonly string _settingsPath;
    private readonly SemaphoreSlim _gate = new(1, 1);
    private DingTalkGroupDocument[] _documents = [];
    private string? _loadedAccountKey;
    private string? _statePath;
    private bool _stateLoaded;
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
    {
        _dws = dws;
        _stateDirectory = stateDirectory ?? Path.Combine(
            DeskBoxDataPathService.Current.DataDirectory, "sync", "dingtalk");
        _settingsPath = Path.Combine(_stateDirectory, "group-documents-settings.json");
        LoadIntervalSettingsFromDisk();
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
        try { return await LoadCoreAsync(cancellationToken); }
        finally { _gate.Release(); }
    }

    private async Task<IReadOnlyList<DingTalkGroupDocument>> LoadCoreAsync(
        CancellationToken cancellationToken)
    {
        await EnsureAuthenticatedStatePathAsync(cancellationToken);
        if (_stateLoaded) return _documents;
        if (_statePath is null || !File.Exists(_statePath))
        {
            await LoadIntervalSettingsAsync(cancellationToken);
            _stateLoaded = true;
            return _documents;
        }
        try
        {
            string json = await File.ReadAllTextAsync(_statePath, cancellationToken);
            DingTalkGroupDocumentStore? store = JsonSerializer.Deserialize(
                json, DingTalkGroupDocumentJsonContext.Default.DingTalkGroupDocumentStore);
            _documents = DingTalkGroupDocumentRepair.Normalize(store?.Documents ?? []);
            LastRefresh = store?.LastRefresh;
            LastSyncStartedAt = store?.LastSyncStartedAt;
            LastSyncCompletedAt = store?.LastSyncCompletedAt ?? store?.LastRefresh;
            HasCompletedInitialSync = store?.HasCompletedInitialSync == true ||
                (store?.LastRefresh is not null && store.InitialScanNextEnd is null);
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
            if (store is not null && (!store.Documents.SequenceEqual(_documents) ||
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
                        if (message.IsSingleChat || string.IsNullOrWhiteSpace(message.Content)) continue;
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
            _settingsPath,
            $"{{\"syncIntervalHours\":{interval.ToString(System.Globalization.CultureInfo.InvariantCulture)}}}");
        SyncIntervalHours = interval;
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
        Directory.CreateDirectory(Path.GetDirectoryName(_statePath)!);
        string json = JsonSerializer.Serialize(
            store, DingTalkGroupDocumentJsonContext.Default.DingTalkGroupDocumentStore);
        cancellationToken.ThrowIfCancellationRequested();
        await ResilientJsonStore.SaveAsync(_statePath, json);
    }

    private async Task LoadIntervalSettingsAsync(CancellationToken cancellationToken)
    {
        if (!File.Exists(_settingsPath)) return;
        try
        {
            string value = await File.ReadAllTextAsync(_settingsPath, cancellationToken);
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

    private void ApplyIntervalSettings(string value)
    {
        using JsonDocument document = JsonDocument.Parse(value);
        if (document.RootElement.TryGetProperty("syncIntervalHours", out JsonElement hours) &&
            hours.TryGetInt32(out int interval))
            SyncIntervalHours = NormalizeInterval(interval);
    }

    private async Task EnsureAuthenticatedStatePathAsync(CancellationToken cancellationToken)
    {
        DingTalkDwsAuthStatus status = await _dws.GetAuthStatusAsync(cancellationToken);
        if (!status.Authenticated || !status.TokenValid ||
            string.IsNullOrWhiteSpace(status.CorpId) || string.IsNullOrWhiteSpace(status.UserId))
        {
            throw new DingTalkDwsAuthorizationRequiredException("DWS authorization is required.");
        }

        string accountKey = $"{status.CorpId.Trim()}\u001f{status.UserId.Trim()}";
        if (string.Equals(_loadedAccountKey, accountKey, StringComparison.Ordinal)) return;

        byte[] digest = SHA256.HashData(System.Text.Encoding.UTF8.GetBytes(accountKey));
        string fileName = $"group-documents-{Convert.ToHexString(digest).ToLowerInvariant()}.json";
        _statePath = Path.Combine(_stateDirectory, fileName);
        _loadedAccountKey = accountKey;
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
