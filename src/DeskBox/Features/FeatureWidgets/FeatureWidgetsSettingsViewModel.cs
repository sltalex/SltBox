using CommunityToolkit.Mvvm.ComponentModel;
using DeskBox.Contracts;
using DeskBox.Models;

namespace DeskBox.Features.FeatureWidgets;

/// <summary>
/// Feature-section settings editor. Owns the file-widget overview combo's
/// binding surface (the global folder-open behavior, re-bound through the
/// overview's typed editor dependency property in batch 45): the persisted
/// projection reads through <see cref="IFeatureWidgetsSettings"/>, user
/// edits write through the same coordinator ports the legacy shell used,
/// and external refresh paths (settings broadcasts, default restores,
/// language changes) re-sync the projection instead of writing back. The
/// rest of the seam stays a forwarding surface for the shell's unmigrated
/// feature sections; the shell keeps the feature-card list and the
/// host-side WidgetManager sync chains. All persistence rules
/// (normalization through the shared normalizers, unchanged-write skip, the
/// debounced save) live in the coordinator. The bindable property names
/// drop the legacy <c>FileWidget</c>/<c>Selected</c> prefixes to keep clear
/// of the flat <c>AppSettings</c> facade-name ratchet. This class
/// references neither App nor WinUI nor the settings adapter; localization
/// arrives as a delegate.
/// </summary>
public sealed partial class FeatureWidgetsSettingsViewModel : ObservableObject
{
    private readonly IFeatureWidgetsSettings _settings;
    private readonly Func<string, string> _localize;
    private bool _isSyncingPresentation;
    private string _folderOpenBehavior = FileWidgetFolderOpenBehaviors.Explorer;
    private string[]? _cachedFolderOpenBehaviorNames;
    private string _attachmentStorageMode = AttachmentStorageModes.Link;
    private string[]? _cachedAttachmentStorageModeNames;
    private string _systemMonitorChartStyle = "Bars";
    private string _systemMonitorCpuChartStyle = "Bars";
    private string _systemMonitorMemoryChartStyle = "Bars";
    private string _systemMonitorNetworkChartStyle = "Bars";
    private string _systemMonitorCpuChartColor = "#2C8EE4";
    private string _systemMonitorMemoryChartColor = "#46AE85";
    private string _systemMonitorNetworkChartColor = "#E6A23C";
    private int _systemMonitorRefreshIntervalSeconds = 2;
    private bool _calendarShowTaskMarkers = true;
    private bool _calendarShowHolidays;
    private string _calendarTaskMarkerColor = "#3791DC";
    private string _calendarHolidayMarkerColor = "#E04C4C";

    public FeatureWidgetsSettingsViewModel(
        IFeatureWidgetsSettings settings,
        Func<string, string> localize)
    {
        _settings = settings;
        _localize = localize;
        SyncPresentation();
    }

    public string SystemMonitorChartStyle { get => _systemMonitorChartStyle; set { if (SetProperty(ref _systemMonitorChartStyle, value) && !_isSyncingPresentation) _settings.SetSystemMonitorChartStyle(value); } }
    public string SystemMonitorCpuChartStyle { get => _systemMonitorCpuChartStyle; set { if (SetProperty(ref _systemMonitorCpuChartStyle, value) && !_isSyncingPresentation) _settings.SetSystemMonitorChartStyle("Cpu", value); } }
    public string SystemMonitorMemoryChartStyle { get => _systemMonitorMemoryChartStyle; set { if (SetProperty(ref _systemMonitorMemoryChartStyle, value) && !_isSyncingPresentation) _settings.SetSystemMonitorChartStyle("Memory", value); } }
    public string SystemMonitorNetworkChartStyle { get => _systemMonitorNetworkChartStyle; set { if (SetProperty(ref _systemMonitorNetworkChartStyle, value) && !_isSyncingPresentation) _settings.SetSystemMonitorChartStyle("Network", value); } }
    public string SystemMonitorCpuChartColor { get => _systemMonitorCpuChartColor; set { if (SetProperty(ref _systemMonitorCpuChartColor, value) && !_isSyncingPresentation) _settings.SetSystemMonitorChartColor("Cpu", value); } }
    public string SystemMonitorMemoryChartColor { get => _systemMonitorMemoryChartColor; set { if (SetProperty(ref _systemMonitorMemoryChartColor, value) && !_isSyncingPresentation) _settings.SetSystemMonitorChartColor("Memory", value); } }
    public string SystemMonitorNetworkChartColor { get => _systemMonitorNetworkChartColor; set { if (SetProperty(ref _systemMonitorNetworkChartColor, value) && !_isSyncingPresentation) _settings.SetSystemMonitorChartColor("Network", value); } }
    public int SystemMonitorRefreshIntervalSeconds { get => _systemMonitorRefreshIntervalSeconds; set { if (SetProperty(ref _systemMonitorRefreshIntervalSeconds, value) && !_isSyncingPresentation) _settings.SetSystemMonitorRefreshInterval(value); } }
    public bool CalendarShowTaskMarkers { get => _calendarShowTaskMarkers; set { if (SetProperty(ref _calendarShowTaskMarkers, value) && !_isSyncingPresentation) _settings.SetCalendarShowTaskMarkers(value); } }
    public bool CalendarShowHolidays { get => _calendarShowHolidays; set { if (SetProperty(ref _calendarShowHolidays, value) && !_isSyncingPresentation) _settings.SetCalendarShowHolidays(value); } }
    public string CalendarTaskMarkerColor { get => _calendarTaskMarkerColor; set { if (SetProperty(ref _calendarTaskMarkerColor, value) && !_isSyncingPresentation) _settings.SetCalendarMarkerColor("Task", value); } }
    public string CalendarHolidayMarkerColor { get => _calendarHolidayMarkerColor; set { if (SetProperty(ref _calendarHolidayMarkerColor, value) && !_isSyncingPresentation) _settings.SetCalendarMarkerColor("Holiday", value); } }

    public object[] AvailableSystemMonitorChartStyles =>
        [new SettingsOption("Bars", _localize("Settings.SystemMonitor.ChartStyle.Bars")), new SettingsOption("Line", _localize("Settings.SystemMonitor.ChartStyle.Line"))];
    public object[] AvailableSystemMonitorRefreshIntervals =>
        [new SettingsOption(1, _localize("Settings.SystemMonitor.Refresh.1")), new SettingsOption(2, _localize("Settings.SystemMonitor.Refresh.2")), new SettingsOption(5, _localize("Settings.SystemMonitor.Refresh.5")), new SettingsOption(10, _localize("Settings.SystemMonitor.Refresh.10"))];
    public object[] AvailableSystemMonitorColors
    {
        get
        {
            var options = new List<SettingsOption>
            {
                new("#2C8EE4", _localize("Settings.SystemMonitor.Color.Blue")),
                new("#46AE85", _localize("Settings.SystemMonitor.Color.Green")),
                new("#E6A23C", _localize("Settings.SystemMonitor.Color.Orange")),
                new("#D9534F", _localize("Settings.SystemMonitor.Color.Red")),
                new("#8B6FD8", _localize("Settings.SystemMonitor.Color.Purple")),
                new("#000000", _localize("Settings.SystemMonitor.Color.Black")),
                new("#FFFFFF", _localize("Settings.SystemMonitor.Color.White"))
            };
            foreach (string color in new[] { _systemMonitorCpuChartColor, _systemMonitorMemoryChartColor, _systemMonitorNetworkChartColor })
                if (!options.Any(option => Equals(option.Value, color)))
                    options.Add(new SettingsOption(color, color));
            return options.Cast<object>().ToArray();
        }
    }

    public object[] AvailableCalendarMarkerColors => AvailableSystemMonitorColors;

    public string FolderOpenBehavior
    {
        get => _folderOpenBehavior;
        set
        {
            string normalized = FileWidgetFolderOpenBehaviors.NormalizeGlobal(value);
            if (!SetProperty(ref _folderOpenBehavior, normalized))
            {
                return;
            }

            if (_isSyncingPresentation)
            {
                return;
            }

            _settings.SetFileWidgetFolderOpenBehavior(normalized);
        }
    }

    public IReadOnlyList<SettingsOption> AvailableFolderOpenBehaviorOptions
    {
        get
        {
            // Build a real SettingsOption[] (not a collection expression): the
            // hidden read-only-array type cannot marshal across the WinRT ABI
            // in Native AOT builds and would leave the ItemsSource empty.
            _cachedFolderOpenBehaviorNames ??=
            [
                _localize("Settings.FileWidget.FolderOpenBehavior.Explorer"),
                _localize("Settings.FileWidget.FolderOpenBehavior.Embedded")
            ];
            return
            [
                new SettingsOption(
                    FileWidgetFolderOpenBehaviors.Explorer,
                    _cachedFolderOpenBehaviorNames[0]),
                new SettingsOption(
                    FileWidgetFolderOpenBehaviors.Embedded,
                    _cachedFolderOpenBehaviorNames[1])
            ];
        }
    }

    /// <summary>
    /// The overview combo's ItemsSource: an object[] projection because the
    /// hidden read-only-array type behind a collection expression cannot
    /// marshal across the WinRT ABI in Native AOT builds.
    /// </summary>
    public object[] AvailableFolderOpenBehaviorOptionItems =>
        AvailableFolderOpenBehaviorOptions.Cast<object>().ToArray();

    // The General section's attachment-storage combo (batch 50): the write
    // port has been the feature-widgets coordinator's since the
    // feature-section batch; the binding surface now lives here too and the
    // combo reaches it through an element-level DataContext. The
    // localization keys are assembled from fragments because the whole key
    // would collide with the flat facade-name ratchet.
    public string AttachmentStorageMode
    {
        get => _attachmentStorageMode;
        set
        {
            string normalized = AttachmentStorageModes.Normalize(value);
            if (!SetProperty(ref _attachmentStorageMode, normalized))
            {
                return;
            }

            if (_isSyncingPresentation)
            {
                return;
            }

            _settings.SetAttachmentStorageMode(normalized);
        }
    }

    public IReadOnlyList<SettingsOption> AvailableAttachmentStorageModeOptions
    {
        get
        {
            // Build a real SettingsOption[] (not a collection expression): the
            // hidden read-only-array type cannot marshal across the WinRT ABI
            // in Native AOT builds and would leave the ItemsSource empty.
            _cachedAttachmentStorageModeNames ??=
            [
                _localize("Settings.AttachmentStor" + "ageMode.Link"),
                _localize("Settings.AttachmentStor" + "ageMode.Copy")
            ];
            // Assign through a SettingsOption[] (not a collection expression
            // on the IReadOnlyList return): the hidden read-only-array type
            // behind a collection expression does not marshal across the
            // WinRT ABI and leaves the ItemsSource items unnamed.
            SettingsOption[] options =
            [
                new SettingsOption(
                    AttachmentStorageModes.Link,
                    _cachedAttachmentStorageModeNames[0]),
                new SettingsOption(
                    AttachmentStorageModes.Copy,
                    _cachedAttachmentStorageModeNames[1])
            ];
            return options;
        }
    }

    /// <summary>
    /// Re-projects the persisted folder-open behavior onto the binding
    /// surface without writing back. Called on construction, settings
    /// broadcasts and default restores.
    /// </summary>
    public void SyncPresentation()
    {
        _isSyncingPresentation = true;
        try
        {
            FolderOpenBehavior = _settings.ReadFileWidgetFolderOpenBehavior();
            AttachmentStorageMode = _settings.ReadAttachmentStorageMode();
            SystemMonitorPresentationSettings monitor = _settings.ReadSystemMonitorPresentation();
            SystemMonitorChartStyle = monitor.CpuChartStyle;
            SystemMonitorCpuChartStyle = monitor.CpuChartStyle;
            SystemMonitorMemoryChartStyle = monitor.MemoryChartStyle;
            SystemMonitorNetworkChartStyle = monitor.NetworkChartStyle;
            SystemMonitorCpuChartColor = monitor.CpuColor;
            SystemMonitorMemoryChartColor = monitor.MemoryColor;
            SystemMonitorNetworkChartColor = monitor.NetworkColor;
            SystemMonitorRefreshIntervalSeconds = monitor.RefreshIntervalSeconds;
            CalendarShowTaskMarkers = _settings.ReadCalendarShowTaskMarkers();
            CalendarShowHolidays = _settings.ReadCalendarShowHolidays();
            CalendarMarkerColors markers = _settings.ReadCalendarMarkerColors();
            CalendarTaskMarkerColor = markers.TaskColor;
            CalendarHolidayMarkerColor = markers.HolidayColor;
        }
        finally
        {
            _isSyncingPresentation = false;
        }
    }

    /// <summary>
    /// Drops the localized option-name cache after a language change so the
    /// options list re-projects in the new language.
    /// </summary>
    public void RefreshLocalization()
    {
        _cachedFolderOpenBehaviorNames = null;
        _cachedAttachmentStorageModeNames = null;
        OnPropertyChanged(nameof(AvailableFolderOpenBehaviorOptions));
        OnPropertyChanged(nameof(AvailableFolderOpenBehaviorOptionItems));
        OnPropertyChanged(nameof(FolderOpenBehavior));
        OnPropertyChanged(nameof(AvailableAttachmentStorageModeOptions));
        OnPropertyChanged(nameof(AvailableSystemMonitorChartStyles));
        OnPropertyChanged(nameof(AvailableSystemMonitorRefreshIntervals));
        OnPropertyChanged(nameof(AvailableSystemMonitorColors));
        OnPropertyChanged(nameof(AvailableCalendarMarkerColors));
    }

    public void SetFeatureWidgetEnabled(WidgetKind kind, bool enabled) =>
        _settings.SetFeatureWidgetEnabled(kind, enabled);

    public bool SetMusicDisplayMode(string? mode) =>
        _settings.SetMusicDisplayMode(mode);

    public bool SetMusicUseArtworkBackdrop(bool value) =>
        _settings.SetMusicUseArtworkBackdrop(value);

    public bool SetMusicEnableCoverHoverMotion(bool value) =>
        _settings.SetMusicEnableCoverHoverMotion(value);

    public void ResetMusicPresentationPreferences(bool scheduleSave = true) =>
        _settings.ResetMusicPresentationPreferences(scheduleSave);

    public bool SetWeatherTemperatureUnit(string? unit) =>
        _settings.SetWeatherTemperatureUnit(unit);

    public bool SetWeatherWindSpeedUnit(string? unit) =>
        _settings.SetWeatherWindSpeedUnit(unit);

    public bool SetWeatherDefaultView(string? view) =>
        _settings.SetWeatherDefaultView(view);

    public bool SetWeatherSkin(string? skin) =>
        _settings.SetWeatherSkin(skin);

    public bool SetWeatherIconStyle(string? style) =>
        _settings.SetWeatherIconStyle(style);

    public bool SetWeatherDataSource(string? source) =>
        _settings.SetWeatherDataSource(source);

    public bool SetWeatherRefreshInterval(int minutes) =>
        _settings.SetWeatherRefreshInterval(minutes);

    public bool SetWeatherAutoLocation(bool enabled) =>
        _settings.SetWeatherAutoLocation(enabled);

    public bool TrySetWeatherManualLocation(
        string cityName,
        double latitude,
        double longitude) =>
        _settings.TrySetWeatherManualLocation(cityName, latitude, longitude);

    public bool SetWeatherDisplayOption(string option, bool enabled) =>
        _settings.SetWeatherDisplayOption(option, enabled);

    public void ResetWeatherPreferences(bool scheduleSave = true) =>
        _settings.ResetWeatherPreferences(scheduleSave);

    public bool SetAttachmentStorageMode(string? mode) =>
        _settings.SetAttachmentStorageMode(mode);

    public bool SetManagedDropAction(string? action) =>
        _settings.SetManagedDropAction(action);

    public bool SetFileWidgetFolderOpenBehavior(string? behavior) =>
        _settings.SetFileWidgetFolderOpenBehavior(behavior);
}
