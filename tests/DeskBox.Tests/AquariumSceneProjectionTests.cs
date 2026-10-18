using DeskBox.Services;
using System.Text.Json;

namespace DeskBox.Tests;

public sealed class AquariumSceneProjectionTests
{
    [Fact]
    public void FirstUseInventoryFishAreVisibleWithoutMutatingScene()
    {
        AquariumState state = State(0,
            new AquariumInventoryItem("fish-1", "clownfish", "now"),
            new AquariumInventoryItem("plant-1", "seagrass", "now"),
            new AquariumInventoryItem("fish-2", "blue-tang", "now"));

        IReadOnlyList<AquariumPlacement> projected = AquariumSceneProjection.ForDisplay(
            state, Catalog(), includeFirstUseFish: true);

        Assert.Equal(new[] { "fish-1", "fish-2" }, projected.Select(item => item.InstanceId));
        Assert.Empty(state.Scene);
        Assert.Equal(0, state.SceneVersion);
    }

    [Fact]
    public void ExplicitlySavedEmptySceneStaysEmpty()
    {
        AquariumState state = State(1,
            new AquariumInventoryItem("fish-1", "clownfish", "now"));

        Assert.Empty(AquariumSceneProjection.ForDisplay(state, Catalog(), includeFirstUseFish: true));
    }

    [Fact]
    public void ExistingSceneIsReturnedUnchanged()
    {
        AquariumPlacement placement = new("fish-1", .7, .5, 1.1);
        AquariumState state = State(0,
            new AquariumInventoryItem("fish-1", "clownfish", "now"),
            scene: placement);

        IReadOnlyList<AquariumPlacement> projected = AquariumSceneProjection.ForDisplay(
            state, Catalog(), includeFirstUseFish: true);

        Assert.Single(projected);
        Assert.Same(state.Scene, projected);
        Assert.Equal(placement, projected[0]);
    }

    [Fact]
    public void SceneLayerDefaultsForOldLayoutsAndRoundTripsNewLayouts()
    {
        using JsonDocument legacy = JsonDocument.Parse(
            "[{\"instance_id\":\"plant-1\",\"x\":0.4,\"y\":0.7,\"scale\":1}]");
        Assert.Equal(0, Assert.Single(AquariumJson.ReadScene(legacy.RootElement)).Layer);

        byte[] bytes = AquariumJson.Write(writer => AquariumJson.WriteScene(writer,
            [new AquariumPlacement("rock-1", .3, .8, 1.1, -1)]));
        using JsonDocument saved = JsonDocument.Parse(bytes);
        Assert.Equal(-1, Assert.Single(AquariumJson.ReadScene(saved.RootElement)).Layer);
    }

    private static AquariumState State(long sceneVersion, params AquariumInventoryItem[] inventory) =>
        new(true, 100, sceneVersion, inventory, Array.Empty<AquariumPlacement>(), Array.Empty<AquariumWalletEntry>());

    private static AquariumState State(long sceneVersion, AquariumInventoryItem inventory, AquariumPlacement scene) =>
        new(true, 100, sceneVersion, new[] { inventory }, new[] { scene }, Array.Empty<AquariumWalletEntry>());

    private static IReadOnlyList<AquariumCatalogItem> Catalog() =>
    [
        new("clownfish", "小丑鱼", "fish", 100, "clownfish"),
        new("blue-tang", "蓝吊鱼", "fish", 180, "blue-tang"),
        new("seagrass", "海草丛", "plant", 60, "seagrass")
    ];
}
