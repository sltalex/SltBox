using DeskBox.Services;
using DeskBox.Controls.WidgetContents;
using System.Text.Json;
using DeskBox.Core.Persistence;

namespace DeskBox.Tests;

public sealed class Tree3DPresentationTests
{
    [Fact]
    public void PresentationModeDefaultsToTwoDAndPersistsLocally()
    {
        string root = Path.Combine(Path.GetTempPath(), $"deskbox-tree3d-{Guid.NewGuid():N}");
        try
        {
            var first = new TreeVisualSettingsService(root);
            Assert.False(first.UseThreeD);
            first.SetUseThreeD(true);

            var reopened = new TreeVisualSettingsService(root);
            Assert.True(reopened.UseThreeD);
            reopened.SetUseThreeD(false);
            Assert.False(new TreeVisualSettingsService(root).UseThreeD);
        }
        finally
        {
            if (Directory.Exists(root)) Directory.Delete(root, recursive: true);
        }
    }

    [Fact]
    public void ThreeDAssetsAreLocalAndDoNotReferenceRemoteSources()
    {
        string root = TestPaths.FromRepository("src/DeskBox/Assets/Tree/ThreeD");
        string html = File.ReadAllText(Path.Combine(root, "index.html"));
        string script = File.ReadAllText(Path.Combine(root, "tree3d.js"));
        Assert.True(File.Exists(Path.Combine(root, "three.module.min.js")));
        Assert.True(File.Exists(Path.Combine(root, "three.core.min.js")));
        Assert.Contains("./bootstrap.js", html, StringComparison.Ordinal);
        Assert.Contains("./tree3d.js", File.ReadAllText(Path.Combine(root, "bootstrap.js")), StringComparison.Ordinal);
        Assert.Contains("./three.module.min.js", script, StringComparison.Ordinal);
        Assert.DoesNotContain("unpkg.com", script, StringComparison.OrdinalIgnoreCase);
        Assert.DoesNotContain("jsdelivr.net", script, StringComparison.OrdinalIgnoreCase);
        Assert.Contains("fruitTapped", script, StringComparison.Ordinal);
        Assert.Contains("wildlifeTapped", script, StringComparison.Ordinal);
    }

    [Fact]
    public void SceneStatePreservesNativeCoordinatesMotionAndLargeIdentifiers()
    {
        const string pendingId = "9223372036854775806";
        var state = new Tree3DSceneState(5, 144000, true, false,
            [new Tree3DFruitState(0, pendingId, "apple", "Fruits/Source/apple.png", 95, 112, false)],
            new Tree3DWildlifeState("encounter", "bird", "sky", "Wildlife/Source/bird.png",
                240, 30, 36, 48, 12,
                [new WildlifeMotionFrame(0, 0, 0, 0, -1, 1, 1),
                 new WildlifeMotionFrame(1, -240, 3, 5, -1, 1, .5)], true, false),
            [new Tree3DDecorationState("ground", "bench", "Decorations/Source/bench.svg",
                50, .5, .94, 1.2, 15, -1)], "Rain", true, false);
        string json = JsonSerializer.Serialize(state, Tree3DJsonContext.Default.Tree3DSceneState);
        using var document = JsonDocument.Parse(json);
        var root = document.RootElement;
        Assert.Equal(5, root.GetProperty("stage").GetInt32());
        Assert.Equal(pendingId, root.GetProperty("fruits")[0].GetProperty("pendingId").GetString());
        Assert.Equal(-240, root.GetProperty("wildlife").GetProperty("frames")[1].GetProperty("x").GetDouble());
        Assert.Equal(.94, root.GetProperty("decorations")[0].GetProperty("y").GetDouble());
        Assert.False(root.GetProperty("animationEnabled").GetBoolean());
        Assert.DoesNotContain("sessionToken", json, StringComparison.OrdinalIgnoreCase);
        Assert.DoesNotContain("inventory", json, StringComparison.OrdinalIgnoreCase);
    }

    [Fact]
    public void OlderSceneStateCanOmitOptionalCollectionsAndWeather()
    {
        var state = JsonSerializer.Deserialize("{\"stage\":2,\"totalEnergy\":36000}",
            Tree3DJsonContext.Default.Tree3DSceneState);
        Assert.NotNull(state);
        Assert.Equal(2, state.Stage);
        Assert.Null(state.Wildlife);
        Assert.Null(state.Weather);
    }

    [Fact]
    public void PresentationModeRecoversFromBackupWithoutTouchingProgress()
    {
        string root = Path.Combine(Path.GetTempPath(), $"deskbox-tree3d-{Guid.NewGuid():N}");
        try
        {
            var service = new TreeVisualSettingsService(root);
            service.SetUseThreeD(true);
            service.SetUseThreeD(false);
            File.WriteAllText(Path.Combine(root, "tree-visual", "presentation.json"), "{");
            Assert.True(new TreeVisualSettingsService(root).UseThreeD);
            Assert.False(File.Exists(Path.Combine(root, "tree", "progress.json")));
        }
        finally { if (Directory.Exists(root)) Directory.Delete(root, true); }
    }

    [Fact]
    public async Task PresentationPreferenceNeverEntersAccountBackup()
    {
        string root = Path.Combine(Path.GetTempPath(), $"deskbox-tree3d-{Guid.NewGuid():N}");
        try
        {
            new TreeVisualSettingsService(root).SetUseThreeD(true);
            string data = Path.Combine(root, "data");
            Directory.CreateDirectory(Path.Combine(data, "tree"));
            File.WriteAllText(Path.Combine(data, "tree", "progress.json"), "{}");
            var files = await new AccountBackupFileCatalog(data).CaptureAsync();
            Assert.Single(files);
            Assert.Equal("tree/progress.json", files[0].Path);
        }
        finally { if (Directory.Exists(root)) Directory.Delete(root, true); }
    }

    [Fact]
    public void TreeSurfaceKeepsTheExistingTwoDRendererAndUsesThreeDAsAnOptInLayer()
    {
        string source = File.ReadAllText(TestPaths.FromRepository(
            "src/DeskBox/Controls/WidgetContents/TreeWidgetContent.cs"));
        string bridge = File.ReadAllText(TestPaths.FromRepository(
            "src/DeskBox/Controls/WidgetContents/TreeWidgetContent.ThreeD.cs"));
        Assert.Contains("_drawingViewbox", source, StringComparison.Ordinal);
        Assert.Contains("_tree3D", source, StringComparison.Ordinal);
        Assert.Contains("layout.Children.Add(_drawingViewbox)", source, StringComparison.Ordinal);
        Assert.Contains("layout.Children.Add(_threeDSurface)", source, StringComparison.Ordinal);
        Assert.Contains("_visualSettings.UseThreeD", bridge, StringComparison.Ordinal);
        Assert.Contains("PickFruitAsync", bridge, StringComparison.Ordinal);
        Assert.Contains("CaptureAsync", File.ReadAllText(TestPaths.FromRepository(
            "src/DeskBox/Controls/WidgetContents/TreeWidgetContent.Wildlife.cs")), StringComparison.Ordinal);
    }
}
