using System.Diagnostics;
using System.Text.Json;
using System.Text.Json.Nodes;
using System.Security.Cryptography;
using DeskBox.Contracts;
using DeskBox.Core.Persistence;
using System.Text;

namespace DeskBox.Services;

internal sealed class SevenPawsDesktopService : IDisposable
{
    private Process? _process;
    private readonly IAuthSession _auth;
    private readonly IAuthenticatedAccountApi _api;
    private CancellationTokenSource? _lifetime;
    private readonly SemaphoreSlim _gate = new(1, 1);
    private bool _enabled;
    private string? _account;
    private bool _subscribed;
    internal SevenPawsDesktopService(IAuthSession auth, IAuthenticatedAccountApi api)
    {
        _auth = auth;
        _api = api;
    }
    internal string DataRoot { get; private set; } = Path.Combine(DeskBoxDataPathService.Current.RootPath, "seven-paws", "desktop");
    internal bool IsRunning => _process is { HasExited: false };

    internal async Task OpenAsync()
    {
        if (!_subscribed)
        {
            _auth.SessionChanged += OnSessionChanged;
            _subscribed = true;
        }
        _enabled = true;
        await RestartForAccountAsync();
    }

    private void OnSessionChanged()
    {
        // Revoke old-account work immediately, before waiting for the process gate.
        if (_account == _auth?.CurrentUser?.Id) return;
        _lifetime?.Cancel();
        App.SafeFireAndForget(RestartForAccountAsync);
    }

    private async Task RestartForAccountAsync()
    {
        await _gate.WaitAsync();
        try
        {
            if (!_enabled) return;
            string? account = _auth?.CurrentUser?.Id;
            if (IsRunning && _account == account && _lifetime is { IsCancellationRequested: false }) return;
            StopProcess();
            _account = account;
            string root = Path.Combine(DeskBoxDataPathService.Current.RootPath, "seven-paws");
            DataRoot = account is null ? Path.Combine(root, "desktop") : Path.Combine(root, "accounts", Convert.ToHexString(SHA256.HashData(System.Text.Encoding.UTF8.GetBytes(account)))[..24]);
            Directory.CreateDirectory(DataRoot);
            string ownerPath = Path.Combine(root, "legacy-owner.json");
            if (account is not null && !File.Exists(ownerPath))
            {
                foreach (string file in new[] { "settings.json", "cats.json" })
                {
                    string legacy = Path.Combine(root, "desktop", file);
                    string target = Path.Combine(DataRoot, file);
                    if (File.Exists(legacy) && !File.Exists(target)) SevenPawsFileStore.CopyLegacy(legacy, target);
                }
                AtomicWrite(ownerPath, new JsonObject { ["account"] = account }.ToJsonString());
            }
            foreach (string command in Directory.Exists(Path.Combine(DataRoot, "social-commands")) ? Directory.GetFiles(Path.Combine(DataRoot, "social-commands"), "*.json") : [])
                SevenPawsFileStore.DeleteCommand(command);
            AtomicWrite(Path.Combine(DataRoot, "social.json"), new JsonObject { ["connected"] = false, ["message"] = account is null ? "在 Box 中登录后，可直接与其他猫咪串门、送礼和双人专注。" : "正在同步猫咪…" }.ToJsonString());
            StartProcess();
            _lifetime = new CancellationTokenSource();
            _ = RunSocialAsync(DataRoot, account, _lifetime.Token);
        }
        finally { _gate.Release(); }
    }

    private void StartProcess()
    {
        _process?.Dispose();
        _process = null;
        string executable = Path.Combine(AppContext.BaseDirectory, "Assets", "SevenPaws", "native", "7paws.exe");
        if (!File.Exists(executable)) throw new FileNotFoundException("7paws desktop runtime is missing.", executable);
        Directory.CreateDirectory(DataRoot);
        string settingsPath = Path.Combine(DataRoot, "settings.json");
        if (!File.Exists(settingsPath))
        {
            File.WriteAllText(settingsPath, new JsonObject
            {
                ["alwaysOnTop"] = true, ["showTrayIcon"] = false, ["autostart"] = false,
                ["privacyMode"] = false, ["showKeyLabels"] = true, ["mcpEnabled"] = false, ["scale"] = 0.6
            }.ToJsonString());
        }
        var start = new ProcessStartInfo(executable)
        {
            WorkingDirectory = Path.GetDirectoryName(executable)!,
            UseShellExecute = false,
            CreateNoWindow = true
        };
        start.Environment["DESKBOX_SEVENPAWS_HOME"] = DataRoot;
        start.Environment["DESKBOX_SEVENPAWS_PARENT_PID"] = Environment.ProcessId.ToString();
        start.Environment["WEBVIEW2_USER_DATA_FOLDER"] = Path.Combine(DataRoot, "webview2");
        _process = Process.Start(start) ?? throw new InvalidOperationException("7paws desktop process did not start.");
        App.Log($"[SevenPawsDesktop] Started pid={_process.Id} path={executable}");
    }

    internal void Stop()
    {
        _enabled = false;
        _lifetime?.Cancel();
        // Account restarts and shutdown can run on different threads.
        _gate.Wait();
        try { StopProcess(); }
        finally { _gate.Release(); }
    }

    private void StopProcess()
    {
        _lifetime?.Cancel();
        Process? process = _process;
        _process = null;
        if (process is null) return;
        try { if (!process.HasExited) { process.Kill(entireProcessTree: true); process.WaitForExit(5000); } }
        catch (InvalidOperationException) { }
        finally { process.Dispose(); }
    }

    private async Task RunSocialAsync(string root, string? account, CancellationToken token)
    {
        if (account is null) return;
        JsonObject? snapshot = null;
        string message = "";
        int ticks = 10;
        string? resultId = null;
        string? resultMessage = null;
        string directorySearch = "";
        string cachePath = Path.Combine(root, "cloud-baseline.json");
        JsonObject? baseline = ReadObject(cachePath);
        while (!token.IsCancellationRequested)
        {
            try
            {
                string commandsRoot = Path.Combine(root, "social-commands");
                string[] commands = Directory.Exists(commandsRoot) ? Directory.GetFiles(commandsRoot, "*.json").OrderBy(File.GetCreationTimeUtc).Take(32).ToArray() : [];
                if (++ticks >= 5 || commands.Length > 0)
                {
                    ticks = 0;
                    snapshot = await RequestAsync(new JsonObject { ["action"] = "state", ["search"] = directorySearch }, account, token);
                    if (token.IsCancellationRequested) break;
                    JsonObject me = snapshot["me"]!.AsObject();
                    JsonObject local = ReadOutfit(root);
                    string localHash = OutfitHash(local);
                    int remoteRevision = me["revision"]!.GetValue<int>();
                    int knownRevision = baseline?["revision"]?.GetValue<int>() ?? -1;
                    bool dirty = baseline is not null && baseline["hash"]?.GetValue<string>() != localHash;
                    if ((baseline is null && remoteRevision == 0) || (dirty && knownRevision == remoteRevision))
                    {
                        snapshot = await RequestAsync(new JsonObject { ["action"] = "profile", ["operation_id"] = Guid.NewGuid().ToString("N"), ["revision"] = remoteRevision, ["outfit"] = local.DeepClone(), ["name"] = me["name"]!.DeepClone() }, account, token);
                        me = snapshot["me"]!.AsObject();
                    }
                    else if (knownRevision != remoteRevision)
                    {
                        if (dirty)
                        {
                            AtomicWrite(Path.Combine(root, $"outfit-conflict-{DateTime.UtcNow:yyyyMMddHHmmssfff}.json"), local.ToJsonString());
                            message = "另一台设备已更新外观；已同步云端版本，本机修改已保留备份。";
                        }
                        ApplyOutfit(root, me["outfit"]!.AsObject());
                    }
                    baseline = new JsonObject { ["revision"] = me["revision"]!.DeepClone(), ["hash"] = OutfitHash(ReadOutfit(root)) };
                    AtomicWrite(cachePath, baseline.ToJsonString());
                    foreach (string path in commands)
                    {
                        token.ThrowIfCancellationRequested();
                        JsonObject? command = ReadObject(path);
                        if (command is null) continue;
                        string id = command["operation_id"]?.GetValue<string>() ?? "";
                        if (DateTime.UtcNow - File.GetCreationTimeUtc(path) > TimeSpan.FromMinutes(2))
                        {
                            resultId = id; resultMessage = "操作已过期，请刷新后重试。"; SevenPawsFileStore.DeleteCommand(path); continue;
                        }
                        try
                        {
                            if (command["action"]?.GetValue<string>() == "browse") directorySearch = command["search"]?.GetValue<string>() ?? "";
                            command["search"] = directorySearch;
                            snapshot = await RequestAsync(command, account, token);
                            resultId = id; resultMessage = "操作成功";
                            if (command["action"]?.GetValue<string>() == "profile")
                            {
                                var updated = snapshot["me"]!.AsObject();
                                ApplyOutfit(root, updated["outfit"]!.AsObject());
                                baseline = new JsonObject { ["revision"] = updated["revision"]!.DeepClone(), ["hash"] = OutfitHash(ReadOutfit(root)) };
                                AtomicWrite(cachePath, baseline.ToJsonString());
                            }
                            SevenPawsFileStore.DeleteCommand(path);
                        }
                        catch (PetSocialException error)
                        {
                            resultId = id; resultMessage = error.Message; SevenPawsFileStore.DeleteCommand(path);
                        }
                    }
                    message = string.IsNullOrEmpty(message) ? "已同步" : message;
                    snapshot["connected"] = true; snapshot["message"] = message;
                    snapshot["result_id"] = resultId; snapshot["result_message"] = resultMessage;
                    token.ThrowIfCancellationRequested();
                    AtomicWrite(Path.Combine(root, "social.json"), snapshot.ToJsonString());
                    message = "";
                }
            }
            catch (OperationCanceledException) when (token.IsCancellationRequested) { break; }
            catch (Exception error)
            {
                if (!token.IsCancellationRequested)
                {
                    var offline = new JsonObject { ["connected"] = false, ["message"] = error is PetSocialException ? error.Message : "暂时无法连接猫咪服务，稍后自动重试。", ["result_id"] = resultId, ["result_message"] = resultMessage };
                    AtomicWrite(Path.Combine(root, "social.json"), offline.ToJsonString());
                }
            }
            try { await Task.Delay(1000, token); } catch (OperationCanceledException) { break; }
        }
    }

    private async Task<JsonObject> RequestAsync(JsonObject body, string account, CancellationToken token)
    {
        if (_auth?.CurrentUser?.Id != account) throw new OperationCanceledException(token);
        using var response = await _api.SendAsync(HttpMethod.Post, "../pets/social.php", Encoding.UTF8.GetBytes(body.ToJsonString()), null, token);
        string json = await response.Content.ReadAsStringAsync(token);
        token.ThrowIfCancellationRequested();
        if (_auth?.CurrentUser?.Id != account) throw new OperationCanceledException(token);
        if ((int)response.StatusCode >= 500) throw new HttpRequestException("猫咪服务暂时不可用。");
        JsonObject? result;
        try { result = JsonNode.Parse(json)?.AsObject(); }
        catch (JsonException) { throw new PetSocialException("猫咪服务尚未上线，请稍后再试。"); }
        if (!response.IsSuccessStatusCode) throw new PetSocialException(result?["message"]?.GetValue<string>() ?? "猫咪服务尚未就绪。请稍后重试。");
        return result ?? throw new PetSocialException("猫咪服务返回了无效数据。");
    }

    internal static JsonObject ReadOutfit(string root)
    {
        string settingsPath = Path.Combine(root, "settings.json");
        JsonObject? settings = File.Exists(settingsPath) ? JsonNode.Parse(File.ReadAllText(settingsPath))!.AsObject() : null;
        string catsPath = Path.Combine(root, "cats.json");
        JsonArray cats = File.Exists(catsPath) ? JsonNode.Parse(File.ReadAllText(catsPath))!.AsArray() : [];
        return new JsonObject { ["coat"] = settings?["coat"]?.GetValue<string>() ?? "tuxedo", ["cats"] = cats.DeepClone() };
    }

    internal static void ApplyOutfit(string root, JsonObject outfit)
    {
        string settingsPath = Path.Combine(root, "settings.json");
        JsonObject settings = File.Exists(settingsPath) ? JsonNode.Parse(File.ReadAllText(settingsPath))!.AsObject() : new();
        settings["coat"] = outfit["coat"]!.DeepClone();
        AtomicWrite(Path.Combine(root, "cats.json"), outfit["cats"]!.ToJsonString());
        AtomicWrite(Path.Combine(root, "settings.json"), settings.ToJsonString());
    }

    private static string OutfitHash(JsonObject outfit) => Convert.ToHexString(SHA256.HashData(Encoding.UTF8.GetBytes(outfit.ToJsonString())));
    private static JsonObject? ReadObject(string path)
    {
        try { return JsonNode.Parse(File.ReadAllText(path))?.AsObject(); } catch { return null; }
    }
    internal static void AtomicWrite(string path, string json)
    {
        SevenPawsFileStore.AtomicWrite(path, json);
    }
    private sealed class PetSocialException(string message) : Exception(message);
    public void Dispose()
    {
        if (_auth is not null) _auth.SessionChanged -= OnSessionChanged;
        Stop();
    }
}
