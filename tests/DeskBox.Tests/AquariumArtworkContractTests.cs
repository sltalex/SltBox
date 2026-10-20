using System.Xml.Linq;
using System.Text.Json;
using DeskBox.Controls.WidgetContents;

namespace DeskBox.Tests;

public sealed class AquariumArtworkContractTests
{
    [Fact]
    public void AllVectorChoicesHaveSelfContainedSvgAndPackagedPng()
    {
        string assets = Path.Combine(AppContext.BaseDirectory, "Assets", "Aquarium");
        string[] originals = Directory.GetFiles(Path.Combine(assets, "Svg"), "*.svg");
        Assert.Equal(350, originals.Length);
        foreach (string original in originals)
        {
            string key = Path.GetFileNameWithoutExtension(original);
            Assert.True(AquariumArtworkCatalog.TryGetProfile(key, out var profile), key);
            Assert.InRange(profile.Width, 40, 320);
            Assert.InRange(profile.Height, 30, 250);
            string exported = Path.Combine(AppContext.BaseDirectory, AquariumArtworkCatalog.AssetRelativePath(key));
            Assert.True(File.Exists(exported), exported);
            byte[] signature = File.ReadAllBytes(exported).Take(8).ToArray();
            Assert.Equal(new byte[] { 137, 80, 78, 71, 13, 10, 26, 10 }, signature);
            XElement root = XDocument.Load(original).Root!;
            Assert.Equal("svg", root.Name.LocalName);
            Assert.NotNull(root.Attribute("viewBox"));
            Assert.DoesNotContain(root.Descendants(), element =>
                element.Name.LocalName is "image" or "script" or "foreignObject" or "filter");
            Assert.DoesNotContain(root.DescendantsAndSelf().Attributes(), attribute =>
                attribute.Name.LocalName == "href" && !attribute.Value.StartsWith('#'));
        }
    }

    [Theory]
    [InlineData("great-white-shark")]
    [InlineData("whale-shark")]
    [InlineData("scalloped-hammerhead")]
    public void LargeSharksRemainVisiblyLargerThanSmallSchoolingFish(string shark)
    {
        Assert.True(AquariumArtworkCatalog.TryGetProfile(shark, out var large));
        Assert.True(AquariumArtworkCatalog.TryGetProfile("sardine", out var small));
        Assert.True(large.Width > small.Width * 2);
        Assert.True(large.Height > small.Height);
    }

    [Theory]
    [InlineData("background")]
    [InlineData("unknown-future-item")]
    public void LegacyAndUnknownAssetsRetainTheirOriginalResourcePath(string key)
    {
        Assert.Equal($"Assets/Aquarium/{key}.png", AquariumArtworkCatalog.AssetRelativePath(key));
    }

    [Theory]
    [InlineData("shrimp", 10)]
    [InlineData("crabs", 10)]
    [InlineData("shellfish", 10)]
    public void CartoonCategoriesHaveTenDistinctLocalisedVectorChoices(string group, int count)
    {
        DirectoryInfo? root = new(AppContext.BaseDirectory);
        while (root is not null && !File.Exists(Path.Combine(root.FullName, "DeskBox.sln"))) root = root.Parent;
        Assert.NotNull(root);
        using JsonDocument plan = JsonDocument.Parse(File.ReadAllText(Path.Combine(root.FullName,
            "docs", "design", "aquarium-cartoon-expansion-plan.json")));
        JsonElement[] entries = plan.RootElement.GetProperty(group).EnumerateArray().ToArray();
        Assert.Equal(count, entries.Length);
        Assert.Equal(count, entries.Select(item => item.GetProperty("id").GetString()).Distinct().Count());
        foreach (string locale in Directory.GetFiles(Path.Combine(root.FullName, "src", "DeskBox", "Strings"), "*.json"))
        {
            using JsonDocument strings = JsonDocument.Parse(File.ReadAllText(locale));
            foreach (JsonElement entry in entries)
            {
                string key = entry.GetProperty("id").GetString()!;
                Assert.True(AquariumArtworkCatalog.TryGetProfile(key, out _), key);
                Assert.Equal($"Assets/Aquarium/Vector/{key}.png", AquariumArtworkCatalog.AssetRelativePath(key));
                Assert.True(strings.RootElement.TryGetProperty("Aquarium.Item." + key, out var name), $"{locale}: {key}");
                Assert.False(string.IsNullOrWhiteSpace(name.GetString()));
            }
        }
    }

    [Fact]
    public void SardinesUseTheExistingSchoolMotionAndDifferFromLargeWhaleSharks()
    {
        AquariumFishMotionInput[] fish = Enumerable.Range(1, 8)
            .Select(index => new AquariumFishMotionInput($"sardine-{index}", "sardine", .2 + index * .04, .4))
            .Append(new AquariumFishMotionInput("whale", "whale-shark", .5, .4)).ToArray();
        var plans = AquariumFishMotionPlanner.Build(fish, 840, 600, 42);
        Assert.Equal(new[] { 4, 4 }, plans.Where(item => item.Key != "whale").Select(item => item.Value)
            .GroupBy(plan => plan.SchoolId).Select(group => group.Count()).OrderBy(count => count).ToArray());
        Assert.True(plans["sardine-1"].SpeedMultiplier > plans["whale"].SpeedMultiplier);
    }

    [Theory]
    [InlineData("duckweed")]
    [InlineData("salvinia")]
    [InlineData("frogbit")]
    [InlineData("water-lettuce")]
    [InlineData("amazon-frogbit")]
    public void FloatingPlantsStartNearSurfaceAndDoNotJumpWhenDraggedHorizontally(string key)
    {
        double initialY = AquariumArtworkCatalog.DefaultPlacementY(key, false, 0);
        Assert.Equal(.16, initialY);
        Assert.Equal(.3, AquariumArtworkCatalog.VerticalAnchor(key, false));
        Assert.Equal(initialY, AquariumArtworkCatalog.ClampPlacementY(key, false, initialY, .2));
    }
}
