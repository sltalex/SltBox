using DeskBox.Controls.WidgetContents;
using System.Globalization;
using System.Xml.Linq;

namespace DeskBox.Tests;

public sealed class TreeIllustrationTests
{
    private static readonly XNamespace Svg = "http://www.w3.org/2000/svg";

    [Theory]
    [InlineData(int.MinValue, 6)]
    [InlineData(1, 6)]
    [InlineData(2, 5)]
    [InlineData(3, 4)]
    [InlineData(4, 3.5)]
    [InlineData(5, 3)]
    [InlineData(int.MaxValue, 3)]
    public void CompactTreeUsesStageAppropriateVisibleSway(int stage, double expected)
    {
        Assert.Equal(expected, TreeIllustrationLayout.SwayAmplitudeDegrees(stage));
    }

    [Fact]
    public void SeedlingLeafMotionRemainsVisibleAtTheCompactDisplaySize()
    {
        // The stage-1 tip (176,96) rotates around (130,142).
        double radius = Math.Sqrt(46 * 46 + 46 * 46);
        double angle = TreeIllustrationLayout.SwayAmplitudeDegrees(1) * Math.PI / 180;
        double screenTravel = 2 * radius * Math.Sin(angle) *
            TreeIllustrationLayout.DisplayHeight / TreeIllustrationLayout.Height;
        Assert.InRange(screenTravel, 4, 9);

        string source = File.ReadAllText(TestPaths.FromRepository(
            "src/DeskBox/Controls/WidgetContents/TreeWidgetContent.cs"));
        Assert.Contains("Height = TreeIllustrationLayout.DisplayHeight", source);
        Assert.Contains("TreeIllustrationLayout.SwayAmplitudeDegrees(p.Stage)", source);
        Assert.Contains("_swayRotation.From = -swayAmplitude", source);
        Assert.Contains("_swayRotation.To = swayAmplitude", source);
    }

    [Theory]
    [InlineData(1, true)]
    [InlineData(2, true)]
    [InlineData(3, true)]
    [InlineData(4, true)]
    [InlineData(5, false)]
    public void StageProgressStopsAtMaturity(int stage, bool visible)
    {
        Assert.Equal(visible, TreeIllustrationLayout.ShowsStageProgress(stage));
    }

    [Fact]
    public void LowerTreeKeepsNativeSizeAndRootsInsideGround()
    {
        double pivotY = TreeIllustrationLayout.ArtworkHeight * .86;
        double offset = TreeIllustrationLayout.TreeVerticalOffset;
        double canopyTop = pivotY + (6 - pivotY) * TreeIllustrationLayout.TreeArtworkScale + offset;
        double trunkFoot = pivotY + (195 - pivotY) * TreeIllustrationLayout.TreeArtworkScale + offset;
        XDocument ground = XDocument.Load(AssetPath("ground.svg"));
        XElement grass = Assert.Single(ground.Descendants(Svg + "ellipse"), element =>
            (string?)element.Attribute("fill") == "url(#grass)");
        double centerY = double.Parse((string)grass.Attribute("cy")!, CultureInfo.InvariantCulture) +
            TreeIllustrationLayout.GroundVerticalOffset;
        double radiusX = double.Parse((string)grass.Attribute("rx")!, CultureInfo.InvariantCulture);
        double radiusY = double.Parse((string)grass.Attribute("ry")!, CultureInfo.InvariantCulture);
        Assert.Equal(195 - 6, trunkFoot - canopyTop);
        Assert.InRange(canopyTop, 54, 58);
        Assert.InRange(radiusX, 90, 112);
        Assert.InRange(radiusY, 19, 25);
        Assert.InRange(trunkFoot, centerY - radiusY / 2, centerY + radiusY / 2);
        Assert.True(centerY + radiusY <= TreeIllustrationLayout.Height);
        Assert.Equal(.5, TreeIllustrationLayout.DisplayHeight / TreeIllustrationLayout.Height);

        string source = File.ReadAllText(TestPaths.FromRepository(
            "src/DeskBox/Controls/WidgetContents/TreeWidgetContent.cs"));
        Assert.Contains("_treeTransform.TranslateY = TreeIllustrationLayout.TreeVerticalOffset", source);
        Assert.Contains("_progress.Visibility = TreeIllustrationLayout.ShowsStageProgress(p.Stage)", source);
        Assert.Contains("_progress.SetAnimationEnabled(shouldAnimate && _progress.Visibility == Visibility.Visible)", source);
    }

    [Theory]
    [InlineData(int.MinValue, "stage-1.svg")]
    [InlineData(0, "stage-1.svg")]
    [InlineData(1, "stage-1.svg")]
    [InlineData(2, "stage-2.svg")]
    [InlineData(3, "stage-3.svg")]
    [InlineData(4, "stage-4.svg")]
    [InlineData(5, "stage-5.svg")]
    [InlineData(6, "stage-5.svg")]
    [InlineData(int.MaxValue, "stage-5.svg")]
    public void EachGrowthStageSelectsItsBundledIllustration(int stage, string expected)
    {
        Assert.Equal(expected, TreeIllustrationLayout.StageAssetName(stage));
        Assert.True(File.Exists(AssetPath(expected)));
    }

    [Theory]
    [InlineData(long.MinValue, 0)]
    [InlineData(-1L, 0)]
    [InlineData(0L, 0)]
    [InlineData(1L, 1)]
    [InlineData(12L, 12)]
    [InlineData(13L, 13)]
    [InlineData(1000L, 1000)]
    [InlineData(long.MaxValue, int.MaxValue)]
    public void FruitCountDoesNotStopAtTheFormerTwelveItemLimit(long inventory, int visible)
    {
        Assert.Equal(visible, TreeIllustrationLayout.VisibleFruitCount(inventory));
    }

    [Fact]
    public void GeneratedFruitPositionsStayInsideTheHarvestableCanopy()
    {
        Assert.Equal(260, TreeIllustrationLayout.Width);
        Assert.Equal(220, TreeIllustrationLayout.ArtworkHeight);
        Assert.Equal(260, TreeIllustrationLayout.Height);
        var positions = Enumerable.Range(0, 32).Select(TreeIllustrationLayout.FruitPositionAt).ToArray();
        Assert.Equal(positions.Length, positions.Distinct().Count());

        foreach ((double x, double y) in positions)
        {
            Assert.True(double.IsFinite(x) && double.IsFinite(y));
            Assert.InRange(x, 0, TreeIllustrationLayout.Width - 24);
            Assert.InRange(y, 0, TreeIllustrationLayout.ArtworkHeight - 28);
            Assert.True(TreeIllustrationLayout.CanHarvest(5, 1, x + 12, y + 14));
        }
    }

    [Fact]
    public void FruitHitTargetRejectsEmptyCanopyAndSelectsVisibleFruit()
    {
        (double x0, double y0) = TreeIllustrationLayout.FruitPositionAt(0);
        (double x1, double y1) = TreeIllustrationLayout.FruitPositionAt(1);
        Assert.Equal(0, TreeIllustrationLayout.FruitIndexAt(1, x0 + 12, y0 + 14));
        Assert.Equal(1, TreeIllustrationLayout.FruitIndexAt(2, x1 + 12, y1 + 14));
        Assert.Equal(-1, TreeIllustrationLayout.FruitIndexAt(1, 130, 80));
        (double x30, double y30) = TreeIllustrationLayout.FruitPositionAt(30);
        Assert.Equal(30, TreeIllustrationLayout.FruitIndexAt(31, x30 + 12, y30 + 14));
    }

    [Fact]
    public void FruitPositionsRemainStableAndContinueBeyondTheLegacyPrefix()
    {
        var positions = Enumerable.Range(0, 100).Select(TreeIllustrationLayout.FruitPositionAt).ToArray();
        Assert.Equal(positions[0], TreeIllustrationLayout.FruitPositionAt(0));
        Assert.Equal(positions[99], TreeIllustrationLayout.FruitPositionAt(99));
        Assert.True(positions.Distinct().Count() > 90);
        Assert.All(positions, position =>
        {
            Assert.InRange(position.X, 8, TreeIllustrationLayout.Width - 32);
            Assert.InRange(position.Y, 4, TreeIllustrationLayout.ArtworkHeight - 32);
        });
    }

    [Fact]
    public void FirstTwelveFruitImagesDoNotOverlap()
    {
        var positions = Enumerable.Range(0, 12).Select(TreeIllustrationLayout.FruitPositionAt).ToArray();
        for (int index = 0; index < positions.Length; index++)
            for (int prior = 0; prior < index; prior++)
                Assert.True(Math.Abs(positions[index].X - positions[prior].X) >= 24 ||
                    Math.Abs(positions[index].Y - positions[prior].Y) >= 28,
                    $"Fruit images {prior} and {index} overlap.");
    }

    [Theory]
    [InlineData(5, 1L, 32, 12, true)]
    [InlineData(5, 1L, 228, 150, true)]
    [InlineData(5, long.MaxValue, 130, 80, true)]
    [InlineData(1, 1L, 130, 80, false)]
    [InlineData(4, 1L, 130, 80, false)]
    [InlineData(6, 1L, 130, 80, false)]
    [InlineData(5, 0L, 130, 80, false)]
    [InlineData(5, -1L, 130, 80, false)]
    [InlineData(5, 1L, 31.99, 80, false)]
    [InlineData(5, 1L, 228.01, 80, false)]
    [InlineData(5, 1L, 130, 11.99, false)]
    [InlineData(5, 1L, 130, 150.01, false)]
    [InlineData(5, 1L, double.NaN, 80, false)]
    [InlineData(5, 1L, 130, double.PositiveInfinity, false)]
    public void HarvestRequiresMaturityInventoryAndACanopyTap(int stage, long inventory, double x, double y, bool expected)
    {
        Assert.Equal(expected, TreeIllustrationLayout.CanHarvest(stage, inventory, x, y));
    }

    [Theory]
    [InlineData("stage-1.svg", "0 0 260 220")]
    [InlineData("stage-2.svg", "0 0 260 220")]
    [InlineData("stage-3.svg", "0 0 260 220")]
    [InlineData("stage-4.svg", "0 0 260 220")]
    [InlineData("stage-5.svg", "0 0 260 220")]
    [InlineData("stage-1-canopy.svg", "0 0 260 220")]
    [InlineData("stage-2-canopy.svg", "0 0 260 220")]
    [InlineData("stage-3-canopy.svg", "0 0 260 220")]
    [InlineData("stage-4-canopy.svg", "0 0 260 220")]
    [InlineData("stage-5-canopy.svg", "0 0 260 220")]
    [InlineData("ground.svg", "0 0 260 220")]
    [InlineData("fruit.svg", "0 0 24 28")]
    [InlineData("falling-leaf.svg", "0 0 18 12")]
    public void IllustrationsAreSelfContainedSvgWithoutUnsupportedEffects(string fileName, string viewBox)
    {
        XDocument document = XDocument.Load(AssetPath(fileName));
        XElement root = Assert.IsType<XElement>(document.Root);
        Assert.Equal(Svg + "svg", root.Name);
        Assert.Equal(viewBox, (string?)root.Attribute("viewBox"));

        string[] unsupportedElements = ["filter", "script", "image", "foreignObject", "text", "animate", "animateTransform"];
        Assert.DoesNotContain(root.Descendants(), element => unsupportedElements.Contains(element.Name.LocalName));

        string[] ids = root.DescendantsAndSelf().Attributes("id").Select(attribute => attribute.Value).ToArray();
        Assert.Equal(ids.Length, ids.Distinct(StringComparer.Ordinal).Count());

        foreach (XAttribute attribute in root.DescendantsAndSelf().Attributes())
        {
            Assert.False(attribute.Name.LocalName.StartsWith("on", StringComparison.OrdinalIgnoreCase),
                $"Unexpected SVG event handler in {fileName}: {attribute.Name}");
            Assert.NotEqual("filter", attribute.Name.LocalName);
            if (attribute.Name.LocalName == "href")
            {
                Assert.StartsWith("#", attribute.Value, StringComparison.Ordinal);
                Assert.Contains(attribute.Value[1..], ids);
            }

            if (attribute.Value.StartsWith("url(", StringComparison.Ordinal))
            {
                Assert.StartsWith("url(#", attribute.Value, StringComparison.Ordinal);
                Assert.EndsWith(")", attribute.Value, StringComparison.Ordinal);
                Assert.Contains(attribute.Value[5..^1], ids);
            }
        }

        foreach (XElement gradient in root.Descendants().Where(element =>
            element.Name == Svg + "linearGradient" || element.Name == Svg + "radialGradient"))
        {
            XElement[] stops = gradient.Elements(Svg + "stop").ToArray();
            Assert.True(stops.Length >= 2, $"Gradient in {fileName} needs at least two color stops.");
            double previous = -1;
            for (int index = 0; index < stops.Length; index++)
            {
                string? specifiedOffset = (string?)stops[index].Attribute("offset");
                if (index > 0)
                    Assert.NotNull(specifiedOffset);
                string offset = specifiedOffset ?? "0";
                double value = offset.EndsWith('%')
                    ? double.Parse(offset[..^1], CultureInfo.InvariantCulture) / 100
                    : double.Parse(offset, CultureInfo.InvariantCulture);
                Assert.InRange(value, 0, 1);
                Assert.True(value >= previous, $"Gradient offsets are out of order in {fileName}.");
                previous = value;
            }
        }
    }

    [Fact]
    public void FiveStagesHaveTheirOwnGeometryAndShadedForms()
    {
        var geometries = new HashSet<string>(StringComparer.Ordinal);
        for (int stage = 1; stage <= 5; stage++)
        {
            XDocument document = XDocument.Load(AssetPath(TreeIllustrationLayout.StageAssetName(stage)));
            XElement[] paths = document.Descendants(Svg + "path").ToArray();
            Assert.NotEmpty(paths);
            Assert.True(geometries.Add(string.Join("|", paths.Select(path => (string?)path.Attribute("d")))),
                $"Stage {stage} reuses another stage's silhouette.");
            Assert.Contains(document.Descendants(), element =>
                element.Name == Svg + "linearGradient" || element.Name == Svg + "radialGradient");
        }
        Assert.Equal(5, geometries.Count);
    }

    [Fact]
    public void EveryStageHasSeparateFoliageWithoutMovingTheTrunkOrGround()
    {
        for (int stage = 1; stage <= 5; stage++)
        {
            XDocument trunk = XDocument.Load(AssetPath(TreeIllustrationLayout.StageAssetName(stage)));
            XDocument canopy = XDocument.Load(AssetPath(TreeIllustrationLayout.CanopyAssetName(stage)));
            string trunkFill = stage == 1 ? "url(#stem)" : "url(#wood)";
            Assert.Contains(trunk.Descendants(), element => (string?)element.Attribute("fill") == trunkFill);
            Assert.DoesNotContain(canopy.Descendants(), element => (string?)element.Attribute("fill") == trunkFill);
            Assert.Contains(canopy.Descendants(Svg + "path"), element => element.Attribute("d") is not null);
            Assert.InRange(TreeIllustrationLayout.CanopyPivotY(stage), 128, 145);
        }
    }

    [Theory]
    [InlineData(0, 0)]
    [InlineData(1, 0)]
    [InlineData(2, 0)]
    [InlineData(3, 2)]
    [InlineData(4, 3)]
    [InlineData(5, 3)]
    [InlineData(6, 0)]
    public void FallingLeavesStayBoundedAndFinishWithinOneSharedCycle(int stage, int count)
    {
        IReadOnlyList<FallingLeaf> leaves = TreeIllustrationLayout.FallingLeaves(stage);
        Assert.Equal(count, leaves.Count);
        foreach (FallingLeaf leaf in leaves)
        {
            Assert.InRange(leaf.X - Math.Abs(leaf.Drift), 12, TreeIllustrationLayout.Width - 30);
            Assert.InRange(leaf.X + Math.Abs(leaf.Drift), 12, TreeIllustrationLayout.Width - 30);
            Assert.InRange(leaf.Y, 12, 150);
            Assert.InRange(leaf.DelaySeconds, 0, TreeIllustrationLayout.LeafCycleSeconds);
            Assert.InRange(leaf.DurationSeconds, 2, TreeIllustrationLayout.LeafCycleSeconds);
            Assert.True(leaf.DelaySeconds + leaf.DurationSeconds <= TreeIllustrationLayout.LeafCycleSeconds);
        }
    }

    [Fact]
    public void TreeAssetsAreCopiedIntoTheApplicationOutput()
    {
        XDocument project = XDocument.Load(TestPaths.FromRepository("src/DeskBox/DeskBox.csproj"));
        XElement content = Assert.Single(project.Descendants("Content").Where(element =>
            ((string?)element.Attribute("Include"))?.Replace('\\', '/') == "Assets/Tree/*.svg"));
        Assert.Equal("PreserveNewest", (string?)content.Element("CopyToOutputDirectory"));
        Assert.Equal("PreserveNewest", (string?)content.Element("CopyToPublishDirectory"));
    }

    private static string AssetPath(string fileName) =>
        TestPaths.FromRepository($"src/DeskBox/Assets/Tree/{fileName}");

    [Fact]
    public void TreeMotionUsesSharedAccessibilityPolicyAndStopsWithTheView()
    {
        string source = File.ReadAllText(TestPaths.FromRepository(
            "src/DeskBox/Controls/WidgetContents/TreeWidgetContent.cs"));
        Assert.Contains("!_disposed && _visible && IsLoaded && WindowsCompatibilityService.ShouldAnimate", source);
        Assert.Contains("Unloaded += (_, _) => { _counter.Changed -= CounterChanged; _timer.Stop(); StopMotion(); };", source);
        Assert.Contains("else { _timer.Stop(); StopMotion(); }", source);
        Assert.Contains("_sway.Stop();", source);
        Assert.Contains("_celebration.Stop();", source);
        Assert.Contains("_fallingLeaves.Stop();", source);
        Assert.Contains("_fallingLeafLayer.Visibility = Visibility.Collapsed;", source);
        Assert.Contains("Storyboard.SetTarget(sway, _canopyTransform);", source);
        Assert.DoesNotContain("new UISettings()", source);
    }

    [Fact]
    public void TreeProgressTrack_DoesNotRenderPercentageText()
    {
        string source = File.ReadAllText(TestPaths.FromRepository(
            "src/DeskBox/Controls/WidgetContents/TreeWidgetContent.cs"));
        string progressSource = File.ReadAllText(TestPaths.FromRepository(
            "src/DeskBox/Controls/WidgetContents/TreeBubbleProgressBar.cs"));
        Assert.Contains("CornerRadius = new CornerRadius(0)", progressSource, StringComparison.Ordinal);
        Assert.Contains("Height = 16", progressSource, StringComparison.Ordinal);
        Assert.DoesNotContain("_baseLabel", progressSource, StringComparison.Ordinal);
        Assert.DoesNotContain("_filledLabel", progressSource, StringComparison.Ordinal);
        Assert.DoesNotContain("$\"{next:0.00}%\"", progressSource, StringComparison.Ordinal);
        Assert.Contains("stack.Children.Add(_progress)", source, StringComparison.Ordinal);
        Assert.Contains("_progress.Margin = new Thickness(16, 8, 16, 4)", source, StringComparison.Ordinal);
        Assert.DoesNotContain("Text = \"🌱\"", source, StringComparison.Ordinal);
        Assert.DoesNotContain("Grid.SetColumn(_percent, 2)", source, StringComparison.Ordinal);
    }

    [Fact]
    public void DesktopTreeProgress_DescribesTheCurrentStageInsteadOfWholeMaturity()
    {
        string source = File.ReadAllText(TestPaths.FromRepository(
            "src/DeskBox/Controls/WidgetContents/TreeWidgetContent.cs"));
        Assert.Contains("double percentage = p.NextStagePercentage", source, StringComparison.Ordinal);
        Assert.DoesNotContain("double percentage = p.MaturityPercentage", source, StringComparison.Ordinal);
    }
}
