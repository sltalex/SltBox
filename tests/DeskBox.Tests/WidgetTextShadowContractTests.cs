namespace DeskBox.Tests;

/// <summary>
/// The dual-layer text shadow route: the same text drawn twice — a black
/// copy offset one pixel behind the real one — mirroring the Windows-native
/// DrawShadowText technique. The 1.5.1 composition route (GetAlphaMask +
/// DropShadow + shadow hosts + LayoutUpdated reconciliation) froze widgets
/// and was removed; these contracts keep that route out while pinning the
/// layer wiring that replaced it.
/// </summary>
public sealed class WidgetTextShadowContractTests
{
    [Fact]
    public void TheCompositionRoute_StaysOutOfTheTree()
    {
        string helper = File.ReadAllText(TestPaths.FromRepository(
            "src/DeskBox/Helpers/WidgetTextShadow.cs"));
        Assert.DoesNotContain("GetAlphaMask", helper, StringComparison.Ordinal);
        Assert.DoesNotContain("CreateDropShadow", helper, StringComparison.Ordinal);
        Assert.DoesNotContain("ElementCompositionPreview", helper, StringComparison.Ordinal);
        // Opacity-only state: nothing per-frame, nothing tree-walking.
        Assert.Contains("SetEnabled", helper, StringComparison.Ordinal);

        Assert.False(File.Exists(TestPaths.FromRepository(
            "src/DeskBox/Views/WidgetTextShadowManager.cs")));
        Assert.False(File.Exists(TestPaths.FromRepository(
            "src/DeskBox/Views/WidgetWindowBase.TextEdge.cs")));
    }

    [Fact]
    public void ShadowLayers_CoverTitlesMarqueeClonesAndFileNames()
    {
        string shell = File.ReadAllText(TestPaths.FromRepository(
            "src/DeskBox/Controls/WidgetShell.xaml"));
        // Standard title + compact title/summary + both marquee clones.
        Assert.Equal(5, CountOccurrences(shell, "helpers:WidgetTextShadow.Layer=\"True\""));
        foreach (string name in new[]
        {
            "TitleTextShadow",
            "CompactTitleTextShadow",
            "CompactTitleMarqueeCloneShadow",
            "CompactSummaryTextShadow",
            "CompactSummaryMarqueeCloneShadow"
        })
        {
            Assert.Contains(name, shell, StringComparison.Ordinal);
        }

        // Shadow layers stay text-synced through bindings, never code.
        Assert.Contains(
            "Text=\"{Binding Text, ElementName=CompactTitleText}\"",
            shell,
            StringComparison.Ordinal);

        string files = File.ReadAllText(TestPaths.FromRepository(
            "src/DeskBox/Controls/FileItemSurface.xaml"));
        Assert.Equal(2, CountOccurrences(files, "helpers:WidgetTextShadow.Layer=\"True\""));
        Assert.Contains("IconItemNameTextShadow", files, StringComparison.Ordinal);
        Assert.Contains("ListItemNameTextShadow", files, StringComparison.Ordinal);
    }

    [Fact]
    public void MarqueeCode_KeepsTheCloneShadowAligned()
    {
        string code = File.ReadAllText(TestPaths.FromRepository(
            "src/DeskBox/Controls/WidgetShell.xaml.cs"));
        Assert.Contains("ResolveMarqueeCloneShadow", code, StringComparison.Ordinal);
        // Start: shadow mirrors the clone offset plus the one-pixel drop.
        Assert.Contains(
            "marquee.NaturalWidth + CompactMarqueeGap + 1",
            code,
            StringComparison.Ordinal);
    }

    [Fact]
    public void Setting_TogglesThroughTheSchemaAndBothWindows()
    {
        Assert.Contains(
            "CurrentSchemaVersion = 12",
            File.ReadAllText(TestPaths.FromRepository(
                "src/DeskBox/Services/SettingsMigrationService.cs")),
            StringComparison.Ordinal);
        Assert.Contains(
            "Migration_10_To_11",
            File.ReadAllText(TestPaths.FromRepository(
                "src/DeskBox/Services/SettingsMigrationService.cs")),
            StringComparison.Ordinal);

        Assert.Contains(
            "WidgetTextShadow.SetEnabled",
            File.ReadAllText(TestPaths.FromRepository(
                "src/DeskBox/Views/ContentWidgetWindow.xaml.cs")),
            StringComparison.Ordinal);

        string settingsXaml = File.ReadAllText(TestPaths.FromRepository(
            "src/DeskBox/Views/SettingsWindow.xaml"));
        Assert.Contains(
            "{Binding WidgetTextShadowEnabled, Mode=TwoWay}",
            settingsXaml,
            StringComparison.Ordinal);

        string bridge = File.ReadAllText(TestPaths.FromRepository(
            "src/DeskBox/Features/Appearance/AppearanceSettingsViewModel.AotBindableProperties.cs"));
        Assert.Contains("nameof(WidgetTextShadowEnabled)", bridge, StringComparison.Ordinal);
    }

    private static int CountOccurrences(string text, string value)
    {
        int count = 0;
        int index = 0;
        while ((index = text.IndexOf(value, index, StringComparison.Ordinal)) >= 0)
        {
            count++;
            index += value.Length;
        }

        return count;
    }
}
