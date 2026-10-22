using DeskBox.Services;
using System.Xml.Linq;

namespace DeskBox.Tests;

public sealed class AquariumInventoryPresentationTests
{
    [Fact]
    public void StoreProductGridReceivesAConstrainedFullWidthMeasure()
    {
        string path = Path.Combine(FindRepositoryRoot(), "src", "DeskBox", "Views",
            "SettingsSections", "AquariumStoreSection.xaml");
        XNamespace toolkit = "using:CommunityToolkit.WinUI.Controls";
        XElement[] cards = XDocument.Load(path).Descendants(toolkit + "SettingsCard").ToArray();
        Assert.Equal(2, cards.Length);
        Assert.All(cards, card =>
        {
            Assert.Equal("Vertical", (string?)card.Attribute("ContentAlignment"));
            Assert.Equal("Stretch", (string?)card.Attribute("HorizontalContentAlignment"));
        });
        Assert.Equal("Aquarium.Settings.Store",
            cards[0].Attributes().Single(attribute => attribute.Name.LocalName == "Localized.HeaderKey").Value);
        Assert.Equal("Tree.FruitMarket",
            cards[1].Attributes().Single(attribute => attribute.Name.LocalName == "Localized.HeaderKey").Value);
    }

    [Fact]
    public void StoreGroupsPartitionEveryAquariumCategoryAndExposeFishHabitats()
    {
        Assert.Equal(["pack"],
            AquariumStoreBrowse.Groups.Select(group => group.Id));
        Assert.Empty(AquariumStoreBrowse.Subcategories("pack"));
        AquariumCatalogItem pack = new("pack", "Pack", "pack", 100, "pack");
        AquariumCatalogItem fish = new("fish", "Fish", "fish", 100, "fish", "marine");
        Assert.True(AquariumStoreBrowse.Matches(pack, "pack", "all"));
        Assert.False(AquariumStoreBrowse.Matches(fish, "pack", "all"));
        Assert.False(AquariumStoreBrowse.Matches(fish, "fish", "all"));
    }

    [Fact]
    public void AquariumAndGeneralBackpacksPartitionOwnedProducts()
    {
        AquariumCatalogItem[] catalog =
        [
            new("fish", "Fish", "fish", 100, "fish"),
            new("plant", "Plant", "plant", 100, "plant"),
            new("pack", "Pack", "pack", 100, "pack")
        ];
        AquariumInventoryItem[] inventory =
        [
            new("fish-1", "fish", "now"),
            new("plant-1", "plant", "now"),
            new("pack-1", "pack", "now")
        ];

        var owned = AquariumInventoryPresentation.Summarize(inventory, [], catalog);

        Assert.Equal(["fish", "plant"], AquariumInventoryPresentation.AquariumItems(owned)
            .Select(item => item.Product.Category));
        Assert.Equal("pack", Assert.Single(AquariumInventoryPresentation.GeneralItems(owned)).Product.Category);
        Assert.DoesNotContain("pack", AquariumInventoryPresentation.AquariumCategories);
    }

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
    public void StoreAndBackpackExposeTopLevelMenusWithoutAnExternalSaveButton()
    {
        string root = FindRepositoryRoot();
        string settings = File.ReadAllText(Path.Combine(root, "src", "DeskBox", "Views", "SettingsSections", "AquariumSettingsSection.cs"));
        string backpack = File.ReadAllText(Path.Combine(root, "src", "DeskBox", "Views", "SettingsSections", "AquariumBackpackSection.xaml"));
        string store = File.ReadAllText(Path.Combine(root, "src", "DeskBox", "Views", "SettingsSections", "AquariumStoreSection.xaml"));
        string navigation = File.ReadAllText(Path.Combine(root, "src", "DeskBox", "Views", "SettingsWindow.DeferredSections.cs"));
        string settingsShell = File.ReadAllText(Path.Combine(root, "src", "DeskBox", "Views", "SettingsWindow.xaml"));

        Assert.Contains("store.CategoriesHost.Children.Add(primaryCategories)", settings, StringComparison.Ordinal);
        Assert.Contains("store.CategoriesHost.Children.Add(subcategories)", settings, StringComparison.Ordinal);
        Assert.Contains("store.ItemsHost.Children.Add(cards)", settings, StringComparison.Ordinal);
        Assert.Contains("StoreProductCard(product", settings, StringComparison.Ordinal);
        Assert.Contains("var primaryCategories = new WrapPanel", settings, StringComparison.Ordinal);
        Assert.Contains("var subcategories = new WrapPanel", settings, StringComparison.Ordinal);
        Assert.Contains("var cards = new WrapPanel", settings, StringComparison.Ordinal);
        Assert.Contains("Width = 150, Height = 170", settings, StringComparison.Ordinal);
        Assert.Contains("<StackPanel x:Name=\"StoreCategories\" Orientation=\"Vertical\"", store,
            StringComparison.Ordinal);
        Assert.DoesNotContain("_storeCategoryFilter", settings, StringComparison.Ordinal);
        Assert.Contains("BackpackPrimaryCategories", backpack, StringComparison.Ordinal);
        Assert.DoesNotContain("BackpackCategories", backpack, StringComparison.Ordinal);
        Assert.Contains("StoreCategories", store, StringComparison.Ordinal);
        Assert.Contains("StoreItems", store, StringComparison.Ordinal);
        Assert.Contains("FruitMarketSection", store, StringComparison.Ordinal);
        Assert.DoesNotContain("SaveLayout", backpack, StringComparison.Ordinal);
        Assert.DoesNotContain("_walletTab", settings, StringComparison.Ordinal);
        Assert.DoesNotContain("RenderWallet", settings, StringComparison.Ordinal);
        Assert.Contains("AquariumInventoryPresentation.GeneralItems", settings, StringComparison.Ordinal);
        Assert.Contains("AquariumInventoryPresentation.AquariumItems", settings, StringComparison.Ordinal);
        Assert.Contains("backpack.AquariumRequested += () => NavigateToSettingsSection(\"AquariumSettings\")", navigation,
            StringComparison.Ordinal);
        Assert.Contains("AttachExternalStore(store)", navigation, StringComparison.Ordinal);
        int backpackIndex = settingsShell.IndexOf("Tag=\"Backpack\"", StringComparison.Ordinal);
        int storeIndex = settingsShell.IndexOf("Tag=\"AquariumStore\"", StringComparison.Ordinal);
        Assert.True(backpackIndex >= 0 && storeIndex > backpackIndex,
            "The商城 navigation item must be declared below 背包.");
    }

    [Fact]
    public void CardPackIsShownAsAnUnplacedBackpackItem()
    {
        AquariumInventoryItem[] inventory = [new("pack-1", "aquarium-card-pack", "now")];
        AquariumCatalogItem[] catalog =
            [new("aquarium-card-pack", "Aquarium card pack", "pack", 100, "card-pack")];

        AquariumOwnedProduct pack = Assert.Single(
            AquariumInventoryPresentation.Summarize(inventory, [], catalog));

        Assert.Equal("pack", pack.Product.Category);
        Assert.Equal(1, pack.OwnedCount);
        Assert.Equal(0, pack.PlacedCount);
        Assert.Equal("pack-1", pack.NextToPlace?.Id);
        Assert.Null(pack.NextToRecall);

        string settings = File.ReadAllText(Path.Combine(
            FindRepositoryRoot(), "src", "DeskBox", "Views", "SettingsSections", "AquariumSettingsSection.cs"));
        Assert.Contains("OpenPackAsync(item.Id)", settings, StringComparison.Ordinal);
        Assert.Contains("ShowPackReveal", settings, StringComparison.Ordinal);
    }

    [Fact]
    public void PackRevealUsesOneAccountScopedEventAndTheWholeSettingsRoot()
    {
        string settings = File.ReadAllText(Path.Combine(
            FindRepositoryRoot(), "src", "DeskBox", "Views", "SettingsSections", "AquariumSettingsSection.cs"));
        Assert.Contains("_service.Opened += OnPackOpened", settings, StringComparison.Ordinal);
        Assert.Contains("ReferenceEquals(service.LastOpenedItem, reward)", settings, StringComparison.Ordinal);
        Assert.Contains("service.CurrentUserId == accountId", settings, StringComparison.Ordinal);
        Assert.Contains("XamlRoot?.Content is Panel root", settings, StringComparison.Ordinal);
        Assert.Contains("root.Children.Add(_packOverlay)", settings, StringComparison.Ordinal);
        Assert.Contains("host.Children.Remove(_packOverlay)", settings, StringComparison.Ordinal);
        Assert.Contains("!WindowsCompatibilityService.ShouldAnimate", settings, StringComparison.Ordinal);
        Assert.DoesNotContain("else if (!_packEffectRunning", settings, StringComparison.Ordinal);
        Assert.DoesNotContain("_packEffectCts", settings, StringComparison.Ordinal);
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
