using DeskBox.Models;

namespace DeskBox.Services;

/// <summary>Read-only projection of pending todo due dates for the calendar widget.</summary>
public sealed record CalendarTaskOccurrence(
    string WidgetId,
    string WidgetName,
    string TaskId,
    string Title,
    DateTimeOffset DueDate,
    string? ColorMarker,
    bool IsRecurring)
{
    public DateOnly LocalDate => DateOnly.FromDateTime(DueDate.ToLocalTime().DateTime);
}

public sealed class TodoCalendarService
{
    private readonly Func<IReadOnlyList<WidgetConfig>> _widgets;
    private readonly Func<WidgetConfig, Task<TodoWidgetData>> _loader;

    public TodoCalendarService(SettingsService settings)
        : this(settings, null)
    {
    }

    internal TodoCalendarService(
        SettingsService settings,
        Func<WidgetConfig, TodoWidgetStore>? storeFactory)
        : this(
            () => settings.Settings.Widgets,
            config => (storeFactory ?? (widget => new TodoWidgetStore(widget.Id)))(config).LoadAsync())
    {
    }

    internal TodoCalendarService(
        Func<IReadOnlyList<WidgetConfig>> widgets,
        Func<WidgetConfig, Task<TodoWidgetData>> loader)
    {
        _widgets = widgets;
        _loader = loader;
    }

    public async Task<IReadOnlyList<CalendarTaskOccurrence>> LoadAsync(
        ISet<string>? deletedWidgetIds = null,
        CancellationToken cancellationToken = default)
    {
        WidgetConfig[] todoWidgets = _widgets()
            .Where(widget => widget.WidgetKind == WidgetKind.Todo)
            .Where(widget => deletedWidgetIds is null || !deletedWidgetIds.Contains(widget.Id))
            .ToArray();

        var tasks = todoWidgets.Select(async widget =>
        {
            try
            {
                TodoWidgetData data = await _loader(widget).WaitAsync(cancellationToken).ConfigureAwait(false);
                return data.Items
                    .Where(item => !item.IsCompleted && !item.IsDeleted && item.DueDate is not null)
                    .Select(item => new CalendarTaskOccurrence(
                        widget.Id,
                        string.IsNullOrWhiteSpace(widget.Name) ? "Todo" : widget.Name,
                        item.Id,
                        item.Text,
                        item.DueDate!.Value,
                        item.ColorMarker,
                        item.Recurrence is not null))
                    .ToArray();
            }
            catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested)
            {
                throw;
            }
            catch (Exception ex)
            {
                App.LogVerbose($"[TodoCalendar] Failed to read widget {widget.Id}: {ex.Message}");
                return [];
            }
        });

        CalendarTaskOccurrence[][] results = await Task.WhenAll(tasks).ConfigureAwait(false);
        return results
            .SelectMany(items => items)
            .OrderBy(item => item.LocalDate)
            .ThenBy(item => item.DueDate.ToLocalTime())
            .ThenBy(item => item.Title, StringComparer.CurrentCultureIgnoreCase)
            .ToArray();
    }
}
