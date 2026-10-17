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
}
