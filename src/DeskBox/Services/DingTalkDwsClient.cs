using System.Diagnostics;
using System.Globalization;
using System.Runtime.InteropServices;
using System.Text.Json;
using System.Text.Json.Serialization.Metadata;

namespace DeskBox.Services;

internal sealed class DingTalkDwsClient : IDingTalkDwsClient
{
    private const int MaximumOutputCharacters = 256 * 1024;
    private readonly string _executablePath;
    private readonly TimeSpan _timeout;

    internal DingTalkDwsClient(string? executablePath = null, TimeSpan? timeout = null)
    {
        _executablePath = executablePath ?? ResolveExecutablePath();
        _timeout = timeout ?? TimeSpan.FromSeconds(45);
    }

    public async Task<DingTalkDwsAuthStatus> GetAuthStatusAsync(CancellationToken cancellationToken = default)
    {
        DwsAuthStatusDto dto = await RunJsonAsync(
            ["auth", "status", "--format", "json"],
            DingTalkDwsJsonContext.Default.DwsAuthStatusDto,
            cancellationToken);
        return new DingTalkDwsAuthStatus(
            dto.Authenticated, dto.TokenValid, dto.RefreshTokenValid,
            dto.CorpId, dto.CorpName, dto.UserId, dto.UserName);
    }

    public async Task<DingTalkDwsAuthStatus> LoginAsync(CancellationToken cancellationToken = default)
    {
        _ = await RunJsonAsync(
            ["auth", "login", "--format", "json"],
            DingTalkDwsJsonContext.Default.DwsAuthStatusDto,
            cancellationToken);
        return await GetAuthStatusAsync(cancellationToken);
    }

    public async Task<DingTalkProfile> GetCurrentUserAsync(CancellationToken cancellationToken = default)
    {
        DwsEmployeeResponseDto response = await RunJsonAsync(
            ["contact", "user", "get-self", "--format", "json"],
            DingTalkDwsJsonContext.Default.DwsEmployeeResponseDto,
            cancellationToken);
        if (!response.Success || response.Result.Length != 1)
            throw new InvalidDataException("DWS returned an ambiguous or unsuccessful identity response.");
        DwsEmployeeDto employee = response.Result.Single().Employee
            ?? throw new InvalidDataException("DWS did not return the current DingTalk user.");
        string department = employee.Departments.FirstOrDefault()?.PathName
            ?? employee.Departments.FirstOrDefault()?.Name
            ?? string.Empty;
        if (string.IsNullOrWhiteSpace(employee.CorpId) || string.IsNullOrWhiteSpace(employee.UserId))
            throw new InvalidDataException("DWS returned an incomplete DingTalk identity.");
        return new DingTalkProfile(
            employee.CorpId.Trim(), employee.UserId.Trim(), employee.DisplayName.Trim(),
            employee.OrganizationName.Trim(), department.Trim(), employee.AvatarUrl.Trim());
    }

    public async Task<DingTalkGroupMessagePage> ListGroupMessagesAsync(
        DateTimeOffset start,
        DateTimeOffset end,
        string? cursor = null,
        int limit = 50,
        CancellationToken cancellationToken = default)
    {
        if (end <= start) throw new ArgumentException("The end time must be after the start time.", nameof(end));
        limit = Math.Clamp(limit, 1, 50);
        var arguments = new List<string>
        {
            "chat", "message", "list-all",
            "--start", start.ToLocalTime().ToString("yyyy-MM-dd HH:mm:ss", CultureInfo.InvariantCulture),
            "--end", end.ToLocalTime().ToString("yyyy-MM-dd HH:mm:ss", CultureInfo.InvariantCulture),
            "--limit", limit.ToString(CultureInfo.InvariantCulture),
            "--cursor", string.IsNullOrWhiteSpace(cursor) ? "0" : cursor,
            "--format", "json"
        };
        DwsGroupMessagesResponseDto response = await RunJsonAsync(
            arguments,
            DingTalkDwsJsonContext.Default.DwsGroupMessagesResponseDto,
            cancellationToken);
        if (!response.Success)
            throw new DingTalkDwsMessageReadException("DWS could not read DingTalk group messages.");

        return ToGroupMessagePage(response, start);
    }

    internal static DingTalkGroupMessagePage ToGroupMessagePage(
        DwsGroupMessagesResponseDto response, DateTimeOffset start)
    {
        var messages = new List<DingTalkGroupMessage>();
        foreach (DwsConversationMessagesDto conversation in response.Result.Conversations)
        {
            foreach (DwsGroupMessageDto message in conversation.Messages)
            {
                if (!DateTimeOffset.TryParse(
                        message.CreateTime,
                        CultureInfo.InvariantCulture,
                        DateTimeStyles.AssumeLocal,
                        out DateTimeOffset createdAt))
                {
                    createdAt = start;
                }

                messages.Add(new DingTalkGroupMessage(
                    string.IsNullOrWhiteSpace(message.ConversationId)
                        ? conversation.ConversationId
                        : message.ConversationId,
                    conversation.Title.Trim(),
                    message.Content,
                    createdAt,
                    conversation.IsSingleChat)
                {
                    Resources = (message.Resources ?? [])
                        .Where(resource => !string.IsNullOrWhiteSpace(resource.Url))
                        .Select(resource => new DingTalkGroupMessageResource(
                            resource.Url, resource.ResourceType, resource.ResourceId))
                        .ToArray()
                });
            }
        }

        return new DingTalkGroupMessagePage(messages, response.Result.HasMore, response.Result.NextCursor);
    }

    public async Task<string?> GetDocumentTitleAsync(string url, CancellationToken cancellationToken = default)
    {
        DwsDriveInfoResponseDto response = await RunJsonAsync(
            ["drive", "info", "--node", url, "--format", "json"],
            DingTalkDwsJsonContext.Default.DwsDriveInfoResponseDto,
            cancellationToken,
            TimeSpan.FromSeconds(8));
        if (!response.Success || response.Result?.Success != true)
            return null;
        string? name = response.Result.Name?.Trim();
        return name?.Length is > 0 and <= 255 ? name : null;
    }

    public async Task<IReadOnlyList<DingTalkCalendarEvent>> ListCurrentWeekCalendarAsync(
        CancellationToken cancellationToken = default)
    {
        DateTimeOffset now = DateTimeOffset.Now;
        DateTimeOffset weekStart = now.Date.AddDays(-(int)now.DayOfWeek + 1);
        if (now.DayOfWeek == DayOfWeek.Sunday)
            weekStart = now.Date.AddDays(-6);
        DateTimeOffset weekEnd = weekStart.AddDays(7);
        DwsCalendarEventListResponseDto response = await RunJsonAsync(
            ["calendar", "event", "list",
                "--start", weekStart.ToString("yyyy-MM-ddTHH:mm:sszzz", CultureInfo.InvariantCulture),
                "--end", weekEnd.ToString("yyyy-MM-ddTHH:mm:sszzz", CultureInfo.InvariantCulture),
                "--limit", "100", "--format", "json"],
            DingTalkDwsJsonContext.Default.DwsCalendarEventListResponseDto,
            cancellationToken,
            TimeSpan.FromSeconds(20));
        if (!response.Success)
            throw new InvalidDataException("DWS returned an unsuccessful calendar response.");

        var events = new List<DingTalkCalendarEvent>(response.Result.Events.Length);
        foreach (DwsCalendarEventDetailsDto item in response.Result.Events)
        {
            string startText = item.Start.DateTime ?? item.Start.Date ?? string.Empty;
            string endText = item.End.DateTime ?? item.End.Date ?? string.Empty;
            if (!DateTimeOffset.TryParse(
                    startText,
                    CultureInfo.InvariantCulture,
                    DateTimeStyles.AssumeLocal,
                    out DateTimeOffset start) ||
                !DateTimeOffset.TryParse(
                    endText,
                    CultureInfo.InvariantCulture,
                    DateTimeStyles.AssumeLocal,
                    out DateTimeOffset end) ||
                end <= start)
            {
                continue;
            }

            bool isCancelled = IsTruthyFlag(item.IsCancelled, item.IsCanceled, item.Cancelled,
                item.Canceled, item.IsCancelledSnakeCase, item.IsCanceledSnakeCase) ||
                IsLifecycleState(item.Status, item.EventStatus, item.State, item.EventStatusSnakeCase,
                    "cancelled", "canceled", "cancelled_event", "cancelledEvent");
            bool isDeleted = IsTruthyFlag(item.IsDeleted, item.Deleted, item.IsDeletedSnakeCase) ||
                IsLifecycleState(item.Status, item.EventStatus, item.State, item.EventStatusSnakeCase,
                    "deleted", "removed", "deleted_event", "deletedEvent");

            events.Add(new DingTalkCalendarEvent(
                (string.IsNullOrWhiteSpace(item.Id) ? item.EventId : item.Id).Trim(),
                string.IsNullOrWhiteSpace(item.Summary) ?
                    (string.IsNullOrWhiteSpace(item.Title) ? "(无标题会议)" : item.Title.Trim()) : item.Summary.Trim(),
                start,
                end,
                CalendarLocation(item),
                isCancelled,
                isDeleted)
            {
                AttendeeNames = CalendarAttendeeNames(item)
            });
        }

        return events;
    }

    internal static IReadOnlyList<string> CalendarAttendeeNames(DwsCalendarEventDetailsDto item) =>
        item.Attendees?
            .Select(attendee => attendee.DisplayName?.Trim())
            .Where(name => !string.IsNullOrWhiteSpace(name))
            .Select(name => name!)
            .ToArray() ?? [];

    private static string CalendarLocation(DwsCalendarEventDetailsDto item)
    {
        if (item.Location.ValueKind == JsonValueKind.String)
            return item.Location.GetString()?.Trim() ?? string.Empty;
        if (item.Location.ValueKind == JsonValueKind.Object)
        {
            foreach (string key in new[] { "displayName", "name", "address" })
                if (item.Location.TryGetProperty(key, out JsonElement value) && value.ValueKind == JsonValueKind.String)
                    return value.GetString()?.Trim() ?? string.Empty;
        }
        return item.MeetingRooms
            .Select(room => string.IsNullOrWhiteSpace(room.RoomName) ? room.Name : room.RoomName)
            .FirstOrDefault(value => !string.IsNullOrWhiteSpace(value))?.Trim() ?? string.Empty;
    }

    private static bool IsTruthyFlag(params JsonElement[] values) => values.Any(IsTruthy);

    private static bool IsTruthy(JsonElement value)
    {
        if (value.ValueKind == JsonValueKind.True)
            return true;
        if (value.ValueKind is JsonValueKind.False or JsonValueKind.Null or JsonValueKind.Undefined)
            return false;
        if (value.ValueKind == JsonValueKind.Number && value.TryGetInt32(out int number))
            return number != 0;
        if (value.ValueKind == JsonValueKind.String)
        {
            string text = value.GetString()?.Trim() ?? string.Empty;
            return text.Equals("true", StringComparison.OrdinalIgnoreCase) ||
                text.Equals("yes", StringComparison.OrdinalIgnoreCase) ||
                text.Equals("1", StringComparison.OrdinalIgnoreCase);
        }
        return false;
    }

    private static bool IsLifecycleState(JsonElement first, JsonElement second, JsonElement third,
        JsonElement fourth, params string[] inactiveStates)
    {
        foreach (JsonElement value in new[] { first, second, third, fourth })
        {
            if (value.ValueKind != JsonValueKind.String)
                continue;
            string state = value.GetString()?.Trim() ?? string.Empty;
            if (inactiveStates.Any(item => state.Equals(item, StringComparison.OrdinalIgnoreCase)))
                return true;
        }
        return false;
    }

    private async Task<T> RunJsonAsync<T>(
        IReadOnlyList<string> arguments,
        JsonTypeInfo<T> jsonTypeInfo,
        CancellationToken cancellationToken,
        TimeSpan? operationTimeout = null)
    {
        if (!File.Exists(_executablePath))
            throw new FileNotFoundException("The bundled DWS runtime is not available.", _executablePath);

        var startInfo = new ProcessStartInfo
        {
            FileName = _executablePath,
            WorkingDirectory = Path.GetDirectoryName(_executablePath) ?? AppContext.BaseDirectory,
            UseShellExecute = false,
            CreateNoWindow = true,
            RedirectStandardOutput = true,
            RedirectStandardError = true,
            StandardOutputEncoding = new System.Text.UTF8Encoding(false, true),
            StandardErrorEncoding = System.Text.Encoding.UTF8
        };
        foreach (string argument in arguments)
            startInfo.ArgumentList.Add(argument);

        using var process = new Process { StartInfo = startInfo, EnableRaisingEvents = true };
        if (!process.Start())
            throw new InvalidOperationException("DWS could not be started.");

        using var timeoutCts = CancellationTokenSource.CreateLinkedTokenSource(cancellationToken);
        timeoutCts.CancelAfter(arguments.Contains("login") ? TimeSpan.FromMinutes(5) : operationTimeout ?? _timeout);
        Task<string> stdoutTask = ReadLimitedAsync(process.StandardOutput, timeoutCts.Token);
        Task<string> stderrTask = ReadLimitedAsync(process.StandardError, timeoutCts.Token);
        try
        {
            await process.WaitForExitAsync(timeoutCts.Token);
            string output = await stdoutTask;
            _ = await stderrTask;
            if (process.ExitCode != 0)
                throw new InvalidOperationException("DWS did not complete the requested operation.");
            try
            {
                return JsonSerializer.Deserialize(output, jsonTypeInfo)
                    ?? throw new InvalidDataException("DWS returned an empty response.");
            }
            catch (JsonException ex)
            {
                throw new InvalidDataException("DWS returned an invalid response.", ex);
            }
        }
        catch (System.Text.DecoderFallbackException ex)
        {
            throw new InvalidDataException("DWS returned invalid UTF-8 text.", ex);
        }
        finally
        {
            TryKill(process);
            await timeoutCts.CancelAsync();
            try { await Task.WhenAll(stdoutTask, stderrTask); } catch { }
        }
    }

    private static async Task<string> ReadLimitedAsync(StreamReader reader, CancellationToken cancellationToken)
    {
        char[] buffer = new char[8192];
        var builder = new System.Text.StringBuilder();
        while (true)
        {
            int read = await reader.ReadAsync(buffer.AsMemory(), cancellationToken);
            if (read == 0) break;
            if (builder.Length + read > MaximumOutputCharacters)
                throw new InvalidDataException("DWS response is too large.");
            builder.Append(buffer, 0, read);
        }
        return builder.ToString();
    }

    private static void TryKill(Process process)
    {
        try { if (!process.HasExited) process.Kill(entireProcessTree: true); }
        catch { }
    }

    private static string ResolveExecutablePath()
    {
        string architecture = RuntimeInformation.ProcessArchitecture switch
        {
            Architecture.Arm64 => "win-arm64",
            _ => "win-x64"
        };
        return Path.Combine(AppContext.BaseDirectory, "Dws", architecture, "dws.exe");
    }
}
