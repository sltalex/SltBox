using System.Text.Json;
using DeskBox.Services;
using Microsoft.Extensions.DependencyInjection;

namespace DeskBox.Tests;

public sealed class AccountCloudBackupAndTreeSurfaceTests
{
    [Fact]
    public async Task ServiceRegistry_ResolvesAccountBackupAndTreeServices()
    {
        var services = new ServiceCollection();
        services.AddDeskBoxServices();

        await using ServiceProvider provider = services.BuildServiceProvider();

        Assert.NotNull(provider.GetRequiredService<AccountCloudBackupService>());
        Assert.NotNull(provider.GetRequiredService<TreeCloudSyncService>());
    }

    [Fact]
    public void CloudBackupPage_ContainsIndependentAccountBackupAndRestoreCards()
    {
        string xaml = File.ReadAllText(TestPaths.FromRepository("src/DeskBox/Views/SettingsWindow.xaml"));
        string section = Slice(xaml, "x:Key=\"CloudBackupSettingsSectionTemplate\"", "x:Key=\"DataHealthSettingsSectionTemplate\"");

        Assert.Contains("x:Name=\"AccountCloudBackupToggle\"", section, StringComparison.Ordinal);
        Assert.Contains("Settings.AccountBackup.Enable.Title", section, StringComparison.Ordinal);
        Assert.Contains("Settings.AccountBackup.Restore.Title", section, StringComparison.Ordinal);
        Assert.Contains("x:Name=\"AccountCloudBackupSnapshotsList\"", section, StringComparison.Ordinal);
        Assert.Contains("Settings.CloudBackup.Provider.Title", section, StringComparison.Ordinal);
        Assert.Contains("Settings.CloudBackup.Interval.Title", section, StringComparison.Ordinal);
        string codeBehind = File.ReadAllText(TestPaths.FromRepository("src/DeskBox/Views/SettingsWindow.CloudBackup.cs"));
        Assert.Contains("_accountCloudBackupListOffline", codeBehind, StringComparison.Ordinal);
        Assert.Contains("CancelPreparedRestore", codeBehind, StringComparison.Ordinal);
    }

    [Fact]
    public void AccountCard_DoesNotOwnTreeManualSyncAfterMovingItToTreeWidget()
    {
        string xaml = File.ReadAllText(TestPaths.FromRepository("src/DeskBox/Views/SettingsWindow.xaml"));
        string account = Slice(xaml, "svc:Localized.HeaderKey=\"Settings.Account.Title\"", "svc:Localized.HeaderKey=\"Settings.DingTalk.Title\"");

        Assert.Contains("x:Name=\"AccountSignedInActions\"", account, StringComparison.Ordinal);
        Assert.DoesNotContain("AccountSyncDataButton", account, StringComparison.Ordinal);
        string treeSettings = File.ReadAllText(TestPaths.FromRepository("src/DeskBox/Views/SettingsSections/TreeSettingsSection.cs"));
        Assert.Contains("Settings.Account.SyncData", treeSettings, StringComparison.Ordinal);
        Assert.Contains("Tree.SyncRepair", treeSettings, StringComparison.Ordinal);
        string treeWidget = File.ReadAllText(TestPaths.FromRepository("src/DeskBox/Controls/WidgetContents/TreeWidgetContent.cs"));
        Assert.DoesNotContain("SyncButton_Click", treeWidget, StringComparison.Ordinal);
    }

    [Fact]
    public void TreeSettings_UsesCardsAndAotSafeListViewRows()
    {
        string source = File.ReadAllText(TestPaths.FromRepository("src/DeskBox/Views/SettingsSections/TreeSettingsSection.cs"));

        Assert.True(source.Split("new() { HorizontalContentAlignment", StringSplitOptions.None).Length - 1 >= 2);
        Assert.Contains("private readonly Border _leaderboardCard", source, StringComparison.Ordinal);
        Assert.Contains("ListView _leaderboard", source, StringComparison.Ordinal);
        Assert.Contains("new Grid", source, StringComparison.Ordinal);
        Assert.Contains("🥇", source, StringComparison.Ordinal);
        Assert.Contains("Tree.Settings.Leaderboard.Me", source, StringComparison.Ordinal);
        Assert.Contains("Tree.Settings.Leaderboard.CurrentRank", source, StringComparison.Ordinal);
        Assert.Contains("AddStageCell", source, StringComparison.Ordinal);
        Assert.Contains("LightGreen", source, StringComparison.Ordinal);
        Assert.DoesNotContain("DataGrid", source, StringComparison.Ordinal);
    }

    [Fact]
    public void TreeSettings_SeparatesLocalProgressRefreshFromLeaderboardRefresh()
    {
        string source = File.ReadAllText(TestPaths.FromRepository("src/DeskBox/Views/SettingsSections/TreeSettingsSection.cs"));

        Assert.Contains("_counter.Changed += OnProgressChanged", source, StringComparison.Ordinal);
        Assert.Contains("_cloud.LeaderboardChanged += OnLeaderboardChanged", source, StringComparison.Ordinal);
        Assert.Contains("private void OnProgressChanged()", source, StringComparison.Ordinal);
        Assert.Contains("private void OnLeaderboardChanged()", source, StringComparison.Ordinal);
        int progressStart = source.IndexOf("private void OnProgressChanged()", StringComparison.Ordinal);
        int progressEnd = source.IndexOf("private void OnLeaderboardChanged()", progressStart, StringComparison.Ordinal);
        Assert.True(progressStart >= 0 && progressEnd > progressStart);
        Assert.DoesNotContain("RenderLeaderboard();", source[progressStart..progressEnd], StringComparison.Ordinal);
    }

    [Fact]
    public void TreeSettings_UsesDedicatedToggleLabelRows()
    {
        string source = File.ReadAllText(TestPaths.FromRepository("src/DeskBox/Views/SettingsSections/TreeSettingsSection.cs"));

        Assert.Contains("CreateToggleRow(_enableLabel, _enable)", source, StringComparison.Ordinal);
        Assert.Contains("CreateToggleRow(_statisticsLabel, _statistics)", source, StringComparison.Ordinal);
        Assert.Contains("CreateToggleRow(_participationLabel, _participation)", source, StringComparison.Ordinal);
        Assert.DoesNotContain("_enable.Header", source, StringComparison.Ordinal);
        Assert.DoesNotContain("_statistics.Header", source, StringComparison.Ordinal);
        Assert.DoesNotContain("_participation.Header", source, StringComparison.Ordinal);
    }

    [Fact]
    public void ApplicationBrand_IsSltBox()
    {
        foreach (string file in Directory.EnumerateFiles(TestPaths.FromRepository("src/DeskBox/Resources"), "Resources.resw", SearchOption.AllDirectories))
        {
            string source = File.ReadAllText(file);
            int marker = source.IndexOf("name=\"AppDisplayName\"", StringComparison.Ordinal);
            Assert.True(marker >= 0, $"{file} misses AppDisplayName");
            string value = source[(marker)..source.IndexOf("</data>", marker, StringComparison.Ordinal)];
            Assert.Contains("<value>SltBox</value>", value, StringComparison.Ordinal);
        }

        foreach (string file in Directory.EnumerateFiles(TestPaths.FromRepository("src/DeskBox/Strings"), "*.json"))
        {
            using JsonDocument document = JsonDocument.Parse(File.ReadAllText(file));
            Assert.Equal("SltBox", document.RootElement.GetProperty("Search.Tab.DeskBox").GetString());
            Assert.Contains("SltBox", document.RootElement.GetProperty("Window.Settings.Title").GetString(), StringComparison.Ordinal);
            Assert.Contains("SltBox", document.RootElement.GetProperty("Window.Onboarding.Title").GetString(), StringComparison.Ordinal);
            Assert.Contains("sltalex/SltBox", document.RootElement.GetProperty("Settings.Update.Detail.Ready").GetString(), StringComparison.Ordinal);
        }

        string project = File.ReadAllText(TestPaths.FromRepository("src/DeskBox/DeskBox.csproj"));
        Assert.Contains("<Product>SltBox</Product>", project, StringComparison.Ordinal);
        Assert.Contains("<Title>SltBox</Title>", project, StringComparison.Ordinal);
        string manifest = File.ReadAllText(TestPaths.FromRepository("src/DeskBox/Package.appxmanifest"));
        Assert.Contains("DisplayName=\"SltBox\"", manifest, StringComparison.Ordinal);

        string settingsWindow = File.ReadAllText(TestPaths.FromRepository("src/DeskBox/Views/SettingsWindow.xaml"));
        Assert.Contains("Title=\"SltBox Settings\"", settingsWindow, StringComparison.Ordinal);
        Assert.Equal(2, CountOccurrences(settingsWindow, "Text=\"SltBox\""));
        Assert.DoesNotContain("Title=\"DeskBox Settings\"", settingsWindow, StringComparison.Ordinal);
        Assert.DoesNotContain("Text=\"DeskBox\"", settingsWindow, StringComparison.Ordinal);

        string searchPopup = File.ReadAllText(TestPaths.FromRepository("src/DeskBox/Views/SearchPopupWindow.xaml.cs"));
        Assert.Contains("Search.Filter.DeskBox", searchPopup, StringComparison.Ordinal);

        static int CountOccurrences(string value, string token)
        {
            int count = 0;
            int offset = 0;
            while ((offset = value.IndexOf(token, offset, StringComparison.Ordinal)) >= 0)
            {
                count++;
                offset += token.Length;
            }

            return count;
        }
    }

    [Fact]
    public void EveryLanguage_DefinesAccountBackupAndTreeLeaderboardKeys()
    {
        string[] keys =
        [
            "Settings.AccountBackup.Enable.Title", "Settings.AccountBackup.Restore.Title",
            "Settings.AccountBackup.SignInRequired.Body", "Settings.AccountBackup.RestoreConfirm.Body",
            "Settings.AccountBackup.Status.LastSuccess", "Settings.AccountBackup.Status.LastFailure",
            "Settings.AccountBackup.Status.Never",
            "Tree.Settings.Card.Current", "Tree.Settings.Card.Switches", "Tree.Settings.Card.Progress",
            "Tree.Settings.Card.Sync", "Tree.Settings.Card.Sync.Description",
            "Tree.Settings.Card.Leaderboard", "Tree.Settings.Leaderboard.Participate",
            "Tree.Settings.Leaderboard.SignIn", "Tree.Settings.Leaderboard.Offline", "Tree.Settings.Leaderboard.Me",
            "Tree.Settings.Leaderboard.CurrentRank", "Tree.SyncRepair", "Tree.SyncRepairTitle",
            "Tree.SyncRepairBody", "Tree.SyncRepairConfirm", "Tree.SyncRepairFailed",
            "Tree.SyncRepairRestartFailed"
        ];
        foreach (string file in Directory.EnumerateFiles(TestPaths.FromRepository("src/DeskBox/Strings"), "*.json"))
        {
            using JsonDocument document = JsonDocument.Parse(File.ReadAllText(file));
            foreach (string key in keys)
                Assert.True(document.RootElement.TryGetProperty(key, out _), $"{Path.GetFileName(file)} misses {key}");
        }

        using JsonDocument chinese = JsonDocument.Parse(File.ReadAllText(TestPaths.FromRepository("src/DeskBox/Strings/zh-CN.json")));
        Assert.Equal("诊断与备份", chinese.RootElement.GetProperty("Settings.Nav.Maintenance").GetString());
        Assert.Equal("诊断与备份", chinese.RootElement.GetProperty("Settings.Section.Maintenance").GetString());
        Assert.Equal("开启云帐户备份", chinese.RootElement.GetProperty("Settings.AccountBackup.Enable.Title").GetString());
    }

    private static string Slice(string value, string start, string end)
    {
        int startIndex = value.IndexOf(start, StringComparison.Ordinal);
        int endIndex = value.IndexOf(end, startIndex, StringComparison.Ordinal);
        Assert.True(startIndex >= 0 && endIndex > startIndex);
        return value[startIndex..endIndex];
    }
}
