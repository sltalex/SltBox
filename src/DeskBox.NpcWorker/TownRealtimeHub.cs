using System.Collections.Concurrent;
using System.Net.WebSockets;
using System.Text;
using System.Text.Json;
using System.Text.Json.Nodes;
using Microsoft.AspNetCore.Http;

/// <summary>
/// Lightweight realtime fan-out for the shared town. PHP remains authoritative
/// for authentication, collision and persistence; this process only relays
/// validated presence responses and NPC action events to connected clients.
/// </summary>
public sealed class TownRealtimeHub(IHttpClientFactory clientFactory, IConfiguration configuration, ILogger<TownRealtimeHub> logger)
{
    private readonly ConcurrentDictionary<Guid, Client> _clients = new();
    private readonly Uri _apiBase = new((configuration["TOWN_API_BASE_URL"] ?? "http://localhost/").TrimEnd('/') + "/");

    public async Task HandleAsync(HttpContext context)
    {
        if (!context.WebSockets.IsWebSocketRequest)
        {
            context.Response.StatusCode = StatusCodes.Status426UpgradeRequired;
            await context.Response.WriteAsync("WebSocket upgrade required.");
            return;
        }

        string token = ReadBearerToken(context.Request.Headers.Authorization.ToString());
        if (token.Length == 0 || !await IsTownUserAsync(token, context.RequestAborted))
        {
            context.Response.StatusCode = StatusCodes.Status401Unauthorized;
            return;
        }

        using WebSocket socket = await context.WebSockets.AcceptWebSocketAsync();
        var client = new Client(Guid.NewGuid(), socket, token);
        _clients[client.Id] = client;
        try
        {
            await client.SendAsync("{\"type\":\"connected\",\"protocol\":1}", context.RequestAborted);
            await ReceiveLoopAsync(client, context.RequestAborted);
        }
        catch (OperationCanceledException) when (context.RequestAborted.IsCancellationRequested) { }
        catch (WebSocketException error) { logger.LogDebug("Town realtime socket closed: {Error}", error.WebSocketErrorCode); }
        finally
        {
            _clients.TryRemove(client.Id, out _);
            await client.CloseAsync();
        }
    }

    public async Task BroadcastNpcActionAsync(JsonElement action, CancellationToken cancellationToken = default)
    {
        if (!action.TryGetProperty("npc_id", out JsonElement npcId) || npcId.ValueKind != JsonValueKind.String) return;
        string message = JsonSerializer.Serialize(new
        {
            type = "npc_state",
            npc_id = npcId.GetString(),
            state = ReadString(action, "state"),
            tile_x = ReadInt(action, "tile_x"),
            tile_y = ReadInt(action, "tile_y"),
            task_id = ReadString(action, "task_id"),
            dialogue = ReadString(action, "dialogue"),
            mood = ReadJsonNode(action, "mood"),
            route = ReadJsonNode(action, "route"),
            navigation_version = ReadJsonNode(action, "navigation_version")
        });
        await BroadcastAsync(message, cancellationToken);
    }

    private static string? ReadString(JsonElement root, string property) =>
        root.TryGetProperty(property, out JsonElement value) && value.ValueKind == JsonValueKind.String
            ? value.GetString() : null;

    private static int ReadInt(JsonElement root, string property) =>
        root.TryGetProperty(property, out JsonElement value) && value.TryGetInt32(out int number)
            ? number : 0;

    private static JsonNode? ReadJsonNode(JsonElement root, string property)
    {
        if (!root.TryGetProperty(property, out JsonElement value) || value.ValueKind == JsonValueKind.Undefined)
            return null;
        try { return JsonNode.Parse(value.GetRawText()); }
        catch (JsonException) { return null; }
    }

    private async Task ReceiveLoopAsync(Client client, CancellationToken cancellationToken)
    {
        byte[] buffer = new byte[16 * 1024];
        while (client.Socket.State == WebSocketState.Open && !cancellationToken.IsCancellationRequested)
        {
            WebSocketReceiveResult result = await client.Socket.ReceiveAsync(buffer, cancellationToken);
            if (result.MessageType == WebSocketMessageType.Close) return;
            if (result.MessageType != WebSocketMessageType.Text || result.Count == 0 || result.EndOfMessage is false)
            {
                if (!result.EndOfMessage) await DrainMessageAsync(client.Socket, cancellationToken);
                continue;
            }
            if (result.Count > 64 * 1024) continue;
            string text = Encoding.UTF8.GetString(buffer, 0, result.Count);
            await HandleMessageAsync(client, text, cancellationToken);
        }
    }

    private async Task HandleMessageAsync(Client client, string text, CancellationToken cancellationToken)
    {
        JsonDocument document;
        try { document = JsonDocument.Parse(text); }
        catch (JsonException) { return; }
        using (document)
        {
            JsonElement root = document.RootElement;
            string type = root.TryGetProperty("type", out JsonElement typeElement) ? typeElement.GetString() ?? "" : "";
            if (type == "ping") { await client.SendAsync("{\"type\":\"pong\"}", cancellationToken); return; }
            if (type != "presence" || !root.TryGetProperty("payload", out JsonElement payload) || payload.ValueKind != JsonValueKind.Object) return;
            long now = Environment.TickCount64;
            if (now - client.LastPresenceAt < 100) return;
            client.LastPresenceAt = now;
            await ForwardPresenceAsync(client, payload.GetRawText(), cancellationToken);
        }
    }

    private async Task ForwardPresenceAsync(Client client, string payload, CancellationToken cancellationToken)
    {
        using HttpRequestMessage request = new(HttpMethod.Post, new Uri(_apiBase, "api/v2/town/presence.php"));
        request.Headers.Authorization = new("Bearer", client.Token);
        request.Content = new StringContent(payload, Encoding.UTF8, "application/json");
        using HttpResponseMessage response = await clientFactory.CreateClient().SendAsync(request, cancellationToken);
        if (!response.IsSuccessStatusCode) return;
        using JsonDocument document = await JsonDocument.ParseAsync(await response.Content.ReadAsStreamAsync(cancellationToken), cancellationToken: cancellationToken);
        if (!document.RootElement.TryGetProperty("presence", out JsonElement presence)) return;
        await BroadcastAsync($"{{\"type\":\"presence\",\"presence\":{presence.GetRawText()}}}", cancellationToken);
    }

    private async Task<bool> IsTownUserAsync(string token, CancellationToken cancellationToken)
    {
        HttpClient http = clientFactory.CreateClient();
        foreach (string path in new[] { "api/v1/auth/me.php", "api/v2/town/world.php" })
        {
            using HttpRequestMessage request = new(HttpMethod.Get, new Uri(_apiBase, path));
            request.Headers.Authorization = new("Bearer", token);
            using HttpResponseMessage response = await http.SendAsync(request, cancellationToken);
            if (!response.IsSuccessStatusCode) return false;
        }
        return true;
    }

    private async Task BroadcastAsync(string message, CancellationToken cancellationToken)
    {
        foreach (Client client in _clients.Values)
        {
            try { await client.SendAsync(message, cancellationToken); }
            catch (WebSocketException) { _clients.TryRemove(client.Id, out _); }
        }
    }

    private static async Task DrainMessageAsync(WebSocket socket, CancellationToken cancellationToken)
    {
        byte[] buffer = new byte[4096];
        WebSocketReceiveResult result;
        do { result = await socket.ReceiveAsync(buffer, cancellationToken); }
        while (!result.EndOfMessage && result.MessageType != WebSocketMessageType.Close);
    }

    private static string ReadBearerToken(string value)
    {
        const string prefix = "Bearer ";
        return value.StartsWith(prefix, StringComparison.OrdinalIgnoreCase) ? value[prefix.Length..].Trim() : string.Empty;
    }

    private sealed class Client(Guid id, WebSocket socket, string token)
    {
        internal Guid Id { get; } = id;
        internal WebSocket Socket { get; } = socket;
        internal string Token { get; } = token;
        internal long LastPresenceAt { get; set; }
        private readonly SemaphoreSlim _sendGate = new(1, 1);

        internal async Task SendAsync(string message, CancellationToken cancellationToken)
        {
            await _sendGate.WaitAsync(cancellationToken);
            try { await Socket.SendAsync(Encoding.UTF8.GetBytes(message), WebSocketMessageType.Text, true, cancellationToken); }
            finally { _sendGate.Release(); }
        }

        internal async Task CloseAsync()
        {
            try
            {
                if (Socket.State is WebSocketState.Open or WebSocketState.CloseReceived)
                    await Socket.CloseAsync(WebSocketCloseStatus.NormalClosure, "closed", CancellationToken.None);
            }
            catch (WebSocketException) { }
        }
    }
}
