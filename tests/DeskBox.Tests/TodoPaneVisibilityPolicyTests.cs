using DeskBox.Controls.WidgetContents;

namespace DeskBox.Tests;

public sealed class TodoPaneVisibilityPolicyTests
{
    [Theory]
    [InlineData(false, false)]
    [InlineData(false, true)]
    [InlineData(true, false)]
    [InlineData(true, true)]
    public void LayoutRefreshKeepsTaskPanesHiddenWhileMeetingsAreSelected(
        bool isDualPane, bool hasDetail)
    {
        TodoPaneVisibility beforeSelection = TodoPaneVisibilityPolicy.Resolve(false, isDualPane, hasDetail);
        TodoPaneVisibility afterLayoutRefresh = TodoPaneVisibilityPolicy.Resolve(true, isDualPane, hasDetail);

        Assert.True(beforeSelection.ShowTaskList || beforeSelection.ShowDetail);
        Assert.True(afterLayoutRefresh.ShowHeader);
        Assert.False(afterLayoutRefresh.ShowTaskList);
        Assert.False(afterLayoutRefresh.ShowDetail);
        Assert.False(afterLayoutRefresh.ShowWideEmptyState);
        Assert.False(afterLayoutRefresh.ShowSplitter);
    }

    [Theory]
    [InlineData(false, false, true, false)]
    [InlineData(false, true, false, true)]
    [InlineData(true, false, true, false)]
    [InlineData(true, true, true, true)]
    public void ReturningToTasksRestoresMasterDetailPanes(
        bool isDualPane, bool hasDetail, bool showTaskList, bool showDetail)
    {
        TodoPaneVisibility panes = TodoPaneVisibilityPolicy.Resolve(false, isDualPane, hasDetail);

        Assert.Equal(showTaskList, panes.ShowTaskList);
        Assert.Equal(showDetail, panes.ShowDetail);
        Assert.Equal(isDualPane, panes.ShowSplitter);
    }
}
