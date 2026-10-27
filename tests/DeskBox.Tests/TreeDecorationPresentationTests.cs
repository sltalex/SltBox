using System.Text.Json;

namespace DeskBox.Tests;

public sealed class TreeDecorationPresentationTests
{
    [Fact]
    public void DecorationSettings_UseFullSceneAndWrappingCompactInventory()
    {
        string section = File.ReadAllText(TestPaths.FromRepository(
            "src/DeskBox/Views/SettingsSections/TreeDecorationSettingsSection.cs"));
        string scene = File.ReadAllText(TestPaths.FromRepository(
            "src/DeskBox/Controls/TreeDecorationSceneView.cs"));

        Assert.DoesNotContain("Tree.Decorations.Ready", section, StringComparison.Ordinal);
        Assert.DoesNotContain("Tree.Decorations.Balance", section, StringComparison.Ordinal);
        Assert.DoesNotContain("Common.Refresh", section, StringComparison.Ordinal);
        Assert.Contains("private readonly Grid _catalog", section, StringComparison.Ordinal);
        Assert.Contains("TreeDecorationInventoryLayout.SettingsColumnsForWidth", section, StringComparison.Ordinal);
        Assert.Contains("UpdateCatalogColumns(args.NewSize.Width)", section, StringComparison.Ordinal);
        Assert.Contains("Width = TreeDecorationInventoryLayout.SettingsCardWidth", section, StringComparison.Ordinal);
        Assert.Contains("Width = 54, Height = 43", section, StringComparison.Ordinal);
        Assert.Contains("BackpackQuantityBadge", section, StringComparison.Ordinal);
        Assert.Contains("var editorFields = new StackPanel { Spacing = 2 }", section, StringComparison.Ordinal);
        Assert.Contains("Height = TreeIllustrationLayout.Height", scene, StringComparison.Ordinal);
        Assert.Contains("VerticalAlignment = VerticalAlignment.Top", scene, StringComparison.Ordinal);
        Assert.Contains("placement.PlacementId", scene, StringComparison.Ordinal);
    }

    [Fact]
    public void DecorationAssets_ContainUniqueTwentyFourItemSourceAndSilhouettePairs()
    {
        string root = TestPaths.FromRepository("src/DeskBox/Assets/Tree/Decorations");
        using JsonDocument document = JsonDocument.Parse(File.ReadAllText(Path.Combine(root, "catalog.json")));
        JsonElement[] items = document.RootElement.GetProperty("items").EnumerateArray().ToArray();

        Assert.Equal(24, items.Length);
        Assert.Equal(24, items.Select(item => item.GetProperty("id").GetString()).Distinct().Count());
        foreach (JsonElement item in items)
        {
            string key = item.GetProperty("assetKey").GetString()!;
            Assert.True(File.Exists(Path.Combine(root, "Source", key + ".svg")), key);
            Assert.True(File.Exists(Path.Combine(root, "Blur", key + ".svg")), key);
        }
    }

    [Fact]
    public void ProjectAndSettings_RegisterAssetsInsideTreeSettingsAndCardPacksInsideStore()
    {
        string project = File.ReadAllText(TestPaths.FromRepository("src/DeskBox/DeskBox.csproj"));
        string xaml = File.ReadAllText(TestPaths.FromRepository("src/DeskBox/Views/SettingsWindow.xaml"));
        string shell = File.ReadAllText(TestPaths.FromRepository("src/DeskBox/Views/SettingsWindow.xaml.cs"));

        Assert.Contains("Assets\\Tree\\Decorations\\**\\*.svg", project, StringComparison.Ordinal);
        Assert.DoesNotContain("TreeDecorationSettingsSectionTemplate", xaml, StringComparison.Ordinal);
        Assert.DoesNotContain("Tag=\"TreeDecorationSettings\"", xaml, StringComparison.Ordinal);
        Assert.DoesNotContain("[\"TreeDecorationSettings\"]", shell, StringComparison.Ordinal);
        string treeSettings = File.ReadAllText(TestPaths.FromRepository(
            "src/DeskBox/Views/SettingsSections/TreeSettingsSection.cs"));
        Assert.Contains("TreeDecorationSettingsSection _decorations", treeSettings, StringComparison.Ordinal);
        string section = File.ReadAllText(TestPaths.FromRepository(
            "src/DeskBox/Views/SettingsSections/TreeDecorationSettingsSection.cs"));
        Assert.Contains("InitializeAsync", section, StringComparison.Ordinal);
        Assert.DoesNotContain("Tree.Decorations.EditorTitle", section, StringComparison.Ordinal);
        Assert.DoesNotContain("Tree.Decorations.EditorDescription", section, StringComparison.Ordinal);
        string aquarium = File.ReadAllText(TestPaths.FromRepository(
            "src/DeskBox/Views/SettingsSections/AquariumSettingsSection.cs"));
        Assert.Contains("TreePackStoreCard(1)", aquarium, StringComparison.Ordinal);
        Assert.Contains("TreePackStoreCard(10)", aquarium, StringComparison.Ordinal);
        Assert.Contains("OpenPackAsync(next.Id)", aquarium, StringComparison.Ordinal);
        Assert.Contains("RenderTreeDecorationBackpack", aquarium, StringComparison.Ordinal);
        Assert.Contains("TreeDecorationBackpackCard", aquarium, StringComparison.Ordinal);
        Assert.Contains("TreeDecorationInventoryLayout.ColumnCount", aquarium, StringComparison.Ordinal);
    }

    [Fact]
    public void DecorationPage_ShowsOfflineAndErrorsBeforeEmptyStateLoading()
    {
        string section = File.ReadAllText(TestPaths.FromRepository(
            "src/DeskBox/Views/SettingsSections/TreeDecorationSettingsSection.cs"));

        int offline = section.IndexOf("_service.IsOffline", StringComparison.Ordinal);
        int error = section.IndexOf("_service.ErrorCode is string errorCode", StringComparison.Ordinal);
        int loading = section.IndexOf("state is null", offline, StringComparison.Ordinal);

        Assert.True(offline >= 0 && error > offline && loading > error,
            "Offline and server errors must not be hidden behind the empty-state loading message.");
    }

    [Fact]
    public void DesktopTree_HasFiveDecorationLayersBelowRealWeather()
    {
        string source = File.ReadAllText(TestPaths.FromRepository(
            "src/DeskBox/Controls/WidgetContents/TreeWidgetContent.cs"));
        string decorations = File.ReadAllText(TestPaths.FromRepository(
            "src/DeskBox/Controls/WidgetContents/TreeWidgetContent.Decorations.cs"));

        Assert.Contains("_decorationLayers[-2]", source, StringComparison.Ordinal);
        Assert.Contains("_decorationLayers[-1]", source, StringComparison.Ordinal);
        Assert.Contains("_decorationLayers[0]", source, StringComparison.Ordinal);
        Assert.Contains("_decorationLayers[1]", source, StringComparison.Ordinal);
        Assert.Contains("_decorationLayers[2]", source, StringComparison.Ordinal);
        Assert.True(source.IndexOf("_decorationLayers[2]", StringComparison.Ordinal) <
            source.IndexOf("_weatherFrontLayer", StringComparison.Ordinal));
        Assert.Contains("IsHitTestVisible = false", decorations, StringComparison.Ordinal);
        Assert.Contains("_renderedDecorationSignature", decorations, StringComparison.Ordinal);
    }

    [Fact]
    public void ChineseAndEnglishResources_CoverDecorationPageAndEditorActions()
    {
        foreach (string culture in new[] { "zh-CN", "en-US" })
        {
            using JsonDocument document = JsonDocument.Parse(File.ReadAllText(
                TestPaths.FromRepository($"src/DeskBox/Strings/{culture}.json")));
            JsonElement root = document.RootElement;
            foreach (string key in new[]
            {
                "Tree.Decorations.Title", "Tree.Decorations.CardPack.Name", "Tree.Decorations.CardPack.Open",
                "Tree.Decorations.Save", "Tree.Decorations.Undo", "Tree.Decorations.Return",
                "Tree.Decorations.Scale", "Tree.Decorations.Rotation", "Tree.Decorations.Layer"
            })
                Assert.True(root.TryGetProperty(key, out _), $"{culture}: {key}");
        }
    }
}
