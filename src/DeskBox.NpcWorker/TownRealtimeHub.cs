using System.Collections.Concurrent;
using System.Net;
using System.Net.WebSockets;
using System.Text;
using System.Text.Json;
using System.Text.Json.Nodes;
using System.Threading.Channels;
using Microsoft.AspNetCore.Http;

/// <summary>Relays authenticated, PHP-validated changes. Slow peers cannot hold up the town.</summary>
public sealed class TownRealtimeHub(IHttpClientFactory clientFactory, IConfiguration configuration, ILogger<TownRealtimeHub> logger)
{
    public sealed record RealtimeMetrics(long ActiveConnections, long AcceptedConnections, long Broadcasts, long DroppedConnections);

    private readonly ConcurrentDictionary<Guid, Client> _clients = new();
    private readonly Uri _apiBase = new((configuration["TOWN_API_BASE_URL"] ?? "http://localhost/").TrimEnd('/') + "/");
    private long _sequence;
    private long _activeConnections;
    private long _acceptedConnections;
    private long _broadcasts;
    private long _droppedConnections;
    private readonly object _broadcastGate = new();

    public RealtimeMetrics Metrics => new(
        Volatile.Read(ref _activeConnections), Volatile.Read(ref _acceptedConnections),
        Volatile.Read(ref _broadcasts), Volatile.Read(ref _droppedConnections));

    public async Task HandleAsync(HttpContext context)
    {
        if (!context.WebSockets.IsWebSocketRequest)
        {
            context.Response.StatusCode = StatusCodes.Status426UpgradeRequired;
            return;
        }
        string authorization = context.Request.Headers.Authorization.ToString();
        string token = authorization.StartsWith("Bearer ", StringComparison.OrdinalIgnoreCase) ? authorization[7..].Trim() : "";
        if (token.Length == 0 || !await HasAccessAsync(token, context.RequestAborted))
        {
            context.Response.StatusCode = StatusCodes.Status401Unauthorized;
            return;
        }
        using WebSocket socket = await context.WebSockets.AcceptWebSocketAsync();
        using var lifetime = CancellationTokenSource.CreateLinkedTokenSource(context.RequestAborted);
        var client = new Client(socket, token);
        _clients[client.Id] = client;
        Interlocked.Increment(ref _acceptedConnections);
        Interlocked.Increment(ref _activeConnections);
        Task writer = WriteLoopAsync(client, lifetime.Token);
        try
        {
            await SendSnapshotAsync(client, lifetime.Token);
            await ReceiveLoopAsync(client, lifetime.Token);
        }
        catch (Exception error) when (error is OperationCanceledException or WebSocketException or HttpRequestException or JsonException or InvalidDataException)
        { logger.LogDebug("Town realtime peer ended: {ErrorType}", error.GetType().Name); }
        finally
        {
            _clients.TryRemove(client.Id, out _);
            Interlocked.Decrement(ref _activeConnections);
            client.Outgoing.Writer.TryComplete();
            await lifetime.CancelAsync();
            socket.Abort();
            try { await writer; }
            catch (Exception error) when (error is OperationCanceledException or WebSocketException or ObjectDisposedException) { }
        }
    }

    public Task BroadcastNpcActionAsync(JsonElement action, CancellationToken cancellationToken = default)
    {
        if (action.ValueKind != JsonValueKind.Object || !action.TryGetProperty("npc_id", out JsonElement id) || id.ValueKind != JsonValueKind.String)
            return Task.CompletedTask;
        var message = JsonNode.Parse(action.GetRawText())!.AsObject();
        message["type"] = "npc_state";
        Broadcast(message);
        return Task.CompletedTask;
    }

    private void Broadcast(JsonObject message)
    {
        lock (_broadcastGate)
        {
        Interlocked.Increment(ref _broadcasts);
        message["sequence"] = Interlocked.Increment(ref _sequence);
        string json = message.ToJsonString();
        foreach (Client client in _clients.Values)
        {
            if (client.Ready) Enqueue(client, json);
            else if (client.Pending.Count < 128) client.Pending.Enqueue(json);
            else { Interlocked.Increment(ref _droppedConnections); client.Socket.Abort(); }
        }
        }
    }

    private void Enqueue(Client client, string json)
    {
        if (client.Outgoing.Writer.TryWrite(json)) return;
        Interlocked.Increment(ref _droppedConnections);
        _clients.TryRemove(client.Id, out _);
        client.Socket.Abort();
    }

    private async Task SendSnapshotAsync(Client client, CancellationToken token)
    {
        // Buffer live deltas during recovery so no change is lost between snapshot
        // reads and subscribing to the ongoing stream.
        using JsonDocument roster = await ApiAsync(HttpMethod.Get, "api/v2/town/presence.php", client.Token, null, token);
        using JsonDocument npcs = await ApiAsync(HttpMethod.Get, "api/v2/town/npcs/snapshot.php", client.Token, null, token);
        lock (_broadcastGate)
        {
        Enqueue(client, new JsonObject {
            ["type"] = "snapshot",
            ["presence"] = JsonNode.Parse(roster.RootElement.GetProperty("presence").GetRawText()),
            ["npc_snapshot"] = JsonNode.Parse(npcs.RootElement.GetRawText())
        }.ToJsonString());
        while (client.Pending.TryDequeue(out string? pending)) Enqueue(client, pending);
        client.Ready = true;
        }
    }

    private async Task ReceiveLoopAsync(Client client, CancellationToken token)
    {
        byte[] buffer = new byte[4096];
        while (!token.IsCancellationRequested)
        {
            using var body = new MemoryStream();
            WebSocketReceiveResult result;
            do
            {
                result = await client.Socket.ReceiveAsync(buffer, token);
                if (result.MessageType == WebSocketMessageType.Close) return;
                if (result.MessageType != WebSocketMessageType.Text) throw new InvalidDataException("Text required.");
                body.Write(buffer, 0, result.Count);
                if (body.Length > 16384) throw new InvalidDataException("Message limit exceeded.");
            } while (!result.EndOfMessage);
            using JsonDocument document = JsonDocument.Parse(body.ToArray());
            JsonElement root = document.RootElement;
            if (root.ValueKind != JsonValueKind.Object || !root.TryGetProperty("type", out JsonElement type) || type.ValueKind != JsonValueKind.String) continue;
            if (Environment.TickCount64 - client.LastAuthAt > 30000)
            {
                if (!await HasAccessAsync(client.Token, token)) return;
                client.LastAuthAt = Environment.TickCount64;
            }
            if (type.GetString() == "ping") { Enqueue(client, "{\"type\":\"pong\"}"); continue; }
            if (type.GetString() != "presence" || !root.TryGetProperty("payload", out JsonElement payload) || payload.ValueKind != JsonValueKind.Object) continue;
            long now = Environment.TickCount64;
            bool durable = payload.TryGetProperty("force", out JsonElement force) && force.ValueKind == JsonValueKind.True;
            if (!durable && client.LastPresenceAt != 0 && now - client.LastPresenceAt < 80) continue;
            client.LastPresenceAt = now;
            try
            {
                bool persist = durable || client.LastPersistAt == 0 || now - client.LastPersistAt >= 1000;
                var position = JsonNode.Parse(payload.GetRawText())!.AsObject();
                position["transient"] = !persist;
                using JsonDocument response = await ApiAsync(HttpMethod.Post, "api/v2/town/presence.php", client.Token, position.ToJsonString(), token);
                if (persist) client.LastPersistAt = now;
                if (!response.RootElement.TryGetProperty("self", out JsonElement self)) continue;
                Broadcast(new JsonObject { ["type"] = "presence_delta", ["player"] = JsonNode.Parse(self.GetRawText()) });
                if (root.TryGetProperty("request_id", out JsonElement requestId) && requestId.ValueKind == JsonValueKind.String)
                    Enqueue(client, JsonSerializer.Serialize(new { type = "ack", request_id = requestId.GetString(), ok = true }));
            }
            catch (HttpRequestException error) when (error.StatusCode is HttpStatusCode.BadRequest or HttpStatusCode.Conflict)
            { Enqueue(client, "{\"type\":\"position_rejected\"}"); }
        }
    }

    private async Task<bool> HasAccessAsync(string token, CancellationToken cancellationToken)
    {
        try
        {
            using JsonDocument user = await ApiAsync(HttpMethod.Get, "api/v1/auth/me.php", token, null, cancellationToken);
            using JsonDocument world = await ApiAsync(HttpMethod.Get, "api/v2/town/world.php", token, null, cancellationToken);
            return world.RootElement.TryGetProperty("enabled", out JsonElement enabled) && enabled.ValueKind == JsonValueKind.True;
        }
        catch (Exception error) when (error is HttpRequestException or JsonException) { return false; }
    }

    private async Task<JsonDocument> ApiAsync(HttpMethod method, string path, string token, string? payload, CancellationToken cancellationToken)
    {
        using var timeout = CancellationTokenSource.CreateLinkedTokenSource(cancellationToken);
        timeout.CancelAfter(TimeSpan.FromSeconds(10));
        using var http = clientFactory.CreateClient();
        using var request = new HttpRequestMessage(method, new Uri(_apiBase, path));
        request.Headers.Authorization = new("Bearer", token);
        if (payload is not null) request.Content = new StringContent(payload, Encoding.UTF8, "application/json");
        using HttpResponseMessage response = await http.SendAsync(request, timeout.Token);
        response.EnsureSuccessStatusCode();
        return await JsonDocument.ParseAsync(await response.Content.ReadAsStreamAsync(timeout.Token), cancellationToken: timeout.Token);
    }

    private static async Task WriteLoopAsync(Client client, CancellationToken token)
    {
        try
        {
            await foreach (string json in client.Outgoing.Reader.ReadAllAsync(token))
            {
                using var timeout = CancellationTokenSource.CreateLinkedTokenSource(token);
                timeout.CancelAfter(TimeSpan.FromSeconds(3));
                await client.Socket.SendAsync(Encoding.UTF8.GetBytes(json), WebSocketMessageType.Text, true, timeout.Token);
            }
        }
        finally { client.Socket.Abort(); }
    }

    private sealed class Client(WebSocket socket, string token)
    {
        internal Guid Id { get; } = Guid.NewGuid();
        internal WebSocket Socket { get; } = socket;
        internal string Token { get; } = token;
        internal long LastPresenceAt { get; set; }
        internal long LastPersistAt { get; set; }
        internal bool Ready { get; set; }
        internal Queue<string> Pending { get; } = new();
        internal long LastAuthAt { get; set; } = Environment.TickCount64;
        internal Channel<string> Outgoing { get; } = Channel.CreateBounded<string>(new BoundedChannelOptions(128)
        { FullMode = BoundedChannelFullMode.Wait, SingleReader = true, SingleWriter = false });
    }
}
