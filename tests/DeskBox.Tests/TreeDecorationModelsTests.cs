using System.Text.Json;
using DeskBox.Services;

namespace DeskBox.Tests;

public sealed class TreeDecorationModelsTests
{
    [Fact]
    public void CatalogAndStateContractsPreserveFreePlacementAndDrawResults()
    {
        using JsonDocument catalogJson = JsonDocument.Parse("""
            {"catalog_version":1,"single_price":200,"ten_price":1800,
             "rates":{"common":70,"rare":25,"theme":5},"items":[
              {"id":"red-lantern","name":"红灯笼","category":"tree","rarity":"common","asset_key":"red-lantern"}]}
            """);
        TreeDecorationCatalog catalog = TreeDecorationJson.ReadCatalog(catalogJson.RootElement);
        Assert.Equal(1, catalog.Version);
        Assert.Equal(200, catalog.SinglePrice);
        Assert.Equal(1800, catalog.TenPrice);
        Assert.Equal("red-lantern", Assert.Single(catalog.Items).Id);

        using JsonDocument stateJson = JsonDocument.Parse("""
            {"mature":true,"coins":800,"shards":30,"scene_version":4,
             "owned_ids":["red-lantern"],"active_theme_id":null,
             "placements":[{"item_id":"red-lantern","x":0.35,"y":0.42,
              "scale":1.2,"rotation":-15,"layer":1}],
             "draw_results":[{"item_id":"red-lantern","rarity":"common",
              "is_new":false,"shards_awarded":10}]}
            """);
        TreeDecorationState state = TreeDecorationJson.ReadState(stateJson.RootElement);
        TreeDecorationPlacement placement = Assert.Single(state.Placements);
        Assert.Equal(new TreeDecorationPlacement("red-lantern", .35, .42, 1.2, -15, 1), placement);
        TreeDecorationDrawResult result = Assert.Single(TreeDecorationJson.ReadDrawResults(stateJson.RootElement));
        Assert.False(result.IsNew);
        Assert.Equal(10, result.ShardsAwarded);
    }

    [Theory]
    [InlineData(-.01, .5, 1, 0, 0)]
    [InlineData(.5, 1.01, 1, 0, 0)]
    [InlineData(.5, .5, .49, 0, 0)]
    [InlineData(.5, .5, 1.51, 0, 0)]
    [InlineData(.5, .5, 1, -45.1, 0)]
    [InlineData(.5, .5, 1, 45.1, 0)]
    [InlineData(.5, .5, 1, 0, -3)]
    [InlineData(.5, .5, 1, 0, 3)]
    public void PlacementValidationRejectsValuesOutsideServerContract(
        double x, double y, double scale, double rotation, int layer)
    {
        Assert.False(TreeDecorationPlacementRules.IsValid(
            new("red-lantern", x, y, scale, rotation, layer)));
    }
}
