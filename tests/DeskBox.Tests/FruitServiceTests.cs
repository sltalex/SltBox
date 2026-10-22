using System.Net;
using System.Text;
using System.Text.Json;
using DeskBox.Contracts;
using DeskBox.Services;

namespace DeskBox.Tests;

public sealed class FruitServiceTests
{
    [Fact]
    public async Task FailedSaleAndPickRetryWithTheirOriginalOperationIds()
    {
        var auth = new FakeAuth("fruit-retry");
        var api = new FakeApi((_, path) => path.EndsWith("sell.php", StringComparison.Ordinal)
            || path.EndsWith("pick.php", StringComparison.Ordinal)
                ? Response(HttpStatusCode.ServiceUnavailable, "{}")
                : Response(HttpStatusCode.OK, "{}"));
        await using var service = new FruitService(auth, api);

        Assert.False(await service.SellAsync("apple", 2));
        Assert.False(await service.SellAsync("apple", 2));
        Assert.False((await service.PickAsync(42)).Success);
        Assert.False((await service.PickAsync(42)).Success);

        Assert.Equal(4, api.Bodies.Count);
        Assert.Equal(Operation(api.Bodies[0]), Operation(api.Bodies[1]));
        Assert.Equal(Operation(api.Bodies[2]), Operation(api.Bodies[3]));
    }

    [Fact]
    public async Task ResponseForPreviousAccountCannotReplaceCurrentFruitState()
    {
        var auth = new FakeAuth("account-a");
        var api = new FakeApi((_, path) =>
        {
            if (path.EndsWith("catalog.php", StringComparison.Ordinal))
                return Response(HttpStatusCode.OK, "{\"items\":[]}");
            auth.ChangeSilently("account-b");
            return Response(HttpStatusCode.OK,
                "{\"inventory\":[{\"fruit_id\":\"apple\",\"quantity\":3,\"discovered\":true}],\"history\":{\"apple\":[{\"day\":\"2026-10-22\",\"price\":18}]},\"on_tree\":[]}");
        });
        await using var service = new FruitService(auth, api);

        Assert.False(await service.RefreshAsync());
        Assert.Empty(service.Inventory);
        Assert.Empty(service.PriceHistory);
    }

    private static string Operation(byte[] body)
    {
        using JsonDocument json = JsonDocument.Parse(body);
        return json.RootElement.GetProperty("operation_id").GetString()!;
    }

    private static HttpResponseMessage Response(HttpStatusCode code, string json) => new(code)
    {
        Content = new StringContent(json, Encoding.UTF8, "application/json")
    };

    private sealed class FakeAuth(string id) : IAuthSession
    {
        public event Action? SessionChanged;
        public bool IsAuthenticated => CurrentUser is not null;
        public AuthUser? CurrentUser { get; private set; } = new(id, "Fruit");
        internal void ChangeSilently(string nextId) => CurrentUser = new(nextId, "Fruit");
        public Task<AuthUser> RegisterAsync(string username, string password, CancellationToken cancellationToken = default) => throw new NotSupportedException();
        public Task<AuthUser> SignInAsync(string username, string password, CancellationToken cancellationToken = default) => throw new NotSupportedException();
        public Task RefreshAsync(CancellationToken cancellationToken = default) => Task.CompletedTask;
        public Task RestoreAsync(CancellationToken cancellationToken = default) => Task.CompletedTask;
        public Task SignOutAsync(CancellationToken cancellationToken = default) => Task.CompletedTask;
    }

    private sealed class FakeApi(Func<HttpMethod, string, HttpResponseMessage> respond) : IAuthenticatedAccountApi
    {
        internal List<byte[]> Bodies { get; } = [];
        public Task<HttpResponseMessage> SendAsync(HttpMethod method, string relativePath, byte[]? jsonBody,
            string? contentEncoding, CancellationToken cancellationToken = default)
        {
            if (jsonBody is not null) Bodies.Add(jsonBody);
            return Task.FromResult(respond(method, relativePath));
        }
    }
}
