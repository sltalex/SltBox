using System.Text.Json;
using DeskBox.Contracts;
using DeskBox.Core.Persistence;

namespace DeskBox.Services;

/// <summary>Account-owned encounter, collection and restore state.</summary>
internal sealed class WildlifeService : IAsyncDisposable
{
    internal static readonly IReadOnlyList<WildlifeCatalogItem> LocalCatalog = WildlifeAssetCatalog.LocalCatalog;
    private readonly IAuthSession _auth;
    private readonly IAuthenticatedAccountApi _api;
    private readonly string _dataDirectory;
    private readonly SemaphoreSlim _gate = new(1, 1);
    private DateTimeOffset _lastOfferAttempt;
    private bool _resumeAfterHidden;
    private string? _pendingEncounter;
    private string? _pendingOperation;
    private string? _loadedOwner;
    private bool _catalogVerified;
    private bool _cacheNeedsMerge;

    internal WildlifeService(IAuthSession auth, IAuthenticatedAccountApi api, string? dataDirectory = null)
    {
        _auth = auth;
        _api = api;
        _dataDirectory = dataDirectory ?? DeskBoxDataPathService.Current.DataDirectory;
        _auth.SessionChanged += OnSessionChanged;
    }

    internal event Action? Changed;
    internal IReadOnlyList<WildlifeCatalogItem> Catalog { get; private set; } = LocalCatalog;
    internal IReadOnlyList<WildlifeInventoryItem> Inventory { get; private set; } = [];
    internal WildlifeEncounter? Encounter { get; private set; }
    internal bool IsAvailable => _auth.IsAuthenticated;
    internal bool CanOffer => IsAvailable && _catalogVerified && WildlifeAssetCatalog.HasCompleteAssets &&
        WeatherVisualStateService.Instance.Snapshot is not null;

    internal void SetVisible(bool visible)
    {
        if (visible) return;
        _resumeAfterHidden = true;
        Encounter = null;
        Changed?.Invoke();
    }

    internal async Task<bool> RefreshAsync(CancellationToken token = default)
    {
        string? owner = _auth.CurrentUser?.Id;
        if (owner is null) return false;
        await _gate.WaitAsync(token);
        try
        {
            LoadCache(owner);
            if (_cacheNeedsMerge)
            {
                if (!await MergeCacheAsync(owner, token)) return false;
                _cacheNeedsMerge = false;
            }
            using var catalog = await _api.SendAsync(HttpMethod.Get, "../wildlife/catalog.php", null, null, token);
            using var state = await _api.SendAsync(HttpMethod.Get, "../wildlife/state.php", null, null, token);
            if (!catalog.IsSuccessStatusCode || !state.IsSuccessStatusCode) return false;
            using JsonDocument catalogJson = JsonDocument.Parse(await catalog.Content.ReadAsStringAsync(token));
            using JsonDocument stateJson = JsonDocument.Parse(await state.Content.ReadAsStringAsync(token));
            if (_auth.CurrentUser?.Id != owner) return false;
            IReadOnlyList<WildlifeCatalogItem> serverCatalog = ReadCatalog(catalogJson.RootElement);
            _catalogVerified = serverCatalog.Count >= 16 &&
                serverCatalog.Select(item => item.Id).Distinct(StringComparer.Ordinal).Count() == serverCatalog.Count &&
                serverCatalog.All(item => WildlifeAssetCatalog.SpeciesIds.Contains(item.Id, StringComparer.Ordinal));
            // Keep the full local encyclopedia visible while a server catalogue
            // update is rolling out. Only the server can actually offer animals.
            var serverIds = serverCatalog.Select(item => item.Id).ToHashSet(StringComparer.Ordinal);
            Catalog = serverCatalog.Concat(LocalCatalog.Where(item => !serverIds.Contains(item.Id))).ToArray();
            ApplyState(owner, stateJson.RootElement);
            return true;
        }
        catch (Exception ex) when (ex is HttpRequestException or TaskCanceledException or JsonException or
            InvalidDataException or AuthApiException or FormatException or InvalidOperationException or KeyNotFoundException)
        {
            App.Log($"[Wildlife] Refresh failed: {ex.GetType().Name}");
            return false;
        }
        finally { _gate.Release(); }
    }

    internal async Task<bool> OfferIfDueAsync(CancellationToken token = default)
    {
        if (!CanOffer || DateTimeOffset.UtcNow - _lastOfferAttempt < TimeSpan.FromSeconds(30)) return false;
        string? owner = _auth.CurrentUser?.Id;
        WeatherVisualSnapshot? weather = WeatherVisualStateService.Instance.Snapshot;
        if (owner is null || weather is null) return false;
        if (!await _gate.WaitAsync(0, token)) return false;
        try
        {
            _lastOfferAttempt = DateTimeOffset.UtcNow;
            var request = new WildlifeOfferRequest(weather.Value.WeatherCode,
                weather.Value.FetchedAtUtc.ToUniversalTime().ToString("O"),
                (int)DateTimeOffset.Now.Offset.TotalMinutes, _resumeAfterHidden);
            byte[] body = JsonSerializer.SerializeToUtf8Bytes(request, WildlifeJsonContext.Default.WildlifeOfferRequest);
            using var response = await _api.SendAsync(HttpMethod.Post, "../wildlife/encounter.php", body, null, token);
            if (!response.IsSuccessStatusCode) return false;
            using JsonDocument json = JsonDocument.Parse(await response.Content.ReadAsStringAsync(token));
            if (_auth.CurrentUser?.Id != owner) return false;
            _resumeAfterHidden = false;
            ApplyState(owner, json.RootElement);
            return true;
        }
        catch (Exception ex) when (ex is HttpRequestException or TaskCanceledException or JsonException or
            InvalidDataException or AuthApiException or FormatException or InvalidOperationException or KeyNotFoundException)
        {
            App.Log($"[Wildlife] Encounter polling failed: {ex.GetType().Name}");
            return false;
        }
        finally { _gate.Release(); }
    }

    internal async Task<bool> CaptureAsync(string encounterId, CancellationToken token = default)
    {
        string? owner = _auth.CurrentUser?.Id;
        if (owner is null || Encounter?.Id != encounterId || !await _gate.WaitAsync(0, token)) return false;
        try
        {
            if (_pendingEncounter != encounterId || _pendingOperation is null)
            {
                _pendingEncounter = encounterId;
                _pendingOperation = Guid.NewGuid().ToString("N");
            }
            var request = new WildlifeCaptureRequest(encounterId, _pendingOperation);
            byte[] body = JsonSerializer.SerializeToUtf8Bytes(request, WildlifeJsonContext.Default.WildlifeCaptureRequest);
            using var response = await _api.SendAsync(HttpMethod.Post, "../wildlife/capture.php", body, null, token);
            if (!response.IsSuccessStatusCode)
            {
                if ((int)response.StatusCode == 409) { Encounter = null; Changed?.Invoke(); }
                return false;
            }
            if (_auth.CurrentUser?.Id != owner) return false;
            _pendingEncounter = _pendingOperation = null;
            Encounter = null;
            Changed?.Invoke();
        }
        catch (Exception ex) when (ex is HttpRequestException or TaskCanceledException or AuthApiException)
        {
            App.Log($"[Wildlife] Capture failed: {ex.GetType().Name}");
            return false;
        }
        finally { _gate.Release(); }
        return await RefreshAsync(token);
    }

    private async Task<bool> MergeCacheAsync(string owner, CancellationToken token)
    {
        if (Inventory.Count == 0) return true;
        var request = new WildlifeRestoreRequest(owner, Inventory.Select(item =>
            new WildlifeRestoreItem(item.SpeciesId, item.Quantity, item.Discovered)).ToArray());
        byte[] body = JsonSerializer.SerializeToUtf8Bytes(request, WildlifeJsonContext.Default.WildlifeRestoreRequest);
        using var response = await _api.SendAsync(HttpMethod.Post, "../wildlife/restore.php", body, null, token);
        if (!response.IsSuccessStatusCode) return false;
        return true;
    }

    private void ApplyState(string owner, JsonElement root)
    {
        Inventory = root.GetProperty("inventory").EnumerateArray().Select(item =>
            new WildlifeInventoryItem(item.GetProperty("species_id").GetString()!,
                item.GetProperty("quantity").GetInt64(), item.GetProperty("discovered").GetBoolean())).ToArray();
        Encounter = root.TryGetProperty("encounter", out JsonElement encounter) && encounter.ValueKind == JsonValueKind.Object
            ? new WildlifeEncounter(encounter.GetProperty("id").GetString()!,
                encounter.GetProperty("species_id").GetString()!,
                DateTimeOffset.Parse(encounter.GetProperty("expires_at_utc").GetString()!)) : null;
        SaveCache(owner);
        Changed?.Invoke();
    }

    private static IReadOnlyList<WildlifeCatalogItem> ReadCatalog(JsonElement root) =>
        root.GetProperty("items").EnumerateArray().Select(item => new WildlifeCatalogItem(
            item.GetProperty("id").GetString()!, item.GetProperty("name").GetString()!,
            item.GetProperty("category").GetString()!)).ToArray();

    private void LoadCache(string owner)
    {
        if (_loadedOwner == owner) return;
        _loadedOwner = owner;
        Inventory = [];
        _cacheNeedsMerge = false;
        string path = CachePath(owner);
        try
        {
            if (!File.Exists(path)) return;
            WildlifeCacheDto? cache = JsonSerializer.Deserialize(File.ReadAllText(path), WildlifeJsonContext.Default.WildlifeCacheDto);
            if (cache?.OwnerUserId != owner) return;
            Inventory = cache.Inventory;
            _cacheNeedsMerge = Inventory.Count > 0;
            Changed?.Invoke();
        }
        catch (Exception ex) when (ex is IOException or JsonException) { App.Log($"[Wildlife] Cache invalid: {ex.GetType().Name}"); }
    }

    private string CachePath(string owner) => Path.Combine(_dataDirectory,
        "wildlife", $"wildlife-state-account-{AccountBackupFileCatalog.SafeAccountId(owner)}.json");

    private void SaveCache(string owner)
    {
        try
        {
            string path = CachePath(owner);
            WildlifeCacheFileStore.Save(path, new WildlifeCacheDto
            {
                OwnerUserId = owner, Inventory = Inventory.ToArray()
            });
        }
        catch (IOException ex) { App.Log($"[Wildlife] Cache save failed: {ex.GetType().Name}"); }
    }

    private void OnSessionChanged()
    {
        Catalog = LocalCatalog;
        _catalogVerified = false;
        Inventory = [];
        Encounter = null;
        _loadedOwner = null;
        _cacheNeedsMerge = false;
        _pendingEncounter = _pendingOperation = null;
        _resumeAfterHidden = false;
        Changed?.Invoke();
        if (IsAvailable) _ = RefreshAsync();
    }

    public ValueTask DisposeAsync()
    {
        _auth.SessionChanged -= OnSessionChanged;
        _gate.Dispose();
        return ValueTask.CompletedTask;
    }
}
