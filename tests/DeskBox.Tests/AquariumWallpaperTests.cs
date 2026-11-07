using System.Text.Json;
using DeskBox.Core.Persistence;
using DeskBox.Models;
using DeskBox.Services;

namespace DeskBox.Tests;

public sealed class AquariumWallpaperTests
{
    [Fact]
    public void WallpaperDefaultsOffForNewAndExistingLayouts()
    {
        Assert.False(new AppSettings().WidgetLayout.AquariumWallpaperEnabled);
        var existing = WidgetLayoutStore.ParseLayoutDocument("""
            {"schemaVersion":1,"layout":{"aquariumAnimationsEnabled":true}}
            """);
        Assert.False(existing.Layout.AquariumWallpaperEnabled);
    }

    [Fact]
    public void WallpaperPreferenceSurvivesLayoutPersistenceAndAdoption()
    {
        var layout = new WidgetLayoutSettingsSlice { AquariumWallpaperEnabled = true };
        string json = JsonSerializer.Serialize(
            new WidgetLayoutDocument { Layout = layout }, WidgetLayoutJsonContext.Default.WidgetLayoutDocument);
        var restored = WidgetLayoutStore.ParseLayoutDocument(json);
        var adopted = new WidgetLayoutSettingsSlice();
        adopted.CopyFrom(restored.Layout);
        Assert.True(adopted.AquariumWallpaperEnabled);
        Assert.Contains("aquariumWallpaperEnabled", WidgetLayoutStore.SettingsWireKeys);
    }

    [Theory]
    [InlineData(1920, 1080, 1066.6666666667)]
    [InlineData(3440, 1440, 1433.3333333333)]
    [InlineData(1080, 1920, 337.5)]
    [InlineData(0, 0, 840)]
    public void WallpaperViewportFillsMonitorWithoutStretchingCreatures(double width, double height, double expected)
        => Assert.Equal(expected, AquariumWallpaperPresentation.SceneWidth(width, height), 6);

    [Fact]
    public void DepthOpacityIncreasesTowardForegroundAndClampsInvalidLayers()
    {
        double previous = 0;
        for (int layer = AquariumLayer.Min; layer <= AquariumLayer.Max; layer++)
        {
            double opacity = AquariumWallpaperPresentation.DepthOpacity(layer);
            Assert.InRange(opacity, .58, 1);
            Assert.True(opacity > previous);
            previous = opacity;
        }
        Assert.Equal(.58, AquariumWallpaperPresentation.DepthOpacity(int.MinValue));
        Assert.Equal(1, AquariumWallpaperPresentation.DepthOpacity(int.MaxValue));
    }
}
