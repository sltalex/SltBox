namespace DeskBox.Services;

public sealed record DingTalkCalendarSnapshot(
    bool IsBound,
    IReadOnlyList<DingTalkCalendarEvent> UpcomingEvents);

/// <summary>Loads the account's current-week calendar for the Todo widget.</summary>
public sealed class DingTalkCalendarService
{
    private readonly IDingTalkDwsClient _dws;
    private readonly DingTalkAccountService _account;

    internal DingTalkCalendarService(IDingTalkDwsClient dws, DingTalkAccountService account)
    {
        _dws = dws;
        _account = account;
    }

    public async Task<DingTalkCalendarSnapshot> GetUpcomingCurrentWeekAsync(
        CancellationToken cancellationToken = default)
    {
        DingTalkIdentityDto? identity = _account.CurrentIdentity;
        if (identity is null)
        {
            try
            {
                identity = await _account.GetAsync(cancellationToken);
            }
            catch (Exception ex) when (ex is AuthApiException or HttpRequestException or IOException or
                InvalidDataException or OperationCanceledException)
            {
                return new DingTalkCalendarSnapshot(false, []);
            }
        }

        if (identity is null)
        {
            return new DingTalkCalendarSnapshot(false, []);
        }

        IReadOnlyList<DingTalkCalendarEvent> events = await _dws.ListCurrentWeekCalendarAsync(cancellationToken);
        DateTimeOffset now = DateTimeOffset.Now;
        DateTimeOffset weekStart = now.Date.AddDays(-(int)now.DayOfWeek + 1);
        if (now.DayOfWeek == DayOfWeek.Sunday)
        {
            weekStart = now.Date.AddDays(-6);
        }

        DateTimeOffset weekEnd = weekStart.AddDays(7);
        return new DingTalkCalendarSnapshot(
            true,
            events
                .Where(item => !item.IsCancelled && !item.IsDeleted &&
                    item.Start >= now && item.Start < weekEnd)
                .OrderBy(item => item.Start)
                .ThenBy(item => item.Title, StringComparer.CurrentCultureIgnoreCase)
                .ToArray());
    }
}
