using System.Net;
using System.Text;
using System.Text.Json;
using System.Xml.Linq;
using DeskBox.Contracts;
using DeskBox.Services;

namespace DeskBox.Tests;

public sealed class WildlifeServiceTests
{
    [Fact]
    public void ClientCatalogAndAssetIds_MatchServerDirectory()
    {
        using JsonDocument manifest = JsonDocument.Parse(File.ReadAllText(
            TestPaths.FromRepository("server/deskbox-api/config/wildlife.json")));
        var server = manifest.RootElement.GetProperty("items").EnumerateArray()
            .Select(item => (Id: item.GetProperty("id").GetString()!, Name: item.GetProperty("name").GetString()!,
                Category: item.GetProperty("category").GetString()!)).ToArray();

        Assert.Equal(180, server.Length);
        Assert.Equal(180, server.Select(item => item.Id).Distinct(StringComparer.Ordinal).Count());
        foreach (string category in new[] { "昆虫", "飞鸟", "小动物" })
            Assert.Equal(60, server.Count(item => item.Category == category));
        Assert.Equal(server.Select(item => item.Id), WildlifeAssetCatalog.SpeciesIds);
        Assert.Equal(server, WildlifeService.LocalCatalog.Select(item =>
            (item.Id, item.Name, item.Category)));
    }

    [Fact]
    public async Task CatalogAvailability_IsReadAndShownInChinese()
    {
        await using var service = new WildlifeService(new FakeAuth("owner"), new FakeApi());

        Assert.True(await service.RefreshAsync());
        WildlifeCatalogItem butterfly = Assert.Single(service.Catalog, item => item.Id == "butterfly");
        Assert.Equal(["spring"], butterfly.Seasons);
        Assert.Equal(["morning", "day"], butterfly.Periods);
        Assert.Equal(["clear", "cloudy"], butterfly.Weathers);
        Assert.Equal("季节：春季", WildlifeAvailabilityPresentation.Seasons(butterfly));
        Assert.Equal("时段：清晨、白天", WildlifeAvailabilityPresentation.Periods(butterfly));
        Assert.Equal("天气：晴、多云", WildlifeAvailabilityPresentation.Weathers(butterfly));
    }

    [Fact]
    public void UnverifiedLocalCatalog_DoesNotInventAvailability()
    {
        WildlifeCatalogItem butterfly = Assert.Single(WildlifeService.LocalCatalog,
            item => item.Id == "butterfly");

        Assert.Equal("季节：待同步", WildlifeAvailabilityPresentation.Seasons(butterfly));
        Assert.Equal("时段：待同步", WildlifeAvailabilityPresentation.Periods(butterfly));
        Assert.Equal("天气：待同步", WildlifeAvailabilityPresentation.Weathers(butterfly));
    }

    [Fact]
    public void EverySpecies_HasDistinctTransparentSvgAndSoftUnrevealingSilhouette()
    {
        string root = TestPaths.FromRepository("src/DeskBox/Assets/Tree/Wildlife");
        var normal = new HashSet<string>(StringComparer.Ordinal);
        var blurred = new HashSet<string>(StringComparer.Ordinal);
        foreach (string id in WildlifeAssetCatalog.SpeciesIds)
        {
            string source = File.ReadAllText(Path.Combine(root, "Source", id + ".svg"));
            string blur = File.ReadAllText(Path.Combine(root, "Blur", id + ".svg"));
            Assert.Equal("svg", XDocument.Parse(source).Root?.Name.LocalName);
            Assert.Equal("svg", XDocument.Parse(blur).Root?.Name.LocalName);
            Assert.DoesNotContain("<image", source, StringComparison.OrdinalIgnoreCase);
            Assert.DoesNotContain("<rect", source, StringComparison.OrdinalIgnoreCase);
            Assert.Contains("opacity=\".065\"", blur, StringComparison.Ordinal);
            Assert.DoesNotContain("<image", blur, StringComparison.OrdinalIgnoreCase);
            Assert.True(normal.Add(source), $"Duplicate animal drawing: {id}");
            Assert.True(blurred.Add(blur), $"Duplicate silhouette: {id}");
        }
        Assert.Equal(180, Directory.GetFiles(Path.Combine(root, "Source"), "*.svg").Length);
        Assert.Equal(180, Directory.GetFiles(Path.Combine(root, "Blur"), "*.svg").Length);
    }

    [Fact]
    public async Task RestoredAccountCache_MergesThroughAuthenticatedApiBeforeReadingState()
    {
        string root = Path.Combine(Path.GetTempPath(), "DeskBoxWildlifeTests", Guid.NewGuid().ToString("N"));
        string wildlifeDirectory = Path.Combine(root, "wildlife");
        Directory.CreateDirectory(wildlifeDirectory);
        try
        {
            File.WriteAllText(Path.Combine(wildlifeDirectory, "wildlife-state-account-owner.json"),
                "{\"OwnerUserId\":\"owner\",\"Inventory\":[{\"SpeciesId\":\"rabbit\",\"Quantity\":3,\"Discovered\":true}]}");
            var api = new FakeApi();
            await using var service = new WildlifeService(new FakeAuth("owner"), api, root);

            Assert.True(await service.RefreshAsync());
            Assert.Equal(["restore.php", "catalog.php", "state.php"], api.Calls);
            using JsonDocument restored = JsonDocument.Parse(api.RestoreBody!);
            Assert.Equal("owner", restored.RootElement.GetProperty("owner_user_id").GetString());
            JsonElement rabbit = restored.RootElement.GetProperty("inventory")[0];
            Assert.Equal("rabbit", rabbit.GetProperty("species_id").GetString());
            Assert.Equal(3, rabbit.GetProperty("quantity").GetInt64());
            Assert.True(rabbit.GetProperty("discovered").GetBoolean());
            Assert.Equal(3, service.Inventory.Single().Quantity);

            api.Calls.Clear();
            Assert.True(await service.RefreshAsync());
            Assert.Equal(["catalog.php", "state.php"], api.Calls);
        }
        finally { Directory.Delete(root, recursive: true); }
    }

    [Theory]
    [InlineData(false)]
    [InlineData(true)]
    public async Task EmptyCacheEntries_AreNotSentToRestore(bool hasCollectedAnimal)
    {
        string root = Path.Combine(Path.GetTempPath(), "DeskBoxWildlifeTests", Guid.NewGuid().ToString("N"));
        string wildlifeDirectory = Path.Combine(root, "wildlife");
        Directory.CreateDirectory(wildlifeDirectory);
        try
        {
            string rabbit = hasCollectedAnimal
                ? "{\"SpeciesId\":\"rabbit\",\"Quantity\":3,\"Discovered\":true},"
                : string.Empty;
            File.WriteAllText(Path.Combine(wildlifeDirectory, "wildlife-state-account-owner.json"),
                "{\"OwnerUserId\":\"owner\",\"Inventory\":[" + rabbit +
                "{\"SpeciesId\":\"butterfly\",\"Quantity\":0,\"Discovered\":false}]}");
            var api = new FakeApi { RejectEmptyRestoreItems = true };
            await using var service = new WildlifeService(new FakeAuth("owner"), api, root);

            Assert.True(await service.RefreshAsync());
            string[] expectedCalls = hasCollectedAnimal
                ? ["restore.php", "catalog.php", "state.php"]
                : ["catalog.php", "state.php"];
            Assert.Equal(expectedCalls, api.Calls);
            if (hasCollectedAnimal)
            {
                using JsonDocument restored = JsonDocument.Parse(api.RestoreBody!);
                Assert.Equal("rabbit", Assert.Single(restored.RootElement.GetProperty("inventory").EnumerateArray())
                    .GetProperty("species_id").GetString());
            }
        }
        finally { Directory.Delete(root, recursive: true); }
    }

    [Fact]
    public async Task ForeignAccountCache_IsNotMerged()
    {
        string root = Path.Combine(Path.GetTempPath(), "DeskBoxWildlifeTests", Guid.NewGuid().ToString("N"));
        string wildlifeDirectory = Path.Combine(root, "wildlife");
        Directory.CreateDirectory(wildlifeDirectory);
        try
        {
            File.WriteAllText(Path.Combine(wildlifeDirectory, "wildlife-state-account-owner.json"),
                "{\"OwnerUserId\":\"somebody-else\",\"Inventory\":[{\"SpeciesId\":\"rabbit\",\"Quantity\":99,\"Discovered\":true}]}");
            var api = new FakeApi();
            await using var service = new WildlifeService(new FakeAuth("owner"), api, root);

            Assert.True(await service.RefreshAsync());
            Assert.DoesNotContain("restore.php", api.Calls);
            Assert.Equal(3, service.Inventory.Single().Quantity);
        }
        finally { Directory.Delete(root, recursive: true); }
    }

    [Fact]
    public async Task ExpandedEncyclopedia_RemainsVisibleDuringOldServerCatalogRollout()
    {
        using JsonDocument manifest = JsonDocument.Parse(File.ReadAllText(
            TestPaths.FromRepository("server/deskbox-api/config/wildlife.json")));
        var originalItems = manifest.RootElement.GetProperty("items").EnumerateArray()
            .Take(16).Select(item => item.GetRawText());
        var api = new FakeApi
        {
            CatalogJsonOverride = "{\"items\":[" + string.Join(',', originalItems) + "]}"
        };
        await using var service = new WildlifeService(new FakeAuth("owner"), api);

        Assert.True(await service.RefreshAsync());
        Assert.Equal(180, service.Catalog.Count);
        Assert.Contains(service.Catalog, item => item.Id == "snail");
    }

    [Fact]
    public async Task CaptureWaitsForAnInFlightRefreshInsteadOfDroppingTheClick()
    {
        var api = new FakeApi
        {
            StateJsonOverride = "{\"inventory\":[],\"encounter\":{\"id\":\"offer-1\",\"species_id\":\"rabbit\",\"expires_at_utc\":\"2030-01-01T00:00:00Z\"}}"
        };
        await using var service = new WildlifeService(new FakeAuth("owner"), api);
        Assert.True(await service.RefreshAsync());

        var catalogEntered = new TaskCompletionSource<bool>(TaskCreationOptions.RunContinuationsAsynchronously);
        var releaseCatalog = new TaskCompletionSource<bool>(TaskCreationOptions.RunContinuationsAsynchronously);
        api.CatalogEntered = catalogEntered;
        api.CatalogGate = releaseCatalog;
        Task<bool> refresh = service.RefreshAsync();
        await catalogEntered.Task.WaitAsync(TimeSpan.FromSeconds(5));
        Task<bool> capture = service.CaptureAsync("offer-1");
        bool droppedClick = capture.IsCompleted;
        releaseCatalog.TrySetResult(true);

        Assert.False(droppedClick);
        Assert.True(await refresh);
        Assert.True(await capture);
        Assert.Contains("capture.php", api.Calls);
    }

    private sealed class FakeApi : IAuthenticatedAccountApi
    {
        internal List<string> Calls { get; } = [];
        internal byte[]? RestoreBody { get; private set; }
        internal string? CatalogJsonOverride { get; init; }
        internal string? StateJsonOverride { get; init; }
        internal TaskCompletionSource<bool>? CatalogEntered { get; set; }
        internal TaskCompletionSource<bool>? CatalogGate { get; set; }
        internal bool RejectEmptyRestoreItems { get; init; }

        public async Task<HttpResponseMessage> SendAsync(HttpMethod method, string relativePath, byte[]? jsonBody,
            string? contentEncoding, CancellationToken cancellationToken = default)
        {
            string name = Path.GetFileName(relativePath);
            Calls.Add(name);
            if (name == "catalog.php" && CatalogGate is { } gate)
            {
                CatalogEntered?.TrySetResult(true);
                await gate.Task.WaitAsync(cancellationToken);
            }
            string json = name switch
            {
                "catalog.php" => CatalogJsonOverride ?? File.ReadAllText(TestPaths.FromRepository("server/deskbox-api/config/wildlife.json")),
                "state.php" => StateJsonOverride ?? "{\"inventory\":[{\"species_id\":\"rabbit\",\"quantity\":3,\"discovered\":true}],\"encounter\":null}",
                "restore.php" => "{\"inventory\":[],\"encounter\":null}",
                _ => "{}"
            };
            if (name == "restore.php") RestoreBody = jsonBody;
            if (name == "restore.php" && RejectEmptyRestoreItems)
            {
                using JsonDocument restore = JsonDocument.Parse(jsonBody!);
                if (restore.RootElement.GetProperty("inventory").EnumerateArray().Any(item =>
                    item.GetProperty("quantity").GetInt64() == 0 &&
                    !item.GetProperty("discovered").GetBoolean()))
                    return new HttpResponseMessage(HttpStatusCode.BadRequest)
                    {
                        Content = new StringContent("{\"code\":\"invalid_wildlife_restore\",\"message\":\"Wildlife backup entry is invalid.\"}",
                            Encoding.UTF8, "application/json")
                    };
            }
            return new HttpResponseMessage(HttpStatusCode.OK)
            {
                Content = new StringContent(json, Encoding.UTF8, "application/json")
            };
        }
    }

    private sealed class FakeAuth(string id) : IAuthSession
    {
        public event Action? SessionChanged;
        public bool IsAuthenticated => true;
        public AuthUser? CurrentUser => new(id, "Wildlife");
        public Task<AuthUser> RegisterAsync(string username, string password, CancellationToken cancellationToken = default) => throw new NotSupportedException();
        public Task<AuthUser> SignInAsync(string username, string password, CancellationToken cancellationToken = default) => throw new NotSupportedException();
        public Task RefreshAsync(CancellationToken cancellationToken = default) => Task.CompletedTask;
        public Task RestoreAsync(CancellationToken cancellationToken = default) => Task.CompletedTask;
        public Task SignOutAsync(CancellationToken cancellationToken = default) => Task.CompletedTask;
    }
}
