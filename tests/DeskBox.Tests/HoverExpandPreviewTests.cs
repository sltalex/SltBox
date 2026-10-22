using DeskBox.Models;
using DeskBox.Services;
using DeskBox.ViewModels;
using System.Text.RegularExpressions;

namespace DeskBox.Tests;

public sealed class HoverExpandPreviewTests
{
    [Fact]
    public void QuickCaptureRecord_HoverTemporarilyRevealsAllLines_WhenEnabled()
    {
        var item = new QuickCaptureItemViewModel(
            new QuickCaptureItem { Body = "one\ntwo\nthree\nfour" },
            TestServices.CreateLocalizationService(),
            textSize: 12,
            iconSize: 16,
            searchText: null);

        item.UpdatePreviewPresentation(3, expandItemOnHover: true);
        Assert.Equal(3, item.PreviewMaxLines);

        item.SetPointerOver(true);
        Assert.Equal(0, item.PreviewMaxLines);

        item.UpdatePreviewPresentation(3, expandItemOnHover: false);
        Assert.Equal(3, item.PreviewMaxLines);

        item.SetPointerOver(false);
        Assert.Equal(3, item.PreviewMaxLines);
    }

    [Fact]
    public void TodoRecord_HoverKeepsConfiguredLineLimit_WhenDisabled()
    {
        var item = new TodoItemViewModel(new TodoItem { Text = "one\ntwo\nthree" });

        item.UpdatePreviewPresentation(2, expandItemOnHover: false);
        item.SetPointerOver(true);

        Assert.Equal(2, item.PreviewMaxLines);
    }

    [Fact]
    public async Task SeparateHoverPreferences_RoundTripIndependently()
    {
        string root = Path.Combine(
            Path.GetTempPath(), "DeskBox.Tests", Guid.NewGuid().ToString("N"));
        try
        {
            var settings = new SettingsService(root);
            settings.Settings.QuickCapture.QuickCaptureExpandItemOnHover = false;
            settings.Settings.Todo.TodoExpandItemOnHover = true;
            await settings.SaveAsync();

            var reloaded = new SettingsService(root);
            await reloaded.LoadAsync();

            Assert.False(reloaded.Settings.QuickCapture.QuickCaptureExpandItemOnHover);
            Assert.True(reloaded.Settings.Todo.TodoExpandItemOnHover);
        }
        finally
        {
            if (Directory.Exists(root))
            {
                Directory.Delete(root, recursive: true);
            }
        }
    }

    [Fact]
    public void SettingsWindow_OffersOneHoverSwitchForEachFeatureSection()
    {
        string xaml = File.ReadAllText(TestPaths.FromRepository(
            "src/DeskBox/Views/SettingsWindow.xaml"));

        Assert.Equal(
            2,
            Regex.Matches(
                xaml,
                "IsOn=\\\"\\{Binding ExpandItemOnHover, Mode=TwoWay\\}\\\"").Count);
    }
}
