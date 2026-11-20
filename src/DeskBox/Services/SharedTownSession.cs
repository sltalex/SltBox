using System.Collections.Concurrent;
using System.Net;
using System.Text;
using System.Text.Json;
using System.Text.Json.Nodes;
using DeskBox.Contracts;

namespace DeskBox.Services;

/// <summary>
/// Client side session for the shared town world.  The session deliberately owns
/// no game rules: the v2 town endpoints are authoritative for terrain, objects and
/// the shared inventory ledger.  Keeping this state separate from
/// <see cref="TownPrototypeSession"/> makes the old account-scoped prototype safe
/// to remove without touching tree progress or decoration state.
/// </summary>
internal sealed class SharedTownSession : IDisposable
{
    private const int MaxMessageBytes = 256 * 1024;
    private const int MaxChunkRequests = 16;
    private const int MaxIdempotentReadAttempts = 2;
    private static readonly TimeSpan IdempotentReadRetryDelay = TimeSpan.FromMilliseconds(250);
    private readonly IAuthSession _auth;
    private readonly IAuthenticatedAccountApi _api;
    private readonly TimeSpan _timeout;
    private readonly object _gate = new();
    private readonly Dictionary<string, Task<string?>> _sheetRequests = new();
    private readonly SemaphoreSlim _chunkLoader = new(1, 1);
    private readonly SemaphoreSlim _presenceLoader = new(1, 1);
    private Task<bool>? _catalogRequest;
    private long _catalogRequestEpoch;
    private long _catalogRequestRelease;
    private string? _catalogRequestHash;
    private long _viewportGeneration;
    private readonly ConcurrentDictionary<string, string> _chunks = new(StringComparer.Ordinal);
    private CancellationTokenSource _identityLifetime = new();
    private string? _identity;
    private long _epoch = 1;
    private long _worldVersion;
    private long _assetReleaseVersion;
    private string? _assetReleaseHash;
    private long _assetCatalogVersion = -1;
    private string? _assetCatalogHash;
    private long _cursor;
    private string _world = "{\"enabled\":false}";
    private string _inventory = "{}";
    private string _presence = "{\"players\":[]}";
    private string _npcs = "{\"npcs\":[],\"cursor\":0}";
    private long _npcCursor;
    private string _events = "{\"events\":[],\"cursor\":0,\"has_more\":false,\"unread_count\":0}";
    private long _eventCursor;
    private string _assetCatalog = "{\"release_version\":0,\"items\":[],\"sheets\":[]}";
    private string _lastChanges = "{\"cursor\":0,\"changes\":[]}";
    private bool _enabled;
    private bool _busy;
    private bool _disposed;
    private TownRealtimeConnection? _realtime;
    private string _status = "正在检查共享小镇开放状态…";

    internal SharedTownSession(IAuthSession auth, IAuthenticatedAccountApi api,
        TimeSpan? timeout = null)
    {
        _auth = auth;
        _api = api;
        _timeout = timeout ?? TimeSpan.FromSeconds(20);
        _identity = Identity();
        _auth.SessionChanged += OnSessionChanged;
    }

    internal event Action<bool>? Changed;
    internal event Action<long, bool>? RealtimeStateChanged;
    internal event Action<long, string>? RealtimeMessage;

    internal long Epoch { get { lock (_gate) return _epoch; } }
    internal bool IsDirty => false;
    internal bool IsAuthenticated => Identity() is not null;
    internal bool IsEnabled { get { lock (_gate) return _enabled; } }
    internal bool RealtimeConnected { get { lock (_gate) return _realtime?.Connected == true; } }
    internal bool AssetCatalogNeedsReload
    {
        get
        {
            lock (_gate)
            {
                return _assetCatalogVersion != _assetReleaseVersion ||
                    !string.Equals(_assetCatalogHash, _assetReleaseHash, StringComparison.OrdinalIgnoreCase);
            }
        }
    }
    internal long Cursor { get { lock (_gate) return _cursor; } }

    internal string BridgeJson()
    {
        lock (_gate)
        {
            return WriteJson(writer =>
            {
                writer.WriteStartObject();
                writer.WriteNumber("session_epoch", _epoch);
                writer.WriteBoolean("authenticated", _identity is not null);
                writer.WriteBoolean("enabled", _enabled);
                if (_identity is not null) writer.WriteString("account_id", _identity);
                writer.WriteString("account_label", _auth.CurrentUser?.Username ?? "未登录");
                writer.WriteString("status", _status);
                writer.WriteNumber("world_version", _worldVersion);
                writer.WriteNumber("cursor", _cursor);
                writer.WritePropertyName("world");
                writer.WriteRawValue(_world);
                writer.WritePropertyName("inventory");
                writer.WriteRawValue(_inventory);
                writer.WritePropertyName("presence");
                writer.WriteRawValue(_presence);
                writer.WritePropertyName("events");
                writer.WriteRawValue(_events);
                writer.WritePropertyName("asset_catalog");
                writer.WriteRawValue(_assetCatalog);
                writer.WriteEndObject();
            });
        }
    }

    internal string WorldJson()
    {
        lock (_gate)
        {
            return AddSessionMetadata(_world);
        }
    }

    internal string ChunksJson()
    {
        lock (_gate)
        {
            return WriteJson(writer =>
            {
                writer.WriteStartObject();
                writer.WriteNumber("session_epoch", _epoch);
                writer.WriteNumber("world_version", _worldVersion);
                writer.WritePropertyName("chunks");
                writer.WriteStartArray();
                foreach (string value in _chunks.Values)
                    writer.WriteRawValue(value);
                writer.WriteEndArray();
                writer.WriteEndObject();
            });
        }
    }

    internal string ChangesJson()
    {
        lock (_gate) return AddSessionMetadata(_lastChanges);
    }

    internal string PresenceJson()
    {
        lock (_gate) return AddSessionMetadata(_presence);
    }

    internal string NpcsJson() { lock (_gate) return AddSessionMetadata(_npcs); }
    internal string EventsJson() { lock (_gate) return AddSessionMetadata(_events); }

    internal async Task<bool> LoadEventsAsync(long epoch, bool reset = false)
    {
        if (!IsCurrent(epoch) || !IsEnabled) return false;
        long cursor;
        CancellationToken token;
        lock (_gate)
        {
            if (reset) _eventCursor = 0;
            cursor = _eventCursor;
            token = _identityLifetime.Token;
        }
        using HttpResponseMessage response = await SendAsync(
            HttpMethod.Get, $"../../v2/town/events.php?cursor={cursor}&limit=50", null, token);
        string text = await response.Content.ReadAsStringAsync(token);
        response.EnsureSuccessStatusCode();
        using JsonDocument document = ParseObject(text, "town events");
        long next = ReadLong(document.RootElement, "cursor");
        lock (_gate)
        {
            if (!IsCurrentLocked(epoch)) return false;
            _eventCursor = Math.Max(_eventCursor, next);
            _events = NormalizeEvents(text, _eventCursor);
        }
        return true;
    }

    internal async Task<string> AcknowledgeEventsAsync(string payload, long epoch)
    {
        EnsureMessageSize(payload);
        if (!IsCurrent(epoch) || !IsEnabled) return ErrorJson("town_not_open");
        byte[] body = Encoding.UTF8.GetBytes(payload);
        CancellationToken token;
        lock (_gate) token = _identityLifetime.Token;
        using HttpResponseMessage response = await SendAsync(
            HttpMethod.Post, "../../v2/town/events/receipt.php", body, token);
        string text = await response.Content.ReadAsStringAsync(token);
        response.EnsureSuccessStatusCode();
        return AddSessionMetadata(text);
    }

    internal async Task<string> LoadPerceptionAsync(string payload, long epoch)
    {
        EnsureMessageSize(payload);
        if (!IsCurrent(epoch) || !IsEnabled) return ErrorJson("town_not_open");
        using JsonDocument request = ParseObject(payload, "town perception request");
        int x = request.RootElement.TryGetProperty("tile_x", out JsonElement xElement) && xElement.TryGetInt32(out int parsedX) ? parsedX : -1;
        int y = request.RootElement.TryGetProperty("tile_y", out JsonElement yElement) && yElement.TryGetInt32(out int parsedY) ? parsedY : -1;
        int radius = request.RootElement.TryGetProperty("radius", out JsonElement radiusElement) && radiusElement.TryGetInt32(out int parsedRadius) ? Math.Clamp(parsedRadius, 1, 64) : 8;
        int height = request.RootElement.TryGetProperty("height", out JsonElement heightElement) && heightElement.TryGetInt32(out int parsedHeight) ? Math.Clamp(parsedHeight, 0, 32) : 0;
        if (x < 0 || y < 0) throw new InvalidDataException("Town perception coordinates are invalid.");
        string channels = request.RootElement.TryGetProperty("channels", out JsonElement channelsElement) && channelsElement.ValueKind == JsonValueKind.Array
            ? string.Join(',', channelsElement.EnumerateArray().Where(value => value.ValueKind == JsonValueKind.String).Select(value => Uri.EscapeDataString(value.GetString() ?? string.Empty))) : string.Empty;
        CancellationToken token;
        lock (_gate) token = _identityLifetime.Token;
        string query = $"tile_x={x}&tile_y={y}&radius={radius}&height={height}" + (channels.Length == 0 ? string.Empty : $"&channels={channels}");
        using HttpResponseMessage response = await SendAsync(HttpMethod.Get, $"../../v2/town/perception.php?{query}", null, token);
        string text = await response.Content.ReadAsStringAsync(token);
        response.EnsureSuccessStatusCode();
        return AddSessionMetadata(text);
    }

    internal Task<string?> LoadResidentDetailAsync(string npcId, long epoch) =>
        LoadResidentReadAsync(npcId, epoch, false, 0);

    internal Task<string?> LoadResidentConversationsAsync(string npcId, long cursor, long epoch) =>
        LoadResidentReadAsync(npcId, epoch, true, cursor);

    internal async Task<string> InviteResidentAsync(string npcId, string intent, long epoch, string? dialogue = null)
    {
        if (npcId.Length != 32 || npcId.Any(c => !(c is >= '0' and <= '9' or >= 'a' and <= 'f')) ||
            intent is not ("talk" or "follow" or "cancel"))
            throw new InvalidDataException("Invalid resident invitation.");
        CancellationToken token;
        lock (_gate)
        {
            if (!IsCurrentLocked(epoch) || !_enabled) return AddSessionMetadata(ErrorJson("town_not_open"));
            token = _identityLifetime.Token;
        }
        dialogue = intent == "talk" ? (dialogue ?? string.Empty).Trim() : string.Empty;
        if (dialogue.Length > 240) throw new InvalidDataException("Resident dialogue is too long.");
        byte[] body = Encoding.UTF8.GetBytes(new JsonObject
        {
            ["npc_id"] = npcId,
            ["intent"] = intent,
            ["dialogue"] = dialogue
        }.ToJsonString());
        // Player dialogue may wait for the configured LLM. Keep the normal
        // town request timeout for reads, but give an interactive turn enough
        // time to receive a model response before the client cancels it.
        using HttpResponseMessage response = await SendAsync(HttpMethod.Post, "../../v2/town/npcs/invite.php", body, token, TimeSpan.FromSeconds(75));
        string text = await response.Content.ReadAsStringAsync(token);
        using JsonDocument document = ParseObject(text, "resident invitation");
        if (!response.IsSuccessStatusCode)
        {
            string code = document.RootElement.TryGetProperty("code", out JsonElement codeElement) &&
                codeElement.ValueKind == JsonValueKind.String ? codeElement.GetString() ?? "resident_invitation_failed" : "resident_invitation_failed";
            return AddSessionMetadata(ErrorJson(code, text));
        }
        return AddSessionMetadata(text);
    }

    internal async Task<string> UploadResidentPhotoAsync(string npcId, string? conversationId, string fileName, string mediaType, byte[] bytes, long epoch)
    {
        if (npcId.Length != 32 || npcId.Any(c => !(c is >= '0' and <= '9' or >= 'a' and <= 'f')))
            throw new InvalidDataException("Invalid resident request.");
        if (bytes.Length < 1 || bytes.Length > 4 * 1024 * 1024)
            throw new InvalidDataException("Resident photo is too large.");
        if (mediaType is not ("image/png" or "image/jpeg" or "image/webp"))
            throw new InvalidDataException("Unsupported resident photo type.");
        CancellationToken token;
        lock (_gate)
        {
            if (!IsCurrentLocked(epoch) || !_enabled) return AddSessionMetadata(ErrorJson("town_not_open"));
            token = _identityLifetime.Token;
        }
        var fields = new Dictionary<string, string> { ["npc_id"] = npcId };
        if (!string.IsNullOrWhiteSpace(conversationId)) fields["conversation_id"] = conversationId.Trim();
        using HttpResponseMessage response = await _api.SendMultipartAsync(
            HttpMethod.Post, "../../v2/town/npcs/conversations/photo.php", fields,
            "image", string.IsNullOrWhiteSpace(fileName) ? "town-photo" : fileName, mediaType, bytes, token);
        string text = await response.Content.ReadAsStringAsync(token);
        using JsonDocument document = ParseObject(text, "resident photo conversation");
        if (!response.IsSuccessStatusCode)
        {
            string code = document.RootElement.TryGetProperty("code", out JsonElement codeElement) && codeElement.ValueKind == JsonValueKind.String
                ? codeElement.GetString() ?? "photo_conversation_failed" : "photo_conversation_failed";
            return AddSessionMetadata(ErrorJson(code, text));
        }
        return AddSessionMetadata(text);
    }

    private async Task<string?> LoadResidentReadAsync(string npcId, long epoch, bool history, long cursor)
    {
        if (npcId.Length != 32 || npcId.Any(c => !(c is >= '0' and <= '9' or >= 'a' and <= 'f')) || cursor < 0)
            throw new InvalidDataException("Invalid resident request.");
        CancellationToken token;
        lock (_gate)
        {
            if (!IsCurrentLocked(epoch) || !_enabled) return null;
            token = _identityLifetime.Token;
        }
        string path = history ? $"conversations.php?npc_id={npcId}&cursor={cursor}&limit=30" : $"detail.php?npc_id={npcId}";
        string text;
        try
        {
            using HttpResponseMessage response = await SendAsync(HttpMethod.Get, "../../v2/town/npcs/" + path, null, token);
            text = await response.Content.ReadAsStringAsync(token);
            using JsonDocument document = ParseObject(text, "town resident");
            if (!response.IsSuccessStatusCode) text = ErrorJson("resident_unavailable");
        }
        catch (Exception ex) when (ex is HttpRequestException or OperationCanceledException or JsonException or InvalidDataException or AuthApiException)
        {
            App.Log($"[SharedTown] Resident read failed: {ex.GetType().Name}");
            text = ErrorJson("resident_unavailable");
        }
        lock (_gate) return IsCurrentLocked(epoch) ? AddSessionMetadata(text) : null;
    }

    internal async Task<bool> LoadNpcSnapshotAsync(long epoch)
    {
        if (!IsCurrent(epoch) || !IsEnabled) return false;
        CancellationToken token; lock (_gate) token = _identityLifetime.Token;
        try
        {
            using HttpResponseMessage response = await SendAsync(HttpMethod.Get, "../../v2/town/npcs/snapshot.php", null, token);
            string text = await response.Content.ReadAsStringAsync(token); response.EnsureSuccessStatusCode();
            using JsonDocument document = ParseObject(text, "town NPC snapshot");
            lock (_gate) if (IsCurrentLocked(epoch)) { _npcs = text; _npcCursor = ReadLong(document.RootElement, "cursor"); }
            return true;
        }
        catch (Exception ex) when (ex is HttpRequestException or OperationCanceledException or JsonException or InvalidDataException or AuthApiException)
        { App.Log($"[SharedTown] NPC snapshot load failed: {ex.GetType().Name}"); return false; }
    }

    internal async Task<string> LoadNpcChangesAsync(long epoch)
    {
        if (!IsCurrent(epoch) || !IsEnabled) return NpcsJson();
        long cursor; CancellationToken token; lock (_gate) { cursor = _npcCursor; token = _identityLifetime.Token; }
        using HttpResponseMessage response = await SendAsync(HttpMethod.Get, $"../../v2/town/npcs/changes.php?cursor={cursor}", null, token);
        string text = await response.Content.ReadAsStringAsync(token); response.EnsureSuccessStatusCode();
        using JsonDocument document = ParseObject(text, "town NPC changes");
        lock (_gate) if (IsCurrentLocked(epoch)) { _npcCursor = Math.Max(_npcCursor, ReadLong(document.RootElement, "cursor")); _npcs = text; }
        return NpcsJson();
    }

    internal string InventoryJson()
    {
        lock (_gate) return AddSessionMetadata(_inventory);
    }

    internal string AssetCatalogJson()
    {
        lock (_gate) return AddSessionMetadata(_assetCatalog);
    }

    internal Task<bool> LoadAssetCatalogAsync(long epoch, string? cacheRoot = null)
    {
        lock (_gate)
        {
            if (!IsCurrentLocked(epoch) || !_enabled) return Task.FromResult(false);
            if (!AssetCatalogNeedsReloadLocked()) return Task.FromResult(true);
            if (_catalogRequest is { IsCompleted: false } && _catalogRequestEpoch == epoch &&
                _catalogRequestRelease == _assetReleaseVersion &&
                string.Equals(_catalogRequestHash, _assetReleaseHash, StringComparison.OrdinalIgnoreCase))
                return _catalogRequest;
            _catalogRequestEpoch = epoch;
            _catalogRequestRelease = _assetReleaseVersion;
            _catalogRequestHash = _assetReleaseHash;
            return _catalogRequest = LoadAssetCatalogCoreAsync(epoch, cacheRoot);
        }
    }

    private async Task<bool> LoadAssetCatalogCoreAsync(long epoch, string? cacheRoot)
    {
        if (!IsCurrent(epoch) || !IsEnabled) return false;
        CancellationToken token; long expectedRelease; string? expectedHash;
        lock (_gate)
        {
            token = _identityLifetime.Token;
            expectedRelease = _assetReleaseVersion;
            expectedHash = _assetReleaseHash;
        }
        try
        {
            if (!string.IsNullOrWhiteSpace(cacheRoot) && TownAssetCache.IsCacheMetadataUsable(expectedRelease, expectedHash))
            {
                string? cached = await TownAssetCache.ReadCatalogAsync(cacheRoot, expectedRelease, expectedHash, token);
                if (cached is not null)
                {
                    using JsonDocument cachedDocument = ParseObject(cached, "cached town asset catalog");
                    long cachedVersion = ReadLong(cachedDocument.RootElement, "release_version");
                    string? cachedHash = ReadString(cachedDocument.RootElement, "config_sha256");
                    lock (_gate)
                    {
                        if (IsCurrentLocked(epoch) && expectedRelease == _assetReleaseVersion &&
                            string.Equals(expectedHash, _assetReleaseHash, StringComparison.OrdinalIgnoreCase))
                        {
                            _assetCatalog = cached;
                            _assetCatalogVersion = cachedVersion;
                            _assetCatalogHash = cachedHash;
                        }
                    }
                    App.Log($"[SharedTown] Asset catalog cache hit: release={expectedRelease}");
                    return true;
                }
                App.Log($"[SharedTown] Asset catalog cache miss: release={expectedRelease}");
            }
            using HttpResponseMessage response = await SendAsync(HttpMethod.Get, "../../v2/town/assets/catalog.php", null, token);
            string text = await response.Content.ReadAsStringAsync(token); response.EnsureSuccessStatusCode();
            using JsonDocument document = ParseObject(text, "town asset catalog");
            long releaseVersion = document.RootElement.TryGetProperty("release_version", out JsonElement versionElement) &&
                versionElement.TryGetInt64(out long parsedVersion) ? parsedVersion : 0;
            string? configHash = ReadString(document.RootElement, "config_sha256");
            if (!string.IsNullOrWhiteSpace(cacheRoot) && configHash is not null)
            {
                try
                {
                    await TownAssetCache.StoreCatalogAsync(cacheRoot, text, releaseVersion, configHash, token);
                }
                catch (Exception ex) when (ex is IOException or UnauthorizedAccessException or InvalidDataException)
                {
                    // The remote catalog is authoritative. A read-only or full cache
                    // directory must not turn a successful network response into a
                    // failed town load; the next launch can simply fetch it again.
                    App.Log($"[SharedTown] Asset catalog cache write skipped: {ex.GetType().Name}");
                }
            }
            lock (_gate)
            {
                if (IsCurrentLocked(epoch) && expectedRelease == _assetReleaseVersion)
                {
                    _assetCatalog = text;
                    _assetCatalogVersion = releaseVersion;
                    _assetCatalogHash = configHash;
                }
            }
            return true;
        }
        catch (Exception ex) when (ex is HttpRequestException or OperationCanceledException or JsonException or InvalidDataException or AuthApiException)
        { App.Log($"[SharedTown] Asset catalog load failed: {ex.GetType().Name}"); return false; }
    }

    internal Task<string?> LoadAssetSheetAsync(string sheetId, long epoch, string cacheRoot)
    {
        lock (_gate)
        {
            if (!IsCurrentLocked(epoch) || !_enabled) return Task.FromResult<string?>(null);
            using JsonDocument catalog = JsonDocument.Parse(_assetCatalog);
            JsonElement sheet = catalog.RootElement.GetProperty("sheets").EnumerateArray()
                .FirstOrDefault(value => value.GetProperty("sheet_id").GetString() == sheetId);
            if (sheet.ValueKind != JsonValueKind.Object) return Task.FromResult<string?>(null);
            string hash = sheet.GetProperty("sha256").GetString() ?? "";
            if (hash.Length != 64 || hash.Any(c => !Uri.IsHexDigit(c))) return Task.FromResult<string?>(null);
            string key = $"{epoch}:{_assetCatalogVersion}:{sheetId}:{hash}";
            if (_sheetRequests.TryGetValue(key, out Task<string?>? existing) && !existing.IsCompleted) return existing;
            return _sheetRequests[key] = DownloadSheetAsync(sheetId, hash,
                sheet.GetProperty("width").GetInt32(), sheet.GetProperty("height").GetInt32(),
                epoch, _assetCatalogVersion, cacheRoot, _identityLifetime.Token);
        }
    }

    private bool AssetCatalogNeedsReloadLocked() =>
        _assetCatalogVersion != _assetReleaseVersion ||
        !string.Equals(_assetCatalogHash, _assetReleaseHash, StringComparison.OrdinalIgnoreCase);

    private async Task<string?> DownloadSheetAsync(string sheetId, string hash, int width, int height,
        long epoch, long release, string cacheRoot, CancellationToken identityToken)
    {
        using var timeout = CancellationTokenSource.CreateLinkedTokenSource(identityToken);
        timeout.CancelAfter(_timeout);
        CancellationToken token = timeout.Token;
        try
        {
            string path = Path.Combine(cacheRoot, hash.ToLowerInvariant() + ".png");
            bool valid = false;
            if (File.Exists(path))
            {
                try { TownAssetCache.Validate(await File.ReadAllBytesAsync(path, token), hash, width, height); valid = true; }
                catch (InvalidDataException) { }
            }
            if (!valid)
            {
                using HttpResponseMessage response = await SendAsync(HttpMethod.Get,
                    $"../../v2/town/assets/sheet.php?sheet_id={Uri.EscapeDataString(sheetId)}", null, token);
                response.EnsureSuccessStatusCode();
                byte[] bytes = await response.Content.ReadAsByteArrayAsync(token);
                path = await TownAssetCache.StoreAsync(cacheRoot, bytes, hash, width, height, token);
            }
            lock (_gate) if (!IsCurrentLocked(epoch) || release != _assetCatalogVersion) return null;
            App.Log($"[SharedTown] Sheet verified: {sheetId}, {width}x{height}, sha256={hash}");
            return path;
        }
        catch (Exception ex) when (ex is HttpRequestException or OperationCanceledException or AuthApiException or IOException)
        { App.Log($"[SharedTown] Asset sheet load failed: {ex.GetType().Name}"); return null; }
    }

    /// <summary>Loads the global world metadata.  A disabled/forbidden response is
    /// a normal grey-release state and is surfaced to the page without throwing.</summary>
    internal async Task<bool> LoadWorldAsync()
    {
        long epoch;
        CancellationToken token;
        lock (_gate)
        {
            if (_disposed || _busy) return false;
            epoch = _epoch;
            token = _identityLifetime.Token;
            _busy = true;
            _status = _identity is null ? "请登录后查看共享小镇" : "正在载入共享小镇…";
        }
        Changed?.Invoke(true);
        try
        {
            if (_identity is null)
            {
                lock (_gate) { _enabled = false; _world = "{\"enabled\":false,\"code\":\"sign_in_required\"}"; }
                return false;
            }
            using HttpResponseMessage response = await SendAsync(
                HttpMethod.Get, "../../v2/town/world.php", null, token);
            string text = await response.Content.ReadAsStringAsync(token);
            using JsonDocument body = ParseObject(text, "town world");
            bool enabled = response.IsSuccessStatusCode &&
                ((body.RootElement.TryGetProperty("enabled", out JsonElement enabledElement) &&
                    enabledElement.ValueKind == JsonValueKind.True) ||
                 body.RootElement.TryGetProperty("world", out JsonElement worldElement) &&
                    worldElement.ValueKind == JsonValueKind.Object);
            bool available;
            bool inventoryIncluded;
            lock (_gate)
            {
                if (!IsCurrentLocked(epoch)) return false;
                _enabled = enabled && response.IsSuccessStatusCode;
                _world = NormalizeWorld(body.RootElement, _enabled);
                inventoryIncluded = body.RootElement.TryGetProperty("inventory", out JsonElement inventory) &&
                    inventory.ValueKind == JsonValueKind.Object;
                if (inventoryIncluded) _inventory = body.RootElement.GetProperty("inventory").GetRawText();
                _worldVersion = ReadLongFromWorld(body.RootElement, "world_version");
                _assetReleaseVersion = ReadAssetReleaseVersion(body.RootElement);
                _assetReleaseHash = ReadAssetReleaseHash(body.RootElement);
                _status = !_enabled ? "共享小镇尚未对当前账号开放" : "共享小镇已载入";
                if (!_enabled && response.StatusCode == HttpStatusCode.Unauthorized)
                    _status = "请登录后查看共享小镇";
                available = _enabled;
            }
            if (available && !inventoryIncluded) await LoadInventoryAsync(epoch);
            return available;
        }
        catch (Exception ex) when (ex is HttpRequestException or OperationCanceledException or
            JsonException or InvalidDataException or AuthApiException)
        {
            lock (_gate)
            {
                if (!IsCurrentLocked(epoch)) return false;
                _enabled = false;
                _status = ex is OperationCanceledException ? "共享小镇载入超时" : "共享小镇暂时不可用";
                _world = "{\"enabled\":false,\"code\":\"unavailable\"}";
            }
            return false;
        }
        finally
        {
            lock (_gate) { if (IsCurrentLocked(epoch)) _busy = false; }
            if (Epoch == epoch) Changed?.Invoke(true);
        }
    }

    /// <summary>Fetches one or more visible chunks. The input is the page's
    /// viewportChanged message: {chunk_x,chunk_y} or {chunks:[{chunk_x,chunk_y}]}.</summary>
    internal async Task<bool> LoadChunksAsync(string viewportJson, long epoch)
    {
        if (!IsCurrent(epoch) || !IsEnabled) return false;
        List<(int X, int Y)> requested = ParseChunkCoordinates(viewportJson);
        if (requested.Count == 0) return true;
        long generation = Interlocked.Increment(ref _viewportGeneration);
        await _chunkLoader.WaitAsync();
        if (!IsCurrent(epoch) || generation != _viewportGeneration) { _chunkLoader.Release(); return false; }
        try
        {
            foreach (var batch in requested.Chunk(MaxChunkRequests))
                await LoadChunkBatchAsync(batch, epoch);
            HashSet<string> requestedKeys = requested
                .Select(pair => $"{pair.X}:{pair.Y}")
                .ToHashSet(StringComparer.Ordinal);
            lock (_gate)
            {
                if (IsCurrentLocked(epoch))
                {
                    foreach (string key in _chunks.Keys)
                        if (!requestedKeys.Contains(key)) _chunks.TryRemove(key, out _);
                }
            }
            return IsCurrent(epoch) && IsEnabled;
        }
        catch (Exception ex) when (ex is HttpRequestException or OperationCanceledException or
            JsonException or InvalidDataException or AuthApiException)
        {
            App.Log($"[SharedTown] Chunk load failed: {ex.GetType().Name}: {ex.Message}");
            lock (_gate) { if (IsCurrentLocked(epoch)) _status = "部分地图载入失败，可继续漫游重试"; }
            return false;
        }
        finally { _chunkLoader.Release(); }
    }

    internal async Task<string> LoadChangesAsync(long epoch)
    {
        if (!IsCurrent(epoch) || !IsEnabled) return ChangesJson();
        long cursor;
        CancellationToken token;
        string[] loadedChunks;
        lock (_gate)
        {
            cursor = _cursor;
            token = _identityLifetime.Token;
            loadedChunks = _chunks.Keys.ToArray();
        }
        // Scope the delta feed to chunks currently rendered by the viewport.
        // Otherwise a busy shared world would accumulate remote objects from
        // every chunk even though the client only displays nearby chunks.
        if (loadedChunks.Length == 0) return ChangesJson();
        string chunkQuery = Uri.EscapeDataString(string.Join(',', loadedChunks));
        using HttpResponseMessage response = await SendAsync(
            HttpMethod.Get, $"../../v2/town/changes.php?cursor={cursor}&chunks={chunkQuery}", null, token);
        string text = await response.Content.ReadAsStringAsync(token);
        response.EnsureSuccessStatusCode();
        using JsonDocument document = ParseObject(text, "town changes");
        long next = ReadLong(document.RootElement, "cursor");
        if (next == 0) next = ReadLong(document.RootElement, "next_cursor");
        lock (_gate)
        {
            if (IsCurrentLocked(epoch))
            {
                _cursor = Math.Max(_cursor, next);
                _lastChanges = NormalizeChanges(text, _cursor);
            }
        }
        return ChangesJson();
    }

    private async Task LoadInventoryAsync(long epoch)
    {
        CancellationToken token;
        lock (_gate) token = _identityLifetime.Token;
        try
        {
            using HttpResponseMessage response = await SendAsync(
                HttpMethod.Get, "../tree/decorations/state.php", null, token);
            if (!response.IsSuccessStatusCode) return;
            string text = await response.Content.ReadAsStringAsync(token);
            using JsonDocument document = ParseObject(text, "tree decoration inventory");
            if (!document.RootElement.TryGetProperty("inventory", out JsonElement items) ||
                items.ValueKind != JsonValueKind.Array) return;
            string inventory = WriteJson(writer =>
            {
                writer.WriteStartObject();
                writer.WritePropertyName("items");
                items.WriteTo(writer);
                writer.WriteEndObject();
            });
            lock (_gate) { if (IsCurrentLocked(epoch)) _inventory = inventory; }
        }
        catch (Exception ex) when (ex is HttpRequestException or OperationCanceledException or
            JsonException or InvalidDataException or AuthApiException)
        {
            App.Log($"[SharedTown] Inventory load failed: {ex.GetType().Name}");
        }
    }

    internal async Task<string> ExecuteActionAsync(string payload, long epoch)
    {
        EnsureMessageSize(payload);
        if (!IsCurrent(epoch) || !IsEnabled) return ErrorJson("town_not_open");
        byte[] body = EnsureOperationId(payload);
        CancellationToken token;
        lock (_gate) token = _identityLifetime.Token;
        using HttpResponseMessage response = await SendAsync(
            HttpMethod.Post, "../../v2/town/actions.php", body, token);
        string text = await response.Content.ReadAsStringAsync(token);
        using JsonDocument document = ParseObject(text, "town action");
        if (!response.IsSuccessStatusCode)
        {
            string code = document.RootElement.TryGetProperty("code", out JsonElement codeElement) &&
                codeElement.ValueKind == JsonValueKind.String ? codeElement.GetString() ?? "town_action_failed" : "town_action_failed";
            return AddSessionMetadata(ErrorJson(code, text));
        }
        lock (_gate)
        {
            if (!IsCurrentLocked(epoch)) return ErrorJson("session_changed");
            _worldVersion = Math.Max(_worldVersion, ReadLongFromWorld(document.RootElement, "world_version"));
            // Keep the last applied cursor here.  The action response's cursor
            // may include changes made by other players before this operation;
            // advancing it before LoadChangesAsync would skip those changes
            // permanently.  TreeTownPrototypeWindow fetches from the old
            // cursor immediately after applying this action, then advances it
            // from the complete change response.
            if (document.RootElement.TryGetProperty("inventory", out JsonElement inventory) && inventory.ValueKind == JsonValueKind.Object)
                _inventory = inventory.GetRawText();
            _status = "已同步共享小镇变更";
        }
        return AddSessionMetadata(text);
    }

    internal async Task<string> SendPresenceHeartbeatAsync(string payload, long epoch)
    {
        EnsureMessageSize(payload);
        if (!IsCurrent(epoch) || !IsEnabled) return PresenceJson();
        byte[] body = EnsureOperationId(payload, false);
        CancellationToken token;
        lock (_gate) token = _identityLifetime.Token;
        await _presenceLoader.WaitAsync(token);
        try
        {
            if (!IsCurrent(epoch) || !IsEnabled) return PresenceJson();
            using HttpResponseMessage response = await SendAsync(
                HttpMethod.Post, "../../v2/town/presence.php", body, token);
            string text = await response.Content.ReadAsStringAsync(token);
            response.EnsureSuccessStatusCode();
            ParseObject(text, "town presence").Dispose();
            lock (_gate) { if (IsCurrentLocked(epoch)) _presence = NormalizePresence(text); }
            return PresenceJson();
        }
        finally { _presenceLoader.Release(); }
    }

    internal async Task<string> LoadPresenceAsync(string? query, long epoch)
    {
        if (!IsCurrent(epoch) || !IsEnabled) return PresenceJson();
        CancellationToken token;
        lock (_gate) token = _identityLifetime.Token;
        string suffix = string.IsNullOrWhiteSpace(query) ? string.Empty :
            (query.StartsWith('?') ? query : "?" + query);
        using HttpResponseMessage response = await SendAsync(
            HttpMethod.Get, "../../v2/town/presence.php" + suffix, null, token);
        string text = await response.Content.ReadAsStringAsync(token);
        response.EnsureSuccessStatusCode();
        ParseObject(text, "town presence").Dispose();
        lock (_gate) { if (IsCurrentLocked(epoch)) _presence = NormalizePresence(text); }
        return PresenceJson();
    }

    internal Task<bool> ConnectRealtimeAsync()
    {
        lock (_gate)
        {
            if (_disposed || !_enabled || _auth is not DeskBoxAuthSession auth)
                return Task.FromResult(false);
            if (_realtime is not null) return _realtime.Start();
            string endpoint = Environment.GetEnvironmentVariable("DESKBOX_TOWN_REALTIME_URL")
                ?? "wss://sltbox.2leaves.cn/ws/town";
            if (!Uri.TryCreate(endpoint, UriKind.Absolute, out Uri? uri) || uri.Scheme is not ("ws" or "wss"))
                return Task.FromResult(false);
            long epoch = _epoch;
            var realtime = new TownRealtimeConnection(uri, async token =>
            {
                try { return await auth.GetRealtimeAccessTokenAsync(token); }
                catch (AuthApiException) { return null; }
            }, _identityLifetime.Token);
            realtime.StateChanged += connected =>
            {
                lock (_gate) if (!IsCurrentLocked(epoch) || !ReferenceEquals(_realtime, realtime)) return;
                RealtimeStateChanged?.Invoke(epoch, connected);
            };
            realtime.Message += json =>
            {
                lock (_gate) if (!IsCurrentLocked(epoch) || !ReferenceEquals(_realtime, realtime)) return;
                RealtimeMessage?.Invoke(epoch, json);
            };
            _realtime = realtime;
            return realtime.Start();
        }
    }

    internal Task<bool> SendPresenceRealtimeAsync(string payload, long epoch, bool durable = false)
    {
        EnsureMessageSize(payload);
        lock (_gate)
        {
            if (!IsCurrentLocked(epoch) || _realtime?.Connected != true) return Task.FromResult(false);
            return durable ? _realtime.SendDurablePresenceAsync(payload, _identityLifetime.Token)
                : _realtime.SendAsync("{\"type\":\"presence\",\"payload\":" + payload + "}", _identityLifetime.Token);
        }
    }

    internal Task DisconnectRealtimeAsync()
    {
        TownRealtimeConnection? realtime;
        lock (_gate) { realtime = _realtime; _realtime = null; }
        realtime?.Dispose();
        return Task.CompletedTask;
    }

    // Compatibility surface used by the old optional prototype probe. Shared
    // actions are immediate, therefore there is no account-scoped draft to save.
    internal bool UpdateLayout(string _, long epoch) => IsCurrent(epoch);
    internal Task LoadAsync(bool discardDraft = false) => LoadWorldAsync();
    internal Task<bool> SaveAsync(long _) => Task.FromResult(false);

    private async Task LoadChunkBatchAsync((int X, int Y)[] batch, long epoch)
    {
        CancellationToken token;
        lock (_gate) token = _identityLifetime.Token;
        int x = batch[0].X, y = batch[0].Y;
        string query = Uri.EscapeDataString(string.Join(',', batch.Select(p => $"{p.X}:{p.Y}")));
        using HttpResponseMessage response = await SendAsync(
            HttpMethod.Get, $"../../v2/town/chunks.php?chunks={query}", null, token);
        string text = await response.Content.ReadAsStringAsync(token);
        if (response.StatusCode == HttpStatusCode.Unauthorized)
        {
            lock (_gate)
            {
                if (IsCurrentLocked(epoch))
                {
                    _enabled = false;
                    _status = "登录已过期，请重新登录后查看共享小镇";
                    _world = "{\"enabled\":false,\"code\":\"invalid_session\"}";
                    _chunks.Clear();
                }
            }
            return;
        }
        response.EnsureSuccessStatusCode();
        using JsonDocument document = ParseObject(text, "town chunk");
        long version = ReadLongFromWorld(document.RootElement, "world_version");
        lock (_gate)
        {
            if (!IsCurrentLocked(epoch)) return;
            if (document.RootElement.TryGetProperty("chunk", out JsonElement chunk) &&
                chunk.ValueKind == JsonValueKind.Object)
            {
                _chunks[$"{x}:{y}"] = chunk.GetRawText();
            }
            else if (document.RootElement.TryGetProperty("chunks", out JsonElement chunks) &&
                chunks.ValueKind == JsonValueKind.Array)
            {
                foreach (JsonElement value in chunks.EnumerateArray())
                {
                    if (value.ValueKind != JsonValueKind.Object) continue;
                    int cx = value.TryGetProperty("chunk_x", out JsonElement xElement) && xElement.TryGetInt32(out int parsedX) ? parsedX : x;
                    int cy = value.TryGetProperty("chunk_y", out JsonElement yElement) && yElement.TryGetInt32(out int parsedY) ? parsedY : y;
                    _chunks[$"{cx}:{cy}"] = value.GetRawText();
                }
            }
            else _chunks[$"{x}:{y}"] = text;
            _worldVersion = Math.Max(_worldVersion, version);
        }
    }

    private void OnSessionChanged()
    {
        lock (_gate)
        {
            string? identity = Identity();
            if (_disposed || identity == _identity) return;
            _ = DisconnectRealtimeAsync();
            _identityLifetime.Cancel();
            _identityLifetime.Dispose();
            _identityLifetime = new CancellationTokenSource();
            _identity = identity;
            _epoch++;
            _busy = false;
            _worldVersion = 0;
            _cursor = 0;
            _enabled = false;
            _world = "{\"enabled\":false}";
            _inventory = "{}";
            _presence = "{\"players\":[]}";
            _npcs = "{\"npcs\":[],\"cursor\":0}"; _npcCursor = 0;
            _events = "{\"events\":[],\"cursor\":0,\"has_more\":false,\"unread_count\":0}"; _eventCursor = 0;
            _assetCatalog = "{\"release_version\":0,\"items\":[],\"sheets\":[]}";
            _assetReleaseVersion = 0;
            _assetReleaseHash = null;
            _assetCatalogVersion = -1;
            _assetCatalogHash = null;
            _lastChanges = "{\"cursor\":0,\"changes\":[]}";
            _chunks.Clear();
            _sheetRequests.Clear();
            _status = identity is null ? "请登录后查看共享小镇" : "正在检查共享小镇开放状态…";
        }
        Changed?.Invoke(true);
        _ = LoadWorldAsync();
    }

    private bool IsCurrent(long epoch)
    {
        lock (_gate) return IsCurrentLocked(epoch);
    }

    private bool IsCurrentLocked(long epoch) => !_disposed && epoch == _epoch && _identity == Identity();
    private string? Identity() => _auth.IsAuthenticated ? _auth.CurrentUser?.Id : null;

    private async Task<HttpResponseMessage> SendAsync(HttpMethod method, string path, byte[]? body,
        CancellationToken identityToken, TimeSpan? requestTimeout = null)
    {
        int attempts = method == HttpMethod.Get ? MaxIdempotentReadAttempts : 1;
        TimeSpan effectiveTimeout = requestTimeout ?? _timeout;
        for (int attempt = 1; attempt <= attempts; attempt++)
        {
            using var timeout = CancellationTokenSource.CreateLinkedTokenSource(identityToken);
            timeout.CancelAfter(effectiveTimeout);
            Task<HttpResponseMessage>? request = null;
            try
            {
                request = _api.SendAsync(method, path, body, null, timeout.Token);
                return await request.WaitAsync(timeout.Token);
            }
            catch (Exception ex) when (attempt < attempts && !identityToken.IsCancellationRequested &&
                ex is HttpRequestException or TaskCanceledException)
            {
                if (request is not null) _ = request.ContinueWith(completed =>
                {
                    if (completed.Status == TaskStatus.RanToCompletion) completed.Result.Dispose();
                    else _ = completed.Exception;
                }, CancellationToken.None, TaskContinuationOptions.ExecuteSynchronously, TaskScheduler.Default);
                await Task.Delay(IdempotentReadRetryDelay, identityToken);
            }
            catch
            {
                if (request is not null) _ = request.ContinueWith(completed =>
                {
                    if (completed.Status == TaskStatus.RanToCompletion) completed.Result.Dispose();
                    else _ = completed.Exception;
                }, CancellationToken.None, TaskContinuationOptions.ExecuteSynchronously, TaskScheduler.Default);
                throw;
            }
        }

        throw new InvalidOperationException("The authenticated request did not complete.");
    }

    private static List<(int X, int Y)> ParseChunkCoordinates(string json)
    {
        EnsureMessageSize(json);
        using JsonDocument document = JsonDocument.Parse(json);
        JsonElement root = document.RootElement;
        var result = new List<(int, int)>();
        if (root.TryGetProperty("chunks", out JsonElement chunks) && chunks.ValueKind == JsonValueKind.Array)
        {
            foreach (JsonElement value in chunks.EnumerateArray())
                AddChunk(value, result);
        }
        else AddChunk(root, result);
        return result.Distinct().ToList();
    }

    private static void AddChunk(JsonElement value, List<(int X, int Y)> output)
    {
        if (value.ValueKind != JsonValueKind.Object ||
            !value.TryGetProperty("chunk_x", out JsonElement x) || !x.TryGetInt32(out int ix) ||
            !value.TryGetProperty("chunk_y", out JsonElement y) || !y.TryGetInt32(out int iy)) return;
        if (ix is >= 0 and < 64 && iy is >= 0 and < 64) output.Add((ix, iy));
    }

    private static byte[] EnsureOperationId(string json, bool required = true)
    {
        JsonNode? node = JsonNode.Parse(json);
        if (node is not JsonObject obj) throw new InvalidDataException("Town request must be an object.");
        if (required && (obj["operation_id"] is null || string.IsNullOrWhiteSpace(obj["operation_id"]?.GetValue<string>())))
            obj["operation_id"] = Guid.NewGuid().ToString("N");
        return Encoding.UTF8.GetBytes(obj.ToJsonString(new JsonSerializerOptions { WriteIndented = false }));
    }

    private static JsonDocument ParseObject(string json, string description)
    {
        EnsureMessageSize(json);
        JsonDocument document = JsonDocument.Parse(json);
        if (document.RootElement.ValueKind != JsonValueKind.Object)
        {
            document.Dispose();
            throw new InvalidDataException($"Invalid {description} response.");
        }
        return document;
    }

    private static void EnsureMessageSize(string value)
    {
        if (Encoding.UTF8.GetByteCount(value) > MaxMessageBytes)
            throw new InvalidDataException("Town message is too large.");
    }

    private static long ReadLong(JsonElement root, string property)
    {
        return root.TryGetProperty(property, out JsonElement value) ? ReadLong(value) : 0;
    }

    private static long ReadLong(JsonElement value) => value.TryGetInt64(out long number) && number >= 0 ? number : 0;

    private static long ReadLongFromWorld(JsonElement root, string property)
    {
        long value = ReadLong(root, property);
        if (value != 0) return value;
        return root.TryGetProperty("world", out JsonElement world) && world.ValueKind == JsonValueKind.Object
            ? ReadLong(world, property) : 0;
    }

    private static long ReadAssetReleaseVersion(JsonElement root)
    {
        JsonElement value = root;
        if (root.TryGetProperty("asset_release", out JsonElement direct) && direct.ValueKind == JsonValueKind.Object)
            value = direct;
        else if (root.TryGetProperty("world", out JsonElement world) &&
            world.ValueKind == JsonValueKind.Object &&
            world.TryGetProperty("asset_release", out JsonElement nested) &&
            nested.ValueKind == JsonValueKind.Object)
            value = nested;
        return ReadLong(value, "release_version");
    }

    private static string? ReadAssetReleaseHash(JsonElement root)
    {
        JsonElement value = root;
        if (root.TryGetProperty("asset_release", out JsonElement direct) && direct.ValueKind == JsonValueKind.Object)
            value = direct;
        else if (root.TryGetProperty("world", out JsonElement world) &&
            world.ValueKind == JsonValueKind.Object &&
            world.TryGetProperty("asset_release", out JsonElement nested) &&
            nested.ValueKind == JsonValueKind.Object)
            value = nested;
        string? hash = ReadString(value, "config_sha256");
        return hash?.Length == 64 && hash.All(Uri.IsHexDigit) ? hash : null;
    }

    private static string? ReadString(JsonElement root, string property) =>
        root.TryGetProperty(property, out JsonElement value) && value.ValueKind == JsonValueKind.String
            ? value.GetString() : null;

    private static string NormalizeWorld(JsonElement root, bool enabled)
    {
        using var stream = new MemoryStream();
        using (var writer = new Utf8JsonWriter(stream))
        {
            writer.WriteStartObject();
            writer.WriteBoolean("enabled", enabled);
            if (root.TryGetProperty("world", out JsonElement world) && world.ValueKind == JsonValueKind.Object)
            {
                foreach (JsonProperty property in world.EnumerateObject()) property.WriteTo(writer);
            }
            foreach (JsonProperty property in root.EnumerateObject())
            {
                if (property.NameEquals("world") || property.NameEquals("enabled")) continue;
                property.WriteTo(writer);
            }
            writer.WriteEndObject();
        }
        return Encoding.UTF8.GetString(stream.ToArray());
    }

    private static string NormalizePresence(string json)
    {
        using JsonDocument document = JsonDocument.Parse(json);
        if (document.RootElement.ValueKind != JsonValueKind.Object) return "{\"players\":[]}";
        if (document.RootElement.TryGetProperty("players", out _)) return json;
        return WriteJson(writer =>
        {
            writer.WriteStartObject();
            foreach (JsonProperty property in document.RootElement.EnumerateObject()) property.WriteTo(writer);
            if (document.RootElement.TryGetProperty("presence", out JsonElement presence))
            {
                writer.WritePropertyName("players");
                presence.WriteTo(writer);
            }
            else
            {
                writer.WritePropertyName("players");
                writer.WriteStartArray();
                writer.WriteEndArray();
            }
            writer.WriteEndObject();
        });
    }

    private static string NormalizeChanges(string json, long cursor)
    {
        using JsonDocument document = JsonDocument.Parse(json);
        return WriteJson(writer =>
        {
            writer.WriteStartObject();
            writer.WriteNumber("cursor", cursor);
            foreach (JsonProperty property in document.RootElement.EnumerateObject())
            {
                if (property.NameEquals("cursor") || property.NameEquals("next_cursor")) continue;
                property.WriteTo(writer);
            }
            if (!document.RootElement.TryGetProperty("changes", out _))
            {
                writer.WritePropertyName("changes");
                writer.WriteStartArray();
                writer.WriteEndArray();
            }
            writer.WriteEndObject();
        });
    }

    private static string NormalizeEvents(string json, long cursor)
    {
        using JsonDocument document = JsonDocument.Parse(json);
        return WriteJson(writer =>
        {
            writer.WriteStartObject();
            writer.WriteNumber("cursor", cursor);
            foreach (JsonProperty property in document.RootElement.EnumerateObject())
            {
                if (property.NameEquals("cursor") || property.NameEquals("session_epoch")) continue;
                property.WriteTo(writer);
            }
            if (!document.RootElement.TryGetProperty("events", out _))
            {
                writer.WritePropertyName("events");
                writer.WriteStartArray();
                writer.WriteEndArray();
            }
            writer.WriteEndObject();
        });
    }

    private static string ErrorJson(string code, string? response = null)
    {
        if (response is not null) return response;
        return WriteJson(writer => { writer.WriteStartObject(); writer.WriteString("code", code); writer.WriteEndObject(); });
    }

    private string AddSessionMetadata(string json)
    {
        using JsonDocument document = JsonDocument.Parse(json);
        return WriteJson(writer =>
        {
            writer.WriteStartObject();
            writer.WriteNumber("session_epoch", Epoch);
            if (_identity is not null) writer.WriteString("account_id", _identity);
            foreach (JsonProperty property in document.RootElement.EnumerateObject())
            {
                if (property.NameEquals("session_epoch")) continue;
                property.WriteTo(writer);
            }
            writer.WriteEndObject();
        });
    }

    private static string WriteJson(Action<Utf8JsonWriter> write)
    {
        using var stream = new MemoryStream();
        using (var writer = new Utf8JsonWriter(stream)) write(writer);
        return Encoding.UTF8.GetString(stream.ToArray());
    }

    public void Dispose()
    {
        _ = DisconnectRealtimeAsync();
        lock (_gate)
        {
            if (_disposed) return;
            _disposed = true;
            _identityLifetime.Cancel();
            _identityLifetime.Dispose();
            _chunks.Clear();
        }
        _auth.SessionChanged -= OnSessionChanged;
    }
}
