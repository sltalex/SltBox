using System.Text.Json;
using DeskBox.Controls.WidgetContents;
using DeskBox.Services;

namespace DeskBox.Tests;

public sealed class AquariumExpansionTests
{
    [Theory]
    [InlineData("coral")]
    [InlineData("algae")]
    [InlineData("snail")]
    [InlineData("jellyfish")]
    public void NewCategoriesRemainAvailableForPlacementAndRecall(string category)
    {
        AquariumInventoryItem[] inventory = [new("first", "item", "now"), new("second", "item", "now")];
        AquariumCatalogItem[] catalog = [new("item", "Item", category, 100, "item")];
        AquariumPlacement[] scene = [new("first", .4, .9, 1)];
        var owned = Assert.Single(AquariumInventoryPresentation.Summarize(inventory, scene, catalog));
        Assert.Contains(category, AquariumInventoryPresentation.Categories);
        Assert.Equal(2, owned.OwnedCount);
        Assert.Equal(1, owned.PlacedCount);
        Assert.Equal("first", owned.NextToRecall?.Id);
        Assert.Equal("second", owned.NextToPlace?.Id);
    }

    [Theory]
    [InlineData(null)]
    [InlineData("freshwater")]
    [InlineData("tropical")]
    [InlineData("marine")]
    public void FishHabitatSurvivesExplicitJsonCacheRoundTrip(string? habitat)
    {
        AquariumCatalogItem[] source = [new("fish", "Fish", "fish", 100, "fish", habitat)];
        using var json = JsonDocument.Parse(AquariumJson.Write(writer => AquariumJson.WriteCatalog(writer, source)));
        Assert.Equal(source, AquariumJson.ReadCatalog(json.RootElement));
        foreach (string filter in AquariumInventoryPresentation.FishHabitats)
            Assert.Equal(filter == "all" || filter == habitat,
                AquariumInventoryPresentation.MatchesHabitat(source[0], filter));
    }

    [Fact]
    public void OlderCatalogWithoutHabitatIsStillReadableInAllFish()
    {
        using var json = JsonDocument.Parse("""[{"id":"clownfish","name":"Clownfish","category":"fish","price":100,"asset_key":"clownfish"}]""");
        var product = Assert.Single(AquariumJson.ReadCatalog(json.RootElement));
        Assert.Null(product.Habitat);
        Assert.True(AquariumInventoryPresentation.MatchesHabitat(product, "all"));
        Assert.False(AquariumInventoryPresentation.MatchesHabitat(product, "marine"));
    }

    [Theory]
    [InlineData("aquarium-fish-expansion-plan.json", "fish", 150)]
    [InlineData("aquarium-reef-expansion-plan.json", "corals", 20)]
    [InlineData("aquarium-reef-expansion-plan.json", "algae", 20)]
    [InlineData("aquarium-snail-expansion-plan.json", "snails", 20)]
    [InlineData("aquarium-jellyfish-expansion-plan.json", "jellyfish", 10)]
    public void ExpansionManifestHasDistinctPackagedAndLocalizedArtwork(string manifest, string group, int count)
    {
        string root = RepositoryRoot();
        using var json = JsonDocument.Parse(File.ReadAllText(Path.Combine(root, "docs", "design", manifest)));
        var entries = json.RootElement.GetProperty(group).EnumerateArray().ToArray();
        Assert.Equal(count, entries.Length);
        Assert.Equal(count, entries.Select(item => item.GetProperty("id").GetString()).Distinct().Count());
        foreach (var entry in entries)
        {
            string id = entry.GetProperty("id").GetString()!;
            Assert.True(AquariumArtworkCatalog.TryGetProfile(id, out var profile), id);
            Assert.True(File.Exists(Path.Combine(AppContext.BaseDirectory, AquariumArtworkCatalog.AssetRelativePath(id))), id);
            if (group == "jellyfish")
            {
                Assert.Equal(entry.GetProperty("width").GetDouble(), profile.Width);
                Assert.Equal(entry.GetProperty("height").GetDouble(), profile.Height);
                Assert.Equal(entry.GetProperty("speed").GetDouble(), profile.SpeedMultiplier);
                Assert.True(AquariumSwimDepth.IsSwimming("jellyfish"));
                Assert.False(AquariumStoreBrowse.Matches(new(id, id, "jellyfish", 100, id), "pack", "all"));
            }
            else if (group != "fish")
            {
                Assert.Equal(0, profile.SpeedMultiplier);
                Assert.Equal(1, AquariumArtworkCatalog.VerticalAnchor(id, false));
                Assert.InRange(AquariumArtworkCatalog.DefaultPlacementY(id, false, 0), .8, .98);
            }
        }
        foreach (string locale in Directory.GetFiles(Path.Combine(root, "src", "DeskBox", "Strings"), "*.json"))
        {
            using var strings = JsonDocument.Parse(File.ReadAllText(locale));
            foreach (var entry in entries)
            {
                string key = "Aquarium.Item." + entry.GetProperty("id").GetString();
                Assert.True(strings.RootElement.TryGetProperty(key, out var name), $"{locale}: {key}");
                Assert.False(string.IsNullOrWhiteSpace(name.GetString()));
            }
            foreach (string category in new[] { "coral", "algae", "snail", "jellyfish" })
                Assert.True(strings.RootElement.TryGetProperty("Aquarium.Category." + category, out _));
            foreach (string habitat in AquariumInventoryPresentation.FishHabitats)
                Assert.True(strings.RootElement.TryGetProperty("Aquarium.Habitat." + habitat, out _));
        }
    }

    [Fact]
    public void FishHaveOneDisplayHabitatAndDoNotDuplicateExistingSpecies()
    {
        string root = RepositoryRoot();
        using var expanded = JsonDocument.Parse(File.ReadAllText(Path.Combine(root, "docs", "design", "aquarium-fish-expansion-plan.json")));
        var entries = expanded.RootElement.GetProperty("fish").EnumerateArray().ToArray();
        Assert.Equal(150, entries.Select(item => item.GetProperty("scientific_name").GetString()).Distinct().Count());
        Assert.Equal(new[] { "freshwater", "marine", "tropical" }, entries
            .Select(item => item.GetProperty("habitat").GetString()).Distinct().Order().ToArray());
        using var original = JsonDocument.Parse(File.ReadAllText(Path.Combine(root, "docs", "design", "aquarium-realistic-expansion-plan.json")));
        foreach (var old in original.RootElement.GetProperty("fish").EnumerateArray())
        {
            var current = Assert.Single(entries, item => item.GetProperty("id").GetString() == old.GetProperty("id").GetString());
            Assert.Equal(old.GetProperty("name").GetString(), current.GetProperty("name").GetString());
            Assert.Equal(old.GetProperty("scientific_name").GetString(), current.GetProperty("scientific_name").GetString());
        }
    }

    private static string RepositoryRoot()
    {
        DirectoryInfo? root = new(AppContext.BaseDirectory);
        while (root is not null && !File.Exists(Path.Combine(root.FullName, "DeskBox.sln"))) root = root.Parent;
        return root?.FullName ?? throw new DirectoryNotFoundException("DeskBox repository root was not found.");
    }
}
