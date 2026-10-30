using DeskBox.Services;

namespace DeskBox.Tests;

public sealed class TreeDecorationInventoryPresentationTests
{
    [Fact]
    public void SummaryPreservesOwnedAndPlacedCountsForDuplicateDecorations()
    {
        TreeDecorationCatalogItem[] catalog =
            [new("red-lantern", "Red lantern", "tree", "common", "red-lantern")];
        TreeDecorationInventoryItem[] inventory = [new("red-lantern", 3)];
        TreeDecorationPlacement[] placements =
        [
            new("first", "red-lantern", .25, .35, 1, 0, 0),
            new("second", "red-lantern", .75, .65, 1, 0, 1)
        ];

        TreeDecorationOwnedProduct product = Assert.Single(
            TreeDecorationInventoryPresentation.Summarize(inventory, placements, catalog));

        Assert.Equal(3, product.OwnedCount);
        Assert.Equal(2, product.PlacedCount);
        Assert.True(product.CanPlace);
        Assert.True(product.CanRecall);
    }

    [Fact]
    public void ExpandedDecorationsUseCatalogChineseNameWhenNoTranslationKeyExists()
    {
        var item = new TreeDecorationCatalogItem("tree-new", "月光铜铃", "tree", "common", "tree-new");

        Assert.Equal("月光铜铃", TreeDecorationInventoryPresentation.DisplayName(item, key => key));
        Assert.Equal("Translated name", TreeDecorationInventoryPresentation.DisplayName(item, _ => "Translated name"));
    }

    [Fact]
    public void PackResultLabelIncludesLocalizedSubcategory()
    {
        var item = new TreeDecorationCatalogItem("tree-new", "月光铜铃", "tree", "common", "tree-new")
        {
            Subcategory = "lanterns"
        };

        Assert.Equal("月光铜铃 · 灯饰", TreeDecorationInventoryPresentation.PackResultLabel(item, key => key));
        Assert.Equal("Display · Subcategory", TreeDecorationInventoryPresentation.PackResultLabel(
            item,
            key => key.EndsWith("tree-new", StringComparison.Ordinal)
                ? "Display"
                : "Subcategory"));
    }

    [Fact]
    public void LegacyCatalogFallsBackToReadablePlacementCategory()
    {
        var item = new TreeDecorationCatalogItem("legacy", "老装饰", "tree", "common", "legacy");

        Assert.Equal("老装饰 · 树上", TreeDecorationInventoryPresentation.PackResultLabel(item, key => key));
    }

    [Fact]
    public void LegacyThemesStayOutOfPlaceableInventory()
    {
        TreeDecorationCatalogItem[] catalog =
        [new("lantern", "灯笼", "tree", "common", "lantern"), new("spring", "春日花园", "theme", "theme", "spring")];

        TreeDecorationOwnedProduct owned = Assert.Single(TreeDecorationInventoryPresentation.Summarize(
            [new("lantern", 1), new("spring", 1)], [], catalog));

        Assert.Equal("lantern", owned.Product.Id);
    }
}
