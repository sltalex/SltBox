using DeskBox.Services;

namespace DeskBox.Tests;

public sealed class UpdatePreferenceServiceTests : IDisposable
{
    private readonly string _tempRoot = Path.Combine(
        Path.GetTempPath(),
        "DeskBox.Tests",
        Guid.NewGuid().ToString("N"));

    [Fact]
    public void Skip_PersistsOnlyTheSelectedVersion()
    {
        var service = new UpdatePreferenceService(_tempRoot);

        service.Skip(" 2026101701 ");

        Assert.True(service.IsSkipped("2026101701"));
        Assert.False(service.IsSkipped("2026101702"));
        var reloaded = new UpdatePreferenceService(_tempRoot);
        Assert.Equal("2026101701", reloaded.SkippedVersion);
        Assert.True(reloaded.IsSkipped("2026101701"));
    }

    [Fact]
    public void NewerVersion_IsNotSuppressedByAnOlderSkip()
    {
        var service = new UpdatePreferenceService(_tempRoot);
        service.Skip("1.5.6");

        Assert.True(service.IsSkipped("1.5.6"));
        Assert.False(service.IsSkipped("1.5.7"));
    }

    public void Dispose()
    {
        if (Directory.Exists(_tempRoot))
        {
            Directory.Delete(_tempRoot, recursive: true);
        }
    }
}
