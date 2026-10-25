using DeskBox.Services;

namespace DeskBox.Tests;

public sealed class TreeDecorationSceneDraftTests
{
    [Fact]
    public void AddUpdateAndUndo_AllowDistinctInstancesOfTheSameItem()
    {
        var draft = new TreeDecorationSceneDraft([], null);

        Assert.True(draft.TryAdd("red-lantern", out string firstId, .25, .35));
        Assert.True(draft.TryAdd("red-lantern", out string secondId, .8, .8));
        Assert.NotEqual(firstId, secondId);
        Assert.True(draft.TryUpdate(firstId, -1, 2, 9, 90, 8));
        TreeDecorationPlacement placement = Assert.Single(draft.Placements, item => item.PlacementId == firstId);
        Assert.Equal(0, placement.X);
        Assert.Equal(1, placement.Y);
        Assert.Equal(1.5, placement.Scale);
        Assert.Equal(45, placement.Rotation);
        Assert.Equal(2, placement.Layer);

        Assert.True(draft.CanUndo);
        draft.Undo();
        Assert.Equal(2, draft.Placements.Count);
        Assert.Equal(.25, Assert.Single(draft.Placements, item => item.PlacementId == firstId).X);
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
        string placementId = Assert.Single(draft.Placements, item => item.ItemId == "item-0").PlacementId;
        Assert.True(draft.Remove(placementId));
        Assert.DoesNotContain(draft.Placements, item => item.PlacementId == placementId);
    }
}
