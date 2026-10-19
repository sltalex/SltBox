namespace DeskBox.Models;

/// <summary>
/// Device-domain widget inventory: widget configs, groups, per-topology layouts, and deletion tombstones.
/// </summary>
public sealed class WidgetLayoutSettingsSlice
{
    /// <summary>Device-local aquarium animation preference.</summary>
    public bool AquariumAnimationsEnabled { get; set; } = true;

    /// <summary>System monitor chart presentation preferences.</summary>
    public string SystemMonitorChartStyle { get; set; } = "Bars";
    // Empty until explicitly chosen so older single-style settings keep their appearance.
    public string SystemMonitorCpuChartStyle { get; set; } = string.Empty;
    public string SystemMonitorMemoryChartStyle { get; set; } = string.Empty;
    public string SystemMonitorNetworkChartStyle { get; set; } = string.Empty;
    public string SystemMonitorCpuChartColor { get; set; } = "#2C8EE4";
    public string SystemMonitorMemoryChartColor { get; set; } = "#46AE85";
    public string SystemMonitorNetworkChartColor { get; set; } = "#E6A23C";
    public int SystemMonitorRefreshIntervalSeconds { get; set; } = 2;
    public bool CalendarShowTaskMarkers { get; set; } = true;
    public bool CalendarShowHolidays { get; set; }
    public string CalendarTaskMarkerColor { get; set; } = "#3791DC";
    public string CalendarHolidayMarkerColor { get; set; } = "#E04C4C";
    /// <summary>
    /// Enabled state for singleton feature widgets, keyed by <see cref="WidgetKind"/> name.
    /// Legacy boolean properties are still kept as compatibility mirrors.
    /// </summary>
    public Dictionary<string, bool> FeatureWidgetEnabledStates { get; set; } = [];

    /// <summary>All configured widgets.</summary>
    public List<WidgetConfig> Widgets { get; set; } = [];

    /// <summary>
    /// Groups that present several normal widget configs through one desktop
    /// surface. Widget data stays in <see cref="Widgets"/>; this collection owns
    /// group membership, active member, and shared window state.
    /// </summary>
    public List<WidgetGroupConfig> WidgetGroups { get; set; } = [];

    /// <summary>
    /// Bounded layout snapshots keyed by the connected-display topology. This
    /// prevents a temporary DPI/topology transition from overwriting the layout
    /// that belongs to another monitor arrangement.
    /// </summary>
    public Dictionary<string, WidgetTopologyLayoutProfile> WidgetTopologyLayouts { get; set; } = [];

    /// <summary>The topology profile currently projected into Widgets/WidgetGroups.</summary>
    public string? ActiveWidgetTopologyKey { get; set; }

    /// <summary>
    /// Legacy compatibility flag. Widget grouping is now always available;
    /// normalization keeps this value true for older settings files.
    /// </summary>
    public bool WidgetGroupsEnabled { get; set; } = true;

    /// <summary>
    /// Legacy navigation style retained only so pre-title-switcher settings can
    /// be migrated without losing user intent.
    /// </summary>
    public string WidgetGroupDefaultNavigationStyle { get; set; } = "Tabs";

    /// <summary>
    /// Default identity layout used by the title-bar member selector.
    /// </summary>
    public string WidgetGroupDefaultTitleDisplayMode { get; set; } =
        WidgetGroupTitleDisplayModes.IconAndText;

    /// <summary>
    /// Enables sequential member switching while the pointer is over the
    /// title-bar member selector.
    /// </summary>
    public bool WidgetGroupWheelSwitchEnabled { get; set; } = true;

    /// <summary>
    /// Enables delayed pointer-hover activation for flat group title tabs.
    /// Individual groups may override this default.
    /// </summary>
    public bool WidgetGroupHoverSwitchEnabled { get; set; }

    /// <summary>Widget ids that were deleted and should not be restored.</summary>
    public List<string> DeletedWidgetIds { get; set; } = [];

    /// <summary>
    /// Replaces every member with <paramref name="other"/>'s values. The
    /// layout store keeps this slice as the live object for the session
    /// (AppSettings.WidgetLayout is get-only by the 2A facade contract), so
    /// adoption copies data in place instead of swapping the reference.
    /// </summary>
    internal void CopyFrom(WidgetLayoutSettingsSlice other)
    {
        ArgumentNullException.ThrowIfNull(other);

        FeatureWidgetEnabledStates = other.FeatureWidgetEnabledStates;
        AquariumAnimationsEnabled = other.AquariumAnimationsEnabled;
        SystemMonitorChartStyle = other.SystemMonitorChartStyle;
        SystemMonitorCpuChartStyle = other.SystemMonitorCpuChartStyle;
        SystemMonitorMemoryChartStyle = other.SystemMonitorMemoryChartStyle;
        SystemMonitorNetworkChartStyle = other.SystemMonitorNetworkChartStyle;
        SystemMonitorCpuChartColor = other.SystemMonitorCpuChartColor;
        SystemMonitorMemoryChartColor = other.SystemMonitorMemoryChartColor;
        SystemMonitorNetworkChartColor = other.SystemMonitorNetworkChartColor;
        SystemMonitorRefreshIntervalSeconds = other.SystemMonitorRefreshIntervalSeconds;
        CalendarShowTaskMarkers = other.CalendarShowTaskMarkers;
        CalendarShowHolidays = other.CalendarShowHolidays;
        CalendarTaskMarkerColor = other.CalendarTaskMarkerColor;
        CalendarHolidayMarkerColor = other.CalendarHolidayMarkerColor;
        Widgets = other.Widgets;
        WidgetGroups = other.WidgetGroups;
        WidgetTopologyLayouts = other.WidgetTopologyLayouts;
        ActiveWidgetTopologyKey = other.ActiveWidgetTopologyKey;
        WidgetGroupsEnabled = other.WidgetGroupsEnabled;
        WidgetGroupDefaultNavigationStyle = other.WidgetGroupDefaultNavigationStyle;
        WidgetGroupDefaultTitleDisplayMode = other.WidgetGroupDefaultTitleDisplayMode;
        WidgetGroupWheelSwitchEnabled = other.WidgetGroupWheelSwitchEnabled;
        WidgetGroupHoverSwitchEnabled = other.WidgetGroupHoverSwitchEnabled;
        DeletedWidgetIds = other.DeletedWidgetIds;
    }
}
