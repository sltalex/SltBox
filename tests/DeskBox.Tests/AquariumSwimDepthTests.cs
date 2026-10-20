using DeskBox.Controls.WidgetContents;
using DeskBox.Services;

namespace DeskBox.Tests;

public sealed class AquariumSwimDepthTests
{
    [Fact]
    public void SwimmingCreaturesOccupyTenOrderedDepthsWithoutChangingSavedLayout()
    {
        AquariumPlacement[] saved = Enumerable.Range(1, 200)
            .Select(index => new AquariumPlacement($"fish-{index}", .5, .5, 1))
            .Append(new AquariumPlacement("rock", .4, .8, 1.2, 0))
            .ToArray();
        var categories = saved.ToDictionary(item => item.InstanceId,
            item => item.InstanceId == "rock" ? "decoration" : "fish");
        AquariumPlacement[] original = saved.ToArray();

        IReadOnlyList<AquariumPlacement> display = AquariumSwimDepth.ForDisplay(saved, categories);
        AquariumPlacement[] fish = display.Where(item => item.InstanceId != "rock").ToArray();
        AquariumPlacement[] byDepth = fish.OrderBy(item => item.Layer).ToArray();

        Assert.Equal(10, fish.Select(item => item.Layer).Distinct().Count());
        Assert.All(fish, item => Assert.InRange(item.Layer, AquariumLayer.Min, AquariumLayer.Max));
        IGrouping<int, AquariumPlacement>[] layers = byDepth.GroupBy(item => item.Layer).ToArray();
        for (int index = 1; index < layers.Length; index++)
        {
            Assert.True(layers[index - 1].Max(item => item.Y) < layers[index].Min(item => item.Y));
            Assert.True(layers[index - 1].Max(item => item.Scale) < layers[index].Min(item => item.Scale));
        }
        Assert.Equal(original, saved);
        Assert.Equal(saved[^1], Assert.Single(display, item => item.InstanceId == "rock"));
        Assert.Equal(display, AquariumSwimDepth.ForDisplay(saved, categories));
        int rockIndex = display.ToList().FindIndex(item => item.InstanceId == "rock");
        Assert.All(display.Take(rockIndex), item => Assert.True(item.Layer <= 0));
        Assert.All(display.Skip(rockIndex + 1), item => Assert.True(item.Layer >= 0));
    }

    [Fact]
    public void ShrimpAndCrabsUseSwimmingDepthWhileRootedItemsKeepTheirPlacement()
    {
        AquariumPlacement[] saved =
        [
            new("shrimp", .2, .8, 1.3, 0),
            new("crab", .8, .8, .9, 0),
            new("jellyfish", .5, .4, 1.0, 0),
            new("plant", .5, .9, 1.1, -1),
            new("snail", .6, .9, 1, 1)
        ];
        var categories = new Dictionary<string, string>
        {
            ["shrimp"] = "shrimp", ["crab"] = "crab", ["jellyfish"] = "jellyfish",
            ["plant"] = "plant", ["snail"] = "snail"
        };

        IReadOnlyList<AquariumPlacement> display = AquariumSwimDepth.ForDisplay(saved, categories);

        Assert.All(display.Where(item => item.InstanceId is "shrimp" or "crab" or "jellyfish"), item =>
            Assert.NotEqual(item.InstanceId == "jellyfish" ? .4 : .8, item.Y));
        Assert.Equal(Assert.Single(saved, item => item.InstanceId == "plant"),
            Assert.Single(display, item => item.InstanceId == "plant"));
        Assert.Equal(Assert.Single(saved, item => item.InstanceId == "snail"),
            Assert.Single(display, item => item.InstanceId == "snail"));
    }
}
