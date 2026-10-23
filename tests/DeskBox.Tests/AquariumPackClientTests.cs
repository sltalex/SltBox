using System.Net;
using System.Text;
using System.Text.Json;
using DeskBox.Contracts;
using DeskBox.Services;

namespace DeskBox.Tests;

public sealed class AquariumPackClientTests : IDisposable
{
    private readonly string _cache = Path.Combine(Path.GetTempPath(), "DeskBoxAquariumPack", Guid.NewGuid().ToString("N"));
    private const string PackId = "11111111111111111111111111111111";

    [Fact]
    public void CardPacksRemainGroupedInBackpackAsTheirOwnCategory()
    {
        AquariumCatalogItem[] catalog = [new(AquariumService.AquariumCardPackItemId, "水族卡包", "pack", 160, "aquarium-card-pack")];
        AquariumInventoryItem[] inventory =
        [
            new(PackId, AquariumService.AquariumCardPackItemId, "now"),
            new("33333333333333333333333333333333", AquariumService.AquariumCardPackItemId, "now")
        ];

        AquariumOwnedProduct packs = Assert.Single(AquariumInventoryPresentation.Summarize(inventory, [], catalog));
        Assert.Equal("pack", packs.Product.Category);
        Assert.Equal(2, packs.OwnedCount);
        Assert.Equal(0, packs.PlacedCount);
    }

    [Fact]
    public void OpenedItemParserAcceptsServerCatalogObjectAndCompactId()
    {
        AquariumCatalogItem[] catalog =
        [
            new("clownfish", "小丑鱼", "fish", 100, "clownfish")
        ];

        using var full = JsonDocument.Parse("""
            {"opened_item":{"id":"clownfish","name":"小丑鱼","category":"fish","asset_key":"clownfish"}}
            """);
        AquariumOpenedItem parsed = Assert.IsType<AquariumOpenedItem>(AquariumJson.ReadOpenedItem(full.RootElement, catalog));
        Assert.Equal("clownfish", parsed.ItemId);
        Assert.Equal("小丑鱼", parsed.Name);
        Assert.Equal("fish", parsed.Category);

        using var compact = JsonDocument.Parse("""{"opened_item_id":"clownfish"}""");
        Assert.Equal("clownfish", Assert.IsType<AquariumOpenedItem>(AquariumJson.ReadOpenedItem(compact.RootElement, catalog)).ItemId);

        using var exact = JsonDocument.Parse("""
            {"opened_item":{"id":"22222222222222222222222222222222","item_id":"clownfish","name":"小丑鱼","category":"fish","asset_key":"clownfish"}}
            """);
        AquariumOpenedItem exactItem = Assert.IsType<AquariumOpenedItem>(AquariumJson.ReadOpenedItem(exact.RootElement, catalog));
        Assert.Equal("clownfish", exactItem.ItemId);
        Assert.Equal("22222222222222222222222222222222", exactItem.InventoryId);
    }

    [Fact]
    public async Task OpenPackSendsInstanceIdAndRaisesRewardEvent()
    {
        var api = new PackApi();
        await using var service = new AquariumService(new PackAuth(), api, _cache);
        AquariumOpenedItem? opened = null;
        service.Opened += item => opened = item;

        Assert.True(await service.OpenPackAsync(PackId));
        Assert.Equal("open_pack", api.Action);
        Assert.Equal(PackId, api.PackInstanceId);
        using JsonDocument request = JsonDocument.Parse(Assert.Single(api.Commands));
        Assert.Equal(["action", "operation_id", "pack_instance_id"],
            request.RootElement.EnumerateObject().Select(property => property.Name));
        Assert.Equal("clownfish", service.LastOpenedItem?.ItemId);
        Assert.Equal("clownfish", opened?.ItemId);
        Assert.DoesNotContain(service.State!.Inventory, item => item.Id == PackId);
        Assert.Contains(service.State.Inventory, item => item.ItemId == "clownfish");
    }

    [Fact]
    public async Task LostOpenResponseReplaysOriginalCommandAndOnlyConsumesOnePack()
    {
        var api = new PackApi { LoseNextResponse = true };
        await using (var first = new AquariumService(new PackAuth(), api, _cache))
        {
            Assert.False(await first.OpenPackAsync(PackId));
            Assert.Null(first.LastOpenedItem);
            Assert.True(first.HasPendingOperation);
        }
        byte[] original = Assert.Single(api.Commands);
        await using var restored = new AquariumService(new PackAuth(), api, _cache);
        Assert.True(await restored.RefreshAsync());
        Assert.Equal(original, api.Commands.Last());
        Assert.Equal(1, api.OpenCount);
        Assert.False(restored.HasPendingOperation);
        Assert.Equal("clownfish", restored.LastOpenedItem?.ItemId);
    }

    [Fact]
    public async Task RejectedOrMalformedOpenDoesNotReusePreviousReward()
    {
        var api = new PackApi();
        await using var service = new AquariumService(new PackAuth(), api, _cache);
        int openedEvents = 0;
        service.Opened += _ => openedEvents++;
        Assert.True(await service.OpenPackAsync(PackId));
        Assert.False(await service.OpenPackAsync(PackId));
        Assert.Equal("invalid_pack", service.ErrorCode);
        Assert.Null(service.LastOpenedItem);
        Assert.Equal(1, openedEvents);

        api.AcceptInvalidPackWithoutReward = true;
        Assert.False(await service.OpenPackAsync(PackId));
        Assert.Equal("invalid_response", service.ErrorCode);
        Assert.Null(service.LastOpenedItem);
        Assert.Equal(1, openedEvents);
    }

    [Fact]
    public async Task OrdinaryUserCanOpenPack()
    {
        var auth = new PackAuth();
        var api = new PackApi();
        auth.SetUser(new("user-2", "Member", "user"));
        await using var service = new AquariumService(auth, api, _cache);
        Assert.True(await service.OpenPackAsync(PackId));
        Assert.NotNull(service.LastOpenedItem);
        Assert.Single(api.Commands);
    }

    public void Dispose()
    {
        if (Directory.Exists(_cache)) Directory.Delete(_cache, recursive: true);
    }

    private sealed class PackAuth : IAuthSession
    {
        public event Action? SessionChanged;
        public bool IsAuthenticated => CurrentUser is not null;
        public AuthUser? CurrentUser { get; private set; } = new("admin-1", "Administrator", "admin");
        internal void SetUser(AuthUser? user) { CurrentUser = user; SessionChanged?.Invoke(); }
        public Task<AuthUser> RegisterAsync(string username, string password, CancellationToken cancellationToken = default) => throw new NotSupportedException();
        public Task<AuthUser> SignInAsync(string username, string password, CancellationToken cancellationToken = default) => throw new NotSupportedException();
        public Task RefreshAsync(CancellationToken cancellationToken = default) => Task.CompletedTask;
        public Task RestoreAsync(CancellationToken cancellationToken = default) => Task.CompletedTask;
        public Task SignOutAsync(CancellationToken cancellationToken = default) => Task.CompletedTask;
    }

    private sealed class PackApi : IAuthenticatedAccountApi
    {
        private bool _opened;
        private readonly HashSet<string> _operations = new(StringComparer.Ordinal);
        internal string? Action { get; private set; }
        internal string? PackInstanceId { get; private set; }
        internal bool LoseNextResponse { get; set; }
        internal bool AcceptInvalidPackWithoutReward { get; set; }
        internal int OpenCount { get; private set; }
        internal List<byte[]> Commands { get; } = [];

        public Task<HttpResponseMessage> SendAsync(HttpMethod method, string relativePath, byte[]? jsonBody,
            string? contentEncoding, CancellationToken cancellationToken = default)
        {
            if (relativePath.EndsWith("catalog.php", StringComparison.Ordinal))
                return Task.FromResult(Response("""
                    {"catalog_version":5,"items":[
                      {"id":"aquarium-card-pack","name":"水族卡包","category":"pack","price":100,"asset_key":"aquarium-card-pack"},
                      {"id":"clownfish","name":"小丑鱼","category":"fish","price":100,"asset_key":"clownfish"}]}
                    """));
            if (relativePath.EndsWith("state.php", StringComparison.Ordinal))
                return Task.FromResult(Response(StateJson()));

            using JsonDocument body = JsonDocument.Parse(jsonBody!);
            Commands.Add(jsonBody!.ToArray());
            Action = body.RootElement.GetProperty("action").GetString();
            PackInstanceId = body.RootElement.GetProperty("pack_instance_id").GetString();
            string operation = body.RootElement.GetProperty("operation_id").GetString()!;
            if (_operations.Contains(operation)) return Task.FromResult(Response(StateJson(withReward: true)));
            if (AcceptInvalidPackWithoutReward) return Task.FromResult(Response(StateJson()));
            if (_opened) return Task.FromResult(Response("""{"code":"invalid_pack"}""", HttpStatusCode.Conflict));
            _opened = true;
            OpenCount++;
            _operations.Add(operation);
            if (LoseNextResponse)
            {
                LoseNextResponse = false;
                throw new HttpRequestException("Lost response after pack opened.");
            }
            return Task.FromResult(Response(StateJson(withReward: true)));
        }

        private string StateJson(bool withReward = false)
        {
            string reward = withReward
                ? ",\"opened_item\":{\"id\":\"fish-1\",\"item_id\":\"clownfish\",\"name\":\"小丑鱼\",\"category\":\"fish\",\"asset_key\":\"clownfish\"}"
                : string.Empty;
            return $$"""
            {"initialized":true,"coins":100,"scene_version":0,
             "inventory":{{(_opened || withReward ? "[{\"id\":\"fish-1\",\"item_id\":\"clownfish\",\"created_at\":\"2026-10-18T00:00:00Z\"}]" : "[{\"id\":\"" + PackId + "\",\"item_id\":\"aquarium-card-pack\",\"created_at\":\"2026-10-18T00:00:00Z\"}]")}},
             "scene":[],"wallet":[]{{reward}}}
            """;
        }

        private static HttpResponseMessage Response(string json, HttpStatusCode status = HttpStatusCode.OK) => new(status)
        {
            Content = new StringContent(json, Encoding.UTF8, "application/json")
        };
    }
}
