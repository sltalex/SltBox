using System.Xml.Linq;

namespace DeskBox.Tests;

public sealed class AdminSettingsPresentationTests
{
    [Fact]
    public void Settings_DoNotExposeDesktopAdministratorManagement()
    {
        string root = FindRepositoryRoot();
        XDocument document = XDocument.Load(Path.Combine(root, "src", "DeskBox", "Views", "SettingsWindow.xaml"));
        XNamespace ui = "http://schemas.microsoft.com/winfx/2006/xaml/presentation";

        Assert.DoesNotContain(document.Descendants(ui + "NavigationViewItem"),
            item => (string?)item.Attribute("Tag") == "Admin");
        Assert.DoesNotContain(document.Descendants(ui + "DataTemplate"),
            item => (string?)item.Attribute("Key") == "AdminSectionTemplate");

        string routes = File.ReadAllText(Path.Combine(root, "src", "DeskBox", "Views", "SettingsWindow.xaml.cs"));
        Assert.DoesNotContain("[\"Admin\"]", routes, StringComparison.Ordinal);
        Assert.False(File.Exists(Path.Combine(root, "src", "DeskBox", "Views", "SettingsSections", "AdminSettingsSection.cs")));
        Assert.False(File.Exists(Path.Combine(root, "src", "DeskBox", "Services", "AdminJsonModels.cs")));
    }

    [Fact]
    public void AquariumSettings_RemainASeparateAccountGatedFeature()
    {
        string root = FindRepositoryRoot();
        string xaml = File.ReadAllText(Path.Combine(root, "src", "DeskBox", "Views", "SettingsWindow.xaml"));
        string routes = File.ReadAllText(Path.Combine(root, "src", "DeskBox", "Views", "SettingsWindow.xaml.cs"));
        Assert.Contains("AquariumSettingsSectionTemplate", xaml, StringComparison.Ordinal);
        Assert.Contains("[\"AquariumSettings\"]", routes, StringComparison.Ordinal);
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
