using DeskBox.Services;

namespace DeskBox.Tests;

public sealed class TreeDecorationSceneProjectionTests
{
    [Fact]
    public void DesktopSceneKeepsEverySavedInstanceAtMatureLocalStage()
    {
        var item = new TreeDecorationCatalogItem("lantern", "Lantern", "tree", "common", "lantern");
        var theme = new TreeDecorationCatalogItem("spring", "Spring", "theme", "theme", "spring");
        var catalog = new TreeDecorationCatalog(1, 200, 1800, [item, theme]);
        TreeDecorationPlacement[] placements =
        [
            new("first", "lantern", .2, .3, 1, 0, 1),
            new("second", "lantern", .7, .6, 1, 0, -1)
        ];
        var state = new TreeDecorationState(false, 0, 0, 2,
            [new("lantern", 2), new("spring", 1)], [], placements, "spring");

        TreeDecorationDisplayScene scene = TreeDecorationSceneProjection.ForDesktop(5, state, catalog);

        Assert.Equal("spring", scene.Theme?.Id);
        Assert.Equal(["second", "first"], scene.Placements.Select(x => x.Placement.PlacementId));
        Assert.Equal(placements[1], scene.Placements[0].Placement);
        Assert.Equal(placements[0], scene.Placements[1].Placement);
        Assert.Empty(TreeDecorationSceneProjection.ForDesktop(4, state, catalog).Placements);
    }

    [Fact]
    public void InventoryLayoutStartsANewRowAfterFourCards()
    {
        Assert.Equal(4, TreeDecorationInventoryLayout.ColumnCount);
        Assert.Equal(608, TreeDecorationInventoryLayout.RowWidth);
        Assert.Equal((0, 0), TreeDecorationInventoryLayout.PositionOf(0));
        Assert.Equal((0, 3), TreeDecorationInventoryLayout.PositionOf(3));
        Assert.Equal((1, 0), TreeDecorationInventoryLayout.PositionOf(4));
        Assert.Equal((4, 1), TreeDecorationInventoryLayout.PositionOf(17));
        Assert.Equal(492, TreeDecorationInventoryLayout.SettingsRowWidth);
        Assert.Equal(4, TreeDecorationInventoryLayout.SettingsColumnsForWidth(492));
    }

    [Theory]
    [InlineData(456, 3)]
    [InlineData(440, 2)]
    [InlineData(728, 4)]
    [InlineData(145, 1)]
    public void InventoryLayoutFitsAvailableSettingsWidth(double availableWidth, int expectedColumns)
    {
        int columns = TreeDecorationInventoryLayout.ColumnsForWidth(availableWidth);

        Assert.Equal(expectedColumns, columns);
        if (availableWidth >= TreeDecorationInventoryLayout.CardWidth)
            Assert.True(TreeDecorationInventoryLayout.RowWidthForColumns(columns) <= availableWidth);
        Assert.Equal((1, 0), TreeDecorationInventoryLayout.PositionOf(columns, columns));
    }
}
