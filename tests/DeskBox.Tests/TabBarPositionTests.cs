using DeskBox.Contracts;
using DeskBox.Features.QuickCapture;
using DeskBox.Services;

namespace DeskBox.Tests;

public sealed class TabBarPositionTests : IDisposable
{
    private readonly string _root = Path.Combine(
        Path.GetTempPath(), "DeskBoxTabBarPositionTests", Guid.NewGuid().ToString("N"));

    [Theory]
    [InlineData("Top", "Top")]
    [InlineData("Left", "Left")]
    [InlineData("Right", "Right")]
    [InlineData("Unsupported", "Top")]
    [InlineData(null, "Top")]
    public void PositionNormalizer_OnlyAcceptsSupportedValues(string? value, string expected)
    {
        Assert.Equal(expected, QuickCaptureOptionKinds.NormalizeTabBarPosition(value));
    }

    [Fact]
    public async Task QuickCaptureAndTodoPositions_ArePersistedIndependently()
    {
        var settings = new SettingsService(_root);
        var todo = new TodoSettingsCoordinator(settings);
        var clipboard = new QuickCaptureClipboardRuntime(
            () => false, () => throw new InvalidOperationException(), _ => { });
        var quickCapture = new QuickCaptureSettingsCoordinator(
            settings, clipboard, (_, _) => Task.CompletedTask,
            action => { action(); return true; }, _ => { });

        quickCapture.SetTabBarPosition(QuickCaptureOptionKinds.TabBarPositionLeft);
        todo.SetTabBarPosition(QuickCaptureOptionKinds.TabBarPositionRight);

        Assert.Equal("Left", quickCapture.ReadTabs().TabBarPosition);
        Assert.Equal("Right", todo.ReadTabs().TabBarPosition);
        await settings.FlushPendingSaveAsync();

        var reloaded = new SettingsService(_root);
        await reloaded.LoadAsync();
        Assert.Equal("Left", reloaded.Settings.QuickCaptureTabBarPosition);
        Assert.Equal("Right", reloaded.Settings.TodoTabBarPosition);

        await quickCapture.StopAsync();
        await todo.StopAsync();
    }

    public void Dispose()
    {
        if (Directory.Exists(_root)) Directory.Delete(_root, recursive: true);
    }
}
