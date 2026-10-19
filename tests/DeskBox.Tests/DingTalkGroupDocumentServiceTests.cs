using DeskBox.Services;
using System.Collections.Concurrent;

namespace DeskBox.Tests;

public sealed class DingTalkGroupDocumentServiceTests
{
    [Fact]
    public async Task RefreshAsync_ResolvesDocumentNameForBareSharedLink()
    {
        string root = CreateTempRoot();
        try
        {
            var dws = new FakeDwsClient();
            const string url = "https://alidocs.dingtalk.com/i/nodes/sample?doc_type=wiki_doc&utm_source=im";
            dws.Titles[url] = "产品需求说明书";
            dws.Pages.Enqueue(new DingTalkGroupMessagePage(
                [new("a", "研发群", url, DateTimeOffset.Now, false)],
                false, null));

            var service = new DingTalkGroupDocumentService(dws, root);
            DingTalkGroupDocument document = Assert.Single(await service.RefreshAsync());

            Assert.Equal("产品需求说明书", document.Title);
        }
        finally { DeleteTempRoot(root); }
    }

    [Fact]
    public async Task RefreshAsync_DoesNotReplaceKnownTitleWithGenericTitle()
    {
        string root = CreateTempRoot();
        try
        {
            const string url = "https://alidocs.dingtalk.com/i/nodes/sample";
            var dws = new FakeDwsClient();
            dws.Pages.Enqueue(new DingTalkGroupMessagePage(
            [
                new("a", "研发群", $"[产品需求说明书]({url})", DateTimeOffset.Now.AddMinutes(-1), false),
                new("a", "研发群", url, DateTimeOffset.Now, false)
            ], false, null));

            var service = new DingTalkGroupDocumentService(dws, root);
            DingTalkGroupDocument document = Assert.Single(await service.RefreshAsync());

            Assert.Equal("产品需求说明书", document.Title);
        }
        finally { DeleteTempRoot(root); }
    }

    [Fact]
    public async Task SyncAsync_UpgradesPreviouslyCachedGenericTitle()
    {
        string root = CreateTempRoot();
        try
        {
            const string url = "https://alidocs.dingtalk.com/i/nodes/cached";
            var dws = new FakeDwsClient();
            dws.Pages.Enqueue(new DingTalkGroupMessagePage(
                [new("a", "研发群", url, DateTimeOffset.Now, false)], false, null));
            var firstService = new DingTalkGroupDocumentService(dws, root);
            Assert.Equal("群文档", Assert.Single(await firstService.SyncAsync()).Title);

            dws.Titles[url] = "已缓存文档的真实名称";
            var reloaded = new DingTalkGroupDocumentService(dws, root);
            Assert.Equal("群文档", Assert.Single(await reloaded.LoadAsync()).Title);

            Assert.Equal("已缓存文档的真实名称", Assert.Single(await reloaded.RefreshAsync()).Title);
            var persisted = new DingTalkGroupDocumentService(dws, root);
            Assert.Equal("已缓存文档的真实名称", Assert.Single(await persisted.LoadAsync()).Title);
        }
        finally { DeleteTempRoot(root); }
    }

    [Fact]
    public async Task SyncAsync_LooksUpOneTitleForTheSameDocumentSharedInTwoGroups()
    {
        string root = CreateTempRoot();
        try
        {
            const string firstUrl = "https://alidocs.dingtalk.com/i/nodes/shared?utm_source=im";
            const string secondUrl = "https://alidocs.dingtalk.com/i/nodes/shared?utm_source=portal";
            var dws = new FakeDwsClient();
            dws.Titles[firstUrl] = "共享方案";
            dws.Pages.Enqueue(new DingTalkGroupMessagePage(
            [
                new("a", "研发群", firstUrl, DateTimeOffset.Now, false),
                new("b", "运营群", secondUrl, DateTimeOffset.Now, false)
            ], false, null));

            var service = new DingTalkGroupDocumentService(dws, root);
            IReadOnlyList<DingTalkGroupDocument> documents = await service.SyncAsync();

            Assert.Equal(2, documents.Count);
            Assert.All(documents, document => Assert.Equal("共享方案", document.Title));
            Assert.Single(dws.TitleRequests);
        }
        finally { DeleteTempRoot(root); }
    }

    [Fact]
    public async Task SyncAsync_KeepsLinkWhenTitleLookupTimesOut()
    {
        string root = CreateTempRoot();
        try
        {
            const string url = "https://alidocs.dingtalk.com/i/nodes/unavailable";
            var dws = new FakeDwsClient { SimulateTitleTimeout = true };
            dws.Pages.Enqueue(new DingTalkGroupMessagePage(
                [new("a", "研发群", url, DateTimeOffset.Now, false)], false, null));

            var service = new DingTalkGroupDocumentService(dws, root);
            DingTalkGroupDocument document = Assert.Single(await service.SyncAsync());

            Assert.Equal(url, document.Url);
            Assert.Equal("群文档", document.Title);
            var reloaded = new DingTalkGroupDocumentService(dws, root);
            await reloaded.SyncAsync();
            Assert.Single(dws.TitleRequests);
        }
        finally { DeleteTempRoot(root); }
    }

    [Fact]
    public async Task SyncAsync_BoundsTitleLookupsAndContinuesOnNextRun()
    {
        string root = CreateTempRoot();
        try
        {
            var dws = new FakeDwsClient();
            dws.Pages.Enqueue(new DingTalkGroupMessagePage(
                Enumerable.Range(0, 130).Select(index => new DingTalkGroupMessage(
                    "a", "研发群", $"https://alidocs.dingtalk.com/i/nodes/doc-{index:000}",
                    DateTimeOffset.Now, false)).ToArray(),
                false, null));
            var service = new DingTalkGroupDocumentService(dws, root);

            Assert.Equal(130, (await service.SyncAsync()).Count);
            Assert.InRange(dws.TitleRequests.Count, 1, 120);

            await service.SyncAsync();
            Assert.Equal(130, dws.TitleRequests.Count);
        }
        finally { DeleteTempRoot(root); }
    }

    [Fact]
    public async Task SyncAsync_KeepsLinkWhenDwsTitleResponseIsInvalid()
    {
        string root = CreateTempRoot();
        try
        {
            const string url = "https://alidocs.dingtalk.com/i/nodes/malformed";
            var dws = new FakeDwsClient { SimulateMalformedTitleResponse = true };
            dws.Pages.Enqueue(new DingTalkGroupMessagePage(
                [new("a", "研发群", url, DateTimeOffset.Now, false)], false, null));

            var service = new DingTalkGroupDocumentService(dws, root);
            DingTalkGroupDocument document = Assert.Single(await service.SyncAsync());

            Assert.Equal(url, document.Url);
            Assert.Equal("群文档", document.Title);
        }
        finally { DeleteTempRoot(root); }
    }

    [Fact]
    public async Task RefreshAsync_KeepsSameUrlUnderEachGroupAndUsesLatestOccurrence()
    {
        string root = CreateTempRoot();
        try
        {
            var dws = new FakeDwsClient();
            dws.Pages.Enqueue(new DingTalkGroupMessagePage(
            [
                new("a", "研发群", "[方案](https://alidocs.dingtalk.com/i/shared) https://alidocs.dingtalk.com/i/shared", DateTimeOffset.Parse("2026-10-15T10:00:00+08:00"), false),
                new("b", "运营群", "https://alidocs.dingtalk.com/i/shared", DateTimeOffset.Parse("2026-10-15T11:00:00+08:00"), false),
                new("a", "研发群", "[更新方案](https://alidocs.dingtalk.com/i/shared)", DateTimeOffset.Parse("2026-10-15T12:00:00+08:00"), false)
            ], false, null));

            var service = new DingTalkGroupDocumentService(dws, root);
            IReadOnlyList<DingTalkGroupDocument> documents = await service.RefreshAsync();

            Assert.Equal(2, documents.Count);
            Assert.Equal("更新方案", documents.Single(document => document.GroupName == "研发群").Title);
            Assert.Equal("运营群", documents.Single(document => document.GroupName == "运营群").GroupName);
            Assert.All(documents, document => Assert.Equal("https://alidocs.dingtalk.com/i/shared", document.Url));
        }
        finally
        {
            DeleteTempRoot(root);
        }
    }

    [Fact]
    public async Task RefreshAsync_SeparatesDifferentGroupsThatHaveTheSameDisplayNameAndDocument()
    {
        string root = CreateTempRoot();
        try
        {
            const string url = "https://alidocs.dingtalk.com/i/shared";
            var dws = new FakeDwsClient();
            dws.Pages.Enqueue(new DingTalkGroupMessagePage(
            [
                new("conversation-a", "项目群", $"[方案]({url})", DateTimeOffset.UtcNow, false),
                new("conversation-b", "项目群", $"[方案]({url})", DateTimeOffset.UtcNow, false)
            ], false, null));

            IReadOnlyList<DingTalkGroupDocument> documents =
                await new DingTalkGroupDocumentService(dws, root).RefreshAsync();

            Assert.Equal(2, documents.Count);
            Assert.Equal(["conversation-a", "conversation-b"],
                documents.Select(document => document.ConversationId).Order().ToArray());
        }
        finally { DeleteTempRoot(root); }
    }

    [Fact]
    public async Task LoadAsync_SeparatesIndexesWhenDwsAccountChanges()
    {
        string root = CreateTempRoot();
        try
        {
            var dws = new FakeDwsClient();
            dws.Pages.Enqueue(new DingTalkGroupMessagePage(
            [new("a", "账号A群", "https://alidocs.dingtalk.com/i/a", DateTimeOffset.Now, false)],
                false,
                null));
            var service = new DingTalkGroupDocumentService(dws, root);
            await service.RefreshAsync();

            dws.UserId = "account-b";
            IReadOnlyList<DingTalkGroupDocument> otherAccount = await service.LoadAsync();

            Assert.Empty(otherAccount);
            Assert.NotEqual("账号A群", otherAccount.FirstOrDefault()?.GroupName);
            Assert.Single(Directory.GetFiles(root, "group-documents-*.json"));
        }
        finally
        {
            DeleteTempRoot(root);
        }
    }

    [Fact]
    public async Task SyncAsync_UsesRecentSixMonthsFirstThenOverlappingIncrementalWindowAndMergesCache()
    {
        string root = CreateTempRoot();
        try
        {
            var dws = new FakeDwsClient();
            dws.Pages.Enqueue(new DingTalkGroupMessagePage(
                [new("a", "研发群", "[旧文档](https://alidocs.dingtalk.com/i/old)", DateTimeOffset.Now.AddDays(-3), false)],
                false, null));
            var service = new DingTalkGroupDocumentService(dws, root);
            var progress = new List<DingTalkGroupDocumentSyncProgress>();
            service.ProgressChanged += progress.Add;

            await service.SyncAsync();
            Assert.True(service.HasCompletedInitialSync);
            Assert.Equal(6, service.SyncIntervalHours);
            Assert.InRange(dws.Requests[0].Start,
                DateTimeOffset.Now.AddMonths(-6), DateTimeOffset.Now);
            Assert.True(dws.Requests[0].End > dws.Requests[0].Start);
            Assert.True(dws.Requests[0].End - dws.Requests[0].Start <= TimeSpan.FromDays(14));
            Assert.All(dws.Requests, request =>
                Assert.True(request.Start >= DateTimeOffset.Now.AddMonths(-6).AddMinutes(-1)));
            Assert.All(dws.Requests.Skip(1).Zip(dws.Requests), pair =>
                Assert.Equal(pair.Second.Start, pair.First.End));
            Assert.Contains(progress, item => item.IsInitialSync && item.IsRunning && item.TotalSegments > 1);
            DateTimeOffset firstCompleted = Assert.IsType<DateTimeOffset>(service.LastSyncCompletedAt);

            dws.Requests.Clear();
            dws.Pages.Enqueue(new DingTalkGroupMessagePage(
                [new("b", "研发群", "[新文档](https://alidocs.dingtalk.com/i/new)", DateTimeOffset.Now, false)],
                false, null));
            await service.SyncAsync();

            Assert.InRange(dws.Requests[0].Start, firstCompleted.AddDays(-2).AddMinutes(-1), firstCompleted.AddDays(-2).AddMinutes(1));
            Assert.Equal(2, service.Documents.Count);
            Assert.Contains(service.Documents, item => item.Url.EndsWith("/old", StringComparison.Ordinal));
            Assert.Contains(service.Documents, item => item.Url.EndsWith("/new", StringComparison.Ordinal));
            Assert.Contains(progress, item => !item.IsInitialSync && item.IsRunning);
        }
        finally { DeleteTempRoot(root); }
    }

    [Fact]
    public async Task SyncAsync_ResumesAfterCompletedTimeWindowWhenNextWindowFails()
    {
        string root = CreateTempRoot();
        try
        {
            var dws = new FakeDwsClient { FailOnRequestNumber = 2 };
            dws.Pages.Enqueue(new DingTalkGroupMessagePage(
                [new("a", "研发群", "https://alidocs.dingtalk.com/i/checkpoint", DateTimeOffset.Now, false)],
                false, null));
            var first = new DingTalkGroupDocumentService(dws, root);

            await Assert.ThrowsAsync<IOException>(() => first.SyncAsync());

            Assert.False(first.HasCompletedInitialSync);
            Assert.Null(first.LastSyncCompletedAt);
            Assert.Single(first.Documents);
            DateTimeOffset nextEnd = dws.Requests[1].End;

            dws.FailOnRequestNumber = null;
            var resumed = new DingTalkGroupDocumentService(dws, root);
            Assert.Single(await resumed.LoadAsync());
            await resumed.SyncAsync();

            Assert.Equal(nextEnd, dws.Requests[2].End);
            Assert.True(resumed.HasCompletedInitialSync);
            Assert.Single(resumed.Documents);
        }
        finally { DeleteTempRoot(root); }
    }

    [Fact]
    public async Task SyncAsync_ReportsPageProgressWithinAWindow()
    {
        string root = CreateTempRoot();
        try
        {
            var dws = new FakeDwsClient();
            dws.Pages.Enqueue(new DingTalkGroupMessagePage([], true, "next"));
            dws.Pages.Enqueue(new DingTalkGroupMessagePage([], false, null));
            var service = new DingTalkGroupDocumentService(dws, root);
            var progress = new List<DingTalkGroupDocumentSyncProgress>();
            service.ProgressChanged += progress.Add;

            await service.SyncAsync();

            DingTalkGroupDocumentSyncProgress firstPage = Assert.Single(progress.Where(
                item => item.IsRunning && item.PagesFetched == 1 && item.CompletedSegments == 0));
            DingTalkGroupDocumentSyncProgress secondPage = Assert.Single(progress.Where(
                item => item.IsRunning && item.PagesFetched == 2 && item.CompletedSegments == 0));
            Assert.True(secondPage.EstimatedCompletion > firstPage.EstimatedCompletion);
            Assert.True(firstPage.WindowStart < firstPage.WindowEnd);
        }
        finally { DeleteTempRoot(root); }
    }

    [Fact]
    public async Task SyncAsync_DenseWindowIsSplitWithoutDroppingDocuments()
    {
        string root = CreateTempRoot();
        try
        {
            var dws = new FakeDwsClient();
            for (int page = 0; page < 200; page++)
                dws.Pages.Enqueue(new DingTalkGroupMessagePage([], true, $"page-{page}"));
            dws.Pages.Enqueue(new DingTalkGroupMessagePage(
                [new("a", "研发群", "https://alidocs.dingtalk.com/i/from-split", DateTimeOffset.Now, false)],
                false, null));
            var service = new DingTalkGroupDocumentService(dws, root);

            await service.SyncAsync();

            Assert.True(service.HasCompletedInitialSync);
            Assert.Single(service.Documents);
            Assert.True(dws.Requests[200].End - dws.Requests[200].Start <
                dws.Requests[0].End - dws.Requests[0].Start);
        }
        finally { DeleteTempRoot(root); }
    }

    [Fact]
    public async Task SyncAsync_ResumedProgressRetainsCompletedSplitWindows()
    {
        string root = CreateTempRoot();
        try
        {
            var dws = new FakeDwsClient { FailOnRequestNumber = 403 };
            for (int page = 0; page < 400; page++)
                dws.Pages.Enqueue(new DingTalkGroupMessagePage([], true, $"page-{page}"));
            var first = new DingTalkGroupDocumentService(dws, root);
            await Assert.ThrowsAsync<IOException>(() => first.SyncAsync());

            dws.FailOnRequestNumber = null;
            var resumed = new DingTalkGroupDocumentService(dws, root);
            var progress = new List<DingTalkGroupDocumentSyncProgress>();
            resumed.ProgressChanged += progress.Add;
            await resumed.SyncAsync();

            Assert.Equal(2, progress.First(item => item.IsRunning).CompletedSegments);
            Assert.True(resumed.HasCompletedInitialSync);
        }
        finally { DeleteTempRoot(root); }
    }

    [Fact]
    public async Task SyncAsync_ResumesWithinAWindowAfterTenPages()
    {
        string root = CreateTempRoot();
        try
        {
            var dws = new FakeDwsClient { FailOnRequestNumber = 11 };
            for (int page = 0; page < 10; page++)
                dws.Pages.Enqueue(new DingTalkGroupMessagePage(
                    page == 0
                        ? [new("a", "研发群", "https://alidocs.dingtalk.com/i/persisted", DateTimeOffset.Now, false)]
                        : [],
                    true, $"page-{page}"));
            var first = new DingTalkGroupDocumentService(dws, root);
            await Assert.ThrowsAsync<IOException>(() => first.SyncAsync());

            dws.FailOnRequestNumber = null;
            var resumed = new DingTalkGroupDocumentService(dws, root);
            await resumed.SyncAsync();

            Assert.Equal("page-9", dws.Requests[11].Cursor);
            Assert.Single(resumed.Documents);
            Assert.EndsWith("/persisted", resumed.Documents[0].Url, StringComparison.Ordinal);
        }
        finally { DeleteTempRoot(root); }
    }

    [Fact]
    public async Task SyncAsync_ExpiredSavedCursorRereadsOnlyItsCurrentWindow()
    {
        string root = CreateTempRoot();
        try
        {
            var dws = new FakeDwsClient { FailOnRequestNumber = 11 };
            for (int page = 0; page < 10; page++)
                dws.Pages.Enqueue(new DingTalkGroupMessagePage([], true, $"page-{page}"));
            var first = new DingTalkGroupDocumentService(dws, root);
            await Assert.ThrowsAsync<IOException>(() => first.SyncAsync());

            dws.FailOnRequestNumber = null;
            dws.FailCursorOnce = "page-9";
            dws.Pages.Enqueue(new DingTalkGroupMessagePage(
                [new("a", "研发群", "https://alidocs.dingtalk.com/i/retried", DateTimeOffset.Now, false)],
                false, null));
            var resumed = new DingTalkGroupDocumentService(dws, root);
            await resumed.SyncAsync();

            Assert.Equal("page-9", dws.Requests[11].Cursor);
            Assert.Null(dws.Requests[12].Cursor);
            Assert.Single(resumed.Documents);
        }
        finally { DeleteTempRoot(root); }
    }

    [Fact]
    public async Task SyncAsync_IncompletePaginationDoesNotReplaceSavedIndexOrAdvanceCheckpoint()
    {
        string root = CreateTempRoot();
        try
        {
            var dws = new FakeDwsClient();
            dws.Pages.Enqueue(new DingTalkGroupMessagePage(
                [new("a", "研发群", "https://alidocs.dingtalk.com/i/kept", DateTimeOffset.Now, false)],
                false, null));
            var service = new DingTalkGroupDocumentService(dws, root);
            await service.SyncAsync();
            DateTimeOffset? completed = service.LastSyncCompletedAt;

            dws.Pages.Enqueue(new DingTalkGroupMessagePage(
                [new("b", "研发群", "https://alidocs.dingtalk.com/i/lost", DateTimeOffset.Now, false)],
                true, null));
            await Assert.ThrowsAsync<InvalidDataException>(() => service.SyncAsync());

            Assert.Equal(completed, service.LastSyncCompletedAt);
            Assert.Single(service.Documents);
            Assert.EndsWith("/kept", service.Documents[0].Url, StringComparison.Ordinal);
            var reloaded = new DingTalkGroupDocumentService(dws, root);
            Assert.Single(await reloaded.LoadAsync());
            Assert.Equal(completed, reloaded.LastSyncCompletedAt);
        }
        finally { DeleteTempRoot(root); }
    }

    [Fact]
    public async Task SetSyncIntervalHoursAsync_PersistsForTheNextServiceInstance()
    {
        string root = CreateTempRoot();
        try
        {
            var dws = new FakeDwsClient();
            var first = new DingTalkGroupDocumentService(dws, root);
            await first.SetSyncIntervalHoursAsync(12);
            var second = new DingTalkGroupDocumentService(dws, root);
            Assert.Equal(12, second.SyncIntervalHours);
            await second.LoadAsync();
            Assert.Equal(12, second.SyncIntervalHours);
        }
        finally { DeleteTempRoot(root); }
    }

    [Fact]
    public async Task SyncAsync_DwsIdentityChangeDuringScanDoesNotCommitMixedDocuments()
    {
        string root = CreateTempRoot();
        try
        {
            var dws = new FakeDwsClient();
            dws.Pages.Enqueue(new DingTalkGroupMessagePage(
                [new("a", "研发群", "https://alidocs.dingtalk.com/i/kept", DateTimeOffset.Now, false)],
                false, null));
            var service = new DingTalkGroupDocumentService(dws, root);
            await service.SyncAsync();
            DateTimeOffset? checkpoint = service.LastSyncCompletedAt;

            dws.Pages.Enqueue(new DingTalkGroupMessagePage(
                [new("b", "其他群", "https://alidocs.dingtalk.com/i/mixed", DateTimeOffset.Now, false)],
                false, null));
            dws.SwitchAccountOnNextMessagePage = true;
            await Assert.ThrowsAsync<DingTalkDwsAuthorizationRequiredException>(() => service.SyncAsync());

            Assert.Equal(checkpoint, service.LastSyncCompletedAt);
            Assert.Single(service.Documents);
            dws.UserId = "account-a";
            var reloaded = new DingTalkGroupDocumentService(dws, root);
            Assert.Single(await reloaded.LoadAsync());
        }
        finally { DeleteTempRoot(root); }
    }

    private static string CreateTempRoot()
        => Directory.CreateDirectory(Path.Combine(
            Path.GetTempPath(), "DeskBox.Tests", Guid.NewGuid().ToString("N"))).FullName;

    private static void DeleteTempRoot(string root)
    {
        try
        {
            if (Directory.Exists(root)) Directory.Delete(root, recursive: true);
        }
        catch
        {
        }
    }

    private sealed class FakeDwsClient : IDingTalkDwsClient
    {
        public string UserId { get; set; } = "account-a";
        public bool SwitchAccountOnNextMessagePage { get; set; }
        public int? FailOnRequestNumber { get; set; }
        public string? FailCursorOnce { get; set; }
        public bool SimulateTitleTimeout { get; set; }
        public bool SimulateMalformedTitleResponse { get; set; }
        public Dictionary<string, string> Titles { get; } = new(StringComparer.Ordinal);
        public ConcurrentBag<string> TitleRequests { get; } = [];
        public Queue<DingTalkGroupMessagePage> Pages { get; } = new();
        public List<(DateTimeOffset Start, DateTimeOffset End, string? Cursor)> Requests { get; } = [];

        public Task<DingTalkDwsAuthStatus> GetAuthStatusAsync(CancellationToken cancellationToken = default)
            => Task.FromResult(new DingTalkDwsAuthStatus(
                true, true, true, "corp", "Corp", UserId, UserId));

        public Task<DingTalkProfile> GetCurrentUserAsync(CancellationToken cancellationToken = default)
            => throw new NotSupportedException();

        public Task<DingTalkDwsAuthStatus> LoginAsync(CancellationToken cancellationToken = default)
            => throw new NotSupportedException();

        public Task<string?> GetDocumentTitleAsync(string url, CancellationToken cancellationToken = default)
        {
            TitleRequests.Add(url);
            if (SimulateTitleTimeout) throw new OperationCanceledException("Simulated metadata timeout.");
            if (SimulateMalformedTitleResponse) throw new InvalidDataException("Simulated invalid DWS JSON.");
            return Task.FromResult(Titles.TryGetValue(url, out string? title) ? title : null);
        }

        public Task<IReadOnlyList<DingTalkCalendarEvent>> ListCurrentWeekCalendarAsync(CancellationToken cancellationToken = default)
            => Task.FromResult<IReadOnlyList<DingTalkCalendarEvent>>([]);

        public Task<DingTalkGroupMessagePage> ListGroupMessagesAsync(
            DateTimeOffset start,
            DateTimeOffset end,
            string? cursor = null,
            int limit = 50,
            CancellationToken cancellationToken = default)
        {
            Requests.Add((start, end, cursor));
            if (Requests.Count == FailOnRequestNumber)
                throw new IOException("Simulated DWS failure after a completed time window.");
            if (FailCursorOnce is not null && cursor == FailCursorOnce)
            {
                FailCursorOnce = null;
                throw new InvalidDataException("Simulated expired DWS cursor.");
            }
            if (SwitchAccountOnNextMessagePage)
            {
                UserId = "account-b";
                SwitchAccountOnNextMessagePage = false;
            }
            DingTalkGroupMessagePage page = Pages.Count > 0
                ? Pages.Dequeue()
                : new DingTalkGroupMessagePage([], false, null);
            return Task.FromResult(page);
        }
    }
}
