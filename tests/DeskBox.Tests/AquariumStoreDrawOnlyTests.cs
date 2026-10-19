namespace DeskBox.Tests;

public sealed class AquariumStoreDrawOnlyTests
{
    [Fact]
    public void StoreSurfaceOnlyExposesCardPacks()
    {
        string root = TestPaths.FromRepository("src/DeskBox/Views/SettingsSections");
        string browse = File.ReadAllText(Path.Combine(TestPaths.FromRepository("src/DeskBox"), "Services", "AquariumStoreBrowse.cs"));
        string settings = File.ReadAllText(Path.Combine(root, "AquariumSettingsSection.cs"));

        Assert.Contains("new(\"pack\", [\"pack\"], [])", browse, StringComparison.Ordinal);
        Assert.DoesNotContain("new(\"fish\"", browse, StringComparison.Ordinal);
        Assert.DoesNotContain("new(\"plants\"", browse, StringComparison.Ordinal);
        Assert.DoesNotContain("new(\"scenery\"", browse, StringComparison.Ordinal);
        Assert.DoesNotContain("new(\"invertebrates\"", browse, StringComparison.Ordinal);
        Assert.Contains("_service.BuyAsync(product.Id)", settings, StringComparison.Ordinal);
        Assert.Contains("AquariumStoreBrowse.Matches", settings, StringComparison.Ordinal);
    }
}
