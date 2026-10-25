using DeskBox.Services;

namespace DeskBox.Tests;

public sealed class TreeDecorationSceneDraftTests
{
    [Fact]
    public void AddUpdateAndUndo_EnforceUniqueItemsAndPlacementBounds()
    {
        var draft = new TreeDecorationSceneDraft([], null);

        Assert.True(draft.TryAdd("red-lantern", .25, .35));
        Assert.False(draft.TryAdd("red-lantern", .8, .8));
        Assert.True(draft.TryUpdate("red-lantern", -1, 2, 9, 90, 8));
        TreeDecorationPlacement placement = Assert.Single(draft.Placements);
        Assert.Equal(0, placement.X);
        Assert.Equal(1, placement.Y);
        Assert.Equal(1.5, placement.Scale);
        Assert.Equal(45, placement.Rotation);
        Assert.Equal(2, placement.Layer);

        Assert.True(draft.CanUndo);
        draft.Undo();
        Assert.Equal(new TreeDecorationPlacement("red-lantern", .25, .35, 1, 0, 0),
            Assert.Single(draft.Placements));
    }

    [Fact]
    public void ThemeSelection_IsExclusiveAndUndoable()
    {
        var draft = new TreeDecorationSceneDraft([], "spring-garden");

        draft.SetTheme("winter-starlight");
        Assert.Equal("winter-starlight", draft.ActiveThemeId);
        draft.SetTheme(null);
        Assert.Null(draft.ActiveThemeId);
        draft.Undo();
        Assert.Equal("winter-starlight", draft.ActiveThemeId);
    }

    [Fact]
    public void SceneLimitAndReturnToBackpack_AreApplied()
    {
        var draft = new TreeDecorationSceneDraft([], null);
        for (int index = 0; index < TreeDecorationPlacementRules.MaximumPlacements; index++)
            Assert.True(draft.TryAdd($"item-{index}", .5, .5));

        Assert.False(draft.TryAdd("overflow", .5, .5));
        Assert.True(draft.Remove("item-0"));
        Assert.DoesNotContain(draft.Placements, item => item.ItemId == "item-0");
    }
}
