namespace DeskBox.Controls.WidgetContents;

internal readonly record struct TodoPaneVisibility(
    bool ShowHeader,
    bool ShowTaskList,
    bool ShowDetail,
    bool ShowWideEmptyState,
    bool ShowSplitter);

internal static class TodoPaneVisibilityPolicy
{
    internal static TodoPaneVisibility Resolve(bool meetingsSelected, bool isDualPane, bool hasDetail)
    {
        bool showList = isDualPane || !hasDetail;
        return new TodoPaneVisibility(
            meetingsSelected || showList,
            !meetingsSelected && showList,
            !meetingsSelected && hasDetail,
            !meetingsSelected && isDualPane && !hasDetail,
            !meetingsSelected && isDualPane);
    }
}
