namespace DeskBox.Services;

public sealed class DingTalkDwsMessageReadException : InvalidOperationException
{
    public DingTalkDwsMessageReadException(string message)
        : base(message)
    {
    }
}

public sealed class DingTalkDwsAuthorizationRequiredException : InvalidOperationException
{
    public DingTalkDwsAuthorizationRequiredException(string message)
        : base(message)
    {
    }
}

public sealed record DingTalkDwsAuthStatus(
    bool Authenticated,
    bool TokenValid,
    bool RefreshTokenValid,
    string CorpId,
    string CorpName,
    string UserId,
    string UserName);

public sealed record DingTalkProfile(
    string CorpId,
    string DingtalkUserId,
    string DisplayName,
    string OrganizationName,
    string DepartmentName,
    string AvatarUrl);

public sealed record DingTalkGroupMessagePage(
    IReadOnlyList<DingTalkGroupMessage> Messages,
    bool HasMore,
    string? NextCursor);

public sealed record DingTalkGroupMessage(
    string ConversationId,
    string GroupName,
    string Content,
    DateTimeOffset CreatedAt,
    bool IsSingleChat);

public sealed record DingTalkCalendarEvent(
    string EventId,
    string Title,
    DateTimeOffset Start,
    DateTimeOffset End,
    string Location,
    bool IsCancelled = false,
    bool IsDeleted = false);

public interface IDingTalkDwsClient
{
    Task<DingTalkDwsAuthStatus> GetAuthStatusAsync(CancellationToken cancellationToken = default);
    Task<DingTalkProfile> GetCurrentUserAsync(CancellationToken cancellationToken = default);
    Task<DingTalkDwsAuthStatus> LoginAsync(CancellationToken cancellationToken = default);
    Task<DingTalkGroupMessagePage> ListGroupMessagesAsync(
        DateTimeOffset start,
        DateTimeOffset end,
        string? cursor = null,
        int limit = 50,
        CancellationToken cancellationToken = default);
    Task<string?> GetDocumentTitleAsync(string url, CancellationToken cancellationToken = default);
    Task<IReadOnlyList<DingTalkCalendarEvent>> ListCurrentWeekCalendarAsync(
        CancellationToken cancellationToken = default);
}
