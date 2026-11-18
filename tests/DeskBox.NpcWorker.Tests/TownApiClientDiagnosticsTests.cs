using System.Net;
using System.Text;
using System.Text.Json;
using Microsoft.Extensions.Configuration;

namespace DeskBox.NpcWorker.Tests;

public sealed class TownApiClientDiagnosticsTests
{
    [Fact]
    public void PhotoMetadataFallbackContainsNoImageBytes()
    {
        PhotoFactResult result = PhotoFactExtractor.MetadataFallback("image/png", 320, 200);

        Assert.Equal("metadata_fallback", result.Mode);
        Assert.Contains("PNG", result.ExtractedText, StringComparison.Ordinal);
        Assert.Contains("320×200", result.FactSummary, StringComparison.Ordinal);
        Assert.DoesNotContain("iVBOR", result.ExtractedText, StringComparison.Ordinal);
    }

    [Fact]
    public async Task ActionFailureIncludesBoundedServerErrorBody()
    {
        using var handler = new StaticResponseHandler(
            HttpStatusCode.BadRequest,
            "{\"code\":\"work_point_busy\",\"message\":\"工作点正在被其他村民使用。\"}");
        using var http = new HttpClient(handler) { BaseAddress = new Uri("https://town.test/") };
        var configuration = new ConfigurationBuilder()
            .AddInMemoryCollection(new Dictionary<string, string?>
            {
                ["NPC_WORKER_TOKEN"] = " token ",
                ["NPC_WORKER_ID"] = " worker-1 "
            })
            .Build();
        var api = new TownApiClient(http, configuration);
        var npc = new Npc(
            "npc-1", "测试居民", "farmer", true, false,
            new NpcRuntime(1, 1, "rest", null, Array.Empty<string>()),
            JsonSerializer.SerializeToElement(Array.Empty<object>()),
            JsonSerializer.SerializeToElement(new { }),
            DecisionPolicy: new NpcDecisionPolicy(60, 300, 60, 60));
        var action = new PlannedAction("work", "farm-1", JsonSerializer.SerializeToElement(new { job = "water_crop" }), "test");

        HttpRequestException error = await Assert.ThrowsAsync<HttpRequestException>(
            () => api.ExecuteAsync(npc, action, null, CancellationToken.None));

        Assert.Contains("actions.php", error.Message, StringComparison.Ordinal);
        Assert.Contains("400", error.Message, StringComparison.Ordinal);
        Assert.Contains("work_point_busy", error.Message, StringComparison.Ordinal);
        Assert.Contains("工作点正在被其他村民使用", error.Message, StringComparison.Ordinal);
    }

    [Fact]
    public async Task RecoverySnapshotRequestReturnsSnapshotId()
    {
        using var handler = new StaticResponseHandler(
            HttpStatusCode.OK,
            "{\"ok\":true,\"snapshot\":{\"snapshot_id\":\"0123456789abcdef0123456789abcdef\"}}");
        using var http = new HttpClient(handler) { BaseAddress = new Uri("https://town.test/") };
        var configuration = new ConfigurationBuilder()
            .AddInMemoryCollection(new Dictionary<string, string?>
            {
                ["NPC_WORKER_TOKEN"] = "worker-token",
                ["NPC_WORKER_ID"] = "worker-1"
            })
            .Build();
        var api = new TownApiClient(http, configuration);

        string? snapshotId = await api.CreateWorldSnapshotAsync(CancellationToken.None);

        Assert.Equal("0123456789abcdef0123456789abcdef", snapshotId);
        Assert.Equal("api/v2/town/agent/snapshot.php", handler.LastRequest?.RequestUri?.AbsolutePath.TrimStart('/'));
    }

    private sealed class StaticResponseHandler(HttpStatusCode status, string body) : HttpMessageHandler
    {
        public HttpRequestMessage? LastRequest { get; private set; }

        protected override Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken)
        {
            LastRequest = request;
            return Task.FromResult(new HttpResponseMessage(status)
            {
                RequestMessage = request,
                Content = new StringContent(body, Encoding.UTF8, "application/json")
            });
        }
    }
}
