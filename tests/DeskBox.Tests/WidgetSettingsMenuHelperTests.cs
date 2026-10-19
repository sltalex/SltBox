// Copyright (c) DeskBox. All rights reserved.

using DeskBox.Models;
using DeskBox.Services;

namespace DeskBox.Tests;

public sealed class WidgetSettingsMenuHelperTests
{
    [Theory]
    [InlineData(WidgetKind.File, "FileDisplaySettings")]
    [InlineData(WidgetKind.QuickCapture, "QuickCaptureSettings")]
    [InlineData(WidgetKind.Todo, "TodoSettings")]
    [InlineData(WidgetKind.Music, "MusicSettings")]
    [InlineData(WidgetKind.Weather, "WeatherSettings")]
    [InlineData(WidgetKind.Glance, "GlanceSettings")]
    [InlineData(WidgetKind.Tree, "TreeSettings")]
    [InlineData(WidgetKind.Search, "SearchSettings")]
    [InlineData(WidgetKind.SystemMonitor, "FeatureWidgets")]
    [InlineData(WidgetKind.DingTalkGroupDocs, "DingTalkGroupDocsSettings")]
    [InlineData(WidgetKind.Calendar, "CalendarSettings")]
    [InlineData(WidgetKind.Aquarium, "AquariumSettings")]
    public void GetSettingsSectionTag_Returns_Expected_Tag(WidgetKind kind, string expectedTag)
    {
        Assert.Equal(expectedTag, WidgetSettingsMenuHelper.GetSettingsSectionTag(kind));
    }

    [Theory]
    [InlineData(WidgetKind.Tags)]
    [InlineData(WidgetKind.Productivity)]
    public void GetSettingsSectionTag_Returns_Null_For_Unmapped_Kinds(WidgetKind kind)
    {
        Assert.Null(WidgetSettingsMenuHelper.GetSettingsSectionTag(kind));
    }

    [Fact]
    public void GetLocalizationKey_Returns_Specific_Key_For_Known_Kinds()
    {
        Assert.Equal("Widget.Settings.FileWidget", WidgetSettingsMenuHelper.GetLocalizationKey(WidgetKind.File));
        Assert.Equal("Widget.Settings.QuickCapture", WidgetSettingsMenuHelper.GetLocalizationKey(WidgetKind.QuickCapture));
        Assert.Equal("Widget.Settings.Todo", WidgetSettingsMenuHelper.GetLocalizationKey(WidgetKind.Todo));
        Assert.Equal("Widget.Settings.Music", WidgetSettingsMenuHelper.GetLocalizationKey(WidgetKind.Music));
        Assert.Equal("Widget.Settings.Weather", WidgetSettingsMenuHelper.GetLocalizationKey(WidgetKind.Weather));
        Assert.Equal("Widget.Settings.Glance", WidgetSettingsMenuHelper.GetLocalizationKey(WidgetKind.Glance));
        Assert.Equal("Widget.Settings.Search", WidgetSettingsMenuHelper.GetLocalizationKey(WidgetKind.Search));
        Assert.Equal("SystemMonitor.Title", WidgetSettingsMenuHelper.GetLocalizationKey(WidgetKind.SystemMonitor));
        Assert.Equal("Widget.Settings.DingTalkGroupDocs", WidgetSettingsMenuHelper.GetLocalizationKey(WidgetKind.DingTalkGroupDocs));
        Assert.Equal("Calendar.Title", WidgetSettingsMenuHelper.GetLocalizationKey(WidgetKind.Calendar));
        Assert.Equal("Widget.Settings.Aquarium", WidgetSettingsMenuHelper.GetLocalizationKey(WidgetKind.Aquarium));
    }

    [Fact]
    public void GetLocalizationKey_Falls_Back_To_Configure_For_Unknown_Kinds()
    {
        Assert.Equal("Common.Configure", WidgetSettingsMenuHelper.GetLocalizationKey(WidgetKind.Tags));
    }
}
