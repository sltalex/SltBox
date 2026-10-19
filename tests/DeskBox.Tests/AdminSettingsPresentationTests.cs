using System.Xml.Linq;

namespace DeskBox.Tests;

public sealed class AdminSettingsPresentationTests
{
    [Fact]
    public void AdministratorMenu_IsHiddenByDefaultAndPrecedesAbout()
    {
        string root = FindRepositoryRoot();
        XDocument document = XDocument.Load(Path.Combine(root, "src", "DeskBox", "Views", "SettingsWindow.xaml"));
        XNamespace ui = "http://schemas.microsoft.com/winfx/2006/xaml/presentation";
        XElement admin = Assert.Single(document.Descendants(ui + "NavigationViewItem")
            .Where(item => (string?)item.Attribute("Tag") == "Admin"));
        Assert.Equal("Collapsed", (string?)admin.Attribute("Visibility"));
        XElement general = Assert.Single(document.Descendants(ui + "NavigationViewItem")
            .Where(item => (string?)item.Attribute("Tag") == "General"));
        XElement backpack = Assert.Single(document.Descendants(ui + "NavigationViewItem")
            .Where(item => (string?)item.Attribute("Tag") == "Backpack"));
        Assert.Equal("Collapsed", (string?)backpack.Attribute("Visibility"));
        Assert.Same(backpack, general.ElementsAfterSelf(ui + "NavigationViewItem").First());
        XElement about = Assert.Single(document.Descendants(ui + "NavigationViewItem")
            .Where(item => (string?)item.Attribute("Tag") == "About"));
        Assert.Same(about, admin.ElementsAfterSelf(ui + "NavigationViewItem").First());

        string navigation = File.ReadAllText(Path.Combine(root, "src", "DeskBox", "Views", "SettingsWindow.Navigation.cs"));
        Assert.Contains("route.Tag == \"Admin\" && !IsVerifiedAdmin", navigation, StringComparison.Ordinal);
        Assert.Contains("sectionTag == \"Admin\" && !IsVerifiedAdmin", navigation, StringComparison.Ordinal);
        Assert.Contains("route.Tag == \"Backpack\" && !IsVerifiedAdmin", navigation, StringComparison.Ordinal);
        Assert.Contains("sectionTag == \"Backpack\" && !IsVerifiedAdmin", navigation, StringComparison.Ordinal);
        string adminCode = File.ReadAllText(Path.Combine(root, "src", "DeskBox", "Views", "SettingsWindow.Admin.cs"));
        Assert.Contains("_authSession.IsAuthenticated", adminCode, StringComparison.Ordinal);
    }

    [Fact]
    public void AccountSettings_OpenInlineWithoutDependingOnAContentDialogComponent()
    {
        string root = FindRepositoryRoot();
        string section = File.ReadAllText(Path.Combine(root,
            "src", "DeskBox", "Views", "SettingsSections", "AdminSettingsSection.cs"));

        Assert.Contains("_detailCard", section, StringComparison.Ordinal);
        Assert.Contains("ShowDetailPanel", section, StringComparison.Ordinal);
        Assert.DoesNotContain("ContentDialog", section, StringComparison.Ordinal);
        Assert.DoesNotContain("ShowDetailDialogAsync", section, StringComparison.Ordinal);
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
