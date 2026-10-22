#if DESKBOX_NATIVE_AOT
namespace DeskBox.Features.Todo;

// The Todo settings section keeps its runtime {Binding} surface and binds
// through a section-level DataContext switch. Expose only the properties
// used by that XAML surface in NativeAOT builds, mirroring the
// Glance/Music/FileStack/QuickCapture editor bridge pattern.
[WinRT.GeneratedBindableCustomProperty([
    nameof(AvailableEnterBehaviorOptions),
    nameof(AvailableLayoutOptions),
    nameof(AvailableNewTaskPositionOptions),
    nameof(AvailablePreviewLineOptions),
    nameof(AvailableReminderOffsetOptions),
    nameof(AvailableThisWeekDueDayOptions),
    nameof(AutoSelectFirstInWideLayout),
    nameof(ContentSummaryText),
    nameof(ContentTextSize),
    nameof(ContentTextSizeValueText),
    nameof(DefaultFilter),
    nameof(DefaultOffsetMinutes),
    nameof(DefaultDueTime),
    nameof(EditorEnterBehavior),
    nameof(Enabled),
    nameof(ExpandItemOnHover),
    nameof(FooterDisplaySummaryText),
    nameof(ItemPreviewLineCount),
    nameof(LayoutMode),
    nameof(LayoutSummaryText),
    nameof(ListTextSize),
    nameof(ListTextSizeValueText),
    nameof(NewTaskPosition),
    nameof(ReminderSummaryText),
    nameof(DueDefaultsSummaryText),
    nameof(RemindersEnabled),
    nameof(ShowCompletedTasks),
    nameof(ShowTabBar),
    nameof(ShowWideOptions),
    nameof(TabStyleIndex),
    nameof(TabBarPositionIndex),
    nameof(TabsSummaryText),
    nameof(ThisWeekDueDay),
    nameof(VisibleDefaultFilterOptions),
    nameof(VisibleTabsText)
], [])]
public sealed partial class TodoSettingsViewModel
{
}
#endif
