using System.Net;
using System.Text;
using System.Text.Json;
using DeskBox.Contracts;
using DeskBox.Core.Persistence;

namespace DeskBox.Services;

/// <summary>Account-scoped mature-tree decoration catalog, wallet actions and versioned scene.</summary>
internal sealed class TreeDecorationService : IAsyncDisposable
{
    internal const int SupportedAssetVersion = 3;
    private readonly IAuthSession _auth;
    private readonly IAuthenticatedAccountApi _api;
    private readonly string _cacheDirectory;
    private readonly SemaphoreSlim _gate = new(1, 1);
    private readonly CancellationTokenSource _lifetime = new();
    private readonly object _identityGate = new();
    private string? _identity;
    private long _identityVersion;
    private string? _loadedIdentity;
    private byte[]? _pending;
    private bool _conflictStateReceived;
    private int _started;
    private int _disposed;

    internal TreeDecorationService(IAuthSession auth, IAuthenticatedAccountApi api,
        string? cacheDirectory = null)
    {
        _auth = auth;
        _api = api;
        _cacheDirectory = cacheDirectory ?? Path.Combine(
            DeskBoxDataPathService.Current.DataDirectory, "cache", "tree-decorations");
        _identity = VerifiedIdentity();
        _auth.SessionChanged += OnSessionChanged;
    }

    internal event Action? Changed;
    internal event Action<IReadOnlyList<TreeDecorationDrawResult>>? Opened;
    internal TreeDecorationCatalog? Catalog { get; private set; }
    internal TreeDecorationState? State { get; private set; }
    internal IReadOnlyList<TreeDecorationDrawResult> LastDrawResults { get; private set; } =
        Array.Empty<TreeDecorationDrawResult>();
    internal IReadOnlyList<TreeDecorationPlacement>? PendingScene { get; private set; }
    internal string? PendingThemeId { get; private set; }
    internal string? CurrentUserId => VerifiedIdentity();
    internal bool IsAvailable => Volatile.Read(ref _disposed) == 0 && CurrentUserId is not null;
    internal bool IsVisibleToCurrentUser => _auth.IsAuthenticated &&
        string.Equals(_auth.CurrentUser?.Username, "sltalex", StringComparison.OrdinalIgnoreCase);
    internal bool IsOffline { get; private set; }
    internal string? ErrorCode { get; private set; }
    internal bool HasPendingOperation => _pending is not null;

    internal void Start()
    {
        ObjectDisposedException.ThrowIf(Volatile.Read(ref _disposed) != 0, this);
        if (Interlocked.Exchange(ref _started, 1) == 0 && IsAvailable) _ = RefreshSafelyAsync();
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

    internal Task<bool> BuyPackAsync(int drawCount, CancellationToken cancellationToken = default)
    {
        if (drawCount is not (1 or 10)) throw new ArgumentOutOfRangeException(nameof(drawCount));
        return MutateAsync("buy_pack", drawCount, null, null, null, null, 0, cancellationToken);
    }

    internal Task<bool> OpenPackAsync(string packInstanceId, CancellationToken cancellationToken = default)
    {
        ArgumentException.ThrowIfNullOrWhiteSpace(packInstanceId);
        return MutateAsync("open_pack", null, null, packInstanceId, null, null, 0, cancellationToken);
    }

    internal Task<bool> RedeemAsync(string itemId, CancellationToken cancellationToken = default)
    {
        ArgumentException.ThrowIfNullOrWhiteSpace(itemId);
        return MutateAsync("redeem", null, itemId, null, null, null, 0, cancellationToken);
    }

    internal Task<bool> SaveSceneAsync(IReadOnlyList<TreeDecorationPlacement> placements,
        string? activeThemeId, long baseSceneVersion, CancellationToken cancellationToken = default)
    {
        ArgumentNullException.ThrowIfNull(placements);
        if (baseSceneVersion < 0) throw new ArgumentOutOfRangeException(nameof(baseSceneVersion));
        TreeDecorationPlacement[] normalized = placements.Select(item => string.IsNullOrWhiteSpace(item.PlacementId)
            ? item with { PlacementId = Guid.NewGuid().ToString("N") } : item).ToArray();
        if (normalized.Length > TreeDecorationPlacementRules.MaximumPlacements ||
            normalized.Any(item => !TreeDecorationPlacementRules.IsValid(item)) ||
            normalized.Select(item => item.PlacementId).Distinct(StringComparer.Ordinal).Count() != normalized.Length)
            throw new ArgumentException("Tree decoration scene is outside the server contract.", nameof(placements));
        return MutateAsync("save_scene", null, null, null,
            Array.AsReadOnly(normalized), activeThemeId, baseSceneVersion, cancellationToken);
    }

    internal Task<bool> ClearConflictDraftAsync(CancellationToken cancellationToken = default) =>
        RunAsync(async (identity, version, token) =>
        {
            Update(identity, version, token, () => { PendingScene = null; PendingThemeId = null; ErrorCode = null; });
            await SaveCacheAsync(identity, version, token);
            return true;
        }, cancellationToken);

    private Task<bool> MutateAsync(string action, int? drawCount, string? itemId, string? packInstanceId,
        IReadOnlyList<TreeDecorationPlacement>? placements, string? activeThemeId,
        long sceneVersion, CancellationToken cancellationToken) =>
        RunAsync(async (identity, version, token) =>
        {
            byte[]? existing = null;
            Update(identity, version, token, () => existing = _pending);
            if (existing is not null)
            {
                if (!MatchesPending(existing, action, drawCount, itemId, packInstanceId,
                    placements, activeThemeId, sceneVersion))
                {
                    Update(identity, version, token, () => ErrorCode = "pending_operation");
                    return false;
                }
            }
            else
            {
                if (action == "buy_pack" && Catalog is null) await ReadCloudAsync(identity, version, token);
                byte[] command = TreeDecorationJson.Write(writer =>
                {
                    writer.WriteStartObject();
                    writer.WriteString("action", action);
                    writer.WriteString("operation_id", Guid.NewGuid().ToString("N"));
                    writer.WriteNumber("asset_version", SupportedAssetVersion);
                    if (drawCount is int count)
                    {
                        writer.WriteNumber("catalog_version", Catalog?.Version ?? 0);
                        writer.WriteNumber("draw_count", count);
                    }
                    if (itemId is not null) writer.WriteString("item_id", itemId);
                    if (packInstanceId is not null) writer.WriteString("pack_instance_id", packInstanceId);
                    if (placements is not null)
                    {
                        writer.WriteNumber("base_scene_version", sceneVersion);
                        if (activeThemeId is null) writer.WriteNull("active_theme_id");
                        else writer.WriteString("active_theme_id", activeThemeId);
                        writer.WritePropertyName("placements");
                        TreeDecorationJson.WritePlacements(writer, placements);
                    }
                    writer.WriteEndObject();
                });
                Update(identity, version, token, () => _pending = command);
            }
            bool success = await SubmitPendingAsync(identity, version, token);
            string? commandError = ErrorCode;
            bool hasConflictState = !success && commandError == "scene_conflict" && _conflictStateReceived;
            try { if (!hasConflictState) await ReadCloudAsync(identity, version, token); }
            catch (Exception ex) when (success && ex is HttpRequestException or TaskCanceledException or AuthApiException or JsonException or InvalidDataException)
            {
                Update(identity, version, token, () =>
                {
                    IsOffline = ex is HttpRequestException or TaskCanceledException;
                    ErrorCode = IsOffline ? "offline" : "refresh_failed";
                });
            }
            if (!success) Update(identity, version, token, () => ErrorCode = commandError);
            await SaveCacheAsync(identity, version, token);
            return success;
        }, cancellationToken);

    private async Task<bool> SubmitPendingAsync(string identity, long version, CancellationToken token)
    {
        CheckIdentity(identity, version, token);
        byte[] command = _pending ?? throw new InvalidOperationException("No pending tree decoration command.");
        _conflictStateReceived = false;
        await SaveCacheAsync(identity, version, token);
        using HttpResponseMessage response = await _api.SendAsync(
            HttpMethod.Post, "../tree/decorations/actions.php", command, null, token);
        CheckIdentity(identity, version, token);
        if (!response.IsSuccessStatusCode)
        {
            using JsonDocument errorBody = await ReadDocumentAsync(response, token);
            string errorCode = errorBody.RootElement.TryGetProperty("code", out JsonElement code) &&
                code.ValueKind == JsonValueKind.String ? code.GetString() ?? "server_error" : "server_error";
            var error = new AuthApiException(errorCode, "Tree decoration request failed.", response.StatusCode);
            if ((int)response.StatusCode is >= 400 and < 500)
            {
                Update(identity, version, token, () =>
                {
                    if (error.Code == "scene_conflict")
                    {
                        using JsonDocument pending = JsonDocument.Parse(command);
                        PendingScene = TreeDecorationJson.ReadPlacements(pending.RootElement.GetProperty("placements"));
                        PendingThemeId = pending.RootElement.TryGetProperty("active_theme_id", out JsonElement theme) &&
                            theme.ValueKind == JsonValueKind.String ? theme.GetString() : null;
                        if (errorBody.RootElement.TryGetProperty("state", out JsonElement cloudState) &&
                            cloudState.ValueKind == JsonValueKind.Object)
                        {
                            State = TreeDecorationJson.ReadState(cloudState);
                            _conflictStateReceived = true;
                        }
                    }
                    _pending = null;
                    IsOffline = false;
                    ErrorCode = error.Code;
                });
                await SaveCacheAsync(identity, version, token);
                return false;
            }
            throw error;
        }
        using JsonDocument body = await ReadDocumentAsync(response, token);
        TreeDecorationState state = TreeDecorationJson.ReadState(body.RootElement);
        IReadOnlyList<TreeDecorationDrawResult> results = TreeDecorationJson.ReadDrawResults(body.RootElement);
        bool opened = false;
        Update(identity, version, token, () =>
        {
            State = state;
            LastDrawResults = results;
            using JsonDocument pending = JsonDocument.Parse(command);
            string? action = pending.RootElement.GetProperty("action").GetString();
            opened = action == "open_pack";
            if (action == "save_scene")
            {
                PendingScene = null;
                PendingThemeId = null;
            }
            _pending = null;
            IsOffline = false;
            ErrorCode = null;
        });
        if (opened) RaiseOpened(results);
        await SaveCacheAsync(identity, version, token);
        return true;
    }

    private async Task ReadCloudAsync(string identity, long version, CancellationToken token)
    {
        using HttpResponseMessage catalogResponse = await _api.SendAsync(
            HttpMethod.Get, $"../tree/decorations/catalog.php?asset_version={SupportedAssetVersion}", null, null, token);
        if (!catalogResponse.IsSuccessStatusCode) throw await ReadErrorAsync(catalogResponse, token);
        using JsonDocument catalogBody = await ReadDocumentAsync(catalogResponse, token);
        TreeDecorationCatalog catalog = TreeDecorationJson.ReadCatalog(catalogBody.RootElement);
        using HttpResponseMessage stateResponse = await _api.SendAsync(
            HttpMethod.Get, "../tree/decorations/state.php", null, null, token);
        if (!stateResponse.IsSuccessStatusCode) throw await ReadErrorAsync(stateResponse, token);
        using JsonDocument stateBody = await ReadDocumentAsync(stateResponse, token);
        TreeDecorationState state = TreeDecorationJson.ReadState(stateBody.RootElement);
        Update(identity, version, token, () =>
        {
            Catalog = catalog;
            State = state;
            IsOffline = false;
            ErrorCode = PendingScene is null ? null : "scene_conflict";
        });
    }

    private async Task<bool> RunAsync(Func<string, long, CancellationToken, Task<bool>> action,
        CancellationToken cancellationToken)
    {
        ObjectDisposedException.ThrowIf(Volatile.Read(ref _disposed) != 0, this);
        using var linked = CancellationTokenSource.CreateLinkedTokenSource(cancellationToken, _lifetime.Token);
        string? identity;
        long version;
        lock (_identityGate) { identity = _identity; version = _identityVersion; }
        if (identity is null || !IsAvailable) { ErrorCode = "sign_in_required"; return false; }
        await _gate.WaitAsync(linked.Token);
        try
        {
            CheckIdentity(identity, version, linked.Token);
            await LoadCacheAsync(identity, version, linked.Token);
            return await action(identity, version, linked.Token);
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
        catch (Exception ex) when (ex is JsonException or InvalidDataException or IOException or UnauthorizedAccessException)
        {
            TryUpdate(identity, version, () => ErrorCode = ex is IOException or UnauthorizedAccessException
                ? "cache_error" : "invalid_response");
            return false;
        }
        finally { _gate.Release(); RaiseChanged(); }
    }

    private static bool MatchesPending(byte[] command, string action, int? drawCount, string? itemId,
        string? packInstanceId,
        IReadOnlyList<TreeDecorationPlacement>? placements, string? activeThemeId, long sceneVersion)
    {
        using JsonDocument document = JsonDocument.Parse(command);
        JsonElement root = document.RootElement;
        if (root.GetProperty("action").GetString() != action) return false;
        if (drawCount is int count && root.GetProperty("draw_count").GetInt32() != count) return false;
        if (itemId is not null && root.GetProperty("item_id").GetString() != itemId) return false;
        if (packInstanceId is not null && root.GetProperty("pack_instance_id").GetString() != packInstanceId) return false;
        if (placements is null) return true;
        string? theme = root.GetProperty("active_theme_id").ValueKind == JsonValueKind.String
            ? root.GetProperty("active_theme_id").GetString() : null;
        return root.GetProperty("base_scene_version").GetInt64() == sceneVersion &&
            theme == activeThemeId &&
            TreeDecorationJson.ReadPlacements(root.GetProperty("placements")).SequenceEqual(placements);
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
                if (root.GetProperty("user_id").GetString() == identity)
                {
                    TreeDecorationCatalog? catalog = root.GetProperty("catalog").ValueKind == JsonValueKind.Null
                        ? null : TreeDecorationJson.ReadCatalog(root.GetProperty("catalog"));
                    TreeDecorationState? state = root.GetProperty("state").ValueKind == JsonValueKind.Null
                        ? null : TreeDecorationJson.ReadState(root.GetProperty("state"));
                    byte[]? pending = root.GetProperty("pending").ValueKind == JsonValueKind.Null
                        ? null : Encoding.UTF8.GetBytes(root.GetProperty("pending").GetRawText());
                    IReadOnlyList<TreeDecorationPlacement>? conflict = root.GetProperty("conflict_scene").ValueKind == JsonValueKind.Null
                        ? null : TreeDecorationJson.ReadPlacements(root.GetProperty("conflict_scene"));
                    string? conflictTheme = root.GetProperty("conflict_theme").ValueKind == JsonValueKind.String
                        ? root.GetProperty("conflict_theme").GetString() : null;
                    Update(identity, version, token, () =>
                    {
                        Catalog = catalog;
                        State = state;
                        _pending = pending;
                        PendingScene = conflict;
                        PendingThemeId = conflictTheme;
                    });
                }
            }
            catch (Exception ex) when (ex is JsonException or InvalidDataException or KeyNotFoundException)
            {
                App.Log($"[TreeDecoration] Ignored invalid cache: {ex.GetType().Name}");
            }
        }
        Update(identity, version, token, () => _loadedIdentity = identity);
    }

    private async Task SaveCacheAsync(string identity, long version, CancellationToken token)
    {
        byte[] bytes = [];
        Update(identity, version, token, () => bytes = TreeDecorationJson.Write(writer =>
        {
            writer.WriteStartObject();
            writer.WriteString("user_id", identity);
            writer.WritePropertyName("catalog");
            if (Catalog is null) writer.WriteNullValue(); else TreeDecorationJson.WriteCatalog(writer, Catalog);
            writer.WritePropertyName("state");
            if (State is null) writer.WriteNullValue(); else TreeDecorationJson.WriteState(writer, State);
            writer.WritePropertyName("pending");
            if (_pending is null) writer.WriteNullValue(); else writer.WriteRawValue(_pending);
            writer.WritePropertyName("conflict_scene");
            if (PendingScene is null) writer.WriteNullValue(); else TreeDecorationJson.WritePlacements(writer, PendingScene);
            if (PendingThemeId is null) writer.WriteNull("conflict_theme");
            else writer.WriteString("conflict_theme", PendingThemeId);
            writer.WriteEndObject();
        }));
        Directory.CreateDirectory(_cacheDirectory);
        string path = CachePath(identity);
        string temporary = path + ".tmp";
        await File.WriteAllBytesAsync(temporary, bytes, token);
        Update(identity, version, token, () => TreeDecorationCacheFileStore.Commit(temporary, path));
    }

    private string CachePath(string identity) => Path.Combine(_cacheDirectory,
        AccountBackupFileCatalog.SafeAccountId(identity) + ".json");
    private string? VerifiedIdentity() => _auth.IsAuthenticated ? _auth.CurrentUser?.Id : null;

    private bool SameIdentity(string identity, long version)
    {
        lock (_identityGate) return _identityVersion == version && _identity == identity &&
            VerifiedIdentity() == identity;
    }

    private void CheckIdentity(string identity, long version, CancellationToken token)
    {
        token.ThrowIfCancellationRequested();
        if (!SameIdentity(identity, version)) throw new OperationCanceledException("Tree decoration account changed.");
    }

    private void Update(string identity, long version, CancellationToken token, Action update)
    {
        lock (_identityGate) { CheckIdentity(identity, version, token); update(); }
    }

    private void TryUpdate(string identity, long version, Action update)
    {
        lock (_identityGate) if (SameIdentity(identity, version)) update();
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
                Catalog = null;
                State = null;
                LastDrawResults = Array.Empty<TreeDecorationDrawResult>();
                _pending = null;
                _conflictStateReceived = false;
                PendingScene = null;
                PendingThemeId = null;
                ErrorCode = null;
                IsOffline = false;
            }
        }
        RaiseChanged();
        if (Volatile.Read(ref _started) != 0 && IsAvailable) _ = RefreshSafelyAsync();
    }

    private async Task RefreshSafelyAsync()
    {
        try { if (IsAvailable) await RefreshAsync(_lifetime.Token); }
        catch (OperationCanceledException) { }
        catch (ObjectDisposedException) { }
        catch (Exception ex) { App.Log($"[TreeDecoration] Refresh failed: {ex.GetType().Name}"); }
    }

    private static async Task<JsonDocument> ReadDocumentAsync(HttpResponseMessage response,
        CancellationToken token) => JsonDocument.Parse(await response.Content.ReadAsByteArrayAsync(token));

    private static async Task<AuthApiException> ReadErrorAsync(HttpResponseMessage response,
        CancellationToken token)
    {
        string code = response.StatusCode == HttpStatusCode.Forbidden ? "sign_in_required" : "server_error";
        try
        {
            using JsonDocument document = await ReadDocumentAsync(response, token);
            if (document.RootElement.TryGetProperty("code", out JsonElement value) &&
                value.ValueKind == JsonValueKind.String)
                code = value.GetString() ?? code;
        }
        catch (JsonException) { }
        return new AuthApiException(code, "Tree decoration request failed.", response.StatusCode);
    }

    private void RaiseChanged()
    {
        foreach (Action observer in Changed?.GetInvocationList() ?? [])
        {
            try { observer(); }
            catch (Exception ex) { App.Log($"[TreeDecoration] Observer failed: {ex.GetType().Name}"); }
        }
    }

    private void RaiseOpened(IReadOnlyList<TreeDecorationDrawResult> results)
    {
        foreach (Action<IReadOnlyList<TreeDecorationDrawResult>> observer in Opened?.GetInvocationList() ?? [])
        {
            try { observer(results); }
            catch (Exception ex) { App.Log($"[TreeDecoration] Open observer failed: {ex.GetType().Name}"); }
        }
    }

    public async ValueTask DisposeAsync()
    {
        if (Interlocked.Exchange(ref _disposed, 1) != 0) return;
        _auth.SessionChanged -= OnSessionChanged;
        _lifetime.Cancel();
        await _gate.WaitAsync();
        _gate.Release();
        _lifetime.Dispose();
    }
}
