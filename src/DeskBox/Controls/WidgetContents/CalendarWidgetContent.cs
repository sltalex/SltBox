using System.Globalization;
using DeskBox.Models;
using DeskBox.Services;
using Microsoft.UI.Text;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
using Microsoft.UI.Xaml.Media;
using Windows.UI;

namespace DeskBox.Controls.WidgetContents;

/// <summary>Compact month calendar that decorates dates with pending todo tasks.</summary>
public sealed partial class CalendarWidgetContent : UserControl, IDisposable
{
    private readonly LocalizationService _localization;
    private readonly SettingsService _settings;
    private readonly TodoCalendarService _calendarService;
    private readonly Grid _monthGrid = new() { ColumnSpacing = 2, RowSpacing = 2 };
    private readonly StackPanel _taskList = new() { Spacing = 3 };
    private readonly TextBlock _monthTitle = new() { FontSize = 16, FontWeight = FontWeights.SemiBold };
    private readonly TextBlock _emptyText = new() { Opacity = 0.65, TextWrapping = TextWrapping.Wrap };
    private readonly TextBlock _holidayCountdownText = new() { Opacity = 0.72, FontSize = 11, TextWrapping = TextWrapping.Wrap };
    private readonly DispatcherTimer _refreshTimer = new() { Interval = TimeSpan.FromSeconds(45) };
    private readonly Dictionary<DateOnly, List<CalendarTaskOccurrence>> _byDate = [];
    private readonly Dictionary<DateOnly, CalendarHoliday> _holidaysByDate = [];
    private DateOnly _displayedMonth;
    private DateOnly _selectedDate;
    private bool _visible = true;
    private bool _loaded;
    private bool _disposed;
    private CancellationTokenSource? _refreshCts;

    public CalendarWidgetContent(
        LocalizationService localization,
        SettingsService settings,
        Func<WidgetConfig, TodoWidgetStore>? todoStoreFactory = null)
    {
        _localization = localization;
        _settings = settings;
        _calendarService = new TodoCalendarService(settings, todoStoreFactory);
        DateOnly today = DateOnly.FromDateTime(DateTime.Today);
        _displayedMonth = new DateOnly(today.Year, today.Month, 1);
        _selectedDate = today;

        var root = new StackPanel { Spacing = 7, Margin = new Thickness(9, 7, 9, 8) };
        var header = new Grid { ColumnSpacing = 5 };
        header.ColumnDefinitions.Add(new ColumnDefinition { Width = GridLength.Auto });
        header.ColumnDefinitions.Add(new ColumnDefinition());
        header.ColumnDefinitions.Add(new ColumnDefinition { Width = GridLength.Auto });
        var previous = new Button { Content = "‹", Padding = new Thickness(8, 0, 8, 0), MinWidth = 30 };
        previous.Click += (_, _) => ChangeMonth(-1);
        var next = new Button { Content = "›", Padding = new Thickness(8, 0, 8, 0), MinWidth = 30 };
        next.Click += (_, _) => ChangeMonth(1);
        _monthTitle.HorizontalAlignment = HorizontalAlignment.Center;
        header.Children.Add(previous);
        Grid.SetColumn(_monthTitle, 1);
        header.Children.Add(_monthTitle);
        Grid.SetColumn(next, 2);
        header.Children.Add(next);
        root.Children.Add(header);

        var weekdays = new Grid { ColumnSpacing = 2 };
        for (int column = 0; column < 7; column++)
        {
            weekdays.ColumnDefinitions.Add(new ColumnDefinition());
            var label = new TextBlock
            {
                Text = CalendarCulture.DateTimeFormat.AbbreviatedDayNames[(column + 1) % 7],
                HorizontalAlignment = HorizontalAlignment.Center,
                FontSize = 10,
                Opacity = 0.62
            };
            Grid.SetColumn(label, column);
            weekdays.Children.Add(label);
        }
        root.Children.Add(weekdays);
        root.Children.Add(_monthGrid);
        root.Children.Add(new Border { Height = 1, Background = new SolidColorBrush(Color.FromArgb(35, 128, 128, 128)), Margin = new Thickness(0, 2, 0, 0) });
        root.Children.Add(_emptyText);
        root.Children.Add(_taskList);
        root.Children.Add(_holidayCountdownText);
        Content = new ScrollViewer
        {
            Content = root,
            HorizontalScrollBarVisibility = ScrollBarVisibility.Disabled,
            HorizontalScrollMode = ScrollMode.Disabled,
            VerticalScrollBarVisibility = ScrollBarVisibility.Auto
        };

        Loaded += OnLoaded;
        Unloaded += OnUnloaded;
        _settings.SettingsChanged += OnSettingsChanged;
        _refreshTimer.Tick += async (_, _) => await RefreshAsync();
        RenderCalendar();
    }

    public void SetVisible(bool visible)
    {
        _visible = visible;
        if (visible && _loaded)
        {
            _refreshTimer.Start();
            _ = RefreshAsync();
        }
        else if (!visible)
            StopRefresh();
    }

    private void OnLoaded(object sender, RoutedEventArgs e)
    {
        _loaded = true;
        if (_visible)
        {
            _refreshTimer.Start();
            _ = RefreshAsync();
        }
    }

    private void OnUnloaded(object sender, RoutedEventArgs e)
    {
        _loaded = false;
        StopRefresh();
    }

    private void OnSettingsChanged()
    {
        if (_disposed) return;
        DispatcherQueue.TryEnqueue(() =>
        {
            RenderCalendar();
            if (_visible) _ = RefreshAsync();
        });
    }

    private void StopRefresh()
    {
        _refreshTimer.Stop();
        _refreshCts?.Cancel();
        _refreshCts?.Dispose();
        _refreshCts = null;
    }

    private async Task RefreshAsync()
    {
        if (_disposed || !_loaded || !_visible) return;
        _refreshCts?.Cancel();
        _refreshCts?.Dispose();
        _refreshCts = null;
        if (!_settings.Settings.CalendarShowTaskMarkers)
        {
            _byDate.Clear();
            RenderCalendar();
            return;
        }
        var cts = _refreshCts = new CancellationTokenSource();
        try
        {
            IReadOnlyList<CalendarTaskOccurrence> items = await _calendarService.LoadAsync(
                _settings.Settings.DeletedWidgetIds.ToHashSet(StringComparer.Ordinal), cts.Token);
            if (cts.IsCancellationRequested) return;
            _byDate.Clear();
            foreach (CalendarTaskOccurrence item in items)
            {
                if (!_byDate.TryGetValue(item.LocalDate, out List<CalendarTaskOccurrence>? dayItems))
                {
                    dayItems = [];
                    _byDate[item.LocalDate] = dayItems;
                }
                dayItems.Add(item);
            }
            DispatcherQueue.TryEnqueue(RenderCalendar);
        }
        catch (OperationCanceledException) when (cts.IsCancellationRequested) { }
        catch (Exception ex) { App.LogVerbose($"[Calendar] Refresh failed: {ex.Message}"); }
    }

    private void ChangeMonth(int offset)
    {
        _displayedMonth = _displayedMonth.AddMonths(offset);
        _selectedDate = new DateOnly(_displayedMonth.Year, _displayedMonth.Month, 1);
        RenderCalendar();
    }

    private void RenderCalendar()
    {
        _monthTitle.Text = _displayedMonth.ToDateTime(TimeOnly.MinValue).ToString("Y", CalendarCulture);
        RenderHolidayProjection();
        _monthGrid.Children.Clear();
        _monthGrid.RowDefinitions.Clear();
        _monthGrid.ColumnDefinitions.Clear();
        for (int column = 0; column < 7; column++) _monthGrid.ColumnDefinitions.Add(new ColumnDefinition());
        for (int row = 0; row < 6; row++) _monthGrid.RowDefinitions.Add(new RowDefinition { Height = new GridLength(34) });
        DateOnly first = _displayedMonth;
        int offset = ((int)first.DayOfWeek + 6) % 7;
        for (int index = 0; index < 42; index++)
        {
            DateOnly date = first.AddDays(index - offset);
            bool currentMonth = date.Month == first.Month;
            _byDate.TryGetValue(date, out List<CalendarTaskOccurrence>? tasks);
            var panel = new StackPanel { Spacing = 0, HorizontalAlignment = HorizontalAlignment.Center };
            panel.Children.Add(new TextBlock { Text = date.Day.ToString(CultureInfo.InvariantCulture), FontSize = 12, HorizontalAlignment = HorizontalAlignment.Center, Opacity = currentMonth ? 1 : 0.35 });
            bool hasTasks = _settings.Settings.CalendarShowTaskMarkers && tasks is { Count: > 0 };
            bool hasHoliday = _settings.Settings.CalendarShowHolidays && _holidaysByDate.ContainsKey(date);
            if (hasTasks || hasHoliday)
            {
                var markers = new StackPanel { Orientation = Orientation.Horizontal, Spacing = 2, HorizontalAlignment = HorizontalAlignment.Center };
                if (hasTasks)
                {
                    markers.Children.Add(new TextBlock
                    {
                        Text = tasks!.Count > 1 ? $"• {tasks.Count}" : "•",
                        FontSize = 10,
                        Foreground = CreateMarkerBrush(_settings.Settings.CalendarTaskMarkerColor, Color.FromArgb(255, 55, 145, 220))
                    });
                }
                if (hasHoliday)
                {
                    markers.Children.Add(new TextBlock
                    {
                        Text = "•",
                        FontSize = 10,
                        Foreground = CreateMarkerBrush(_settings.Settings.CalendarHolidayMarkerColor, Color.FromArgb(255, 224, 76, 76))
                    });
                }
                panel.Children.Add(markers);
            }
            var button = new Button { Content = panel, Padding = new Thickness(2), HorizontalAlignment = HorizontalAlignment.Stretch, Tag = date, Background = date == _selectedDate ? new SolidColorBrush(Color.FromArgb(45, 80, 150, 220)) : null };
            button.Click += Day_Click;
            Grid.SetRow(button, index / 7);
            Grid.SetColumn(button, index % 7);
            _monthGrid.Children.Add(button);
        }
        RenderTasks();
    }

    private static SolidColorBrush CreateMarkerBrush(string? hex, Color fallback)
    {
        if (string.IsNullOrWhiteSpace(hex) || hex.Length != 7 || hex[0] != '#')
            return new SolidColorBrush(fallback);
        try
        {
            byte red = Convert.ToByte(hex[1..3], 16);
            byte green = Convert.ToByte(hex[3..5], 16);
            byte blue = Convert.ToByte(hex[5..7], 16);
            return new SolidColorBrush(Color.FromArgb(255, red, green, blue));
        }
        catch (FormatException)
        {
            return new SolidColorBrush(fallback);
        }
        catch (OverflowException)
        {
            return new SolidColorBrush(fallback);
        }
    }

    private void Day_Click(object sender, RoutedEventArgs e)
    {
        if (sender is Button { Tag: DateOnly date })
        {
            _selectedDate = date;
            RenderCalendar();
        }
    }

    private void RenderTasks()
    {
        _taskList.Children.Clear();
        if (!_settings.Settings.CalendarShowTaskMarkers)
        {
            _emptyText.Text = _localization.T("Calendar.MarkersDisabled");
            _emptyText.Visibility = Visibility.Visible;
            return;
        }
        List<CalendarTaskOccurrence> tasks = _byDate.TryGetValue(_selectedDate, out var selected) ? selected : [];
        _emptyText.Text = tasks.Count == 0 ? _localization.T("Calendar.NoTasks") : string.Empty;
        _emptyText.Visibility = tasks.Count == 0 ? Visibility.Visible : Visibility.Collapsed;
        foreach (CalendarTaskOccurrence task in tasks)
        {
            var button = new Button { HorizontalContentAlignment = HorizontalAlignment.Left, Padding = new Thickness(7, 4, 7, 4), Tag = task };
            var text = new Grid { ColumnSpacing = 7 };
            text.ColumnDefinitions.Add(new ColumnDefinition { Width = GridLength.Auto });
            text.ColumnDefinitions.Add(new ColumnDefinition());
            text.Children.Add(new TextBlock { Text = task.DueDate.ToLocalTime().ToString("t", CalendarCulture), FontSize = 10, Opacity = 0.65, VerticalAlignment = VerticalAlignment.Center });
            var title = new TextBlock { Text = task.IsRecurring ? $"↻ {task.Title}" : task.Title, TextTrimming = TextTrimming.CharacterEllipsis, TextWrapping = TextWrapping.NoWrap, VerticalAlignment = VerticalAlignment.Center };
            Grid.SetColumn(title, 1);
            text.Children.Add(title);
            button.Content = text;
            button.Click += Task_Click;
            _taskList.Children.Add(button);
        }
    }

    private void RenderHolidayProjection()
    {
        _holidaysByDate.Clear();
        if (!_settings.Settings.CalendarShowHolidays)
        {
            _holidayCountdownText.Visibility = Visibility.Collapsed;
            return;
        }

        foreach (int year in new[] { _displayedMonth.Year - 1, _displayedMonth.Year, _displayedMonth.Year + 1 })
        {
            foreach (CalendarHoliday holiday in CalendarHolidayService.GetHolidays(year))
            {
                _holidaysByDate[holiday.Date] = holiday;
            }
        }

        CalendarHoliday? next = CalendarHolidayService.GetNextHoliday(DateOnly.FromDateTime(DateTime.Today));
        if (next is null)
        {
            _holidayCountdownText.Visibility = Visibility.Collapsed;
            return;
        }

        int remainingDays = next.Date.DayNumber - DateOnly.FromDateTime(DateTime.Today).DayNumber;
        _holidayCountdownText.Text = _localization.Format(
            "Calendar.HolidayCountdown",
            _localization.T(next.NameKey),
            remainingDays);
        _holidayCountdownText.Visibility = Visibility.Visible;
    }

    private CultureInfo CalendarCulture
    {
        get
        {
            try
            {
                return CultureInfo.GetCultureInfo(_localization.CurrentCultureName);
            }
            catch (CultureNotFoundException)
            {
                return CultureInfo.CurrentCulture;
            }
        }
    }

    private async void Task_Click(object sender, RoutedEventArgs e)
    {
        if (sender is not Button { Tag: CalendarTaskOccurrence task }) return;
        try
        {
            if (App.Current.WidgetManager is { } manager)
                await manager.ShowTodoReminderTargetAsync(task.WidgetId, task.TaskId, true);
        }
        catch (Exception ex) { App.LogVerbose($"[Calendar] Failed to open task: {ex.Message}"); }
    }

    public void Dispose()
    {
        if (_disposed) return;
        _disposed = true;
        StopRefresh();
        _settings.SettingsChanged -= OnSettingsChanged;
    }
}
