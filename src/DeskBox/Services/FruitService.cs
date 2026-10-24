using System.Net;
using System.Text;
using System.Text.Json;
using DeskBox.Contracts;
using DeskBox.Core.Persistence;

namespace DeskBox.Services;

/// <summary>Account-scoped fruit catalogue, typed inventory and daily prices.</summary>
internal sealed class FruitService : IAsyncDisposable
{
    private readonly IAuthSession _auth;
    private readonly IAuthenticatedAccountApi _api;
    private readonly SemaphoreSlim _gate = new(1, 1);
    private readonly object _stateGate = new();
    private readonly Dictionary<(string FruitId, int Quantity), string> _pendingSales = new();
    private string? CachePath => _auth.CurrentUser is { } user
        ? Path.Combine(DeskBoxDataPathService.Current.DataDirectory, "fruit", $"fruit-state-account-{AccountBackupFileCatalog.SafeAccountId(user.Id)}.json")
        : null;
    private long _pendingPickId;
    private string? _pendingPickOperation;
    internal FruitService(IAuthSession auth, IAuthenticatedAccountApi api) { _auth = auth; _api = api; _auth.SessionChanged += OnSessionChanged; }
    internal event Action? Changed;
    internal IReadOnlyList<FruitCatalogItem> Catalog { get; private set; } = Array.Empty<FruitCatalogItem>();
    internal IReadOnlyList<FruitInventoryItem> Inventory { get; private set; } = Array.Empty<FruitInventoryItem>();
    internal IReadOnlyDictionary<string, IReadOnlyList<FruitPricePoint>> PriceHistory { get; private set; } = new Dictionary<string, IReadOnlyList<FruitPricePoint>>();
    internal IReadOnlyList<FruitOnTreeItem> OnTree { get; private set; } = Array.Empty<FruitOnTreeItem>();
    internal bool IsAvailable => _auth.IsAuthenticated;

    internal async Task<bool> RefreshAsync(CancellationToken cancellationToken = default)
    {
        string? ownerId = _auth.CurrentUser?.Id;
        if (ownerId is null)
        {
            LoadLocalCatalog();
            return false;
        }
        await _gate.WaitAsync(cancellationToken);
        try
        {
            using var catalog = await SendAsync(HttpMethod.Get, "../fruit/catalog.php", null, cancellationToken);
            using var state = await SendAsync(HttpMethod.Get, "../fruit/state.php", null, cancellationToken);
            if (!catalog.IsSuccessStatusCode || !state.IsSuccessStatusCode)
            {
                LoadLocalCatalog();
                return false;
            }
            using JsonDocument catalogDoc = JsonDocument.Parse(await catalog.Content.ReadAsStringAsync(cancellationToken));
            IReadOnlyList<FruitCatalogItem> items = FruitJson.ReadCatalog(catalogDoc.RootElement);
            using var stateDoc = JsonDocument.Parse(await state.Content.ReadAsStringAsync(cancellationToken));
            IReadOnlyList<FruitInventoryItem> inventory = FruitJson.ReadInventory(stateDoc.RootElement);
            IReadOnlyList<FruitOnTreeItem> onTree = FruitJson.ReadOnTree(stateDoc.RootElement);
            var history = new Dictionary<string, IReadOnlyList<FruitPricePoint>>(StringComparer.Ordinal);
            if (stateDoc.RootElement.TryGetProperty("history", out var all))
                foreach (var p in all.EnumerateObject()) history[p.Name] = FruitJson.ReadHistory(p.Value);
            lock (_stateGate)
            {
                if (_auth.CurrentUser?.Id != ownerId) return false;
                Catalog = items;
                Inventory = inventory;
                OnTree = onTree;
                PriceHistory = history;
                SaveCache(ownerId);
            }
            Changed?.Invoke(); return true;
        }
        catch (Exception ex) when (ex is HttpRequestException or TaskCanceledException or JsonException or InvalidDataException or InvalidOperationException)
        {
            LoadLocalCatalog();
            return false;
        }
        finally { _gate.Release(); }
    }

    internal async Task<bool> SellAsync(string fruitId, int quantity, CancellationToken cancellationToken = default)
    {
        string? ownerId = _auth.CurrentUser?.Id;
        if (ownerId is null || string.IsNullOrWhiteSpace(fruitId) || quantity < 1) return false;
        var sale = (fruitId, quantity);
        string operationId;
        lock (_stateGate)
        {
            if (!_pendingSales.TryGetValue(sale, out string? pending))
                _pendingSales[sale] = pending = Guid.NewGuid().ToString("N");
            operationId = pending;
        }
        byte[] body = Encoding.UTF8.GetBytes(JsonSerializer.Serialize(
            new FruitSellRequest(fruitId, quantity, operationId),
            FruitRequestJsonContext.Default.FruitSellRequest));
        using var response = await SendAsync(HttpMethod.Post, "../fruit/sell.php", body, cancellationToken);
        if (!response.IsSuccessStatusCode)
        {
            string errorCode = "unknown";
            try
            {
                using JsonDocument error = JsonDocument.Parse(await response.Content.ReadAsStringAsync(cancellationToken));
                if (error.RootElement.ValueKind == JsonValueKind.Object &&
                    error.RootElement.TryGetProperty("code", out JsonElement code) &&
                    code.ValueKind == JsonValueKind.String)
                {
                    string? candidate = code.GetString();
                    if (candidate is { Length: > 0 and <= 64 } &&
                        candidate.All(ch => ch is >= 'a' and <= 'z' or '_'))
                        errorCode = candidate;
                }
            }
            catch (JsonException) { }
            App.Log($"[Fruit] Sell rejected status={(int)response.StatusCode} code={errorCode}");
            return false;
        }
        lock (_stateGate)
        {
            if (_auth.CurrentUser?.Id != ownerId) return false;
            _pendingSales.Remove(sale);
        }
        await RefreshAsync(cancellationToken); return true;
    }

    internal async Task<(bool Success, string FruitId, int Quantity)> PickAsync(long pendingId, CancellationToken cancellationToken = default)
    {
        string? ownerId = _auth.CurrentUser?.Id;
        if (ownerId is null || pendingId < 1) return (false, string.Empty, 0);
        if (_pendingPickId != pendingId || _pendingPickOperation is null)
        {
            _pendingPickId = pendingId;
            _pendingPickOperation = Guid.NewGuid().ToString("N");
        }
        byte[] body = Encoding.UTF8.GetBytes(JsonSerializer.Serialize(
            new FruitPickRequest(pendingId, _pendingPickOperation),
            FruitRequestJsonContext.Default.FruitPickRequest));
        using var response = await SendAsync(HttpMethod.Post, "../fruit/pick.php", body, cancellationToken);
        if (!response.IsSuccessStatusCode)
        {
            string errorCode = "unknown";
            try
            {
                using JsonDocument error = JsonDocument.Parse(await response.Content.ReadAsStringAsync(cancellationToken));
                if (error.RootElement.ValueKind == JsonValueKind.Object &&
                    error.RootElement.TryGetProperty("code", out JsonElement code) &&
                    code.ValueKind == JsonValueKind.String)
                {
                    string? candidate = code.GetString();
                    if (candidate is { Length: > 0 and <= 64 } &&
                        candidate.All(ch => ch is >= 'a' and <= 'z' or '_'))
                        errorCode = candidate;
                }
            }
            catch (JsonException) { }
            App.Log($"[Fruit] Pick rejected status={(int)response.StatusCode} code={errorCode}");
            return (false, string.Empty, 0);
        }
        using JsonDocument document = JsonDocument.Parse(await response.Content.ReadAsStringAsync(cancellationToken));
        var root = document.RootElement;
        string fruitId = root.TryGetProperty("fruit_id", out var id) ? id.GetString() ?? string.Empty : string.Empty;
        int quantity = root.TryGetProperty("quantity", out var count) && count.TryGetInt32(out var n) ? n : 0;
        if (fruitId.Length == 0 || quantity < 1) return (false, string.Empty, 0);
        if (_auth.CurrentUser?.Id != ownerId) return (false, string.Empty, 0);
        _pendingPickOperation = null;
        await RefreshAsync(cancellationToken);
        return (true, fruitId, quantity);
    }

    private Task<HttpResponseMessage> SendAsync(HttpMethod method, string path, byte[]? body, CancellationToken token) => _api.SendAsync(method, path, body, null, token);
    private void OnSessionChanged()
    {
        lock (_stateGate)
        {
            Inventory = Array.Empty<FruitInventoryItem>();
            PriceHistory = new Dictionary<string, IReadOnlyList<FruitPricePoint>>();
            OnTree = Array.Empty<FruitOnTreeItem>();
            _pendingPickOperation = null;
            _pendingSales.Clear();
        }
        LoadLocalCatalog();
        if (IsAvailable)
        {
            _ = RefreshAsync();
            return;
        }
        Changed?.Invoke();
    }
    private void LoadLocalCatalog()
    {
        try
        {
            string path = Path.Combine(AppContext.BaseDirectory, "Assets", "Tree", "fruits-manifest.json");
            using JsonDocument document = JsonDocument.Parse(File.ReadAllText(path));
            Catalog = FruitJson.ReadCatalog(document.RootElement);
            TryLoadCache();
            Changed?.Invoke();
        }
        catch (Exception ex) when (ex is IOException or JsonException)
        {
            App.Log($"[Fruit] Local catalogue unavailable: {ex.Message}");
        }
    }

    private void SaveCache(string ownerId)
    {
        try
        {
            string path = Path.Combine(DeskBoxDataPathService.Current.DataDirectory, "fruit",
                $"fruit-state-account-{AccountBackupFileCatalog.SafeAccountId(ownerId)}.json");
            var cache = new FruitCacheDto
            {
                OwnerUserId = ownerId,
                Inventory = Inventory.ToArray(),
                History = PriceHistory.ToDictionary(x => x.Key, x => x.Value.ToArray(), StringComparer.Ordinal),
                OnTree = OnTree.ToArray()
            };
            FruitCacheFileStore.Save(path, cache);
        }
        catch (IOException ex) { App.Log($"[Fruit] Cache save failed: {ex.Message}"); }
    }

    private void TryLoadCache()
    {
        try
        {
            string? path = CachePath;
            if (path is null || !File.Exists(path)) return;
            FruitCacheDto? cache = JsonSerializer.Deserialize(File.ReadAllText(path), AccountCloudJsonContext.Default.FruitCacheDto);
            if (cache is null || _auth.CurrentUser?.Id != cache.OwnerUserId) return;
            Inventory = cache.Inventory;
            PriceHistory = cache.History.ToDictionary(x => x.Key, x => (IReadOnlyList<FruitPricePoint>)x.Value, StringComparer.Ordinal);
            OnTree = cache.OnTree;
        }
        catch (Exception ex) when (ex is IOException || ex is JsonException) { }
    }

    public ValueTask DisposeAsync() { _auth.SessionChanged -= OnSessionChanged; _gate.Dispose(); return ValueTask.CompletedTask; }
}
