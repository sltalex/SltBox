using System.Net.Http.Headers;
using System.Net.Http.Json;
using System.Diagnostics;
using System.Text;
using System.Text.Json;
using System.Text.Json.Serialization;
using Microsoft.AspNetCore.Builder;
using Microsoft.AspNetCore.Hosting;
using Microsoft.AspNetCore.Http;
using Microsoft.Extensions.DependencyInjection;
using Microsoft.Extensions.Hosting;

WebApplicationBuilder builder = WebApplication.CreateBuilder(args);
builder.Services.AddHttpClient<TownApiClient>((services, client) =>
{
    string baseUrl = builder.Configuration["TOWN_API_BASE_URL"] ?? "http://localhost/";
    client.BaseAddress = new Uri(baseUrl.EndsWith('/') ? baseUrl : baseUrl + "/");
    client.Timeout = TimeSpan.FromSeconds(30);
});
builder.Services.AddSingleton<OpenAiCompatiblePlanner>();
builder.Services.AddSingleton<TownRealtimeHub>();
builder.Services.AddHostedService<NpcWorkerService>();
int realtimePort = int.TryParse(builder.Configuration["REALTIME_PORT"], out int configuredPort) && configuredPort is > 0 and <= 65535
    ? configuredPort : 8080;
builder.WebHost.UseUrls($"http://0.0.0.0:{realtimePort}");
WebApplication app = builder.Build();
app.UseWebSockets(new WebSocketOptions
{
    KeepAliveInterval = TimeSpan.FromSeconds(20)
});
app.Map("/ws/town", async context =>
{
    await context.RequestServices.GetRequiredService<TownRealtimeHub>().HandleAsync(context);
});
app.MapGet("/health", () => Results.Ok(new { ok = true, service = "npc-worker" }));
await app.RunAsync();

public sealed record Lease(string Token, DateTimeOffset ExpiresAt);
public sealed record NpcRuntime(int TileX, int TileY, string State, string[] Capabilities);
public sealed record Npc(string Id, string Name, string Occupation, bool Enabled, bool AgentPaused, NpcRuntime Runtime, JsonElement Schedule, JsonElement Profile);
public sealed record NpcSnapshot(long NavigationVersion, IReadOnlyList<Npc> Npcs);
public sealed record PlannedAction(string Action, string? TargetId, JsonElement Payload, string Reason);
public sealed record AgentPlanResult(PlannedAction? Action, string Status, int PromptTokens, int CompletionTokens, int TotalTokens, int LatencyMs, string ModelName, string ProviderId, string ErrorCode, string ErrorMessage);
public sealed record ModelProvider(string ProviderId, string BaseUrl, string ModelName, string ApiKey, double Temperature, int TimeoutSeconds);

public sealed class TownApiClient(HttpClient http, IConfiguration configuration)
{
    private readonly string _workerToken = configuration["NPC_WORKER_TOKEN"] ?? "";
    private readonly string _workerId = configuration["NPC_WORKER_ID"] ?? Environment.MachineName;
    private Lease? _lease;

    private async Task<HttpRequestMessage> RequestAsync(HttpMethod method, string path, object? body, CancellationToken cancellationToken)
    {
        var request = new HttpRequestMessage(method, path);
        request.Headers.Add("X-DeskBox-Agent-Token", _workerToken);
        request.Headers.Add("X-DeskBox-Worker-Id", _workerId);
        if (_lease is not null) request.Headers.Add("X-DeskBox-Agent-Lease", _lease.Token);
        if (body is not null) request.Content = new StringContent(JsonSerializer.Serialize(body), Encoding.UTF8, "application/json");
        return request;
    }

    public async Task<Lease> AcquireLeaseAsync(CancellationToken cancellationToken)
    {
        using HttpRequestMessage request = await RequestAsync(HttpMethod.Post, "api/v2/town/agent/lease.php?action=acquire", new { }, cancellationToken);
        using HttpResponseMessage response = await http.SendAsync(request, cancellationToken); response.EnsureSuccessStatusCode();
        using JsonDocument document = await JsonDocument.ParseAsync(await response.Content.ReadAsStreamAsync(cancellationToken), cancellationToken: cancellationToken);
        JsonElement lease = document.RootElement.GetProperty("lease");
        _lease = new Lease(lease.GetProperty("lease_token").GetString()!, lease.GetProperty("expires_at").GetDateTimeOffset()); return _lease;
    }

    public async Task<bool> RenewLeaseAsync(CancellationToken cancellationToken)
    {
        if (_lease is null) return false;
        try {
            using HttpRequestMessage request = await RequestAsync(HttpMethod.Post, "api/v2/town/agent/lease.php?action=renew", new { lease_token = _lease.Token }, cancellationToken);
            using HttpResponseMessage response = await http.SendAsync(request, cancellationToken); if (!response.IsSuccessStatusCode) { _lease = null; return false; }
            using JsonDocument document = await JsonDocument.ParseAsync(await response.Content.ReadAsStreamAsync(cancellationToken), cancellationToken: cancellationToken);
            JsonElement lease = document.RootElement.GetProperty("lease"); _lease = new Lease(lease.GetProperty("lease_token").GetString()!, lease.GetProperty("expires_at").GetDateTimeOffset()); return true;
        } catch (HttpRequestException) { return false; }
    }

    public async Task<NpcSnapshot> SnapshotAsync(CancellationToken cancellationToken)
    {
        using HttpRequestMessage request = await RequestAsync(HttpMethod.Get, "api/v2/town/npcs/snapshot.php", null, cancellationToken);
        using HttpResponseMessage response = await http.SendAsync(request, cancellationToken); response.EnsureSuccessStatusCode();
        using JsonDocument document = await JsonDocument.ParseAsync(await response.Content.ReadAsStreamAsync(cancellationToken), cancellationToken: cancellationToken);
        long version = document.RootElement.GetProperty("navigation_version").GetInt64(); var list = new List<Npc>();
        foreach (JsonElement value in document.RootElement.GetProperty("npcs").EnumerateArray()) {
            JsonElement runtime = value.GetProperty("runtime");
            list.Add(new Npc(value.GetProperty("npc_id").GetString()!, value.GetProperty("name").GetString() ?? "NPC", value.GetProperty("occupation").GetString() ?? "farmer", value.GetProperty("enabled").GetBoolean(), value.GetProperty("agent_paused").GetBoolean(), new NpcRuntime(runtime.GetProperty("tile_x").GetInt32(), runtime.GetProperty("tile_y").GetInt32(), runtime.GetProperty("state").GetString() ?? "rest", runtime.GetProperty("capabilities").EnumerateArray().Select(item => item.GetString() ?? "").ToArray()), value.GetProperty("schedule").Clone(), value.GetProperty("profile").Clone()));
        }
        return new NpcSnapshot(version, list);
    }

    public async Task<string?> RecordRunAsync(Npc npc, PlannedAction action, AgentPlanResult plan, string status, CancellationToken cancellationToken, string? runId = null)
    {
        var output = new Dictionary<string, object?> { ["action"] = action.Action, ["target_id"] = action.TargetId, ["reason"] = action.Reason, ["payload"] = JsonSerializer.Deserialize<object>(action.Payload.GetRawText()) };
        var body = new { run_id = runId, npc_id = npc.Id, provider_id = string.IsNullOrWhiteSpace(plan.ProviderId) ? null : plan.ProviderId, model_name = plan.ModelName, status, prompt_tokens = plan.PromptTokens, completion_tokens = plan.CompletionTokens, total_tokens = plan.TotalTokens, latency_ms = plan.LatencyMs, action = action.Action, reason = action.Reason, error_code = plan.ErrorCode, error_message = plan.ErrorMessage, output };
        try {
            using HttpRequestMessage request = await RequestAsync(HttpMethod.Post, "api/v2/town/agent/runs.php", body, cancellationToken);
            using HttpResponseMessage response = await http.SendAsync(request, cancellationToken); response.EnsureSuccessStatusCode();
            using JsonDocument document = await JsonDocument.ParseAsync(await response.Content.ReadAsStreamAsync(cancellationToken), cancellationToken: cancellationToken);
            return document.RootElement.TryGetProperty("run_id", out JsonElement run) ? run.GetString() : null;
        } catch (HttpRequestException) { return null; }
    }

    public async Task UpdateRunAsync(Npc npc, string runId, string status, string errorCode, string errorMessage, CancellationToken cancellationToken)
    {
        var body = new { operation = "update", run_id = runId, npc_id = npc.Id, status, error_code = errorCode, error_message = errorMessage };
        using HttpRequestMessage request = await RequestAsync(HttpMethod.Post, "api/v2/town/agent/runs.php", body, cancellationToken);
        using HttpResponseMessage response = await http.SendAsync(request, cancellationToken); response.EnsureSuccessStatusCode();
    }

    public async Task<JsonElement> ExecuteAsync(Npc npc, PlannedAction action, string? runId, CancellationToken cancellationToken)
    {
        Dictionary<string, object?> actionPayload = action.Payload.ValueKind == JsonValueKind.Object
            ? JsonSerializer.Deserialize<Dictionary<string, object?>>(action.Payload.GetRawText()) ?? new()
            : new();
        if (!string.IsNullOrWhiteSpace(action.TargetId) && !actionPayload.ContainsKey("target_id")) actionPayload["target_id"] = action.TargetId;
        if (!string.IsNullOrWhiteSpace(runId)) actionPayload["run_id"] = runId;
        var payload = new Dictionary<string, object?> { ["npc_id"] = npc.Id, ["action"] = action.Action, ["payload"] = actionPayload };
        using HttpRequestMessage request = await RequestAsync(HttpMethod.Post, "api/v2/town/npcs/actions.php", payload, cancellationToken);
        using HttpResponseMessage response = await http.SendAsync(request, cancellationToken);
        response.EnsureSuccessStatusCode();
        using JsonDocument document = await JsonDocument.ParseAsync(
            await response.Content.ReadAsStreamAsync(cancellationToken), cancellationToken: cancellationToken);
        return document.RootElement.Clone();
    }

    public async Task<ModelProvider?> ModelProviderAsync(CancellationToken cancellationToken)
    {
        try {
            using HttpRequestMessage request = await RequestAsync(HttpMethod.Get, "api/v2/town/agent/model.php", null, cancellationToken);
            using HttpResponseMessage response = await http.SendAsync(request, cancellationToken); response.EnsureSuccessStatusCode();
            using JsonDocument document = await JsonDocument.ParseAsync(await response.Content.ReadAsStreamAsync(cancellationToken), cancellationToken: cancellationToken);
            if (!document.RootElement.TryGetProperty("provider", out JsonElement provider) || provider.ValueKind != JsonValueKind.Object) return null;
            return new ModelProvider(provider.TryGetProperty("provider_id", out JsonElement id) ? id.GetString() ?? "" : "", provider.GetProperty("base_url").GetString() ?? "", provider.GetProperty("model_name").GetString() ?? "", provider.GetProperty("api_key").GetString() ?? "", provider.GetProperty("temperature").GetDouble(), provider.GetProperty("timeout_seconds").GetInt32());
        } catch (Exception error) when (error is HttpRequestException or TaskCanceledException or JsonException or KeyNotFoundException) { return null; }
    }
}

public sealed class OpenAiCompatiblePlanner(IConfiguration configuration, IHttpClientFactory clientFactory, ILogger<OpenAiCompatiblePlanner> logger)
{
    public async Task<AgentPlanResult> TryPlanAsync(Npc npc, ModelProvider? configuredProvider, CancellationToken cancellationToken)
    {
        string baseUrl = configuredProvider?.BaseUrl ?? configuration["OPENAI_BASE_URL"] ?? ""; string apiKey = configuredProvider?.ApiKey ?? configuration["OPENAI_API_KEY"] ?? ""; string model = configuredProvider?.ModelName ?? configuration["OPENAI_MODEL"] ?? "";
        string providerId = configuredProvider?.ProviderId ?? "";
        if (string.IsNullOrWhiteSpace(baseUrl) || string.IsNullOrWhiteSpace(apiKey) || string.IsNullOrWhiteSpace(model)) return new(null, "no_model", 0, 0, 0, 0, model, providerId, "model_not_configured", "未配置可用模型");
        Stopwatch stopwatch = Stopwatch.StartNew();
        try {
            using HttpClient client = clientFactory.CreateClient(); client.BaseAddress = new Uri(baseUrl.TrimEnd('/') + "/"); client.Timeout = TimeSpan.FromSeconds(Math.Clamp(configuredProvider?.TimeoutSeconds ?? 20, 5, 300)); client.DefaultRequestHeaders.Authorization = new AuthenticationHeaderValue("Bearer", apiKey);
            var body = new { model, temperature = configuredProvider?.Temperature ?? 0.4, response_format = new { type = "json_object" }, messages = new[] { new { role = "system", content = "Return only a JSON action with action, target_id, payload, dialogue, mood, steps and reason. Allowed actions: walk, work, rest, talk. dialogue is a short Chinese sentence or empty; mood is an object such as {label,score,reason}; steps is an array of planned steps." }, new { role = "user", content = JsonSerializer.Serialize(new { npc.Name, npc.Occupation, npc.Runtime, npc.Profile }) } } };
            using HttpResponseMessage response = await client.PostAsJsonAsync("chat/completions", body, cancellationToken); response.EnsureSuccessStatusCode();
            using JsonDocument document = await JsonDocument.ParseAsync(await response.Content.ReadAsStreamAsync(cancellationToken), cancellationToken: cancellationToken); JsonElement responseRoot = document.RootElement;
            int promptTokens = responseRoot.TryGetProperty("usage", out JsonElement usage) && usage.TryGetProperty("prompt_tokens", out JsonElement prompt) ? prompt.GetInt32() : 0;
            int completionTokens = usage.ValueKind == JsonValueKind.Object && usage.TryGetProperty("completion_tokens", out JsonElement completion) ? completion.GetInt32() : 0;
            int totalTokens = usage.ValueKind == JsonValueKind.Object && usage.TryGetProperty("total_tokens", out JsonElement total) ? total.GetInt32() : promptTokens + completionTokens;
            string content = responseRoot.GetProperty("choices")[0].GetProperty("message").GetProperty("content").GetString() ?? "";
            using JsonDocument output = JsonDocument.Parse(content); JsonElement root = output.RootElement; string action = root.GetProperty("action").GetString() ?? "";
            if (!new[] { "walk", "work", "rest", "talk" }.Contains(action)) return new(null, "failed", promptTokens, completionTokens, totalTokens, (int)stopwatch.ElapsedMilliseconds, model, providerId, "invalid_action", "模型返回了不支持的行动");
            Dictionary<string, object?> payloadObject = root.TryGetProperty("payload", out JsonElement payloadElement) && payloadElement.ValueKind == JsonValueKind.Object
                ? JsonSerializer.Deserialize<Dictionary<string, object?>>(payloadElement.GetRawText()) ?? new()
                : new();
            foreach (string field in new[] { "dialogue", "mood", "steps" }) if (root.TryGetProperty(field, out JsonElement value)) payloadObject[field] = JsonSerializer.Deserialize<object>(value.GetRawText());
            JsonElement payload = JsonSerializer.SerializeToElement(payloadObject);
            return new(new PlannedAction(action, root.TryGetProperty("target_id", out JsonElement target) ? target.GetString() : null, payload, root.TryGetProperty("reason", out JsonElement reason) ? reason.GetString() ?? "" : ""), "completed", promptTokens, completionTokens, totalTokens, (int)stopwatch.ElapsedMilliseconds, model, providerId, "", "");
        } catch (Exception error) when (error is HttpRequestException or TaskCanceledException or JsonException or KeyNotFoundException or InvalidOperationException) { stopwatch.Stop(); logger.LogWarning("NPC model fallback: {Error}", error.GetType().Name); return new(null, "failed", 0, 0, 0, (int)stopwatch.ElapsedMilliseconds, model, providerId, error is TaskCanceledException ? "timeout" : "model_error", error.Message); }
    }
}

public sealed class NpcWorkerService(TownApiClient api, OpenAiCompatiblePlanner planner, TownRealtimeHub hub, ILogger<NpcWorkerService> logger) : BackgroundService
{
    private readonly Dictionary<string, DateTimeOffset> _lastActions = new(StringComparer.Ordinal);
    protected override async Task ExecuteAsync(CancellationToken stoppingToken)
    {
        Lease? lease = null; DateTimeOffset nextRenew = DateTimeOffset.MinValue;
        while (!stoppingToken.IsCancellationRequested) {
            try {
                if (lease is null || DateTimeOffset.UtcNow >= nextRenew) { lease = lease is null ? await api.AcquireLeaseAsync(stoppingToken) : (await api.RenewLeaseAsync(stoppingToken) ? lease : null); nextRenew = DateTimeOffset.UtcNow.AddSeconds(10); }
                if (lease is not null) { NpcSnapshot snapshot = await api.SnapshotAsync(stoppingToken); ModelProvider? modelProvider = await api.ModelProviderAsync(stoppingToken); foreach (Npc npc in snapshot.Npcs.Where(item => item.Enabled && !item.AgentPaused)) { if (_lastActions.TryGetValue(npc.Id, out DateTimeOffset last) && DateTimeOffset.UtcNow - last < TimeSpan.FromMinutes(1)) continue; DateTimeOffset now = DateTimeOffset.UtcNow; AgentPlanResult plan = await planner.TryPlanAsync(npc, modelProvider, stoppingToken); PlannedAction? action = plan.Action ?? DeterministicSchedule.Next(npc, now); if (action is not null) { action = DeterministicSchedule.FillMissingTarget(npc, action, now); string finalStatus = plan.Action is null ? "fallback" : plan.Status; string? runId = await api.RecordRunAsync(npc, action, plan, "started", stoppingToken); try { JsonElement result = await api.ExecuteAsync(npc, action, runId, stoppingToken); await hub.BroadcastNpcActionAsync(result, stoppingToken); if (runId is not null) await api.UpdateRunAsync(npc, runId, finalStatus, plan.ErrorCode, plan.ErrorMessage, stoppingToken); _lastActions[npc.Id] = now; } catch (Exception error) { if (runId is not null) await api.UpdateRunAsync(npc, runId, "failed", "action_failed", error.Message, stoppingToken); throw; } } } }
            } catch (OperationCanceledException) when (stoppingToken.IsCancellationRequested) { break; }
            catch (Exception error) { logger.LogWarning("NPC worker loop failed: {Error}", error.Message); lease = null; }
            await Task.Delay(TimeSpan.FromSeconds(5), stoppingToken);
        }
    }
}

public static class DeterministicSchedule
{
    public static PlannedAction FillMissingTarget(Npc npc, PlannedAction action, DateTimeOffset now)
    {
        if (!string.IsNullOrWhiteSpace(action.TargetId) || (action.Action is not ("work" or "talk"))) return action;
        PlannedAction? scheduled = Next(npc, now);
        if (scheduled is not null && scheduled.Action == action.Action && !string.IsNullOrWhiteSpace(scheduled.TargetId)) return action with { TargetId = scheduled.TargetId };
        if (npc.Schedule.ValueKind == JsonValueKind.Array) foreach (JsonElement slot in npc.Schedule.EnumerateArray())
            if (slot.GetProperty("action").GetString() == action.Action && slot.TryGetProperty("target_id", out JsonElement target) && !string.IsNullOrWhiteSpace(target.GetString()))
                return action with { TargetId = target.GetString() };
        return action;
    }

    public static PlannedAction? Next(Npc npc, DateTimeOffset now)
    {
        if (npc.Schedule.ValueKind != JsonValueKind.Array) return new PlannedAction("rest", null, JsonSerializer.SerializeToElement(new { reason = "no_schedule" }), "no_schedule");
        int minute = now.UtcDateTime.Hour * 60 + now.UtcDateTime.Minute; int day = (int)now.UtcDateTime.DayOfWeek;
        foreach (JsonElement slot in npc.Schedule.EnumerateArray()) if ((slot.TryGetProperty("weekday", out JsonElement weekday) ? weekday.GetInt32() : day) == day && minute >= slot.GetProperty("start_minute").GetInt32() && minute < slot.GetProperty("end_minute").GetInt32()) {
            string action = slot.GetProperty("action").GetString() ?? "rest"; string? targetId = slot.TryGetProperty("target_id", out JsonElement target) ? target.GetString() : null; string? jobValue = slot.TryGetProperty("job", out JsonElement job) ? job.GetString() : null; return new PlannedAction(action, targetId, JsonSerializer.SerializeToElement(new { target_id = targetId, job = jobValue }), "deterministic_schedule");
        }
        return new PlannedAction("rest", null, JsonSerializer.SerializeToElement(new { reason = "outside_schedule" }), "outside_schedule");
    }
}
