using DeskBox.Controls.WidgetContents;
using DeskBox.Models;

namespace DeskBox.Tests;

public sealed class AquariumVisibilityTests
{
    [Fact]
    public void VisibleSceneDoesNotRequireActivationAndKeepsPlayingAfterFocusLeaves()
    {
        using var adapter = Create();
        adapter.OnWindowVisibilityChanged(true);
        Assert.True(adapter.IsSceneVisible);
        adapter.OnActivated();
        adapter.OnDeactivated();
        Assert.True(adapter.IsSceneVisible);
    }

    [Fact]
    public void CoveredSceneResumesWithoutClickingButHiddenAndCompactStayPaused()
    {
        using var adapter = Create();
        adapter.OnWindowVisibilityChanged(true);
        adapter.OnWindowOcclusionChanged(true);
        Assert.False(adapter.IsSceneVisible);
        adapter.OnWindowOcclusionChanged(false);
        Assert.True(adapter.IsSceneVisible);
        adapter.OnCompactStateChanged(true);
        Assert.False(adapter.IsSceneVisible);
        adapter.OnCompactStateChanged(false);
        Assert.True(adapter.IsSceneVisible);
        adapter.OnWindowVisibilityChanged(false);
        adapter.OnWindowOcclusionChanged(false);
        Assert.False(adapter.IsSceneVisible);
    }

    private static AquariumWidgetContentAdapter Create() =>
        new(new WidgetConfig { WidgetKind = WidgetKind.Aquarium },
            () => throw new InvalidOperationException("Lifecycle tests must not materialize WinUI."), () => true);
}
