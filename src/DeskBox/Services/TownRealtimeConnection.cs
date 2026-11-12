using System.Net.WebSockets;
using System.Collections.Concurrent;
using System.Text;
using System.Text.Json;

namespace DeskBox.Services;

/// <summary>A reconnecting transport bound to one authenticated town session.</summary>
internal sealed class TownRealtimeConnection : IDisposable
{
    private readonly Uri _endpoint;
    private readonly Func<CancellationToken, Task<string?>> _getToken;
    private readonly Func<CancellationToken, Task<WebSocket>>? _connect;
    private readonly TimeSpan _retryDelay;
    private readonly CancellationTokenSource _lifetime;
    private readonly SemaphoreSlim _sendGate = new(1, 1);
    private readonly object _gate = new();
    private WebSocket? _socket;
    private bool _disposed;
    private int _connected;
    private readonly ConcurrentDictionary<string, TaskCompletionSource<bool>> _receipts = new();
    private readonly TaskCompletionSource<bool> _firstAttempt = new(TaskCreationOptions.RunContinuationsAsynchronously);

    internal TownRealtimeConnection(Uri endpoint, Func<CancellationToken, Task<string?>> getToken,
        CancellationToken lifetime, Func<CancellationToken, Task<WebSocket>>? connect = null,
        TimeSpan? retryDelay = null)
    {
        _endpoint = endpoint;
        _getToken = getToken;
        _connect = connect;
        _retryDelay = retryDelay ?? TimeSpan.FromSeconds(2);
        _lifetime = CancellationTokenSource.CreateLinkedTokenSource(lifetime);
    }

    internal event Action<bool>? StateChanged;
    internal event Action<string>? Message;
    internal bool Connected => Volatile.Read(ref _connected) == 1;
    internal Task Completion { get; private set; } = Task.CompletedTask;

    internal Task<bool> Start()
    {
        lock (_gate)
        {
            if (_disposed) return Task.FromResult(false);
            if (Completion == Task.CompletedTask) Completion = RunAsync(_lifetime.Token);
            return _firstAttempt.Task;
        }
    }

    private async Task RunAsync(CancellationToken token)
    {
        int failures = 0;
        while (!token.IsCancellationRequested)
        {
            WebSocket? socket = null;
            try
            {
                using var timeout = CancellationTokenSource.CreateLinkedTokenSource(token);
                timeout.CancelAfter(TimeSpan.FromSeconds(15));
                if (_connect is not null) socket = await _connect(timeout.Token);
                else
                {
                    string? accessToken = await _getToken(timeout.Token);
                    if (string.IsNullOrWhiteSpace(accessToken)) return;
                    var client = new ClientWebSocket();
                    socket = client;
                    client.Options.SetRequestHeader("Authorization", $"Bearer {accessToken}");
                    client.Options.KeepAliveInterval = TimeSpan.FromSeconds(10);
                    client.Options.KeepAliveTimeout = TimeSpan.FromSeconds(10);
                    await client.ConnectAsync(_endpoint, timeout.Token);
                }
                token.ThrowIfCancellationRequested();
                lock (_gate)
                {
                    if (_disposed) return;
                    _socket = socket;
                    Interlocked.Exchange(ref _connected, 1);
                }
                StateChanged?.Invoke(true);
                _firstAttempt.TrySetResult(true);
                failures = 0;
                using var connection = CancellationTokenSource.CreateLinkedTokenSource(token);
                Task receive = ReceiveAsync(socket, connection.Token);
                Task heartbeat = HeartbeatAsync(connection.Token);
                await Task.WhenAny(receive, heartbeat);
                await connection.CancelAsync();
                socket.Abort();
                await Task.WhenAll(receive, heartbeat);
            }
            catch (Exception error) when (error is WebSocketException or HttpRequestException or OperationCanceledException or IOException or ObjectDisposedException or JsonException)
            { /* The session owns fallback; this loop retries with a fresh token. */ }
            finally
            {
                lock (_gate) if (ReferenceEquals(_socket, socket)) _socket = null;
                socket?.Dispose();
                foreach (var receipt in _receipts.Values) receipt.TrySetResult(false);
                if (Interlocked.Exchange(ref _connected, 0) == 1) StateChanged?.Invoke(false);
                _firstAttempt.TrySetResult(false);
            }
            if (token.IsCancellationRequested) break;
            try
            {
                double backoff = Math.Min(15, Math.Pow(2, failures++));
                await Task.Delay(TimeSpan.FromMilliseconds(Math.Min(30000, _retryDelay.TotalMilliseconds * backoff)), token);
            }
            catch (OperationCanceledException) { break; }
        }
    }

    private async Task ReceiveAsync(WebSocket socket, CancellationToken token)
    {
        byte[] buffer = new byte[8192];
        while (!token.IsCancellationRequested)
        {
            using var body = new MemoryStream();
            WebSocketReceiveResult result;
            do
            {
                result = await socket.ReceiveAsync(buffer, token);
                if (result.MessageType == WebSocketMessageType.Close) return;
                if (result.MessageType != WebSocketMessageType.Text) throw new InvalidDataException("Expected text frame.");
                body.Write(buffer, 0, result.Count);
                if (body.Length > 256 * 1024) throw new InvalidDataException("Town frame too large.");
            } while (!result.EndOfMessage);
            using JsonDocument document = JsonDocument.Parse(body.ToArray());
            if (document.RootElement.ValueKind != JsonValueKind.Object) continue;
            token.ThrowIfCancellationRequested();
            if (document.RootElement.TryGetProperty("type", out JsonElement type) && type.ValueKind == JsonValueKind.String && type.GetString() == "ack"
                && document.RootElement.TryGetProperty("request_id", out JsonElement id)
                && id.ValueKind == JsonValueKind.String && _receipts.TryGetValue(id.GetString()!, out var receipt))
                receipt.TrySetResult(document.RootElement.TryGetProperty("ok", out JsonElement ok) && ok.ValueKind == JsonValueKind.True);
            Message?.Invoke(document.RootElement.GetRawText());
        }
    }

    private async Task HeartbeatAsync(CancellationToken token)
    {
        while (!token.IsCancellationRequested)
        {
            await Task.Delay(TimeSpan.FromSeconds(10), token);
            if (!await SendAsync("{\"type\":\"ping\"}", token)) return;
        }
    }

    internal async Task<bool> SendAsync(string message, CancellationToken token)
    {
        using var timeout = CancellationTokenSource.CreateLinkedTokenSource(token, _lifetime.Token);
        timeout.CancelAfter(TimeSpan.FromSeconds(3));
        bool entered = false;
        WebSocket? socket = null;
        try
        {
            await _sendGate.WaitAsync(timeout.Token);
            entered = true;
            lock (_gate) socket = _socket;
            if (socket?.State != WebSocketState.Open) return false;
            await socket.SendAsync(Encoding.UTF8.GetBytes(message), WebSocketMessageType.Text, true, timeout.Token);
            return true;
        }
        catch (Exception error) when (error is WebSocketException or OperationCanceledException or ObjectDisposedException)
        {
            socket?.Abort();
            return false;
        }
        finally { if (entered) _sendGate.Release(); }
    }

    internal async Task<bool> SendDurablePresenceAsync(string payload, CancellationToken token)
    {
        string id = Guid.NewGuid().ToString("N");
        var receipt = new TaskCompletionSource<bool>(TaskCreationOptions.RunContinuationsAsynchronously);
        _receipts[id] = receipt;
        try
        {
            var position = System.Text.Json.Nodes.JsonNode.Parse(payload)!.AsObject();
            position["force"] = true;
            string json = "{\"type\":\"presence\",\"request_id\":\"" + id + "\",\"payload\":" + position.ToJsonString() + "}";
            if (!await SendAsync(json, token)) return false;
            return await receipt.Task.WaitAsync(TimeSpan.FromSeconds(10), token);
        }
        catch (Exception error) when (error is TimeoutException or OperationCanceledException) { return false; }
        finally { _receipts.TryRemove(id, out _); }
    }

    public void Dispose()
    {
        lock (_gate)
        {
            if (_disposed) return;
            _disposed = true;
            _lifetime.Cancel();
            _socket?.Abort();
        }
        // RunAsync observes cancellation before emitting any further message.
        _ = Completion.ContinueWith(_ => _lifetime.Dispose(), TaskScheduler.Default);
    }
}
