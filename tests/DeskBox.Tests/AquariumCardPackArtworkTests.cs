using System.Buffers.Binary;
using System.Xml.Linq;
using DeskBox.Controls.WidgetContents;
using DeskBox.Services;

namespace DeskBox.Tests;

public sealed class AquariumCardPackArtworkTests
{
    [Fact]
    public void CardPackUsesPackagedRootArtworkWithoutBecomingASceneSpecies()
    {
        string key = AquariumService.AquariumCardPackItemId;
        Assert.False(AquariumArtworkCatalog.TryGetProfile(key, out _));
        Assert.Equal("Assets/Aquarium/aquarium-card-pack.png", AquariumArtworkCatalog.AssetRelativePath(key));

        string assets = Path.Combine(AppContext.BaseDirectory, "Assets", "Aquarium");
        Assert.True(File.Exists(Path.Combine(assets, key + ".svg")));
        byte[] png = File.ReadAllBytes(Path.Combine(assets, key + ".png"));
        Assert.Equal(new byte[] { 137, 80, 78, 71, 13, 10, 26, 10 }, png.Take(8).ToArray());
        Assert.Equal(512u, BinaryPrimitives.ReadUInt32BigEndian(png.AsSpan(16, 4)));
        Assert.Equal(512u, BinaryPrimitives.ReadUInt32BigEndian(png.AsSpan(20, 4)));
        Assert.Equal(8, png[24]);
        Assert.Equal(6, png[25]); // RGBA keeps the card silhouette transparent in both themes.
    }

    [Fact]
    public void CardPackMasterContainsThreeOrderedSelfContainedCardLayers()
    {
        string source = Path.Combine(AppContext.BaseDirectory, "Assets", "Aquarium", "aquarium-card-pack.svg");
        XElement root = XDocument.Load(source).Root!;
        XNamespace svg = "http://www.w3.org/2000/svg";
        Assert.Equal(svg + "svg", root.Name);
        Assert.Equal("0 0 512 512", root.Attribute("viewBox")?.Value);
        Assert.Equal(new[] { "card-back", "card-middle", "card-front" },
            root.Elements(svg + "g").Select(layer => (string?)layer.Attribute("id")));
        Assert.DoesNotContain(root.Descendants(), element =>
            element.Name.LocalName is "image" or "script" or "foreignObject" or "filter" or "text");
        Assert.DoesNotContain(root.DescendantsAndSelf().Attributes(), attribute =>
            attribute.Name.LocalName == "href" && !attribute.Value.StartsWith('#'));
    }

    [Fact]
    public void StoreBackpackAndRevealShareThePackImageAndPreserveRewardArtwork()
    {
        DirectoryInfo? root = new(AppContext.BaseDirectory);
        while (root is not null && !File.Exists(Path.Combine(root.FullName, "DeskBox.sln"))) root = root.Parent;
        Assert.NotNull(root);
        string settings = File.ReadAllText(Path.Combine(root.FullName,
            "src", "DeskBox", "Views", "SettingsSections", "AquariumSettingsSection.cs"));

        Assert.Contains("IsCardPack(product.Category) ? PackVisual(width, height)", settings, StringComparison.Ordinal);
        Assert.Contains("Source = AquariumSceneView.Asset(AquariumService.AquariumCardPackItemId)", settings, StringComparison.Ordinal);
        Assert.Contains("content.Children.Add(PackVisual(76, 64))", settings, StringComparison.Ordinal);
        Assert.Contains("content.Children.Add(_packRevealImage)", settings, StringComparison.Ordinal);
        Assert.Contains("product is null ? null : AquariumSceneView.Asset(product.AssetKey)",
            settings, StringComparison.Ordinal);
        Assert.Contains("TreeDecorationAssetCatalog.Source(first.ItemId)", settings, StringComparison.Ordinal);
        Assert.Contains("_packRevealImage.Source = image", settings, StringComparison.Ordinal);
        Assert.DoesNotContain("Text = \"✦\"", settings, StringComparison.Ordinal);
    }
}
