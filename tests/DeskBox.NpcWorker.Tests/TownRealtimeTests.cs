using System.Collections.Concurrent;
using System.Net;
using System.Net.WebSockets;
using System.Text;
using System.Text.Json;
using System.Threading.Channels;
using DeskBox.Services;
using Microsoft.AspNetCore.Builder;
using Microsoft.AspNetCore.Hosting;
using Microsoft.AspNetCore.Hosting.Server;
using Microsoft.AspNetCore.Hosting.Server.Features;
using Microsoft.Extensions.Configuration;
using Microsoft.Extensions.DependencyInjection;
using Microsoft.Extensions.Logging;

namespace DeskBox.NpcWorker.Tests;

public sealed class TownRealtimeTests
{
    [Fact]
    public async Task UnauthorizedAndDisabledAccountsCannotConnect()
    {
        await using var host = await TestHost.StartAsync();
        foreach (string? token in new string?[] { null, "expired", "disabled" })
        {
            using var socket = new ClientWebSocket();
            if (token is not null) socket.Options.SetRequestHeader("Authorization", "Bearer " + token);
            await Assert.ThrowsAsync<WebSocketException>(() => socket.ConnectAsync(host.Endpoint, CancellationToken.None));
        }
        Assert.Empty(host.Api.Moves);
    }

    [Fact]
    public async Task FragmentedMovesBroadcastOnlyAuthenticatedSelfAndPreservePixels()
    {
        await using var host = await TestHost.StartAsync();
        using var a = await host.ConnectAsync("a");
        using var b = await host.ConnectAsync("b");
        const string move = "{\"type\":\"presence\",\"payload\":{\"user_id\":\"spoofed\",\"tile_x\":100,\"tile_y\":120,\"pixel_x\":1608.25,\"pixel_y\":1935.5,\"force\":true}}";
        byte[] bytes = Encoding.UTF8.GetBytes(move);
        await a.SendAsync(bytes.AsMemory(0, 30), WebSocketMessageType.Text, false, CancellationToken.None);
        await a.SendAsync(bytes.AsMemory(30), WebSocketMessageType.Text, true, CancellationToken.None);
        using JsonDocument delta = await ReceiveAsync(b);
        Assert.Equal("presence_delta", delta.RootElement.GetProperty("type").GetString());
        Assert.False(delta.RootElement.TryGetProperty("presence", out _));
        Assert.Equal("a", delta.RootElement.GetProperty("player").GetProperty("user_id").GetString());
        Assert.Equal(1608.25, delta.RootElement.GetProperty("player").GetProperty("pixel_x").GetDouble());
        Assert.Equal(1935.5, delta.RootElement.GetProperty("player").GetProperty("pixel_y").GetDouble());
        Assert.False(host.Api.Moves.Single().GetProperty("transient").GetBoolean());
    }

    [Fact]
    public async Task TransientMoveIsValidatedWithoutPersistentWriteAndFinalMoveHasReceipt()
    {
        await using var host = await TestHost.StartAsync();
        using var a = await host.ConnectAsync("a");
        using var b = await host.ConnectAsync("b");
        await SendAsync(a, "{\"type\":\"presence\",\"payload\":{\"tile_x\":100,\"tile_y\":120}}");
        using var first = await ReceiveAsync(b);
        await Task.Delay(120);
        await SendAsync(a, "{\"type\":\"presence\",\"payload\":{\"tile_x\":101,\"tile_y\":120}}");
        using var second = await ReceiveAsync(b);
        Assert.True(host.Api.Moves.Last().GetProperty("transient").GetBoolean());
        await SendAsync(a, "{\"type\":\"presence\",\"request_id\":\"final\",\"payload\":{\"tile_x\":102,\"tile_y\":120,\"force\":true}}");
        using var third = await ReceiveAsync(b);
        using JsonDocument receipt = await ReceiveTypeAsync(a, "ack");
        Assert.Equal("final", receipt.RootElement.GetProperty("request_id").GetString());
        Assert.False(host.Api.Moves.Last().GetProperty("transient").GetBoolean());
        Assert.True(third.RootElement.GetProperty("sequence").GetInt64() > second.RootElement.GetProperty("sequence").GetInt64());
    }

    [Fact]
    public async Task NpcRouteAndDialogueAreDeliveredToEveryPeer()
    {
        await using var host = await TestHost.StartAsync();
        using var a = await host.ConnectAsync("a");
        using var b = await host.ConnectAsync("b");
        using JsonDocument action = JsonDocument.Parse("{\"npc_id\":\"npc-1\",\"state\":\"walk\",\"route\":[{\"x\":1,\"y\":2},{\"x\":2,\"y\":2}],\"dialogue\":\"去浇水\",\"mood\":{\"label\":\"高兴\"},\"navigation_version\":12}");
        await host.Hub.BroadcastNpcActionAsync(action.RootElement);
        foreach (var socket in new[] { a, b })
        {
            using JsonDocument message = await ReceiveAsync(socket);
            Assert.Equal("npc_state", message.RootElement.GetProperty("type").GetString());
            Assert.Equal(2, message.RootElement.GetProperty("route").GetArrayLength());
            Assert.Equal("去浇水", message.RootElement.GetProperty("dialogue").GetString());
            Assert.Equal(12, message.RootElement.GetProperty("navigation_version").GetInt32());
        }
        Assert.True(host.Hub.Metrics.ActiveConnections >= 2);
        Assert.True(host.Hub.Metrics.AcceptedConnections >= 2);
        Assert.True(host.Hub.Metrics.Broadcasts >= 1);
    }

    [Fact]
    public async Task ReconnectObtainsFreshTokenAndSnapshotAndDurableSaveWaitsForServer()
    {
        await using var host = await TestHost.StartAsync();
        using var lifetime = new CancellationTokenSource();
        int tokenReads = 0;
        using var connection = new TownRealtimeConnection(host.Endpoint,
            _ => Task.FromResult<string?>(Interlocked.Increment(ref tokenReads) == 1 ? "a" : "b"),
            lifetime.Token, retryDelay: TimeSpan.FromMilliseconds(10));
        var snapshots = Channel.CreateUnbounded<string>();
        var disconnect = new TaskCompletionSource(TaskCreationOptions.RunContinuationsAsynchronously);
        connection.Message += json => { if (json.Contains("\"snapshot\"")) snapshots.Writer.TryWrite(json); };
        connection.StateChanged += connected => { if (!connected) disconnect.TrySetResult(); };
        Assert.True(await connection.Start());
        await snapshots.Reader.ReadAsync().AsTask().WaitAsync(TimeSpan.FromSeconds(5));
        Assert.True(await connection.SendAsync("{", CancellationToken.None));
        await disconnect.Task.WaitAsync(TimeSpan.FromSeconds(5));
        await snapshots.Reader.ReadAsync().AsTask().WaitAsync(TimeSpan.FromSeconds(5));
        Assert.True(tokenReads >= 2);
        Assert.True(await connection.SendDurablePresenceAsync("{\"tile_x\":101,\"tile_y\":120}", CancellationToken.None));
        Assert.Equal("b", host.Api.LastMover);
        lifetime.Cancel();
        await connection.Completion.WaitAsync(TimeSpan.FromSeconds(5));
        Assert.False(connection.Connected);
    }

    [Fact]
    public async Task InitialHandshakeFailureRetriesAndCancellationStopsFurtherMessages()
    {
        await using var host = await TestHost.StartAsync();
        using var lifetime = new CancellationTokenSource();
        int attempts = 0;
        using var connection = new TownRealtimeConnection(host.Endpoint,
            _ => Task.FromResult<string?>(Interlocked.Increment(ref attempts) == 1 ? "expired" : "a"),
            lifetime.Token, retryDelay: TimeSpan.FromMilliseconds(10));
        var snapshot = new TaskCompletionSource(TaskCreationOptions.RunContinuationsAsynchronously);
        int messages = 0;
        connection.Message += _ => { Interlocked.Increment(ref messages); snapshot.TrySetResult(); };
        Assert.False(await connection.Start());
        await snapshot.Task.WaitAsync(TimeSpan.FromSeconds(5));
        Assert.True(connection.Connected);
        lifetime.Cancel();
        await connection.Completion.WaitAsync(TimeSpan.FromSeconds(5));
        int count = messages;
        using JsonDocument action = JsonDocument.Parse("{\"npc_id\":\"npc-1\",\"state\":\"rest\"}");
        await host.Hub.BroadcastNpcActionAsync(action.RootElement);
        Assert.Equal(count, messages);
        Assert.False(connection.Connected);
    }

    [Fact]
    public async Task RejectedMovementDoesNotBroadcastAndDoesNotBreakOtherPeers()
    {
        await using var host = await TestHost.StartAsync();
        using var a = await host.ConnectAsync("a");
        using var b = await host.ConnectAsync("b");
        await SendAsync(a, "{\"type\":\"presence\",\"payload\":{\"tile_x\":-1,\"tile_y\":0}}");
        using var rejection = await ReceiveAsync(a);
        Assert.Equal("position_rejected", rejection.RootElement.GetProperty("type").GetString());
        using JsonDocument action = JsonDocument.Parse("{\"npc_id\":\"npc-1\",\"state\":\"rest\"}");
        await host.Hub.BroadcastNpcActionAsync(action.RootElement);
        using var next = await ReceiveAsync(b);
        Assert.Equal("npc_state", next.RootElement.GetProperty("type").GetString());
    }

    [Fact]
    public async Task MultiplePeersReceiveBurstWithoutDroppedConnections()
    {
        await using var host = await TestHost.StartAsync();
        var peers = new List<ClientWebSocket>();
        try
        {
            for (int i = 0; i < 8; i++) peers.Add(await host.ConnectAsync("peer-" + i));
            const int broadcasts = 24;
            for (int i = 0; i < broadcasts; i++)
            {
                using JsonDocument action = JsonDocument.Parse($"{{\"npc_id\":\"npc-1\",\"state\":\"walk\",\"tile_x\":{i},\"tile_y\":2}}");
                await host.Hub.BroadcastNpcActionAsync(action.RootElement);
            }
            foreach (ClientWebSocket peer in peers)
                for (int i = 0; i < broadcasts; i++)
                {
                    using JsonDocument message = await ReceiveAsync(peer);
                    Assert.Equal("npc_state", message.RootElement.GetProperty("type").GetString());
                }
            Assert.True(host.Hub.Metrics.ActiveConnections >= peers.Count);
            Assert.True(host.Hub.Metrics.Broadcasts >= broadcasts);
            Assert.Equal(0, host.Hub.Metrics.DroppedConnections);
        }
        finally
        {
            foreach (ClientWebSocket peer in peers) peer.Dispose();
        }
    }

    private static Task SendAsync(ClientWebSocket socket, string text) => socket.SendAsync(Encoding.UTF8.GetBytes(text), WebSocketMessageType.Text, true, CancellationToken.None);

    private static async Task<JsonDocument> ReceiveTypeAsync(ClientWebSocket socket, string type)
    {
        for (int i = 0; i < 8; i++)
        {
            JsonDocument message = await ReceiveAsync(socket);
            if (message.RootElement.GetProperty("type").GetString() == type) return message;
            message.Dispose();
        }
        throw new InvalidOperationException("Missing frame: " + type);
    }

    private static async Task<JsonDocument> ReceiveAsync(ClientWebSocket socket)
    {
        using var timeout = new CancellationTokenSource(TimeSpan.FromSeconds(5));
        using var body = new MemoryStream();
        byte[] buffer = new byte[1024];
        WebSocketReceiveResult result;
        do
        {
            result = await socket.ReceiveAsync(buffer, timeout.Token);
            if (result.MessageType == WebSocketMessageType.Close) throw new InvalidOperationException("Peer closed.");
            body.Write(buffer, 0, result.Count);
        } while (!result.EndOfMessage);
        return JsonDocument.Parse(body.ToArray());
    }

    private sealed class TestHost(WebApplication app, Uri endpoint, FakeApi api) : IAsyncDisposable
    {
        internal Uri Endpoint => endpoint;
        internal FakeApi Api => api;
        internal TownRealtimeHub Hub => app.Services.GetRequiredService<TownRealtimeHub>();

        internal static async Task<TestHost> StartAsync()
        {
            var builder = WebApplication.CreateBuilder();
            builder.Logging.ClearProviders();
            builder.Configuration.AddInMemoryCollection(new Dictionary<string, string?> { ["TOWN_API_BASE_URL"] = "http://town.test/" });
            builder.WebHost.ConfigureKestrel(options => options.Listen(IPAddress.Loopback, 0));
            var api = new FakeApi();
            builder.Services.AddHttpClient().ConfigureHttpClientDefaults(client => client.ConfigurePrimaryHttpMessageHandler(() => api));
            builder.Services.AddSingleton<TownRealtimeHub>();
            var app = builder.Build();
            app.UseWebSockets();
            app.Map("/ws/town", context => context.RequestServices.GetRequiredService<TownRealtimeHub>().HandleAsync(context));
            await app.StartAsync();
            string address = app.Services.GetRequiredService<IServer>().Features.Get<IServerAddressesFeature>()!.Addresses.Single();
            return new TestHost(app, new Uri(address.Replace("http:", "ws:") + "/ws/town"), api);
        }

        internal async Task<ClientWebSocket> ConnectAsync(string token)
        {
            var socket = new ClientWebSocket();
            socket.Options.SetRequestHeader("Authorization", "Bearer " + token);
            await socket.ConnectAsync(endpoint, CancellationToken.None);
            using JsonDocument snapshot = await ReceiveAsync(socket);
            Assert.Equal("snapshot", snapshot.RootElement.GetProperty("type").GetString());
            return socket;
        }

        public async ValueTask DisposeAsync() { await app.StopAsync(); await app.DisposeAsync(); }
    }

    private sealed class FakeApi : HttpMessageHandler
    {
        internal ConcurrentQueue<JsonElement> Moves { get; } = new();
        internal string? LastMover;
        protected override async Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken token)
        {
            string user = request.Headers.Authorization?.Parameter ?? "";
            if (user == "expired") return new(HttpStatusCode.Unauthorized);
            string path = request.RequestUri!.AbsolutePath;
            string json;
            if (path.EndsWith("world.php")) json = user == "disabled" ? "{\"enabled\":false}" : "{\"enabled\":true}";
            else if (path.EndsWith("snapshot.php")) json = "{\"npcs\":[],\"cursor\":0,\"navigation_version\":1}";
            else if (request.Method == HttpMethod.Post)
            {
                using JsonDocument body = JsonDocument.Parse(await request.Content!.ReadAsStringAsync(token));
                JsonElement value = body.RootElement;
                if (value.GetProperty("tile_x").GetInt32() < 0) return new(HttpStatusCode.BadRequest);
                Moves.Enqueue(value.Clone()); LastMover = user;
                json = JsonSerializer.Serialize(new { self = new { user_id = user, tile_x = value.GetProperty("tile_x").GetInt32(), tile_y = value.GetProperty("tile_y").GetInt32(), pixel_x = value.TryGetProperty("pixel_x", out var px) ? px.GetDouble() : 1616, pixel_y = value.TryGetProperty("pixel_y", out var py) ? py.GetDouble() : 1935 } });
            }
            else json = path.EndsWith("me.php") ? "{\"user\":{\"id\":\"a\"}}" : "{\"presence\":[]}";
            return new(HttpStatusCode.OK) { Content = new StringContent(json, Encoding.UTF8, "application/json") };
        }
    }
}
