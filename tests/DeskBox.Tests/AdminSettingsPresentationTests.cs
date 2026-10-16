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
        XElement about = Assert.Single(document.Descendants(ui + "NavigationViewItem")
            .Where(item => (string?)item.Attribute("Tag") == "About"));
        Assert.Same(about, admin.ElementsAfterSelf(ui + "NavigationViewItem").First());

        string navigation = File.ReadAllText(Path.Combine(root, "src", "DeskBox", "Views", "SettingsWindow.Navigation.cs"));
        Assert.Contains("route.Tag == \"Admin\" && !IsVerifiedAdmin", navigation, StringComparison.Ordinal);
        Assert.Contains("sectionTag == \"Admin\" && !IsVerifiedAdmin", navigation, StringComparison.Ordinal);
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
