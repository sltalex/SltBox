using System.IO.Compression;
using System.Reflection;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using DeskBox.Contracts;
using DeskBox.Core.Persistence;

namespace DeskBox.Services;

internal enum AccountBackupEnableResult
{
    Enabled,
    Disabled,
    SignInRequired
}

internal sealed class AccountCloudBackupService : IAsyncDisposable
{
    private readonly IAuthenticatedAccountApi _api;
    private readonly IAuthSession _auth;
    private readonly SettingsService _settings;
    private readonly DeskBoxDataBackupService _localBackup;
    private readonly AccountBackupFileCatalog _catalog;
    private readonly DingTalkGroupDocumentFavorites? _favorites;
    private readonly FruitService? _fruit;
    private readonly WildlifeService? _wildlife;
    private readonly Func<DateTimeOffset> _clock;
    private readonly SemaphoreSlim _operation = new(1, 1);
    private readonly CancellationTokenSource _lifetime = new();
    private PeriodicTimer? _timer;
    private Task? _timerTask;
    private int _disposed;

    internal AccountCloudBackupService(
        IAuthenticatedAccountApi api,
        IAuthSession auth,
        SettingsService settings,
        DeskBoxDataBackupService localBackup,
        string? dataDirectory = null,
        Func<DateTimeOffset>? clock = null, DingTalkGroupDocumentFavorites? favorites = null,
        FruitService? fruit = null, WildlifeService? wildlife = null)
    {
        _api = api;
        _auth = auth;
        _favorites = favorites;
        _fruit = fruit;
        _wildlife = wildlife;
        _settings = settings;
        _localBackup = localBackup;
        _catalog = new AccountBackupFileCatalog(dataDirectory ?? DeskBoxDataPathService.Current.DataDirectory);
        _clock = clock ?? (() => DateTimeOffset.UtcNow);
        _auth.SessionChanged += OnSessionChanged;
    }

    internal event Action? StateChanged;
    internal AccountBackupSnapshotDto[] Snapshots { get; private set; } = [];
    internal bool IsBusy { get; private set; }

    internal void Start()
    {
        if (_timerTask is not null) return;
        _timer = new PeriodicTimer(TimeSpan.FromMinutes(1));
        _timerTask = RunTimerAsync(_lifetime.Token);
        _ = RunIfDueAsync(force: false, _lifetime.Token);
    }

    internal async Task<AccountBackupEnableResult> SetEnabledAsync(bool enabled, CancellationToken cancellationToken = default)
    {
        if (enabled && (!_auth.IsAuthenticated || _auth.CurrentUser is null))
            return AccountBackupEnableResult.SignInRequired;

        var cloud = _settings.Settings.CloudBackup;
        cloud.AccountCloudBackupEnabled = enabled;
        if (enabled)
        {
            cloud.AccountCloudBackupOwnerUserId = _auth.CurrentUser!.Id;
            cloud.AccountCloudBackupLastSuccessUtcTicks = 0;
            cloud.AccountCloudBackupLastFailureUtcTicks = 0;
        }
        cancellationToken.ThrowIfCancellationRequested();
        await _settings.SaveAsync();
        RaiseStateChanged();
        if (enabled) await RunIfDueAsync(force: true, cancellationToken);
        return enabled ? AccountBackupEnableResult.Enabled : AccountBackupEnableResult.Disabled;
    }

    internal bool IsEnabledForCurrentAccount
    {
        get
        {
            var cloud = _settings.Settings.CloudBackup;
            return cloud.AccountCloudBackupEnabled && _auth.CurrentUser is { } user &&
                   string.Equals(cloud.AccountCloudBackupOwnerUserId, user.Id, StringComparison.Ordinal);
        }
    }

    internal bool IsBoundToCurrentAccount => _auth.CurrentUser is { } user &&
        string.Equals(_settings.Settings.CloudBackup.AccountCloudBackupOwnerUserId, user.Id, StringComparison.Ordinal);

    internal async Task<bool> RunIfDueAsync(bool force, CancellationToken cancellationToken = default)
    {
        if (!IsEnabledForCurrentAccount) return false;
        string ownerId = _auth.CurrentUser!.Id;
        var cloud = _settings.Settings.CloudBackup;
        DateTimeOffset lastSuccess = cloud.AccountCloudBackupLastSuccessUtcTicks > 0
            ? new DateTimeOffset(cloud.AccountCloudBackupLastSuccessUtcTicks, TimeSpan.Zero)
            : DateTimeOffset.MinValue;
        if (!force && _clock() - lastSuccess < TimeSpan.FromMinutes(Math.Clamp(cloud.CloudBackupIntervalMinutes, 5, 30 * 24 * 60)))
            return false;
        if (!await _operation.WaitAsync(0, cancellationToken)) return false;
        using var ownerLifetime = CancellationTokenSource.CreateLinkedTokenSource(cancellationToken);
        void CheckOwner()
        {
            if (!_auth.IsAuthenticated || _auth.CurrentUser?.Id != ownerId) ownerLifetime.Cancel();
        }
        _auth.SessionChanged += CheckOwner;
        cancellationToken = ownerLifetime.Token;
        SetBusy(true);
        try
        {
            CheckOwner();
            cancellationToken.ThrowIfCancellationRequested();
            if (_favorites is not null) await _favorites.LoadAsync(cancellationToken);
            if (_fruit is not null && !await _fruit.RefreshAsync(cancellationToken))
                throw new InvalidDataException("Fruit account data could not be refreshed before backup.");
            if (_wildlife is not null && !await _wildlife.RefreshAsync(cancellationToken) &&
                WildlifeAssetCatalog.HasCompleteAssets)
                throw new InvalidDataException("Wildlife account data could not be refreshed before backup.");
            AccountBackupFileDto[] files = await _catalog.CaptureAsync(cancellationToken, ownerId);
            var request = new AccountBackupCreateRequest
            {
                DeviceId = DeviceIdentity.Id,
                AppVersion = Assembly.GetExecutingAssembly().GetName().Version?.ToString() ?? "unknown",
                Files = files
            };
            byte[] json = JsonSerializer.SerializeToUtf8Bytes(request, AccountCloudJsonContext.Default.AccountBackupCreateRequest);
            byte[] compressed;
            using (var output = new MemoryStream())
            {
                using (var gzip = new GZipStream(output, CompressionLevel.SmallestSize, leaveOpen: true))
                    await gzip.WriteAsync(json, cancellationToken);
                compressed = output.ToArray();
            }
            string packageHash = Convert.ToHexString(SHA256.HashData(compressed));
            var initRequest = new AccountBackupUploadInitRequest
            {
                DeviceId = request.DeviceId, AppVersion = request.AppVersion,
                TotalBytes = compressed.LongLength, Sha256 = packageHash
            };
            byte[] initJson = SerializeUploadInit(initRequest);
            using HttpResponseMessage initResponse = await _api.SendAsync(HttpMethod.Post, "../backup/upload/init.php", initJson, null, cancellationToken);
            await EnsureSuccessAsync(initResponse, cancellationToken);
            AccountBackupUploadInitResponse? upload = null;
            try
            {
                await using Stream initStream = await initResponse.Content.ReadAsStreamAsync(cancellationToken);
                using JsonDocument document = await JsonDocument.ParseAsync(initStream, cancellationToken: cancellationToken);
                JsonElement root = document.RootElement;
                upload = new AccountBackupUploadInitResponse
                {
                    UploadId = root.TryGetProperty("upload_id", out JsonElement id) ? id.GetString() ?? string.Empty : string.Empty,
                    ChunkSize = root.TryGetProperty("chunk_size", out JsonElement size) ? size.GetInt32() : 0,
                    Received = root.TryGetProperty("received", out JsonElement ranges)
                        ? ranges.EnumerateArray().Where(item => item.ValueKind == JsonValueKind.Array && item.GetArrayLength() >= 2)
                            .Select(item => new[] { item[0].GetInt64(), item[1].GetInt64() }).ToArray()
                        : []
                };
            }
            catch (JsonException) { }
            if (upload is null || string.IsNullOrWhiteSpace(upload.UploadId) || upload.ChunkSize <= 0)
            {
                // Compatibility with an older server during a rolling deployment.
                using HttpResponseMessage legacy = await _api.SendAsync(HttpMethod.Post, "../backup/create.php", compressed, "gzip", cancellationToken);
                await EnsureSuccessAsync(legacy, cancellationToken);
            }
            else
            {
                int chunkSize = Math.Clamp(upload.ChunkSize, 64 * 1024, 4 * 1024 * 1024);
                var received = upload.Received.Select(range => range.Length >= 2 ? (Start: range[0], End: range[1]) : (Start: -1L, End: -1L)).ToArray();
                using var gate = new SemaphoreSlim(4);
                var chunkTasks = Enumerable.Range(0, (int)((compressed.LongLength + chunkSize - 1) / chunkSize))
                    .Where(index => !received.Any(range => range.Start <= index * (long)chunkSize && range.End >= Math.Min(compressed.LongLength - 1, (index + 1L) * chunkSize - 1)))
                    .Select(async index =>
                    {
                        await gate.WaitAsync(cancellationToken);
                        try
                        {
                            long start = index * (long)chunkSize;
                            long end = Math.Min(compressed.LongLength - 1, start + chunkSize - 1);
                            byte[] chunk = compressed.AsSpan((int)start, (int)(end - start + 1)).ToArray();
                            var headers = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase)
                            {
                                ["Content-Range"] = $"bytes {start}-{end}/{compressed.LongLength}",
                                ["X-Chunk-Sha256"] = Convert.ToHexString(SHA256.HashData(chunk))
                            };
                            using HttpResponseMessage chunkResponse = await _api.SendBinaryAsync(HttpMethod.Put, $"../backup/upload/chunk.php?upload_id={Uri.EscapeDataString(upload.UploadId)}", chunk, headers, cancellationToken);
                            await EnsureSuccessAsync(chunkResponse, cancellationToken);
                        }
                        finally { gate.Release(); }
                    });
                await Task.WhenAll(chunkTasks);
                byte[] completeJson = Encoding.UTF8.GetBytes($"{{\"upload_id\":\"{upload.UploadId}\"}}");
                using HttpResponseMessage complete = await _api.SendAsync(HttpMethod.Post, "../backup/upload/complete.php", completeJson, null, cancellationToken);
                await EnsureSuccessAsync(complete, cancellationToken);
            }
            cloud.AccountCloudBackupLastSuccessUtcTicks = _clock().UtcTicks;
            cloud.AccountCloudBackupLastFailureUtcTicks = 0;
            cancellationToken.ThrowIfCancellationRequested();
            await _settings.SaveAsync();
            return true;
        }
        catch
        {
            cloud.AccountCloudBackupLastFailureUtcTicks = _clock().UtcTicks;
            try { await _settings.SaveAsync(); } catch { }
            throw;
        }
        finally
        {
            _auth.SessionChanged -= CheckOwner;
            _operation.Release();
            SetBusy(false);
        }
    }

    internal async Task<AccountBackupSnapshotDto[]> RefreshSnapshotsAsync(CancellationToken cancellationToken = default)
    {
        if (!await _operation.WaitAsync(0, cancellationToken))
            throw new InvalidOperationException("Another account backup operation is already running.");
        SetBusy(true);
        try
        {
            using HttpResponseMessage response = await _api.SendAsync(HttpMethod.Get, "../backup/list.php", null, null, cancellationToken);
            await EnsureSuccessAsync(response, cancellationToken);
            await using Stream stream = await response.Content.ReadAsStreamAsync(cancellationToken);
            AccountBackupListResponse body = await JsonSerializer.DeserializeAsync(
                stream, AccountCloudJsonContext.Default.AccountBackupListResponse, cancellationToken)
                ?? throw new InvalidDataException("The account backup service returned an empty list.");
            Snapshots = body.Snapshots;
            return Snapshots;
        }
        finally
        {
            _operation.Release();
            SetBusy(false);
        }
    }

    internal async Task PrepareRestoreAsync(string snapshotId, CancellationToken cancellationToken = default)
    {
        if (_auth.CurrentUser is not { } user) throw new AuthApiException("invalid_session", "Sign in is required.", System.Net.HttpStatusCode.Unauthorized);
        if (!await _operation.WaitAsync(0, cancellationToken))
            throw new InvalidOperationException("Another account backup operation is already running.");
        SetBusy(true);
        try
        {
            string escaped = Uri.EscapeDataString(snapshotId);
            using HttpResponseMessage response = await _api.SendAsync(HttpMethod.Get, $"../backup/download.php?snapshot_id={escaped}", null, null, cancellationToken);
            await EnsureSuccessAsync(response, cancellationToken);
            await using Stream stream = await response.Content.ReadAsStreamAsync(cancellationToken);
            AccountBackupDownloadResponse body = await JsonSerializer.DeserializeAsync(
                stream, AccountCloudJsonContext.Default.AccountBackupDownloadResponse, cancellationToken)
                ?? throw new InvalidDataException("The account backup service returned an empty snapshot.");
            await _catalog.PrepareRestoreAsync(
                body.Snapshot,
                user.Id,
                async token => { _ = await _localBackup.ExportBackupAsync(_localBackup.PreRestoreBackupDirectory, token); },
                cancellationToken);
        }
        finally
        {
            _operation.Release();
            SetBusy(false);
        }
    }

    internal Task<AccountBackupRestoreResult> ApplyPendingRestoreAsync(CancellationToken cancellationToken = default) =>
        _catalog.ApplyPendingRestoreAsync(cancellationToken);

    internal void CancelPreparedRestore() => _catalog.CancelPreparedRestore();

    private async Task RunTimerAsync(CancellationToken cancellationToken)
    {
        try
        {
            while (_timer is not null && await _timer.WaitForNextTickAsync(cancellationToken))
            {
                try { await RunIfDueAsync(force: false, cancellationToken); }
                catch (Exception ex) when (ex is not OperationCanceledException) { App.Log($"[AccountBackup] Scheduled upload failed: {ex.GetType().Name}"); }
            }
        }
        catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested) { }
    }

    private void OnSessionChanged()
    {
        RaiseStateChanged();
        if (IsEnabledForCurrentAccount) _ = RunIfDueAsync(force: false, _lifetime.Token);
    }

    private static async Task EnsureSuccessAsync(HttpResponseMessage response, CancellationToken cancellationToken)
    {
        if (response.IsSuccessStatusCode) return;
        AuthErrorResponse? error = null;
        try
        {
            await using Stream stream = await response.Content.ReadAsStreamAsync(cancellationToken);
            error = await JsonSerializer.DeserializeAsync(stream, AuthJsonContext.Default.AuthErrorResponse, cancellationToken);
        }
        catch (JsonException) { }
        throw new AuthApiException(error?.Code ?? "server_error", error?.Message ?? "Account backup request failed.", response.StatusCode);
    }

    private static byte[] SerializeUploadInit(AccountBackupUploadInitRequest request)
    {
        using var stream = new MemoryStream();
        using (var writer = new Utf8JsonWriter(stream))
        {
            writer.WriteStartObject();
            writer.WriteString("device_id", request.DeviceId);
            writer.WriteString("app_version", request.AppVersion);
            writer.WriteNumber("total_bytes", request.TotalBytes);
            writer.WriteString("sha256", request.Sha256);
            writer.WriteEndObject();
        }
        return stream.ToArray();
    }

    private void SetBusy(bool value) { IsBusy = value; RaiseStateChanged(); }
    private void RaiseStateChanged()
    {
        foreach (Action observer in StateChanged?.GetInvocationList() ?? [])
        {
            try { observer(); } catch (Exception ex) { App.Log($"[AccountBackup] Observer failed: {ex.GetType().Name}"); }
        }
    }

    public async ValueTask DisposeAsync()
    {
        if (Interlocked.Exchange(ref _disposed, 1) != 0)
            return;

        _auth.SessionChanged -= OnSessionChanged;
        _lifetime.Cancel();
        _timer?.Dispose();
        if (_timerTask is not null) { try { await _timerTask; } catch { } }
        _lifetime.Dispose();
        _operation.Dispose();
    }
}
