namespace DeskBox.Tests;

public sealed class AppUpdateSchedulingContractTests
{
    [Fact]
    public void BackgroundUpdateChecksRepeatHourlyAndHonorShutdownCancellation()
    {
        string source = File.ReadAllText(TestPaths.FromRepository("src/DeskBox/App.xaml.cs"));

        Assert.Contains("TimeSpan.FromHours(1)", source, StringComparison.Ordinal);
        Assert.Contains("await Task.Delay(BackgroundUpdateCheckInterval, cancellationToken)", source, StringComparison.Ordinal);
        Assert.Contains("CancellationTokenSource? _backgroundUpdateCheckCancellationSource", source, StringComparison.Ordinal);
        Assert.Contains("CancelBackgroundUpdateCheck", source, StringComparison.Ordinal);
        Assert.Contains("ShutdownStep.Sync(\"background-update-check\", CancelBackgroundUpdateCheck)", source, StringComparison.Ordinal);
    }
}
