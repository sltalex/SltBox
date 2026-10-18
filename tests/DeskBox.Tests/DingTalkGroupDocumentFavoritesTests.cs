using DeskBox.Services;

namespace DeskBox.Tests;

public sealed class DingTalkGroupDocumentFavoritesTests
{
    [Fact]
    public async Task FavoriteSurvivesReloadAndSharesIdentityAcrossLinkParameters()
    {
        string root = Path.Combine(Path.GetTempPath(), $"deskbox-dingtalk-favorites-{Guid.NewGuid():N}");
        try
        {
            var dws = new FakeDwsClient();
            var favorites = new DingTalkGroupDocumentFavorites(dws, root);
            const string shared = "https://alidocs.dingtalk.com/i/nodes/document?utm_source=im";
            const string recent = "https://alidocs.dingtalk.com/i/nodes/document?utm_source=portal_recent";

            await favorites.LoadAsync();
            await favorites.SetFavoriteAsync(shared, true);

            var reopened = new DingTalkGroupDocumentFavorites(dws, root);
            await reopened.LoadAsync();
            Assert.True(reopened.IsFavorite(recent));

            await reopened.SetFavoriteAsync(recent, false);
            var afterRemoval = new DingTalkGroupDocumentFavorites(dws, root);
            await afterRemoval.LoadAsync();
            Assert.False(afterRemoval.IsFavorite(shared));
        }
        finally { if (Directory.Exists(root)) Directory.Delete(root, recursive: true); }
    }

    [Fact]
    public async Task FavoriteDocumentDetailsSurviveReloadWithoutTheGroupDocumentIndex()
    {
        string root = Path.Combine(Path.GetTempPath(), $"deskbox-dingtalk-favorites-{Guid.NewGuid():N}");
        try
        {
            var dws = new FakeDwsClient();
            var document = new DingTalkGroupDocument("项目群", "预算说明", "https://alidocs.dingtalk.com/i/nodes/document?utm_source=im", DateTimeOffset.UtcNow);
            await new DingTalkGroupDocumentFavorites(dws, root).SetFavoriteAsync(document, true);

            var reopened = new DingTalkGroupDocumentFavorites(dws, root);
            await reopened.LoadAsync();
            await reopened.UpdateDocumentMetadataAsync([document with { Title = "群文档" }]);

            DingTalkGroupDocument saved = Assert.Single(reopened.FavoriteDocuments);
            Assert.Equal(document.GroupName, saved.GroupName);
            Assert.Equal(document.Title, saved.Title);
            Assert.Equal(document.Url, saved.Url);
        }
        finally { if (Directory.Exists(root)) Directory.Delete(root, recursive: true); }
    }

    [Fact]
    public async Task LegacySyncFavoritesAreMigratedToTheBackupEligibleDirectory()
    {
        string root = Path.Combine(Path.GetTempPath(), $"deskbox-dingtalk-favorites-{Guid.NewGuid():N}");
        try
        {
            var dws = new FakeDwsClient();
            string legacy = Path.Combine(root, "data", "sync", "dingtalk");
            string managed = Path.Combine(root, "data", "dingtalk");
            const string url = "https://alidocs.dingtalk.com/i/nodes/document";
            await new DingTalkGroupDocumentFavorites(dws, legacy).SetFavoriteAsync(url, true);

            var migrated = new DingTalkGroupDocumentFavorites(dws, managed, legacy);
            await migrated.LoadAsync();

            Assert.True(migrated.IsFavorite(url));
            Assert.Single(Directory.GetFiles(managed, "group-document-favorites-*.json"));
            Assert.Empty(Directory.GetFiles(legacy, "group-document-favorites-*.json"));
            Assert.Single(Directory.GetFiles(legacy, "group-document-favorites-*.json.migrated-*"));
            var catalog = new DeskBox.Core.Persistence.AccountBackupFileCatalog(Path.Combine(root, "data"));
            Assert.Contains(await catalog.CaptureAsync(), file =>
                file.Path.StartsWith("dingtalk/group-document-favorites-", StringComparison.Ordinal));
        }
        finally { if (Directory.Exists(root)) Directory.Delete(root, recursive: true); }
    }

    [Fact]
    public async Task BackupEnrichesAnAlreadyMigratedFavoriteWithoutRestoringRemovedStars()
    {
        string root = Path.Combine(Path.GetTempPath(), $"deskbox-dingtalk-favorites-{Guid.NewGuid():N}");
        try
        {
            var dws = new FakeDwsClient();
            string legacy = Path.Combine(root, "data", "sync", "dingtalk");
            string managed = Path.Combine(root, "data", "dingtalk");
            const string kept = "https://alidocs.dingtalk.com/i/nodes/kept";
            const string removed = "https://alidocs.dingtalk.com/i/nodes/removed";
            var old = new DingTalkGroupDocumentFavorites(dws, legacy);
            await old.SetFavoriteAsync(kept, true);
            await old.SetFavoriteAsync(removed, true);
            string name = Path.GetFileName(Assert.Single(Directory.GetFiles(legacy, "group-document-favorites-*.json")));
            Directory.CreateDirectory(managed);
            await File.WriteAllTextAsync(Path.Combine(managed, name),
                "{\"DocumentUrls\":[\"" + kept + "\"],\"Documents\":[]}");
            await File.WriteAllTextAsync(Path.Combine(legacy, name.Replace("group-document-favorites-", "group-documents-")),
                "{\"Documents\":[" +
                "{\"GroupName\":\"Team\",\"Title\":\"Guide\",\"Url\":\"" + kept + "\",\"LastSeenAt\":\"2026-10-18T00:00:00+00:00\"}," +
                "{\"GroupName\":\"Team\",\"Title\":\"Removed\",\"Url\":\"" + removed + "\",\"LastSeenAt\":\"2026-10-18T00:00:00+00:00\"}]}");

            var catalog = new DeskBox.Core.Persistence.AccountBackupFileCatalog(Path.Combine(root, "data"));
            var file = Assert.Single((await catalog.CaptureAsync()).Where(item => item.Path == "dingtalk/" + name));

            Assert.Contains("Guide", file.Content, StringComparison.Ordinal);
            Assert.DoesNotContain("Removed", file.Content, StringComparison.Ordinal);
            Assert.Empty(Directory.GetFiles(legacy, "group-document-favorites-*.json"));
        }
        finally { if (Directory.Exists(root)) Directory.Delete(root, recursive: true); }
    }

    [Fact]
    public async Task ExistingWidgetRetainsMigratedMetadataWhenAddingAnotherStar()
    {
        string root = Path.Combine(Path.GetTempPath(), $"deskbox-dingtalk-favorites-{Guid.NewGuid():N}");
        try
        {
            var dws = new FakeDwsClient();
            string legacy = Path.Combine(root, "data", "sync", "dingtalk");
            string managed = Path.Combine(root, "data", "dingtalk");
            const string first = "https://alidocs.dingtalk.com/i/nodes/first";
            const string second = "https://alidocs.dingtalk.com/i/nodes/second";
            var widget = new DingTalkGroupDocumentFavorites(dws, managed, legacy);
            await widget.SetFavoriteAsync(first, true);
            string name = Path.GetFileName(Assert.Single(Directory.GetFiles(managed, "group-document-favorites-*.json")));
            Directory.CreateDirectory(legacy);
            File.Copy(Path.Combine(managed, name), Path.Combine(legacy, name));
            await File.WriteAllTextAsync(Path.Combine(legacy,
                name.Replace("group-document-favorites-", "group-documents-")),
                "{\"Documents\":[{\"GroupName\":\"Team\",\"Title\":\"Guide\",\"Url\":\"" + first +
                "\",\"LastSeenAt\":\"2026-10-18T00:00:00+00:00\"}]}");

            var catalog = new DeskBox.Core.Persistence.AccountBackupFileCatalog(Path.Combine(root, "data"));
            await Task.WhenAll(catalog.CaptureAsync(), catalog.CaptureAsync());
            await widget.SetFavoriteAsync(second, true);

            var reopened = new DingTalkGroupDocumentFavorites(dws, managed, legacy);
            await reopened.LoadAsync();
            Assert.True(reopened.IsFavorite(first));
            Assert.True(reopened.IsFavorite(second));
            Assert.Equal("Guide", Assert.Single(reopened.FavoriteDocuments).Title);
        }
        finally { if (Directory.Exists(root)) Directory.Delete(root, recursive: true); }
    }

    [Fact]
    public async Task FavoritesAreScopedToTheCurrentDwsAccount()
    {
        string root = Path.Combine(Path.GetTempPath(), $"deskbox-dingtalk-favorites-{Guid.NewGuid():N}");
        try
        {
            var dws = new FakeDwsClient();
            var favorites = new DingTalkGroupDocumentFavorites(dws, root);
            const string url = "https://alidocs.dingtalk.com/i/nodes/document";
            await favorites.SetFavoriteAsync(url, true);

            dws.UserId = "another-user";
            await favorites.LoadAsync();
            Assert.False(favorites.IsFavorite(url));

            dws.UserId = "first-user";
            await favorites.LoadAsync();
            Assert.True(favorites.IsFavorite(url));
        }
        finally { if (Directory.Exists(root)) Directory.Delete(root, recursive: true); }
    }

    [Fact]
    public async Task UnreadableNextAccountDoesNotShowPreviousAccountsFavorites()
    {
        string root = Path.Combine(Path.GetTempPath(), $"deskbox-dingtalk-favorites-{Guid.NewGuid():N}");
        try
        {
            var dws = new FakeDwsClient();
            var favorites = new DingTalkGroupDocumentFavorites(dws, root);
            const string url = "https://alidocs.dingtalk.com/i/nodes/document";
            await favorites.SetFavoriteAsync(url, true);

            dws.UserId = "another-user";
            await favorites.SetFavoriteAsync("https://alidocs.dingtalk.com/i/nodes/another", true);
            string nextAccountFile = Directory.GetFiles(root, "group-document-favorites-*.json")
                .Single(path => File.ReadAllText(path).Contains("/another", StringComparison.Ordinal));
            await File.WriteAllTextAsync(nextAccountFile, "{invalid json");

            dws.UserId = "first-user";
            await favorites.LoadAsync();
            dws.UserId = "another-user";
            await Assert.ThrowsAsync<System.Text.Json.JsonException>(() => favorites.LoadAsync());
            Assert.False(favorites.IsFavorite(url));
            Assert.Empty(favorites.FavoriteDocuments);
        }
        finally { if (Directory.Exists(root)) Directory.Delete(root, recursive: true); }
    }

    [Fact]
    public async Task ExpiredDwsAuthorizationHidesPreviouslyLoadedFavoriteDetails()
    {
        string root = Path.Combine(Path.GetTempPath(), $"deskbox-dingtalk-favorites-{Guid.NewGuid():N}");
        try
        {
            var dws = new FakeDwsClient();
            var favorites = new DingTalkGroupDocumentFavorites(dws, root);
            var document = new DingTalkGroupDocument("Team", "Private guide",
                "https://alidocs.dingtalk.com/i/nodes/private", DateTimeOffset.UtcNow);
            await favorites.SetFavoriteAsync(document, true);

            dws.TokenValid = false;
            await Assert.ThrowsAsync<DingTalkDwsAuthorizationRequiredException>(() => favorites.LoadAsync());
            Assert.False(favorites.IsFavorite(document.Url));
            Assert.Empty(favorites.FavoriteDocuments);

            dws.TokenValid = true;
            await favorites.LoadAsync();
            Assert.Equal("Private guide", Assert.Single(favorites.FavoriteDocuments).Title);
        }
        finally { if (Directory.Exists(root)) Directory.Delete(root, recursive: true); }
    }

    private sealed class FakeDwsClient : IDingTalkDwsClient
    {
        public string UserId { get; set; } = "first-user";
        public bool TokenValid { get; set; } = true;

        public Task<DingTalkDwsAuthStatus> GetAuthStatusAsync(CancellationToken cancellationToken = default)
            => Task.FromResult(new DingTalkDwsAuthStatus(true, TokenValid, true, "corp", "Company", UserId, "User"));

        public Task<DingTalkProfile> GetCurrentUserAsync(CancellationToken cancellationToken = default)
            => throw new NotSupportedException();

        public Task<DingTalkDwsAuthStatus> LoginAsync(CancellationToken cancellationToken = default)
            => throw new NotSupportedException();

        public Task<DingTalkGroupMessagePage> ListGroupMessagesAsync(DateTimeOffset start, DateTimeOffset end,
            string? cursor = null, int limit = 50, CancellationToken cancellationToken = default)
            => throw new NotSupportedException();

        public Task<string?> GetDocumentTitleAsync(string url, CancellationToken cancellationToken = default)
            => throw new NotSupportedException();

        public Task<IReadOnlyList<DingTalkCalendarEvent>> ListCurrentWeekCalendarAsync(CancellationToken cancellationToken = default)
            => Task.FromResult<IReadOnlyList<DingTalkCalendarEvent>>([]);
    }
}
