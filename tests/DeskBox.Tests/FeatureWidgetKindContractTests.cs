using DeskBox.Models;
using DeskBox.Services;

namespace DeskBox.Tests;

/// <summary>
/// Pins the user-enableable widget kind set. <see cref="FeatureWidgetSettings"/>
/// derives its ordered list from the content descriptor table, so flipping
/// <c>IsFeatureWidget</c> on a descriptor changes the enable/disable semantics
/// that every user's settings are normalized against. These assertions make
/// that a deliberate edit rather than a silent one.
/// </summary>
public sealed class FeatureWidgetKindContractTests
{
    [Fact]
    public void FeatureKinds_AreExactlyTheUserEnableableOnes()
    {
        Assert.Equal(
            new[]
            {
                WidgetKind.QuickCapture,
                WidgetKind.Todo,
                WidgetKind.Music,
                WidgetKind.Weather,
                WidgetKind.SystemMonitor,
                WidgetKind.Search,
                WidgetKind.Glance,
                WidgetKind.Tree,
                WidgetKind.Aquarium,
                WidgetKind.DingTalkGroupDocs,
                WidgetKind.Calendar
            },
            FeatureWidgetSettings.FeatureKinds);
    }

    [Fact]
    public void NonFeatureKinds_AreNotUserEnableable()
    {
        Assert.False(FeatureWidgetSettings.IsFeatureWidget(WidgetKind.File));
        Assert.False(FeatureWidgetSettings.IsFeatureWidget(WidgetKind.Tags));
        Assert.True(FeatureWidgetSettings.IsFeatureWidget(WidgetKind.SystemMonitor));
        Assert.False(FeatureWidgetSettings.IsFeatureWidget(WidgetKind.Productivity));
    }

    [Fact]
    public void TreeIsDisabledByDefaultButExplicitEnableIsPreserved()
    {
        var settings = new AppSettings();
        Assert.False(FeatureWidgetSettings.IsEnabled(settings, WidgetKind.Tree));
        FeatureWidgetSettings.Normalize(settings);
        Assert.False(FeatureWidgetSettings.IsEnabled(settings, WidgetKind.Tree));

        FeatureWidgetSettings.SetEnabled(settings, WidgetKind.Tree, true);
        Assert.True(FeatureWidgetSettings.IsEnabled(settings, WidgetKind.Tree));
    }

    [Fact]
    public void SystemMonitorIsDisabledByDefault()
    {
        var settings = new AppSettings();
        Assert.False(FeatureWidgetSettings.IsEnabled(settings, WidgetKind.SystemMonitor));
        FeatureWidgetSettings.Normalize(settings);
        Assert.False(FeatureWidgetSettings.IsEnabled(settings, WidgetKind.SystemMonitor));
    }

    [Fact]
    public void CalendarIsDisabledByDefault()
    {
        var settings = new AppSettings();
        Assert.False(FeatureWidgetSettings.IsEnabled(settings, WidgetKind.Calendar));
        Assert.True(settings.CalendarShowTaskMarkers);
        Assert.False(settings.CalendarShowHolidays);
    }

    [Fact]
    public void DescriptorTable_DeclaresEachKindOnce()
    {
        WidgetKind[] kinds = WidgetContentFactory.DescriptorList
            .Select(descriptor => descriptor.WidgetKind)
            .ToArray();

        Assert.Equal(kinds.Length, kinds.Distinct().Count());
    }

    [Fact]
    public void RemovedFourWheelDriveStateAndWidgetsAreMigratedAway()
    {
        var settings = new AppSettings
        {
            Widgets = [new WidgetConfig { WidgetKind = WidgetKind.FourWheelDrive }],
            FeatureWidgetEnabledStates = new Dictionary<string, bool>
            {
                [nameof(WidgetKind.FourWheelDrive)] = true
            }
        };

        Assert.True(FeatureWidgetSettings.Normalize(settings));
        var normalize = typeof(SettingsService).GetMethod(
            "NormalizeWidgetContentSettings",
            System.Reflection.BindingFlags.NonPublic | System.Reflection.BindingFlags.Static);
        Assert.NotNull(normalize);
        Assert.True((bool)normalize!.Invoke(null, [settings])!);
        Assert.Empty(settings.Widgets);
        Assert.DoesNotContain(nameof(WidgetKind.FourWheelDrive), settings.FeatureWidgetEnabledStates.Keys);
        Assert.False(WidgetRegistry.Default.IsKnown(WidgetKind.FourWheelDrive));
        Assert.False(FeatureWidgetSettings.IsFeatureWidget(WidgetKind.FourWheelDrive));
    }

    [Fact]
    public void EveryWidgetKindIsDescribedExceptTheKnownGap()
    {
        // WidgetKind.Productivity is declared in the enum but has neither a
        // content descriptor nor a WidgetRegistry entry, so nothing can create
        // it and nothing enumerates the enum to notice. It stays listed here as
        // a known gap: either give it a descriptor (Placeholder/Planned, like
        // Tags) or drop the enum member, then remove it from this list.
        WidgetKind[] knownGap = [WidgetKind.Productivity, WidgetKind.FourWheelDrive];

        WidgetKind[] described = WidgetContentFactory.DescriptorList
            .Select(descriptor => descriptor.WidgetKind)
            .ToArray();

        WidgetKind[] missing = Enum.GetValues<WidgetKind>()
            .Where(kind => !described.Contains(kind))
            .Where(kind => !knownGap.Contains(kind))
            .ToArray();

        Assert.Empty(missing);
    }
}
