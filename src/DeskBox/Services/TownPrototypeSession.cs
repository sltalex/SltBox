#if DEBUG
using System.Net;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using DeskBox.Contracts;
using DeskBox.Core.Persistence;

namespace DeskBox.Services;

/// <summary>Isolated prototype drafts and authenticated cloud saves. No tree economy state is accessed.</summary>
internal sealed class TownPrototypeSession : IDisposable
{
    private readonly IAuthSession _auth;
    private readonly IAuthenticatedAccountApi _api;
    private readonly string _defaultLayout;
    private readonly string _cacheDirectory;
    private readonly TimeSpan _timeout;
    private readonly object _gate = new();
    private CancellationTokenSource _identityLifetime = new();
    private string? _identity;
    private long _epoch = 1;
    private long _sceneVersion;
    private long _revision;
    private string _layout;
    private string? _pendingCommand;
    private bool _dirty;
    private bool _busy;
    private bool _conflict;
    private bool _disposed;
    private string _status = "演示数据 · 尚未保存";

    internal TownPrototypeSession(IAuthSession auth, IAuthenticatedAccountApi api,
        string defaultLayout, string cacheDirectory, TimeSpan? timeout = null)
    {
        _auth = auth;
        _api = api;
        _defaultLayout = NormalizeLayout(defaultLayout);
        _layout = _defaultLayout;
        _cacheDirectory = cacheDirectory;
        _timeout = timeout ?? TimeSpan.FromSeconds(20);
        _identity = Identity();
        ReadDraftLocked();
        _auth.SessionChanged += OnSessionChanged;
    }

    internal event Action<bool>? Changed;
    internal long Epoch { get { lock (_gate) return _epoch; } }
    internal bool IsDirty { get { lock (_gate) return _dirty; } }
    internal bool IsAuthenticated => Identity() is not null;

    internal string BridgeJson()
    {
        lock (_gate)
        {
            return WriteJson(writer =>
            {
                writer.WriteStartObject();
                writer.WriteNumber("session_epoch", _epoch);
                writer.WriteNumber("scene_version", _sceneVersion);
                writer.WritePropertyName("layout");
                writer.WriteRawValue(_layout);
                writer.WriteBoolean("authenticated", _identity is not null);
                writer.WriteString("account_label", _auth.CurrentUser?.Username ?? "未登录");
                writer.WriteString("status", _status);
                writer.WriteBoolean("can_save", _identity is not null && !_busy && !_conflict);
                writer.WriteBoolean("dirty", _dirty);
                writer.WriteBoolean("conflict", _conflict);
                writer.WriteEndObject();
            });
        }
    }

    internal bool UpdateLayout(string json, long epoch)
    {
        string normalized = NormalizeLayout(json);
        lock (_gate)
        {
            if (!IsCurrentLocked(epoch)) return false;
            if (Equivalent(_layout, normalized)) return true;
            _layout = normalized;
            _revision++;
            _dirty = true;
            _status = _conflict ? "云端版本已变化，当前草稿已保留；请重新载入" : "有未保存的布置";
            WriteDraftLocked();
        }
        Changed?.Invoke(false);
        return true;
    }

    internal async Task LoadAsync(bool discardDraft = false)
    {
        long epoch;
        long revision;
        CancellationToken identityToken;
        lock (_gate)
        {
            if (_disposed || _busy) return;
            if (_identity is null)
            {
                _status = "演示数据 · 登录后可保存到账号";
                return;
            }
            epoch = _epoch;
            revision = _revision;
            identityToken = _identityLifetime.Token;
            _busy = true;
            _status = "正在读取账号的小镇布置…";
        }
        Changed?.Invoke(false);
        bool replace = false;
        try
        {
            using HttpResponseMessage response = await SendAsync(HttpMethod.Get, null, identityToken);
            string text = await response.Content.ReadAsStringAsync(identityToken);
            response.EnsureSuccessStatusCode();
            using JsonDocument document = JsonDocument.Parse(text);
            long version = document.RootElement.GetProperty("scene_version").GetInt64();
            string cloudLayout = NormalizeLayout(document.RootElement.GetProperty("layout").GetRawText());
            lock (_gate)
            {
                if (!IsCurrentLocked(epoch)) return;
                if (_dirty && (!discardDraft || _revision != revision))
                {
                    _conflict = _pendingCommand is null && version != _sceneVersion;
                    _status = _pendingCommand is not null ? "上次保存结果尚未确认，点击保存可安全重试"
                        : _conflict ? "云端版本已变化，本机草稿已保留；请重新载入"
                        : _revision != revision ? "载入期间产生的新编辑已保留，请保存或重新载入" : "已恢复本机未保存的草稿";
                }
                else
                {
                    _layout = cloudLayout;
                    _sceneVersion = version;
                    _revision++;
                    _dirty = false;
                    _conflict = false;
                    _pendingCommand = null;
                    _status = version == 0 ? "示例小镇 · 点击保存后归入当前账号" : $"已载入云端布置 · 版本 {version}";
                    replace = true;
                }
                WriteDraftLocked();
            }
        }
        catch (Exception ex) when (ex is HttpRequestException or OperationCanceledException or JsonException or InvalidDataException or KeyNotFoundException or InvalidOperationException or FormatException)
        {
            lock (_gate)
            {
                if (!IsCurrentLocked(epoch)) return;
                _status = ex is OperationCanceledException ? "读取超时，本机草稿已保留，可重新载入" : "读取失败，本机草稿已保留，可重新载入";
            }
        }
        finally
        {
            lock (_gate) { if (IsCurrentLocked(epoch)) _busy = false; }
            if (Epoch == epoch) Changed?.Invoke(replace);
        }
    }

    internal async Task<bool> SaveAsync(long epoch)
    {
        string command;
        long revision;
        CancellationToken identityToken;
        lock (_gate)
        {
            if (!IsCurrentLocked(epoch) || _identity is null || _busy || _conflict) return false;
            _busy = true;
            _status = "正在保存到当前账号…";
            revision = _revision;
            identityToken = _identityLifetime.Token;
            _pendingCommand ??= WriteJson(writer =>
            {
                writer.WriteStartObject();
                writer.WriteString("action", "save_scene");
                writer.WriteString("operation_id", Guid.NewGuid().ToString("N"));
                writer.WriteNumber("base_scene_version", _sceneVersion);
                writer.WritePropertyName("layout");
                writer.WriteRawValue(_layout);
                writer.WriteEndObject();
            });
            command = _pendingCommand;
            WriteDraftLocked();
        }
        Changed?.Invoke(false);
        try
        {
            using HttpResponseMessage response = await SendAsync(HttpMethod.Post, command, identityToken);
            string text = await response.Content.ReadAsStringAsync(identityToken);
            using JsonDocument document = JsonDocument.Parse(text);
            lock (_gate)
            {
                if (!IsCurrentLocked(epoch)) return false;
                if (response.StatusCode == HttpStatusCode.Conflict)
                {
                    _conflict = true;
                    _pendingCommand = null;
                    _status = "云端布置已更新，当前草稿已保留；请重新载入后编辑";
                    WriteDraftLocked();
                    return false;
                }
                if (!response.IsSuccessStatusCode)
                    throw new InvalidDataException(document.RootElement.TryGetProperty("code", out JsonElement code)
                        ? code.GetString() : "save_failed");
                string savedLayout = NormalizeLayout(document.RootElement.GetProperty("layout").GetRawText());
                _sceneVersion = document.RootElement.GetProperty("scene_version").GetInt64();
                _pendingCommand = null;
                _dirty = _revision != revision || !Equivalent(_layout, savedLayout);
                _status = _dirty ? "上次提交已保存，当前仍有未保存的布置" : $"已保存到当前账号 · 版本 {_sceneVersion}";
                WriteDraftLocked();
                return true;
            }
        }
        catch (Exception ex) when (ex is HttpRequestException or OperationCanceledException or JsonException or InvalidDataException or KeyNotFoundException or InvalidOperationException or FormatException)
        {
            lock (_gate)
            {
                if (!IsCurrentLocked(epoch)) return false;
                _status = ex is OperationCanceledException
                    ? "保存超过 20 秒，草稿已保留；点击保存可安全重试"
                    : "保存失败，草稿已保留；点击保存可重试";
                WriteDraftLocked();
            }
            return false;
        }
        finally
        {
            lock (_gate) { if (IsCurrentLocked(epoch)) _busy = false; }
            if (Epoch == epoch) Changed?.Invoke(false);
        }
    }

    private async Task<HttpResponseMessage> SendAsync(HttpMethod method, string? command, CancellationToken identityToken)
    {
        using var timeout = CancellationTokenSource.CreateLinkedTokenSource(identityToken);
        timeout.CancelAfter(_timeout);
        Task<HttpResponseMessage> request = _api.SendAsync(method,
            method == HttpMethod.Get ? "../tree/town-prototype/state.php" : "../tree/town-prototype/actions.php",
            command is null ? null : Encoding.UTF8.GetBytes(command), null, timeout.Token);
        try
        {
            HttpResponseMessage response = await request.WaitAsync(timeout.Token);
            try
            {
                await response.Content.ReadAsByteArrayAsync(timeout.Token).WaitAsync(timeout.Token);
                return response;
            }
            catch { response.Dispose(); throw; }
        }
        catch
        {
            _ = request.ContinueWith(completed =>
            {
                if (completed.Status == TaskStatus.RanToCompletion) completed.Result.Dispose();
                else _ = completed.Exception;
            }, CancellationToken.None, TaskContinuationOptions.ExecuteSynchronously, TaskScheduler.Default);
            throw;
        }
    }

    private void OnSessionChanged()
    {
        lock (_gate)
        {
            string? identity = Identity();
            if (_disposed || identity == _identity) return;
            WriteDraftLocked();
            _identityLifetime.Cancel();
            _identityLifetime.Dispose();
            _identityLifetime = new CancellationTokenSource();
            _identity = identity;
            _epoch++;
            _sceneVersion = 0;
            _revision = 0;
            _layout = _defaultLayout;
            _pendingCommand = null;
            _dirty = _conflict = _busy = false;
            _status = "演示数据 · 尚未保存";
            ReadDraftLocked();
        }
        Changed?.Invoke(true);
        _ = LoadAsync();
    }

    private string? Identity() => _auth.IsAuthenticated ? _auth.CurrentUser?.Id : null;
    private bool IsCurrentLocked(long epoch) => !_disposed && epoch == _epoch && _identity == Identity();
    private string? DraftPath() => _identity is null ? null : Path.Combine(_cacheDirectory,
        Convert.ToHexString(SHA256.HashData(Encoding.UTF8.GetBytes(_identity))) + ".json");

    private void ReadDraftLocked()
    {
        string? path = DraftPath();
        if (path is null || !File.Exists(path)) return;
        try
        {
            using JsonDocument document = JsonDocument.Parse(File.ReadAllText(path));
            JsonElement root = document.RootElement;
            if (root.GetProperty("account_id").GetString() != _identity) return;
            string layout = NormalizeLayout(root.GetProperty("layout").GetRawText());
            long version = root.GetProperty("scene_version").GetInt64();
            if (version < 0) throw new InvalidDataException("Invalid draft scene version.");
            bool dirty = root.GetProperty("dirty").GetBoolean();
            string? command = null;
            if (root.TryGetProperty("pending_command", out JsonElement pending) && pending.ValueKind == JsonValueKind.Object)
                command = pending.GetRawText();
            _layout = layout;
            _sceneVersion = version;
            _dirty = dirty;
            _pendingCommand = command;
        }
        catch (Exception ex) when (ex is IOException or UnauthorizedAccessException or JsonException or
            InvalidDataException or KeyNotFoundException or InvalidOperationException or FormatException or OverflowException)
        { App.Log("[TownPrototype] Ignored an invalid cached draft."); }
    }

    private void WriteDraftLocked()
    {
        string? path = DraftPath();
        if (path is null) return;
        try
        {
            string json = WriteJson(writer =>
            {
                writer.WriteStartObject();
                writer.WriteString("account_id", _identity);
                writer.WriteNumber("scene_version", _sceneVersion);
                writer.WriteBoolean("dirty", _dirty);
                writer.WritePropertyName("layout");
                writer.WriteRawValue(_layout);
                if (_pendingCommand is not null)
                {
                    writer.WritePropertyName("pending_command");
                    writer.WriteRawValue(_pendingCommand);
                }
                writer.WriteEndObject();
            });
            TownPrototypeDraftFileStore.Write(path, json);
        }
        catch (Exception ex) when (ex is IOException or UnauthorizedAccessException)
        { App.Log("[TownPrototype] Draft cache is unavailable; current edit remains in memory."); }
    }

    private static string NormalizeLayout(string json)
    {
        if (Encoding.UTF8.GetByteCount(json) > 131072) throw new InvalidDataException("Town layout is too large.");
        using JsonDocument document = JsonDocument.Parse(json);
        JsonElement root = document.RootElement;
        if (root.ValueKind != JsonValueKind.Object || !root.TryGetProperty("placements", out JsonElement placements) ||
            placements.ValueKind != JsonValueKind.Array || placements.GetArrayLength() > 48 ||
            !root.TryGetProperty("demo_stage", out JsonElement stage) || !stage.TryGetInt32(out int value) || value is < 1 or > 5)
            throw new InvalidDataException("Invalid prototype layout.");
        return WriteJson(root.WriteTo);
    }

    private static bool Equivalent(string left, string right)
    {
        using JsonDocument first = JsonDocument.Parse(left);
        using JsonDocument second = JsonDocument.Parse(right);
        return JsonElement.DeepEquals(first.RootElement, second.RootElement);
    }

    private static string WriteJson(Action<Utf8JsonWriter> write)
    {
        using var stream = new MemoryStream();
        using (var writer = new Utf8JsonWriter(stream)) write(writer);
        return Encoding.UTF8.GetString(stream.ToArray());
    }

    public void Dispose()
    {
        lock (_gate)
        {
            if (_disposed) return;
            WriteDraftLocked();
            _disposed = true;
            _identityLifetime.Cancel();
            _identityLifetime.Dispose();
        }
        _auth.SessionChanged -= OnSessionChanged;
    }
}
#endif
