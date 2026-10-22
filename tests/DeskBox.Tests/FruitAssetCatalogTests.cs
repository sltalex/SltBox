using System.Text.Json;

namespace DeskBox.Tests;

public sealed class FruitAssetCatalogTests
{
    [Fact]
    public void ManifestMapsAll112FruitsToExplicitNormalAndBlurAssets()
    {
        string root = TestPaths.FromRepository("src/DeskBox/Assets/Tree");
        using JsonDocument document = JsonDocument.Parse(File.ReadAllText(Path.Combine(root, "fruits-manifest.json")));
        JsonElement items = document.RootElement.GetProperty("items");

        Assert.Equal(112, items.GetArrayLength());
        Assert.Equal("individual", document.RootElement.GetProperty("assetMode").GetString());
        Assert.Equal(210, document.RootElement.GetProperty("assetCount").GetInt32());

        var normal = new HashSet<string>(StringComparer.Ordinal);
        var blur = new HashSet<string>(StringComparer.Ordinal);
        foreach (JsonElement item in items.EnumerateArray())
        {
            string id = item.GetProperty("id").GetString()!;
            string normalPath = item.GetProperty("assetFile").GetString()!;
            string blurPath = item.GetProperty("blurAssetFile").GetString()!;
            Assert.StartsWith("Fruits/Source/", normalPath, StringComparison.Ordinal);
            Assert.StartsWith("Fruits/Blur/", blurPath, StringComparison.Ordinal);
            Assert.True(normal.Add(normalPath), $"Duplicate normal asset for {id}");
            Assert.True(blur.Add(blurPath), $"Duplicate blur asset for {id}");
            Assert.True(File.Exists(Path.Combine(root, normalPath.Replace('/', Path.DirectorySeparatorChar))), normalPath);
            Assert.True(File.Exists(Path.Combine(root, blurPath.Replace('/', Path.DirectorySeparatorChar))), blurPath);
        }

        Assert.Equal(112, normal.Count);
        Assert.Equal(112, blur.Count);
    }

    [Fact]
    public void ImportedNormalAndBlurAssetsAreRgbaPngsAndSourcesArePaired()
    {
        string root = TestPaths.FromRepository("src/DeskBox/Assets/Tree/Fruits");
        string source = Path.Combine(root, "Source");
        string blurred = Path.Combine(root, "Blur");
        string[] normalFiles = Directory.GetFiles(source, "*.png");
        string[] blurFiles = Directory.GetFiles(blurred, "*.png");

        Assert.Equal(210, normalFiles.Length);
        Assert.Equal(210, blurFiles.Length);
        Assert.Equal(normalFiles.Select(Path.GetFileName).OrderBy(x => x), blurFiles.Select(Path.GetFileName).OrderBy(x => x));
        foreach (string file in normalFiles.Concat(blurFiles))
        {
            byte[] png = File.ReadAllBytes(file);
            Assert.True(png.Length > 26 && png.AsSpan(0, 8).SequenceEqual(new byte[] { 137, 80, 78, 71, 13, 10, 26, 10 }), file);
            Assert.Equal(6, png[25]); // RGBA color type in the PNG IHDR chunk.
        }
    }

    [Fact]
    public void KnownEntriesUseIndependentFilesInsteadOfLegacyAtlasOrIdPaths()
    {
        string manifest = File.ReadAllText(TestPaths.FromRepository("src/DeskBox/Assets/Tree/fruits-manifest.json"));
        Assert.Contains("Fruits/Source/fruit_r01_c01.png", manifest, StringComparison.Ordinal); // apple
        Assert.Contains("Fruits/Source/fruit_r03_c09.png", manifest, StringComparison.Ordinal); // heart entry's reviewed slot
        Assert.Contains("Fruits/Blur/fruit_r03_c10.png", manifest, StringComparison.Ordinal); // spike entry's blur slot
        Assert.DoesNotContain("assetFile\": \"fruits-atlas.png", manifest, StringComparison.Ordinal);

        foreach (string file in new[]
        {
            "src/DeskBox/Controls/WidgetContents/TreeWidgetContent.cs",
            "src/DeskBox/Views/SettingsSections/FruitMarketSection.xaml.cs",
            "src/DeskBox/Views/SettingsSections/FruitEncyclopediaSection.xaml.cs"
        })
        {
            string source = File.ReadAllText(TestPaths.FromRepository(file));
            Assert.DoesNotContain("Assets/Tree/Fruits/{", source, StringComparison.Ordinal);
            Assert.DoesNotContain("atlasIndex", source, StringComparison.Ordinal);
        }
    }

    [Fact]
    public void ExplicitMappingsKeepTheLegacyTwentyColumnCatalogueOrder()
    {
        using JsonDocument document = JsonDocument.Parse(File.ReadAllText(
            TestPaths.FromRepository("src/DeskBox/Assets/Tree/fruits-manifest.json")));
        JsonElement[] items = document.RootElement.GetProperty("items").EnumerateArray().ToArray();

        Assert.Equal("Fruits/Source/fruit_r01_c20.png", items.Single(x => x.GetProperty("atlasIndex").GetInt32() == 19).GetProperty("assetFile").GetString());
        Assert.Equal("Fruits/Source/fruit_r02_c01.png", items.Single(x => x.GetProperty("atlasIndex").GetInt32() == 20).GetProperty("assetFile").GetString());
        Assert.Equal("Fruits/Source/fruit_r03_c01.png", items.Single(x => x.GetProperty("atlasIndex").GetInt32() == 40).GetProperty("assetFile").GetString());
    }
}
