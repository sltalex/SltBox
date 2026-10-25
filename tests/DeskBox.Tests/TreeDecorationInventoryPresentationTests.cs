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
}
