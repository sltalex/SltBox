using System.Net;
using System.Text;
using DeskBox.Services;

namespace DeskBox.Tests;

public sealed class DingTalkCalendarServiceTests
{
    [Fact]
    public async Task UpcomingEventsExcludeCancelledAndDeletedMeetings()
    {
        DateTimeOffset start = DateTimeOffset.Now.AddMinutes(30);
        var dws = new FakeDwsClient
        {
            Events =
            [
                new("active", "有效会议", start, start.AddMinutes(30), string.Empty),
                new("cancelled", "已取消会议", start.AddMinutes(1), start.AddMinutes(31), string.Empty, IsCancelled: true),
                new("deleted", "已删除会议", start.AddMinutes(2), start.AddMinutes(32), string.Empty, IsDeleted: true)
            ]
        };
        var account = new DingTalkAccountService(new FakeAccountApi());

        DingTalkCalendarSnapshot snapshot = await new DingTalkCalendarService(dws, account)
            .GetUpcomingCurrentWeekAsync();

        Assert.True(snapshot.IsBound);
        var meeting = Assert.Single(snapshot.UpcomingEvents);
        Assert.Equal("active", meeting.EventId);
    }

    [Fact]
    public void CalendarJsonAcceptsLifecycleFlagsInBooleanAndTextForms()
    {
        const string json = """
            {"events":[
              {"eventId":"cancelled","title":"取消","start":"2026-10-19 10:00","end":"2026-10-19 11:00","isCancelled":true},
              {"eventId":"deleted","title":"删除","start":"2026-10-19 10:00","end":"2026-10-19 11:00","status":"deleted"}
            ]}
            """;

        DwsCalendarResponseDto response =
            System.Text.Json.JsonSerializer.Deserialize(
                json, DingTalkDwsJsonContext.Default.DwsCalendarResponseDto)!;

        Assert.True(response.Events[0].IsCancelled.GetBoolean());
        Assert.Equal("deleted", response.Events[1].Status.GetString());
    }

    private sealed class FakeAccountApi : IAuthenticatedAccountApi
    {
        public Task<HttpResponseMessage> SendAsync(
            HttpMethod method, string relativePath, byte[]? jsonBody, string? contentEncoding,
            CancellationToken cancellationToken = default)
            => Task.FromResult(new HttpResponseMessage(HttpStatusCode.OK)
            {
                Content = new StringContent(
                    "{\"identity\":{\"corp_id\":\"corp\",\"dingtalk_user_id\":\"user\"}}",
                    Encoding.UTF8,
                    "application/json")
            });
    }

    private sealed class FakeDwsClient : IDingTalkDwsClient
    {
        public IReadOnlyList<DingTalkCalendarEvent> Events { get; init; } = [];

        public Task<DingTalkDwsAuthStatus> GetAuthStatusAsync(CancellationToken cancellationToken = default)
            => throw new NotSupportedException();

        public Task<DingTalkProfile> GetCurrentUserAsync(CancellationToken cancellationToken = default)
            => throw new NotSupportedException();

        public Task<DingTalkDwsAuthStatus> LoginAsync(CancellationToken cancellationToken = default)
            => throw new NotSupportedException();

        public Task<DingTalkGroupMessagePage> ListGroupMessagesAsync(
            DateTimeOffset start, DateTimeOffset end, string? cursor = null, int limit = 50,
            CancellationToken cancellationToken = default)
            => throw new NotSupportedException();

        public Task<string?> GetDocumentTitleAsync(string url, CancellationToken cancellationToken = default)
            => throw new NotSupportedException();

        public Task<IReadOnlyList<DingTalkCalendarEvent>> ListCurrentWeekCalendarAsync(
            CancellationToken cancellationToken = default)
            => Task.FromResult(Events);
    }
}
