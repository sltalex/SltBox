using System.Text.Json;
using System.Text.Json.Serialization;

namespace DeskBox.Services;

internal sealed class DwsAuthStatusDto
{
    [JsonPropertyName("authenticated")] public bool Authenticated { get; set; }
    [JsonPropertyName("token_valid")] public bool TokenValid { get; set; }
    [JsonPropertyName("refresh_token_valid")] public bool RefreshTokenValid { get; set; }
    [JsonPropertyName("corp_id")] public string CorpId { get; set; } = string.Empty;
    [JsonPropertyName("corp_name")] public string CorpName { get; set; } = string.Empty;
    [JsonPropertyName("user_id")] public string UserId { get; set; } = string.Empty;
    [JsonPropertyName("user_name")] public string UserName { get; set; } = string.Empty;
}

internal sealed class DwsEmployeeResponseDto
{
    [JsonPropertyName("result")] public DwsEmployeeResultDto[] Result { get; set; } = [];
    [JsonPropertyName("success")] public bool Success { get; set; }
}

internal sealed class DwsEmployeeResultDto
{
    [JsonPropertyName("orgEmployeeModel")] public DwsEmployeeDto? Employee { get; set; }
}

internal sealed class DwsEmployeeDto
{
    [JsonPropertyName("corpId")] public string CorpId { get; set; } = string.Empty;
    [JsonPropertyName("userId")] public string UserId { get; set; } = string.Empty;
    [JsonPropertyName("orgUserName")] public string DisplayName { get; set; } = string.Empty;
    [JsonPropertyName("orgName")] public string OrganizationName { get; set; } = string.Empty;
    [JsonPropertyName("avatar")] public string AvatarUrl { get; set; } = string.Empty;
    [JsonPropertyName("depts")] public DwsDepartmentDto[] Departments { get; set; } = [];
}

internal sealed class DwsDepartmentDto
{
    [JsonPropertyName("deptName")] public string Name { get; set; } = string.Empty;
    [JsonPropertyName("deptPathName")] public string PathName { get; set; } = string.Empty;
}

internal sealed class DwsGroupMessagesResponseDto
{
    [JsonPropertyName("success")] public bool Success { get; set; }
    [JsonPropertyName("result")] public DwsGroupMessagesResultDto Result { get; set; } = new();
}

internal sealed class DwsGroupMessagesResultDto
{
    [JsonPropertyName("conversationMessagesList")] public DwsConversationMessagesDto[] Conversations { get; set; } = [];
    [JsonPropertyName("hasMore")] public bool HasMore { get; set; }
    [JsonPropertyName("nextCursor")] public string? NextCursor { get; set; }
}

internal sealed class DwsConversationMessagesDto
{
    [JsonPropertyName("openConversationId")] public string ConversationId { get; set; } = string.Empty;
    [JsonPropertyName("singleChat")] public bool IsSingleChat { get; set; }
    [JsonPropertyName("title")] public string Title { get; set; } = string.Empty;
    [JsonPropertyName("messages")] public DwsGroupMessageDto[] Messages { get; set; } = [];
}

internal sealed class DwsGroupMessageDto
{
    [JsonPropertyName("content")] public string Content { get; set; } = string.Empty;
    [JsonPropertyName("createTime")] public string CreateTime { get; set; } = string.Empty;
    [JsonPropertyName("openConversationId")] public string ConversationId { get; set; } = string.Empty;
}

internal sealed class DwsDriveInfoResponseDto
{
    [JsonPropertyName("success")] public bool Success { get; set; }
    [JsonPropertyName("result")] public DwsDriveInfoResultDto? Result { get; set; }
}

internal sealed class DwsDriveInfoResultDto
{
    [JsonPropertyName("success")] public bool Success { get; set; }
    [JsonPropertyName("name")] public string? Name { get; set; }
}

internal sealed class DwsCalendarResponseDto
{
    [JsonPropertyName("events")] public DwsCalendarEventDto[] Events { get; set; } = [];
}

internal sealed class DwsCalendarEventListResponseDto
{
    [JsonPropertyName("success")] public bool Success { get; set; }
    [JsonPropertyName("result")] public DwsCalendarEventListResultDto Result { get; set; } = new();
}

internal sealed class DwsCalendarEventListResultDto
{
    [JsonPropertyName("events")] public DwsCalendarEventDetailsDto[] Events { get; set; } = [];
}

internal sealed class DwsCalendarEventDetailsDto
{
    [JsonPropertyName("id")] public string Id { get; set; } = string.Empty;
    [JsonPropertyName("eventId")] public string EventId { get; set; } = string.Empty;
    [JsonPropertyName("summary")] public string Summary { get; set; } = string.Empty;
    [JsonPropertyName("title")] public string Title { get; set; } = string.Empty;
    [JsonPropertyName("start")] public DwsCalendarDateTimeDto Start { get; set; } = new();
    [JsonPropertyName("end")] public DwsCalendarDateTimeDto End { get; set; } = new();
    [JsonPropertyName("location")] public JsonElement Location { get; set; }
    [JsonPropertyName("meetingRooms")] public DwsCalendarMeetingRoomDto[] MeetingRooms { get; set; } = [];
    [JsonPropertyName("status")] public JsonElement Status { get; set; }
    [JsonPropertyName("eventStatus")] public JsonElement EventStatus { get; set; }
    [JsonPropertyName("state")] public JsonElement State { get; set; }
    [JsonPropertyName("isCancelled")] public JsonElement IsCancelled { get; set; }
    [JsonPropertyName("isCanceled")] public JsonElement IsCanceled { get; set; }
    [JsonPropertyName("isDeleted")] public JsonElement IsDeleted { get; set; }
    [JsonPropertyName("deleted")] public JsonElement Deleted { get; set; }
    [JsonPropertyName("cancelled")] public JsonElement Cancelled { get; set; }
    [JsonPropertyName("canceled")] public JsonElement Canceled { get; set; }
    [JsonPropertyName("is_cancelled")] public JsonElement IsCancelledSnakeCase { get; set; }
    [JsonPropertyName("is_canceled")] public JsonElement IsCanceledSnakeCase { get; set; }
    [JsonPropertyName("is_deleted")] public JsonElement IsDeletedSnakeCase { get; set; }
    [JsonPropertyName("event_status")] public JsonElement EventStatusSnakeCase { get; set; }
}

internal sealed class DwsCalendarDateTimeDto
{
    [JsonPropertyName("dateTime")] public string? DateTime { get; set; }
    [JsonPropertyName("date")] public string? Date { get; set; }
}

internal sealed class DwsCalendarMeetingRoomDto
{
    [JsonPropertyName("roomName")] public string? RoomName { get; set; }
    [JsonPropertyName("name")] public string? Name { get; set; }
}

internal sealed class DwsCalendarEventDto
{
    [JsonPropertyName("eventId")] public string EventId { get; set; } = string.Empty;
    [JsonPropertyName("title")] public string Title { get; set; } = string.Empty;
    [JsonPropertyName("start")] public string Start { get; set; } = string.Empty;
    [JsonPropertyName("end")] public string End { get; set; } = string.Empty;
    [JsonPropertyName("location")] public string? Location { get; set; }
    // DWS versions have used several names and both boolean and textual values
    // for lifecycle state. Keep these as JsonElement so older/newer responses
    // remain readable without making the whole calendar request fail.
    [JsonPropertyName("status")] public JsonElement Status { get; set; }
    [JsonPropertyName("eventStatus")] public JsonElement EventStatus { get; set; }
    [JsonPropertyName("state")] public JsonElement State { get; set; }
    [JsonPropertyName("isCancelled")] public JsonElement IsCancelled { get; set; }
    [JsonPropertyName("isCanceled")] public JsonElement IsCanceled { get; set; }
    [JsonPropertyName("isDeleted")] public JsonElement IsDeleted { get; set; }
    [JsonPropertyName("deleted")] public JsonElement Deleted { get; set; }
    [JsonPropertyName("cancelled")] public JsonElement Cancelled { get; set; }
    [JsonPropertyName("canceled")] public JsonElement Canceled { get; set; }
    [JsonPropertyName("is_cancelled")] public JsonElement IsCancelledSnakeCase { get; set; }
    [JsonPropertyName("is_canceled")] public JsonElement IsCanceledSnakeCase { get; set; }
    [JsonPropertyName("is_deleted")] public JsonElement IsDeletedSnakeCase { get; set; }
    [JsonPropertyName("event_status")] public JsonElement EventStatusSnakeCase { get; set; }
}

[JsonSourceGenerationOptions(GenerationMode = JsonSourceGenerationMode.Metadata)]
[JsonSerializable(typeof(DwsAuthStatusDto))]
[JsonSerializable(typeof(DwsEmployeeResponseDto))]
[JsonSerializable(typeof(DwsGroupMessagesResponseDto))]
[JsonSerializable(typeof(DwsDriveInfoResponseDto))]
[JsonSerializable(typeof(DwsCalendarResponseDto))]
[JsonSerializable(typeof(DwsCalendarEventListResponseDto))]
internal sealed partial class DingTalkDwsJsonContext : JsonSerializerContext
{
}
