using DeskBox.Services;

namespace DeskBox.Tests;

public sealed class AquariumInventoryPresentationTests
{
    [Fact]
    public void PartlyPlacedSpeciesCanAddAnotherOwnedInstanceAndRecallAnExistingOne()
    {
        AquariumInventoryItem[] inventory =
        [
            new("fish-1", "sardine", "now"),
            new("fish-2", "sardine", "now"),
            new("fish-3", "sardine", "now"),
            new("rock-1", "reef-rock", "now"),
            new("unknown-1", "unknown", "now")
        ];
        AquariumCatalogItem[] catalog =
        [
            new("sardine", "Sardine", "fish", 140, "sardine"),
            new("reef-rock", "Reef rock", "decoration", 180, "reef-rock")
        ];
        AquariumPlacement[] partlyPlaced = [new("fish-1", .2, .4, 1)];

        IReadOnlyList<AquariumOwnedProduct> groups =
            AquariumInventoryPresentation.Summarize(inventory, partlyPlaced, catalog);

        Assert.Equal(2, groups.Count);
        AquariumOwnedProduct fish = Assert.Single(groups, item => item.Product.Category == "fish");
        Assert.Equal(3, fish.OwnedCount);
        Assert.Equal(1, fish.PlacedCount);
        Assert.Equal("fish-2", fish.NextToPlace?.Id);
        Assert.Equal("fish-1", fish.NextToRecall?.Id);
        Assert.Equal("rock-1", Assert.Single(groups, item => item.Product.Category == "decoration").NextToPlace?.Id);

        AquariumPlacement[] allPlaced =
        [
            new("fish-1", .2, .4, 1),
            new("fish-2", .3, .4, 1),
            new("fish-3", .4, .4, 1)
        ];
        fish = Assert.Single(AquariumInventoryPresentation.Summarize(inventory, allPlaced, catalog),
            item => item.Product.Category == "fish");
        Assert.Null(fish.NextToPlace);
        Assert.Equal("fish-3", fish.NextToRecall?.Id);
    }

    [Fact]
    public void StoreAndBackpackExposeTopLevelCategoriesWithoutAnExternalSaveButton()
    {
        string root = FindRepositoryRoot();
        string settings = File.ReadAllText(Path.Combine(root, "src", "DeskBox", "Views", "SettingsSections", "AquariumSettingsSection.cs"));
        string backpack = File.ReadAllText(Path.Combine(root, "src", "DeskBox", "Views", "SettingsSections", "AquariumBackpackSection.xaml"));
        string navigation = File.ReadAllText(Path.Combine(root, "src", "DeskBox", "Views", "SettingsWindow.DeferredSections.cs"));

        Assert.Contains("_storeCategories.Children.Add(button)", settings, StringComparison.Ordinal);
        Assert.DoesNotContain("_storeCategoryFilter", settings, StringComparison.Ordinal);
        Assert.Contains("BackpackPrimaryCategories", backpack, StringComparison.Ordinal);
        Assert.Contains("BackpackCategories", backpack, StringComparison.Ordinal);
        Assert.DoesNotContain("SaveLayout", backpack, StringComparison.Ordinal);
        Assert.Contains("backpack.AquariumRequested += () => NavigateToSettingsSection(\"AquariumSettings\")", navigation,
            StringComparison.Ordinal);
    }

    private static string FindRepositoryRoot()
    {
        DirectoryInfo? directory = new(AppContext.BaseDirectory);
        while (directory is not null)
        {
            if (File.Exists(Path.Combine(directory.FullName, "DeskBox.sln"))) return directory.FullName;
            directory = directory.Parent;
        }
        throw new DirectoryNotFoundException("DeskBox repository root was not found.");
    }
}
