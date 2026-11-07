using System.Text.Json;
using DeskBox.Services;

namespace DeskBox.Tests;

public sealed class FruitTreeGroupsTests
{
    [Fact]
    public void SameTypeHasOneIconAndExactQuantityAndOtherTypesStaySeparate()
    {
        var groups = FruitTreeGroups.Create([
            new(1, "apple"), new(2, "apple"), new(3, "pear"), new(4, "apple")], []);
        Assert.Equal(2, groups.Count);
        Assert.Equal(new FruitOnTreeGroup(1, "apple", 3, FruitTreeGroups.PositionIndex("apple", [])), groups[0]);
        Assert.Equal(1, groups[1].Quantity);
    }

    [Fact]
    public void PositionSurvivesQuantityChangesAndRemovingOtherTypes()
    {
        var before = FruitTreeGroups.Create([new(1, "apple"), new(2, "pear"), new(3, "pear")], []);
        var after = FruitTreeGroups.Create([new(3, "pear")], []);
        Assert.Equal(before[1].PositionIndex, after[0].PositionIndex);
        Assert.Equal(1, after[0].Quantity);
    }

    [Fact]
    public void AccountInventoryAcceptsQuantitiesAboveIntMaxValue()
    {
        using var json = JsonDocument.Parse("""{"inventory":[{"fruit_id":"apple","quantity":3000000000,"discovered":true}]}""");
        Assert.Equal(3_000_000_000L, Assert.Single(FruitJson.ReadInventory(json.RootElement)).Quantity);
    }
}
