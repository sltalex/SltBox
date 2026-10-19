using DeskBox.Models;
using DeskBox.Services;

namespace DeskBox.Tests;

public sealed class TodoCalendarServiceTests
{
    [Fact]
    public async Task LoadAsync_AggregatesPendingDueItemsAndFiltersCompletedDeletedAndUndated()
    {
        var first = new WidgetConfig { Id = "todo-a", Name = "Work", WidgetKind = WidgetKind.Todo };
        var second = new WidgetConfig { Id = "todo-b", Name = "Home", WidgetKind = WidgetKind.Todo };
        DateTimeOffset due = new(2026, 10, 19, 10, 0, 0, TimeSpan.FromHours(8));
        var data = new Dictionary<string, TodoWidgetData>
        {
            [first.Id] = new TodoWidgetData
            {
                Items =
                [
                    new TodoItem { Id = "keep", Text = "Keep", DueDate = due },
                    new TodoItem { Id = "done", Text = "Done", DueDate = due, IsCompleted = true },
                    new TodoItem { Id = "deleted", Text = "Deleted", DueDate = due, IsDeleted = true },
                    new TodoItem { Id = "none", Text = "No date" }
                ]
            },
            [second.Id] = new TodoWidgetData
            {
                Items = [new TodoItem { Id = "repeat", Text = "Repeat", DueDate = due.AddMinutes(-10), Recurrence = new TodoRecurrence { Mode = TodoRecurrenceMode.Daily } }]
            }
        };
        var service = new TodoCalendarService(
            () => [first, second],
            widget => Task.FromResult(data[widget.Id]));

        IReadOnlyList<CalendarTaskOccurrence> result = await service.LoadAsync();

        Assert.Equal(["repeat", "keep"], result.Select(item => item.TaskId));
        Assert.Equal("Work", result[1].WidgetName);
        Assert.True(result[0].IsRecurring);
        Assert.Equal(DateOnly.FromDateTime(due.ToLocalTime().DateTime), result[0].LocalDate);
    }

    [Fact]
    public async Task LoadAsync_SkipsDeletedWidgetAndContinuesWhenOneStoreFails()
    {
        var deleted = new WidgetConfig { Id = "deleted", WidgetKind = WidgetKind.Todo };
        var broken = new WidgetConfig { Id = "broken", WidgetKind = WidgetKind.Todo };
        var valid = new WidgetConfig { Id = "valid", WidgetKind = WidgetKind.Todo };
        var service = new TodoCalendarService(
            () => [deleted, broken, valid],
            widget => widget.Id switch
            {
                "broken" => Task.FromException<TodoWidgetData>(new IOException("test")),
                _ => Task.FromResult(new TodoWidgetData
                {
                    Items = [new TodoItem { Id = widget.Id, Text = widget.Id, DueDate = DateTimeOffset.Now }]
                })
            });

        IReadOnlyList<CalendarTaskOccurrence> result = await service.LoadAsync(new HashSet<string>(["deleted"]));

        Assert.Equal(["valid"], result.Select(item => item.TaskId));
    }
}
