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
    public void TreeSettings_OffersManualTreeCalibrationAlongsideRepair()
    {
        string xaml = File.ReadAllText(TestPaths.FromRepository("src/DeskBox/Views/SettingsWindow.xaml"));
        string account = Slice(xaml, "svc:Localized.HeaderKey=\"Settings.Account.Title\"", "svc:Localized.HeaderKey=\"Settings.DingTalk.Title\"");

        Assert.Contains("x:Name=\"AccountSignedInActions\"", account, StringComparison.Ordinal);
        Assert.Contains("x:Name=\"AccountSyncDataButton\"", account, StringComparison.Ordinal);
        Assert.Contains("x:Name=\"AccountSyncStatusText\"", account, StringComparison.Ordinal);
        Assert.Contains("Grid.ColumnSpan=\"3\"", account, StringComparison.Ordinal);
        string accountHandlers = File.ReadAllText(TestPaths.FromRepository("src/DeskBox/Views/SettingsWindow.Account.cs"));
        Assert.Contains("AccountSyncDataButton_Click", accountHandlers, StringComparison.Ordinal);
        Assert.Contains("_treeCloudSyncService.SyncNowAsync()", accountHandlers, StringComparison.Ordinal);
        string treeSettings = File.ReadAllText(TestPaths.FromRepository("src/DeskBox/Views/SettingsSections/TreeSettingsSection.cs"));
        Assert.DoesNotContain("Settings.Account.SyncData", treeSettings, StringComparison.Ordinal);
        Assert.Contains("_syncButton.Click += SyncButton_Click", treeSettings, StringComparison.Ordinal);
        Assert.Contains("_cloud.SyncProgressNowAsync()", treeSettings, StringComparison.Ordinal);
        Assert.Contains("Tree.SyncCalibrate", treeSettings, StringComparison.Ordinal);
        Assert.Contains("Tree.SyncRepair", treeSettings, StringComparison.Ordinal);
        string treeWidget = File.ReadAllText(TestPaths.FromRepository("src/DeskBox/Controls/WidgetContents/TreeWidgetContent.cs"));
        Assert.DoesNotContain("SyncButton_Click", treeWidget, StringComparison.Ordinal);
    }

    [Fact]
    public void TreeSettings_UsesCardsAndAotSafeListViewRows()
    {
        string source = File.ReadAllText(TestPaths.FromRepository("src/DeskBox/Views/SettingsSections/TreeSettingsSection.cs"));

        Assert.Contains("SettingsCard _switchesCard", source, StringComparison.Ordinal);
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
    public void TreeSettings_PutsDecorationFirstAndLeaderboardLastWithoutCurrentProgressCard()
    {
        string source = File.ReadAllText(TestPaths.FromRepository(
            "src/DeskBox/Views/SettingsSections/TreeSettingsSection.cs"));

        Assert.DoesNotContain("_currentCard", source, StringComparison.Ordinal);
        Assert.DoesNotContain("_growthSummary", source, StringComparison.Ordinal);
        int panel = source.IndexOf("var panel = new StackPanel", StringComparison.Ordinal);
        int decorations = source.IndexOf("panel.Children.Add(_decorations)", panel, StringComparison.Ordinal);
        int switches = source.IndexOf("panel.Children.Add(_switchesCard)", panel, StringComparison.Ordinal);
        int leaderboard = source.IndexOf("panel.Children.Add(_leaderboardCard)", panel, StringComparison.Ordinal);
        Assert.True(panel >= 0 && decorations > panel && switches > decorations && leaderboard > switches);
        Assert.DoesNotContain("_syncCard", source, StringComparison.Ordinal);
    }

    [Fact]
    public void TreeLeaderboard_DeserializesUnpickedFruitAlongsideCumulativeTotal()
    {
        const string json = """
            {"entries":[{"fruit_total":5,"fruit_inventory":4}],"current_user":{}}
            """;
        TreeLeaderboardResponse? response = JsonSerializer.Deserialize(
            json, AccountCloudJsonContext.Default.TreeLeaderboardResponse);

        Assert.NotNull(response);
        Assert.Single(response.Entries);
        Assert.Equal(5, response.Entries[0].FruitTotal);
        Assert.Equal(4, response.Entries[0].FruitInventory);
    }

    [Fact]
    public void TreeLeaderboardSurfaceShowsDailyEnergyAsTheRankedValue()
    {
        string source = File.ReadAllText(TestPaths.FromRepository(
            "src/DeskBox/Views/SettingsSections/TreeSettingsSection.cs"));
        string backend = File.ReadAllText(TestPaths.FromRepository(
            "server/deskbox-api/api/_app/account_data.php"));

        Assert.Contains("ORDER BY COALESCE(d.growth, 0) DESC", backend, StringComparison.Ordinal);
        Assert.Contains("$leaderboard['entries'][0]['username'] === 'Alice_Cloud'", File.ReadAllText(
            TestPaths.FromRepository("server/deskbox-api/tests/account_data_service_test.php")), StringComparison.Ordinal);
        Assert.Contains("$pdo->prepare('UPDATE tree_profiles SET total_energy = 90000", File.ReadAllText(
            TestPaths.FromRepository("server/deskbox-api/tests/account_data_service_test.php")), StringComparison.Ordinal);
        Assert.Contains("AddCell(grid, $\"+{entry.TodayGrowth:N0}\", 3, false);", source, StringComparison.Ordinal);
        Assert.Contains("AddCell(grid, $\"⚡{entry.TotalEnergy:N0}\", 4, false);", source, StringComparison.Ordinal);
    }

    [Fact]
    public void TreeVisit_DeserializesReadOnlyScene()
    {
        const string json = """
            {"username":"friend","stage":5,"total_energy":180000,"visit_count":12,"like_count":3,"liked_by_me":true,"fruit_inventory":3,"fruit_on_tree_count":1,"fruits":["apple"],"placements":[{"item_id":"red-lantern","x":0.5,"y":0.4,"scale":1,"rotation":0,"layer":1}],"active_theme_id":null,"wildlife_species_id":"butterfly"}
            """;
        TreeVisitSnapshot? snapshot = JsonSerializer.Deserialize(
            json, AccountCloudJsonContext.Default.TreeVisitSnapshot);

        Assert.NotNull(snapshot);
        Assert.Equal("friend", snapshot.Username);
        Assert.Equal("apple", Assert.Single(snapshot.Fruits));
        Assert.Equal(1, snapshot.FruitOnTreeCount);
        Assert.Equal("red-lantern", Assert.Single(snapshot.Placements).ItemId);
        Assert.Null(snapshot.ActiveThemeId);
        Assert.Equal(180000, snapshot.TotalEnergy);
        Assert.Equal("butterfly", snapshot.WildlifeSpeciesId);
        Assert.Equal(12, snapshot.VisitCount);
        Assert.Equal(3, snapshot.LikeCount);
        Assert.True(snapshot.LikedByMe);
    }

    [Fact]
    public void TreeCounter_StartsCountingRegardlessOfTreeWidgetVisibility()
    {
        string source = File.ReadAllText(TestPaths.FromRepository("src/DeskBox/App.xaml.cs"));
        int start = source.IndexOf("RunOptionalStartupStep(\"tree-counter\"", StringComparison.Ordinal);
        int end = source.IndexOf("MarkStartupProgress();", start, StringComparison.Ordinal);
        Assert.True(start >= 0 && end > start);
        string startup = source[start..end];

        Assert.Contains("TreeCounterService.Instance.SetPaused(false)", startup, StringComparison.Ordinal);
        Assert.Contains("TreeCounterService.Instance.SetEnabled(true)", startup, StringComparison.Ordinal);
        Assert.True(startup.IndexOf("SetPaused(false)", StringComparison.Ordinal) <
            startup.IndexOf("SetEnabled(true)", StringComparison.Ordinal));
        Assert.DoesNotContain("FeatureWidgetSettings.IsEnabled", startup, StringComparison.Ordinal);
    }

    [Fact]
    public void TreeSettings_OnlyShowsParticipationToggle()
    {
        string source = File.ReadAllText(TestPaths.FromRepository("src/DeskBox/Views/SettingsSections/TreeSettingsSection.cs"));

        Assert.DoesNotContain("CreateToggleRow(_enableLabel, _enable)", source, StringComparison.Ordinal);
        Assert.DoesNotContain("_statistics", source, StringComparison.Ordinal);
        Assert.Contains("_participationRow.Children.Add(_participation)", source, StringComparison.Ordinal);
        Assert.Contains("_participationRow.Children.Add(_syncButton)", source, StringComparison.Ordinal);
        Assert.DoesNotContain("_enable = new()", source, StringComparison.Ordinal);
        Assert.DoesNotContain("_participation.Header", source, StringComparison.Ordinal);
        Assert.DoesNotContain("_progressCard", source, StringComparison.Ordinal);
    }

    [Fact]
    public void TreeSettings_ShowsLiveTodayKeyboardPressCount()
    {
        string source = File.ReadAllText(TestPaths.FromRepository("src/DeskBox/Views/SettingsSections/TreeSettingsSection.cs"));

        Assert.Contains("TreeCounterService _counter", source, StringComparison.Ordinal);
        Assert.Contains("_counter.Snapshot.TodayKeys", source, StringComparison.Ordinal);
        Assert.Contains("_counter.Changed += OnCounterChanged", source, StringComparison.Ordinal);
        Assert.Contains("Tree.Settings.TodayKeys", source, StringComparison.Ordinal);
        Assert.Contains("Grid.SetColumn(_todayKeysValue, 4)", source, StringComparison.Ordinal);
        Assert.Contains("_participationRow.Children.Add(_todayKeysValue)", source, StringComparison.Ordinal);
        Assert.DoesNotContain("todayKeysRow", source, StringComparison.Ordinal);
    }

    [Fact]
    public void TreeSettings_ControlsOptionalFrontendEnergyDisplay()
    {
        string settings = File.ReadAllText(TestPaths.FromRepository("src/DeskBox/Views/SettingsSections/TreeSettingsSection.cs"));
        string widget = File.ReadAllText(TestPaths.FromRepository("src/DeskBox/Controls/WidgetContents/TreeWidgetContent.cs"));

        Assert.Contains("_showEnergy", settings, StringComparison.Ordinal);
        Assert.Contains("_counter.SetShowEnergy(_showEnergy.IsOn)", settings, StringComparison.Ordinal);
        Assert.Contains("Tree.Settings.ShowEnergy", settings, StringComparison.Ordinal);
        Assert.Contains("Grid.SetColumn(_showEnergy, 1)", settings, StringComparison.Ordinal);
        Assert.Contains("_energyText", widget, StringComparison.Ordinal);
        Assert.Contains("p.ShowEnergy ? Visibility.Visible : Visibility.Collapsed", widget, StringComparison.Ordinal);
        Assert.Contains("⚡ {p.TotalEnergy:N0}", widget, StringComparison.Ordinal);
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
            "Tree.Settings.Leaderboard.CurrentRank", "Tree.SyncCalibrate", "Tree.SyncCalibrating",
            "Tree.SyncCalibrateSuccess", "Tree.SyncCalibrateUnavailable", "Tree.SyncCalibrateError",
            "Tree.SyncRepair", "Tree.SyncRepairTitle",
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
