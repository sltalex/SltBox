namespace DeskBox.Tests;

public sealed class AutomaticUpdateExperienceContractTests
{
    [Fact]
    public void App_AutomaticallyDownloadsBeforePromptingForInstallation()
    {
        string app = File.ReadAllText(TestPaths.FromRepository("src/DeskBox/App.xaml.cs"));

        Assert.Contains("DownloadAvailableUpdateAndPromptAsync(result.Manifest)", app, StringComparison.Ordinal);
        Assert.Contains("AppUpdateService.DownloadUpdateAsync(manifest)", app, StringComparison.Ordinal);
        Assert.Contains("QueueDownloadedUpdatePrompt(manifest, result.FilePath)", app, StringComparison.Ordinal);
        Assert.Contains("UpdatePreferenceService.Current.IsSkipped", app, StringComparison.Ordinal);
    }

    [Fact]
    public void Settings_ExposeSkipVersionAndDownloadedInstallPrompt()
    {
        string xaml = File.ReadAllText(TestPaths.FromRepository("src/DeskBox/Views/SettingsWindow.xaml"));
        string codeBehind = File.ReadAllText(TestPaths.FromRepository(
            "src/DeskBox/Views/SettingsWindow.StorageAndUpdates.cs"));

        Assert.Contains("SkipUpdateVersionButton_Click", xaml, StringComparison.Ordinal);
        Assert.Contains("SkipUpdateVersionVisibility", xaml, StringComparison.Ordinal);
        Assert.Contains("Settings.Update.DownloadedPromptTitle", codeBehind, StringComparison.Ordinal);
        Assert.Contains("Settings.Update.DownloadedPromptBody", codeBehind, StringComparison.Ordinal);
    }

    [Fact]
    public void AutoUpdate_HasSeparateToggleAndSilentRetryPath()
    {
        string xaml = File.ReadAllText(TestPaths.FromRepository("src/DeskBox/Views/SettingsWindow.xaml"));
        string app = File.ReadAllText(TestPaths.FromRepository("src/DeskBox/App.xaml.cs"));
        string settings = File.ReadAllText(TestPaths.FromRepository("src/DeskBox/Models/CoreSettingsSlice.cs"));
        string settingsSync = File.ReadAllText(TestPaths.FromRepository(
            "src/DeskBox/ViewModels/SettingsViewModel.SettingsSync.cs"));

        Assert.True(xaml.IndexOf("Settings.Update.AutoCheck", StringComparison.Ordinal) <
                    xaml.IndexOf("Settings.Update.AutoUpdate", StringComparison.Ordinal));
        Assert.Contains("IsOn=\"{Binding AutoUpdateEnabled, Mode=TwoWay}\"", xaml, StringComparison.Ordinal);
        Assert.Contains("public bool AutoUpdateEnabled { get; set; }", settings, StringComparison.Ordinal);
        Assert.Contains("StartSilentUpdate(result.Manifest)", app, StringComparison.Ordinal);
        Assert.Contains("await Task.Delay(TimeSpan.FromMinutes(1), token)", app, StringComparison.Ordinal);
        Assert.Contains("CreateAutomaticSnapshotResultAsync(force: true, token)", app, StringComparison.Ordinal);
        Assert.Contains("download.FilePath, silent: true", app, StringComparison.Ordinal);
        Assert.Contains("await ShutdownForUpdateAsync()", app, StringComparison.Ordinal);
        Assert.Contains("previousAutoUpdateEnabled != AutoUpdateEnabled", settingsSync, StringComparison.Ordinal);
        Assert.Contains("WasAutomaticInstallAttempted(manifest.Version)", app, StringComparison.Ordinal);
        Assert.Contains("RecordAutomaticInstallAttempt(manifest.Version)", app, StringComparison.Ordinal);
    }
}
