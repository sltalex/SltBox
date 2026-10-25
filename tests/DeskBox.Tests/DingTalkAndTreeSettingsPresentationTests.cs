using System.Globalization;
using System.Text.Json;
using System.Xml.Linq;

namespace DeskBox.Tests;

public sealed class DingTalkAndTreeSettingsPresentationTests
{
    [Fact]
    public void GroupDocumentSettings_HasDistinctMenuCardAndNavigationLabels()
    {
        using JsonDocument json = JsonDocument.Parse(Read("src/DeskBox/Strings/zh-CN.json"));
        Assert.Equal("群文档设置", json.RootElement.GetProperty("Widget.Settings.DingTalkGroupDocs").GetString());
        Assert.Equal("钉钉群文档", json.RootElement.GetProperty("DingTalkGroupDocs.Title").GetString());
        Assert.Equal("钉钉格子", json.RootElement.GetProperty("DingTalkGroupDocs.Settings.Title").GetString());
        Assert.Contains("_card.Header = _localization.T(\"DingTalkGroupDocs.Title\")",
            Read("src/DeskBox/Views/SettingsSections/DingTalkGroupDocsSettingsSection.cs"), StringComparison.Ordinal);
    }

    [Theory]
    [InlineData(1)]
    [InlineData(2)]
    [InlineData(3)]
    [InlineData(4)]
    [InlineData(5)]
    public void LeaderboardStage_DisplaysStageNumber(int stage)
    {
        using JsonDocument json = JsonDocument.Parse(Read("src/DeskBox/Strings/zh-CN.json"));
        string format = json.RootElement.GetProperty("Tree.Settings.Leaderboard.Stage").GetString()!;
        Assert.Equal($"第 {stage} 阶段", string.Format(CultureInfo.InvariantCulture, format, stage));
        string source = Read("src/DeskBox/Views/SettingsSections/TreeSettingsSection.cs");
        Assert.Contains("_localization.Format(\"Tree.Settings.Leaderboard.Stage\", entry.Stage)", source, StringComparison.Ordinal);
        Assert.DoesNotContain("Tree.Stage.{entry.Stage}", source, StringComparison.Ordinal);
    }

    [Fact]
    public void Leaderboard_HidesDecorativeCopyButKeepsStatusAndParticipation()
    {
        string source = Read("src/DeskBox/Views/SettingsSections/TreeSettingsSection.cs");
        Assert.DoesNotContain("_leaderboardCard.Header =", source, StringComparison.Ordinal);
        Assert.DoesNotContain("_leaderboardCard.Description =", source, StringComparison.Ordinal);
        Assert.DoesNotContain("Tree.Settings.Leaderboard.Online", source, StringComparison.Ordinal);
        Assert.Contains("_leaderboardHint.Visibility = string.IsNullOrEmpty", source, StringComparison.Ordinal);
        Assert.Contains("Tree.Settings.Leaderboard.SignIn", source, StringComparison.Ordinal);
        Assert.Contains("Tree.Settings.Leaderboard.Offline", source, StringComparison.Ordinal);
        Assert.Contains("Tree.Settings.Leaderboard.Participate", source, StringComparison.Ordinal);
    }

    [Fact]
    public void TreeSettings_PutAllControlsInOneCompactRowBeforeLeaderboard()
    {
        string source = Read("src/DeskBox/Views/SettingsSections/TreeSettingsSection.cs");

        Assert.Contains("for (int column = 0; column < 4; column++)", source, StringComparison.Ordinal);
        Assert.Contains("Grid.SetColumn(_participationRow, 2)", source, StringComparison.Ordinal);
        Assert.Contains("Grid.SetColumn(syncPanel, 3)", source, StringComparison.Ordinal);
        Assert.DoesNotContain("_syncCard", source, StringComparison.Ordinal);
        Assert.True(source.IndexOf("panel.Children.Add(_switchesCard)", StringComparison.Ordinal) <
            source.IndexOf("panel.Children.Add(_leaderboardCard)", StringComparison.Ordinal));
    }

    [Fact]
    public void DingTalkAccount_UsesOneRowForDescriptionStatusAndActions()
    {
        XDocument document = XDocument.Parse(Read("src/DeskBox/Views/SettingsWindow.xaml"));
        XNamespace ui = "http://schemas.microsoft.com/winfx/2006/xaml/presentation";
        XNamespace localized = "using:DeskBox.Services";
        XElement label = Assert.Single(document.Descendants(ui + "TextBlock").Where(
            item => (string?)item.Attribute(localized + "Localized.Key") == "Settings.DingTalk.Title"));
        XElement row = label.Parent!;
        Assert.Equal(ui + "Grid", row.Name);
        Assert.Equal("Left", (string?)row.Parent!.Attribute("ContentAlignment"));
        Assert.Equal("Stretch", (string?)row.Parent.Attribute("HorizontalContentAlignment"));
        Assert.Equal("Settings.DingTalk.Title", (string?)row.Parent.Attribute(localized + "Localized.HeaderKey"));
        Assert.Equal("Settings.DingTalk.Description", (string?)row.Parent.Attribute(localized + "Localized.DescriptionKey"));
        Assert.Null(row.Element(ui + "Grid.RowDefinitions"));
        Assert.Equal(5, row.Element(ui + "Grid.ColumnDefinitions")!.Elements().Count());
        XElement icon = Assert.Single(row.Elements(ui + "FontIcon"));
        Assert.Equal("0", (string?)icon.Attribute("Grid.Column") ?? "0");
        Assert.Equal("\uE71B", (string?)icon.Attribute("Glyph"));
        Assert.Equal("False", (string?)icon.Attribute("IsHitTestVisible"));
        Assert.Equal("1", (string?)label.Attribute("Grid.Column"));
        XElement description = Assert.Single(row.Elements(ui + "TextBlock").Where(
            item => (string?)item.Attribute(localized + "Localized.Key") == "Settings.DingTalk.Description"));
        Assert.Equal("2", (string?)description.Attribute("Grid.Column"));
        Assert.Equal("NoWrap", (string?)description.Attribute("TextWrapping"));
        XElement actions = Assert.Single(row.Elements(ui + "StackPanel"));
        Assert.Equal("4", (string?)actions.Attribute("Grid.Column"));
        Assert.Equal("Horizontal", (string?)actions.Attribute("Orientation"));
        Assert.Contains(actions.Elements(ui + "Button"), item => (string?)item.Attribute("Click") == "DingTalkLink_Click");
        Assert.Contains(actions.Elements(ui + "Button"), item => (string?)item.Attribute("Click") == "DingTalkRefresh_Click");
    }

    [Fact]
    public void EveryLanguage_HasMenuAndNumericStageLabels()
    {
        foreach (string file in Directory.EnumerateFiles(TestPaths.FromRepository("src/DeskBox/Strings"), "*.json"))
        {
            using JsonDocument json = JsonDocument.Parse(File.ReadAllText(file));
            Assert.False(string.IsNullOrWhiteSpace(json.RootElement.GetProperty("Widget.Settings.DingTalkGroupDocs").GetString()));
            Assert.Contains("{0}", json.RootElement.GetProperty("Tree.Settings.Leaderboard.Stage").GetString()!, StringComparison.Ordinal);
        }
    }

    private static string Read(string path) => File.ReadAllText(TestPaths.FromRepository(path));
}
