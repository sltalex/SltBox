using System.Security.Cryptography;
using System.Text;
using DeskBox.Services;
using DeskBox.Core.Persistence;

namespace DeskBox.Tests;

public sealed class AccountBackupFileCatalogTests : IDisposable
{
    private readonly string _root = Path.Combine(Path.GetTempPath(), "DeskBoxAccountBackupTests", Guid.NewGuid().ToString("N"));

    [Theory]
    [InlineData(false)]
    [InlineData(true)]
    public async Task RestorePreservesOtherAccountsFavoritesIncludingOnRollback(bool fail)
    {
        string first = "dingtalk/" + DingTalkGroupDocumentFavorites.AccountFileName("first");
        string second = "dingtalk/" + DingTalkGroupDocumentFavorites.AccountFileName("second");
        Write(first, "{\"old\":true}");
        Write(second, "{\"private\":true}");
        var catalog = new AccountBackupFileCatalog(Path.Combine(_root, "data"), path => fail && path == first);
        await catalog.PrepareRestoreAsync(Snapshot((first, "{\"new\":true}")), "first", _ => Task.CompletedTask);
        Assert.Equal(!fail, (await catalog.ApplyPendingRestoreAsync()).Applied);
        Assert.Equal("{\"private\":true}", File.ReadAllText(Path.Combine(_root, "data", second)));
        Assert.Equal(fail ? "{\"old\":true}" : "{\"new\":true}", File.ReadAllText(Path.Combine(_root, "data", first)));
    }

    [Fact]
    public async Task FruitStateBackup_StaysScopedToTheSignedInAccount()
    {
        const string first = "fruit/fruit-state-account-first.json";
        const string second = "fruit/fruit-state-account-second.json";
        Write(first, "{\"ownerUserId\":\"first\",\"inventory\":[]}");
        Write(second, "{\"ownerUserId\":\"second\",\"inventory\":[]}");
        var catalog = new AccountBackupFileCatalog(Path.Combine(_root, "data"));
        AccountBackupFileDto[] files = await catalog.CaptureAsync(ownerUserId: "first");
        Assert.Contains(files, file => file.Path == first);
        Assert.DoesNotContain(files, file => file.Path == second);
        await catalog.PrepareRestoreAsync(Snapshot((first, "{\"ownerUserId\":\"first\",\"inventory\":[{\"fruitId\":\"apple\",\"quantity\":2,\"discovered\":true}]}")),
            "first", _ => Task.CompletedTask);
        Assert.True((await catalog.ApplyPendingRestoreAsync()).Applied);
        Assert.Equal("{\"ownerUserId\":\"second\",\"inventory\":[]}",
            File.ReadAllText(Path.Combine(_root, "data", second)));
    }

    [Fact]
    public async Task Capture_IncludesOnlyManagedPrimaryJsonFiles()
    {
        Write("settings.json", "{}");
        Write("widget-layout.json", "[]");
        Write("tree/progress.json", "{\"energy\":1}");
        Write("glance/state.json", "{}");
        Write("dingtalk/group-document-favorites-account.json", "{\"documentUrls\":[]}");
        Write("cache/result.json", "{}");
        Write("sync/tree/state.json", "{}");
        Write("todo/attachments/file.json", "{}");
        Write("quick-capture/attachments/meta.json", "{}");
        Write("weather-cache.json", "{}");
        Write("settings.json.bak", "{}");
        Write("state.tmp", "{}");
        Write("plain.txt", "{}");

        var catalog = new AccountBackupFileCatalog(Path.Combine(_root, "data"));
        AccountBackupFileDto[] files = await catalog.CaptureAsync();

        Assert.Equal(
            ["dingtalk/group-document-favorites-account.json", "glance/state.json", "settings.json", "tree/progress.json", "widget-layout.json"],
            files.Select(file => file.Path).ToArray());
        Assert.All(files, file => Assert.Equal(
            Convert.ToHexString(SHA256.HashData(Encoding.UTF8.GetBytes(file.Content))).ToLowerInvariant(),
            file.Sha256));
    }

    [Fact]
    public async Task Capture_MigratesLegacyFavoritesEvenBeforeTheDingTalkWidgetOpens()
    {
        string name = "group-document-favorites-" + new string('a', 64) + ".json";
        Write("settings.json", "{}");
        Write("sync/dingtalk/" + name, "{\"DocumentUrls\":[\"https://alidocs.dingtalk.com/i/nodes/doc\"]}");
        Write("sync/dingtalk/group-documents-" + new string('a', 64) + ".json",
            "{\"Documents\":[{\"GroupName\":\"Team\",\"Title\":\"Guide\",\"Url\":\"https://alidocs.dingtalk.com/i/nodes/doc\",\"LastSeenAt\":\"2026-10-18T00:00:00+00:00\"}]}");
        var catalog = new AccountBackupFileCatalog(Path.Combine(_root, "data"));

        AccountBackupFileDto[] files = await catalog.CaptureAsync();

        Assert.Contains(files, file => file.Path == "dingtalk/" + name &&
            file.Content.Contains("Guide", StringComparison.Ordinal));
        Assert.DoesNotContain(files, file => file.Path.StartsWith("sync/", StringComparison.Ordinal));
    }

    [Fact]
    public async Task Restore_ReplacesEligibleSetButPreservesExcludedData()
    {
        Write("settings.json", "{\"old\":true}");
        Write("obsolete.json", "{}");
        Write("todo/attachments/keep.json", "{\"attachment\":true}");
        Write("cache/keep.json", "{}");
        Write("sync/tree/keep.json", "{}");
        var catalog = new AccountBackupFileCatalog(Path.Combine(_root, "data"));
        string content = "{\"new\":true}";
        var snapshot = new AccountBackupSnapshotDownloadDto
        {
            Id = new string('a', 32),
            Files =
            [
                new AccountBackupFileDto
                {
                    Path = "settings.json", Content = content, Bytes = Encoding.UTF8.GetByteCount(content),
                    Sha256 = Convert.ToHexString(SHA256.HashData(Encoding.UTF8.GetBytes(content))).ToLowerInvariant()
                }
            ]
        };
        bool safetyCreated = false;

        await catalog.PrepareRestoreAsync(snapshot, "user-1", _ => { safetyCreated = true; return Task.CompletedTask; });
        AccountBackupRestoreResult result = await catalog.ApplyPendingRestoreAsync();

        Assert.True(result.Applied);
        Assert.True(safetyCreated);
        Assert.Equal(content, File.ReadAllText(Path.Combine(_root, "data", "settings.json")));
        Assert.False(File.Exists(Path.Combine(_root, "data", "obsolete.json")));
        Assert.True(File.Exists(Path.Combine(_root, "data", "todo", "attachments", "keep.json")));
        Assert.True(File.Exists(Path.Combine(_root, "data", "cache", "keep.json")));
        Assert.True(File.Exists(Path.Combine(_root, "data", "sync", "tree", "keep.json")));
    }

    [Fact]
    public async Task Restore_RecoversFavoriteDocumentDetailsWithoutReplacingDwsSyncState()
    {
        Write("settings.json", "{}");
        Write("sync/dingtalk/group-documents.json", "{\"cursor\":\"keep\"}");
        const string favoritePath = "dingtalk/group-document-favorites-account.json";
        const string favoriteJson = "{\"documentUrls\":[\"https://alidocs.dingtalk.com/i/nodes/doc\"],\"documents\":[{\"groupName\":\"Team\",\"title\":\"Guide\",\"url\":\"https://alidocs.dingtalk.com/i/nodes/doc\",\"lastSeenAt\":\"2026-10-18T00:00:00+00:00\"}]}";
        var catalog = new AccountBackupFileCatalog(Path.Combine(_root, "data"));
        AccountBackupSnapshotDownloadDto snapshot = Snapshot(
            ("settings.json", "{}"), (favoritePath, favoriteJson));

        await catalog.PrepareRestoreAsync(snapshot, "user-1", _ => Task.CompletedTask);
        AccountBackupRestoreResult applied = await catalog.ApplyPendingRestoreAsync();

        Assert.True(applied.Applied);
        Assert.Equal(favoriteJson, File.ReadAllText(Path.Combine(_root, "data", favoritePath.Replace('/', Path.DirectorySeparatorChar))));
        Assert.True(File.Exists(Path.Combine(_root, "data", "sync", "dingtalk", "group-documents.json")));
    }

    [Fact]
    public async Task Restore_WithoutFavoritesDoesNotResurrectMigratedLegacyStars()
    {
        string name = "group-document-favorites-" + new string('a', 64) + ".json";
        Write("settings.json", "{}");
        Write("sync/dingtalk/" + name,
            "{\"DocumentUrls\":[\"https://alidocs.dingtalk.com/i/nodes/old\"]}");
        var catalog = new AccountBackupFileCatalog(Path.Combine(_root, "data"));
        Assert.Contains(await catalog.CaptureAsync(), file => file.Path == "dingtalk/" + name);

        await catalog.PrepareRestoreAsync(Snapshot(("settings.json", "{}")), "user-1", _ => Task.CompletedTask);
        Assert.True((await catalog.ApplyPendingRestoreAsync()).Applied);
        Assert.DoesNotContain(await catalog.CaptureAsync(), file => file.Path == "dingtalk/" + name);
        Assert.Single(Directory.GetFiles(Path.Combine(_root, "data", "sync", "dingtalk"),
            name + ".migrated-*"));
    }

    [Fact]
    public async Task Restore_RejectsTamperedDownloadedContent()
    {
        var catalog = new AccountBackupFileCatalog(Path.Combine(_root, "data"));
        var snapshot = new AccountBackupSnapshotDownloadDto
        {
            Id = new string('b', 32),
            Files = [new AccountBackupFileDto { Path = "settings.json", Content = "{}", Bytes = 2, Sha256 = new string('0', 64) }]
        };

        await Assert.ThrowsAsync<InvalidDataException>(() => catalog.PrepareRestoreAsync(snapshot, "user-1", _ => Task.CompletedTask));
    }

    [Fact]
    public async Task Restore_FailureRollsBackWholeManagedJsonSetAndKeepsMarkerForRetry()
    {
        Write("settings.json", "{\"old\":true}");
        Write("obsolete.json", "{\"keepUntilCommit\":true}");
        AccountBackupSnapshotDownloadDto snapshot = Snapshot(
            ("settings.json", "{\"new\":true}"),
            ("widget-layout.json", "[]"));
        var failing = new AccountBackupFileCatalog(
            Path.Combine(_root, "data"),
            relative => relative.Equals("widget-layout.json", StringComparison.Ordinal));

        await failing.PrepareRestoreAsync(snapshot, "user-1", _ => Task.CompletedTask);
        AccountBackupRestoreResult failed = await failing.ApplyPendingRestoreAsync();

        Assert.False(failed.Applied);
        Assert.Equal("{\"old\":true}", File.ReadAllText(Path.Combine(_root, "data", "settings.json")));
        Assert.True(File.Exists(Path.Combine(_root, "data", "obsolete.json")));
        Assert.False(File.Exists(Path.Combine(_root, "data", "widget-layout.json")));

        var retry = new AccountBackupFileCatalog(Path.Combine(_root, "data"));
        AccountBackupRestoreResult applied = await retry.ApplyPendingRestoreAsync();

        Assert.True(applied.Applied);
        Assert.Equal("{\"new\":true}", File.ReadAllText(Path.Combine(_root, "data", "settings.json")));
        Assert.Equal("[]", File.ReadAllText(Path.Combine(_root, "data", "widget-layout.json")));
        Assert.False(File.Exists(Path.Combine(_root, "data", "obsolete.json")));
    }

    [Fact]
    public async Task Restore_FailureWithNoOriginalManagedFilesRemovesPartiallyAppliedSet()
    {
        AccountBackupSnapshotDownloadDto snapshot = Snapshot(
            ("settings.json", "{\"new\":true}"),
            ("widget-layout.json", "[]"));
        int applyCount = 0;
        var failing = new AccountBackupFileCatalog(
            Path.Combine(_root, "data"),
            _ => Interlocked.Increment(ref applyCount) == 2);

        await failing.PrepareRestoreAsync(snapshot, "user-1", _ => Task.CompletedTask);
        AccountBackupRestoreResult failed = await failing.ApplyPendingRestoreAsync();

        Assert.False(failed.Applied);
        Assert.False(File.Exists(Path.Combine(_root, "data", "settings.json")));
        Assert.False(File.Exists(Path.Combine(_root, "data", "widget-layout.json")));

        var retry = new AccountBackupFileCatalog(Path.Combine(_root, "data"));
        AccountBackupRestoreResult applied = await retry.ApplyPendingRestoreAsync();

        Assert.True(applied.Applied);
        Assert.Equal("{\"new\":true}", File.ReadAllText(Path.Combine(_root, "data", "settings.json")));
        Assert.Equal("[]", File.ReadAllText(Path.Combine(_root, "data", "widget-layout.json")));
    }

    [Fact]
    public async Task CancelPreparedRestorePreventsADeferredRestore()
    {
        Write("settings.json", "{\"old\":true}");
        var catalog = new AccountBackupFileCatalog(Path.Combine(_root, "data"));
        await catalog.PrepareRestoreAsync(
            Snapshot(("settings.json", "{\"new\":true}")),
            "user-1",
            _ => Task.CompletedTask);

        catalog.CancelPreparedRestore();
        AccountBackupRestoreResult result = await catalog.ApplyPendingRestoreAsync();

        Assert.False(result.Found);
        Assert.Equal("{\"old\":true}", File.ReadAllText(Path.Combine(_root, "data", "settings.json")));
    }

    private static AccountBackupSnapshotDownloadDto Snapshot(params (string Path, string Content)[] files) => new()
    {
        Id = new string('c', 32),
        Files = files.Select(file => new AccountBackupFileDto
        {
            Path = file.Path,
            Content = file.Content,
            Bytes = Encoding.UTF8.GetByteCount(file.Content),
            Sha256 = Convert.ToHexString(SHA256.HashData(Encoding.UTF8.GetBytes(file.Content))).ToLowerInvariant()
        }).ToArray()
    };

    private void Write(string relativePath, string content)
    {
        string path = Path.Combine(_root, "data", relativePath.Replace('/', Path.DirectorySeparatorChar));
        Directory.CreateDirectory(Path.GetDirectoryName(path)!);
        File.WriteAllText(path, content);
    }

    public void Dispose()
    {
        if (Directory.Exists(_root)) Directory.Delete(_root, recursive: true);
    }
}
