using System.Net;
using System.Text;
using System.Text.Json;
using DeskBox.Contracts;
using DeskBox.Core.Persistence;

namespace DeskBox.Services;

/// <summary>Account-scoped aquarium cache and an idempotent, server-authoritative command queue.</summary>
internal sealed class AquariumService : IAsyncDisposable
{
    internal const string AquariumCardPackItemId = "aquarium-card-pack";

    private readonly IAuthSession _auth;
    private readonly IAuthenticatedAccountApi _api;
    private readonly string _cacheDirectory;
    private readonly TimeSpan _pollInterval;
    private readonly SemaphoreSlim _gate = new(1, 1);
    private readonly CancellationTokenSource _lifetime = new();
    private readonly object _identityGate = new();
    private string? _identity;
    private long _identityVersion;
    private string? _loadedIdentity;
    private byte[]? _pending;
    private PeriodicTimer? _timer;
    private Task? _timerTask;
    private int _started;
    private int _disposed;

    internal AquariumService(IAuthSession auth, IAuthenticatedAccountApi api,
        string? cacheDirectory = null, TimeSpan? pollInterval = null)
    {
        _auth = auth;
        _api = api;
        _cacheDirectory = cacheDirectory ?? Path.Combine(DeskBoxDataPathService.Current.DataDirectory, "cache", "aquarium");
        _pollInterval = pollInterval ?? TimeSpan.FromMinutes(5);
        if (_pollInterval <= TimeSpan.Zero) throw new ArgumentOutOfRangeException(nameof(pollInterval));
        _identity = VerifiedIdentity();
        _auth.SessionChanged += OnSessionChanged;
    }

    internal event Action? Changed;
    /// <summary>Raised after a card pack is opened and the authoritative state is accepted.</summary>
    internal event Action<AquariumOpenedItem>? Opened;
    internal AquariumState? State { get; private set; }
    internal AquariumOpenedItem? LastOpenedItem { get; private set; }
    internal IReadOnlyList<AquariumCatalogItem> Catalog { get; private set; } = Array.Empty<AquariumCatalogItem>();
    internal int CatalogVersion { get; private set; }
    internal string? CurrentUserId => VerifiedIdentity();
    internal bool IsAvailable => Volatile.Read(ref _disposed) == 0 && CurrentUserId is not null;
    internal bool IsOffline { get; private set; }
    internal string? ErrorCode { get; private set; }
    internal bool HasPendingOperation => _pending is not null;
    internal IReadOnlyList<AquariumPlacement>? PendingScene { get; private set; }

    /// <summary>Discards only the locally retained scene conflict draft after the user chooses the cloud layout.</summary>
    internal Task<bool> ClearConflictDraftAsync(CancellationToken cancellationToken = default) =>
        RunAsync(async (identity, version, token) =>
        {
            Update(identity, version, token, () => PendingScene = null);
            await SaveCacheAsync(identity, version, token);
            return true;
        }, cancellationToken);

    internal void Start()
    {
        ObjectDisposedException.ThrowIf(Volatile.Read(ref _disposed) != 0, this);
        if (Interlocked.Exchange(ref _started, 1) != 0) return;
        _timer = new PeriodicTimer(_pollInterval);
        _timerTask = RunTimerAsync(_lifetime.Token);
        _ = RefreshSafelyAsync();
    }

    internal Task<bool> RefreshAsync(CancellationToken cancellationToken = default) =>
        RunAsync(async (identity, version, token) =>
        {
            bool replayed = _pending is null || await SubmitPendingAsync(identity, version, token);
            string? replayError = ErrorCode;
            await ReadCloudAsync(identity, version, token);
            if (!replayed) Update(identity, version, token, () => ErrorCode = replayError);
            await SaveCacheAsync(identity, version, token);
            return replayed;
        }, cancellationToken);

    internal Task<bool> InitializeAsync(CancellationToken cancellationToken = default) =>
        MutateAsync("initialize", null, null, null, 0, cancellationToken);

    internal Task<bool> BuyAsync(string itemId, CancellationToken cancellationToken = default)
    {
        ArgumentException.ThrowIfNullOrWhiteSpace(itemId);
        return MutateAsync("buy", itemId, null, null, 0, cancellationToken);
    }

    /// <summary>
    /// Opens one aquarium card pack from the account inventory. The server
    /// chooses the reward atomically and returns the complete authoritative
    /// state plus an optional <c>opened_item</c>/<c>opened_item_id</c> result.
    /// </summary>
    internal Task<bool> OpenPackAsync(string packInstanceId,
        CancellationToken cancellationToken = default)
    {
        ArgumentException.ThrowIfNullOrWhiteSpace(packInstanceId);
        return MutateAsync("open_pack", null, packInstanceId, null, 0, cancellationToken);
    }

    internal Task<bool> SaveSceneAsync(IReadOnlyList<AquariumPlacement> scene, long baseSceneVersion,
        CancellationToken cancellationToken = default)
    {
        ArgumentNullException.ThrowIfNull(scene);
        if (baseSceneVersion < 0) throw new ArgumentOutOfRangeException(nameof(baseSceneVersion));
        if (scene.Any(item => !double.IsFinite(item.X) || !double.IsFinite(item.Y) || !double.IsFinite(item.Scale) ||
            item.Layer is < AquariumLayer.Min or > AquariumLayer.Max))
            throw new ArgumentException("Scene coordinates and layer must be valid.", nameof(scene));
        return MutateAsync("save_scene", null, null, Array.AsReadOnly(scene.ToArray()), baseSceneVersion, cancellationToken);
    }

    private Task<bool> MutateAsync(string action, string? itemId, string? packInstanceId,
        IReadOnlyList<AquariumPlacement>? scene, long sceneVersion, CancellationToken cancellationToken) => RunAsync(async (identity, version, token) =>
    {
        if (action == "open_pack") Update(identity, version, token, () => LastOpenedItem = null);
        byte[]? existingPending = null;
        Update(identity, version, token, () => existingPending = _pending);
        if (existingPending is not null)
        {
            // A timed-out purchase may have committed. Recover that exact command before accepting another.
            if (!MatchesPending(existingPending, action, itemId, packInstanceId, scene, sceneVersion))
            {
                Update(identity, version, token, () => ErrorCode = "pending_operation");
                return false;
            }
        }
        else
        {
            if ((action == "buy" || action == "open_pack") && CatalogVersion == 0) await ReadCloudAsync(identity, version, token);
            byte[] pending = AquariumJson.Write(writer =>
            {
                writer.WriteStartObject();
                writer.WriteString("action", action);
                writer.WriteString("operation_id", Guid.NewGuid().ToString("N"));
                if (itemId is not null)
                {
                    writer.WriteString("item_id", itemId);
                    writer.WriteNumber("catalog_version", CatalogVersion);
                }
                if (packInstanceId is not null)
                    writer.WriteString("pack_instance_id", packInstanceId);
                if (scene is not null)
                {
                    writer.WriteNumber("base_scene_version", sceneVersion);
                    writer.WritePropertyName("scene");
                    AquariumJson.WriteScene(writer, scene);
                }
                writer.WriteEndObject();
            });
            Update(identity, version, token, () => _pending = pending);
        }
        bool success = await SubmitPendingAsync(identity, version, token);
        string? actionError = ErrorCode;
        try { await ReadCloudAsync(identity, version, token); }
        catch (Exception ex) when (success && ex is HttpRequestException or TaskCanceledException or AuthApiException or JsonException or InvalidDataException or KeyNotFoundException)
        {
            // The command response already confirmed the transaction; a failed refresh is not a failed purchase.
            Update(identity, version, token, () =>
            {
                IsOffline = ex is HttpRequestException or TaskCanceledException;
                ErrorCode = IsOffline ? "offline" : "refresh_failed";
            });
        }
        if (!success) Update(identity, version, token, () => ErrorCode = actionError);
        await SaveCacheAsync(identity, version, token);
        return success;
    }, cancellationToken);

    private async Task<bool> RunAsync(Func<string, long, CancellationToken, Task<bool>> action, CancellationToken cancellationToken)
    {
        ObjectDisposedException.ThrowIf(Volatile.Read(ref _disposed) != 0, this);
        using var linked = CancellationTokenSource.CreateLinkedTokenSource(cancellationToken, _lifetime.Token);
        CancellationToken token = linked.Token;
        string? identity;
        long version;
        lock (_identityGate) { identity = _identity; version = _identityVersion; }
        if (identity is null || !IsAvailable) { ErrorCode = "admin_required"; return false; }
        await _gate.WaitAsync(token);
        try
        {
            CheckIdentity(identity, version, token);
            await LoadCacheAsync(identity, version, token);
            Update(identity, version, token, () => ErrorCode = PendingScene is null ? null : "scene_conflict");
            return await action(identity, version, token);
        }
        catch (OperationCanceledException) when (!cancellationToken.IsCancellationRequested && !_lifetime.IsCancellationRequested)
        {
            TryUpdate(identity, version, () => { IsOffline = true; ErrorCode = "offline"; });
            return false;
        }
        catch (HttpRequestException)
        {
            TryUpdate(identity, version, () => { IsOffline = true; ErrorCode = "offline"; });
            return false;
        }
        catch (AuthApiException ex)
        {
            TryUpdate(identity, version, () => { IsOffline = false; ErrorCode = ex.Code; });
            return false;
        }
        catch (Exception ex) when (ex is JsonException or InvalidDataException or InvalidOperationException or IOException or UnauthorizedAccessException or KeyNotFoundException)
        {
            TryUpdate(identity, version, () => ErrorCode = ex is IOException or UnauthorizedAccessException ? "cache_error" : "invalid_response");
            return false;
        }
        finally { _gate.Release(); RaiseChanged(); }
    }

    private async Task<bool> SubmitPendingAsync(string identity, long version, CancellationToken token)
    {
        CheckIdentity(identity, version, token);
        byte[] command = _pending ?? throw new InvalidOperationException("No pending command.");
        // Persist before every attempt, including recovery after a previous disk failure.
        await SaveCacheAsync(identity, version, token);
        CheckIdentity(identity, version, token);
        using HttpResponseMessage response = await _api.SendAsync(HttpMethod.Post, "../aquarium/actions.php", command, null, token);
        CheckIdentity(identity, version, token);
        if (!response.IsSuccessStatusCode)
        {
            AuthApiException error = await ReadErrorAsync(response, token);
            App.Log($"[Aquarium] Command rejected status={(int)response.StatusCode} code={error.Code} message={error.Message}");
            CheckIdentity(identity, version, token);
            if ((int)response.StatusCode is >= 400 and < 500)
            {
                Update(identity, version, token, () =>
                {
                    if (error.Code == "scene_conflict")
                    {
                        using JsonDocument pending = JsonDocument.Parse(command);
                        PendingScene = AquariumJson.ReadScene(pending.RootElement.GetProperty("scene"));
                    }
                    _pending = null;
                    ErrorCode = error.Code;
                    IsOffline = false;
                });
                await SaveCacheAsync(identity, version, token);
                return false;
            }
            throw error;
        }
        using JsonDocument body = await ReadDocumentAsync(response, token);
        AquariumState state = AquariumJson.ReadState(body.RootElement);
        string actionName;
        int sceneCount;
        using (JsonDocument committed = JsonDocument.Parse(command))
        {
            actionName = AquariumJson.Text(committed.RootElement, "action");
            sceneCount = committed.RootElement.TryGetProperty("scene", out JsonElement scene)
                && scene.ValueKind == JsonValueKind.Array ? scene.GetArrayLength() : state.Scene.Count;
        }
        AquariumOpenedItem? openedItem = actionName == "open_pack"
            ? AquariumJson.ReadOpenedItem(body.RootElement, Catalog) : null;
        bool validResult = actionName != "open_pack" || openedItem is not null;
        Update(identity, version, token, () =>
        {
            State = state;
            if (actionName == "open_pack") LastOpenedItem = openedItem;
            using (JsonDocument pending = JsonDocument.Parse(command))
                if (AquariumJson.Text(pending.RootElement, "action") == "save_scene") PendingScene = null;
            _pending = null;
            IsOffline = false;
            ErrorCode = validResult ? null : "invalid_response";
        });
        if (openedItem is not null) RaiseOpened(openedItem);
        App.Log($"[Aquarium] Command committed action={actionName} scene={sceneCount} authoritativeScene={state.Scene.Count} version={state.SceneVersion}");
        await SaveCacheAsync(identity, version, token);
        return validResult;
    }

    private async Task ReadCloudAsync(string identity, long version, CancellationToken token)
    {
        CheckIdentity(identity, version, token);
        using HttpResponseMessage catalogResponse = await _api.SendAsync(HttpMethod.Get, "../aquarium/catalog.php", null, null, token);
        CheckIdentity(identity, version, token);
        if (!catalogResponse.IsSuccessStatusCode) throw await ReadErrorAsync(catalogResponse, token);
        using JsonDocument catalogBody = await ReadDocumentAsync(catalogResponse, token);
        IReadOnlyList<AquariumCatalogItem> catalog = AquariumJson.ReadCatalog(catalogBody.RootElement.GetProperty("items"));
        int catalogVersion = catalogBody.RootElement.GetProperty("catalog_version").GetInt32();
        CheckIdentity(identity, version, token);
        using HttpResponseMessage stateResponse = await _api.SendAsync(HttpMethod.Get, "../aquarium/state.php", null, null, token);
        CheckIdentity(identity, version, token);
        if (!stateResponse.IsSuccessStatusCode) throw await ReadErrorAsync(stateResponse, token);
        using JsonDocument stateBody = await ReadDocumentAsync(stateResponse, token);
        AquariumState state = AquariumJson.ReadState(stateBody.RootElement);
        Update(identity, version, token, () =>
        {
            State = state;
            Catalog = catalog;
            CatalogVersion = catalogVersion;
            IsOffline = false;
            // A successful authoritative read clears a stale transport/API
            // error, while retaining the explicit scene-conflict marker.
            ErrorCode = PendingScene is null ? null : "scene_conflict";
        });
        App.Log($"[Aquarium] Cloud state initialized={state.Initialized} inventory={state.Inventory.Count} scene={state.Scene.Count} version={state.SceneVersion}");
    }

    private static bool MatchesPending(byte[] command, string action, string? itemId, string? packInstanceId,
        IReadOnlyList<AquariumPlacement>? scene, long sceneVersion)
    {
        using JsonDocument document = JsonDocument.Parse(command);
        JsonElement pending = document.RootElement;
        if (AquariumJson.Text(pending, "action") != action) return false;
        if (itemId is not null && AquariumJson.Text(pending, "item_id") != itemId) return false;
        if (packInstanceId is not null && AquariumJson.Text(pending, "pack_instance_id") != packInstanceId) return false;
        return scene is null || (pending.GetProperty("base_scene_version").GetInt64() == sceneVersion
            && AquariumJson.ReadScene(pending.GetProperty("scene")).SequenceEqual(scene));
    }

    private async Task LoadCacheAsync(string identity, long version, CancellationToken token)
    {
        if (_loadedIdentity == identity) return;
        string path = CachePath(identity);
        if (File.Exists(path))
        {
            try
            {
                using JsonDocument document = JsonDocument.Parse(await File.ReadAllTextAsync(path, token));
                JsonElement root = document.RootElement;
                if (AquariumJson.Text(root, "user_id") == identity)
                {
                    AquariumState? state = root.GetProperty("state").ValueKind == JsonValueKind.Null ? null : AquariumJson.ReadState(root.GetProperty("state"));
                    IReadOnlyList<AquariumCatalogItem> catalog = AquariumJson.ReadCatalog(root.GetProperty("catalog"));
                    int catalogVersion = root.GetProperty("catalog_version").GetInt32();
                    byte[]? pending = root.GetProperty("pending").ValueKind == JsonValueKind.Null ? null : Encoding.UTF8.GetBytes(root.GetProperty("pending").GetRawText());
                    IReadOnlyList<AquariumPlacement>? conflict = root.GetProperty("conflict_scene").ValueKind == JsonValueKind.Null ? null : AquariumJson.ReadScene(root.GetProperty("conflict_scene"));
                    Update(identity, version, token, () =>
                    {
                        State = state;
                        Catalog = catalog;
                        CatalogVersion = catalogVersion;
                        _pending = pending;
                        PendingScene = conflict;
                    });
                }
            }
            catch (Exception ex) when (ex is JsonException or InvalidDataException or InvalidOperationException or KeyNotFoundException)
            {
                // Malformed display caches never become commands or authoritative assets.
                App.Log($"[Aquarium] Ignored invalid cache: {ex.GetType().Name}");
            }
        }
        Update(identity, version, token, () => _loadedIdentity = identity);
    }

    private async Task SaveCacheAsync(string identity, long version, CancellationToken token)
    {
        byte[] bytes = [];
        Update(identity, version, token, () => bytes = AquariumJson.Write(writer =>
        {
            writer.WriteStartObject();
            writer.WriteString("user_id", identity);
            writer.WritePropertyName("state");
            if (State is null) writer.WriteNullValue(); else AquariumJson.WriteState(writer, State);
            writer.WriteNumber("catalog_version", CatalogVersion);
            writer.WritePropertyName("catalog");
            AquariumJson.WriteCatalog(writer, Catalog);
            writer.WritePropertyName("pending");
            if (_pending is null) writer.WriteNullValue(); else writer.WriteRawValue(_pending);
            writer.WritePropertyName("conflict_scene");
            if (PendingScene is null) writer.WriteNullValue(); else AquariumJson.WriteScene(writer, PendingScene);
            writer.WriteEndObject();
        }));
        Directory.CreateDirectory(_cacheDirectory);
        string path = CachePath(identity);
        string temporary = path + ".tmp";
        await File.WriteAllBytesAsync(temporary, bytes, token);
        Update(identity, version, token, () => File.Move(temporary, path, overwrite: true));
    }

    private string CachePath(string identity) => Path.Combine(_cacheDirectory, AccountBackupFileCatalog.SafeAccountId(identity) + ".json");
    private string? VerifiedIdentity() => _auth.IsAuthenticated && _auth.CurrentUser is { Role: "admin" } user ? user.Id : null;

    private bool SameIdentity(string identity, long version)
    {
        lock (_identityGate) return _identityVersion == version && _identity == identity && VerifiedIdentity() == identity;
    }

    private void CheckIdentity(string identity, long version, CancellationToken token)
    {
        token.ThrowIfCancellationRequested();
        if (!SameIdentity(identity, version)) throw new OperationCanceledException("Aquarium account changed.");
    }

    private void Update(string identity, long version, CancellationToken token, Action update)
    {
        lock (_identityGate)
        {
            CheckIdentity(identity, version, token);
            update();
        }
    }

    private void TryUpdate(string identity, long version, Action update)
    {
        lock (_identityGate)
            if (SameIdentity(identity, version)) update();
    }

    private void OnSessionChanged()
    {
        lock (_identityGate)
        {
            string? identity = VerifiedIdentity();
            if (_identity != identity)
            {
                _identity = identity;
                _identityVersion++;
                _loadedIdentity = null;
                State = null;
                Catalog = Array.Empty<AquariumCatalogItem>();
                CatalogVersion = 0;
                LastOpenedItem = null;
                _pending = null;
                PendingScene = null;
                ErrorCode = null;
                IsOffline = false;
            }
        }
        RaiseChanged();
        if (Volatile.Read(ref _started) != 0 && IsAvailable) _ = RefreshSafelyAsync();
    }

    private async Task RunTimerAsync(CancellationToken token)
    {
        try
        {
            while (_timer is not null && await _timer.WaitForNextTickAsync(token)) await RefreshSafelyAsync();
        }
        catch (OperationCanceledException) when (token.IsCancellationRequested) { }
    }

    private async Task RefreshSafelyAsync()
    {
        try { if (IsAvailable) await RefreshAsync(_lifetime.Token); }
        catch (OperationCanceledException) { }
        catch (ObjectDisposedException) { }
        catch (Exception ex) { App.Log($"[Aquarium] Background refresh failed: {ex.GetType().Name}"); }
    }

    private static async Task<JsonDocument> ReadDocumentAsync(HttpResponseMessage response, CancellationToken token) =>
        JsonDocument.Parse(await response.Content.ReadAsByteArrayAsync(token));

    private static async Task<AuthApiException> ReadErrorAsync(HttpResponseMessage response, CancellationToken token)
    {
        string code = response.StatusCode == HttpStatusCode.Forbidden ? "admin_required" : "server_error";
        try
        {
            using JsonDocument document = await ReadDocumentAsync(response, token);
            if (document.RootElement.TryGetProperty("code", out JsonElement value) && value.ValueKind == JsonValueKind.String)
                code = value.GetString() ?? code;
            else if (document.RootElement.TryGetProperty("error", out JsonElement error)
                && error.ValueKind == JsonValueKind.Object
                && error.TryGetProperty("code", out JsonElement nested)
                && nested.ValueKind == JsonValueKind.String)
                code = nested.GetString() ?? code;
        }
        catch (JsonException) { }
        return new AuthApiException(code, "Aquarium request failed.", response.StatusCode);
    }

    private void RaiseChanged()
    {
        foreach (Action observer in Changed?.GetInvocationList() ?? [])
        {
            try { observer(); } catch (Exception ex) { App.Log($"[Aquarium] Observer failed: {ex.GetType().Name}"); }
        }
    }

    private void RaiseOpened(AquariumOpenedItem openedItem)
    {
        foreach (Action<AquariumOpenedItem> observer in Opened?.GetInvocationList() ?? [])
        {
            try { observer(openedItem); } catch (Exception ex) { App.Log($"[Aquarium] Pack-open observer failed: {ex.GetType().Name}"); }
        }
    }

    public async ValueTask DisposeAsync()
    {
        if (Interlocked.Exchange(ref _disposed, 1) != 0) return;
        _auth.SessionChanged -= OnSessionChanged;
        _lifetime.Cancel();
        _timer?.Dispose();
        if (_timerTask is not null) await _timerTask;
        await _gate.WaitAsync();
        _gate.Release();
        // Callers already waiting on the gate may still observe cancellation; leave the semaphore usable until GC.
        _lifetime.Dispose();
    }
}
