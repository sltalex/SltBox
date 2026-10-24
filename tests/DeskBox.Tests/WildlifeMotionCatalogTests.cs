using DeskBox.Controls.WidgetContents;
using DeskBox.Services;

namespace DeskBox.Tests;

public sealed class WildlifeMotionCatalogTests
{
    [Fact]
    public void EveryCatalogAnimal_HasSafeMotionOrSkyFlight()
    {
        var boundaryViolations = new List<string>();
        Assert.Equal(180, WildlifeMotionCatalog.Profiles.Count);
        Assert.Equal(WildlifeAssetCatalog.SpeciesIds.Order(StringComparer.Ordinal),
            WildlifeMotionCatalog.Profiles.Keys.Order(StringComparer.Ordinal));
        Assert.Equal(180, WildlifeMotionCatalog.Profiles.Values
            .Select(profile => profile.Behavior).Distinct(StringComparer.Ordinal).Count());

        foreach ((string id, WildlifeMotionProfile motion) in WildlifeMotionCatalog.Profiles)
        {
            WildlifeCatalogItem species = Assert.Single(WildlifeAssetCatalog.LocalCatalog, item => item.Id == id);
            double visualSize = WildlifeMotionCatalog.VisualSize(id, species.Category, 5);
            bool skyFlight = WildlifeMotionCatalog.FliesAcrossSky(id);
            Assert.InRange(motion.Seconds, 2, 11);
            Assert.InRange(motion.Frames.Count, 4, 7);
            Assert.Equal(0, motion.Frames[0].At);
            Assert.Equal(1, motion.Frames[^1].At);
            if (skyFlight)
            {
                double start = motion.X + motion.Frames[0].X;
                double finish = motion.X + motion.Frames[^1].X;
                Assert.True((start + visualSize <= 0 && finish >= TreeIllustrationLayout.Width) ||
                    (start >= TreeIllustrationLayout.Width && finish + visualSize <= 0), id);
                double direction = Math.Sign(finish - start);
                Assert.True(motion.Frames.Zip(motion.Frames.Skip(1),
                    (left, right) => (right.X - left.X) * direction > 0).All(movesForward => movesForward), id);
                Assert.InRange(motion.X, 0, TreeIllustrationLayout.Width - visualSize);
                Assert.InRange(motion.Y, 0, 55 - visualSize);
                Assert.All(motion.Frames, frame => Assert.True(
                    motion.Y + frame.Y + visualSize <= 6 + TreeIllustrationLayout.TreeVerticalOffset, id));
            }
            else Assert.Equal(motion.Frames[0] with { At = 1 }, motion.Frames[^1]);
            Assert.True(motion.Frames.Zip(motion.Frames.Skip(1), (a, b) => a.At < b.At).All(x => x), id);
            Assert.True(motion.Frames.Select(frame => (frame.X, frame.Y, frame.Rotation,
                frame.ScaleX, frame.ScaleY, frame.Opacity)).Distinct().Count() >= 3, id);
            foreach (WildlifeMotionFrame frame in motion.Frames)
            {
                if (skyFlight)
                    Assert.InRange(motion.Y + frame.Y, 0, 55 - visualSize);
                else
                {
                    Assert.InRange(motion.X + frame.X, 0, TreeIllustrationLayout.Width - visualSize);
                    double y = motion.Y + frame.Y + TreeIllustrationLayout.TreeVerticalOffset;
                    if (y < 0 || y > TreeIllustrationLayout.Height - visualSize)
                        boundaryViolations.Add($"{id} at y={y}, size={visualSize}, frame={frame.At}");
                }
                Assert.InRange(frame.Opacity, .35, 1);
            }
        }
        Assert.True(boundaryViolations.Count == 0, string.Join("; ", boundaryViolations));
    }

    [Fact]
    public void FlightCapableBirdsCrossTheSkyWhileGroundAndWadingBirdsKeepTheirBehavior()
    {
        foreach (string id in new[] { "swallow", "sparrow", "owl", "goose", "swan", "seagull" })
            Assert.True(WildlifeMotionCatalog.FliesAcrossSky(id), id);
        foreach (string id in new[] { "quail", "pheasant", "heron", "woodpecker", "egret", "crane" })
            Assert.False(WildlifeMotionCatalog.FliesAcrossSky(id), id);
        Assert.Equal(50, WildlifeAssetCatalog.LocalCatalog.Count(species =>
            WildlifeMotionCatalog.FliesAcrossSky(species.Id)));
    }

    [Fact]
    public void SpeciesSignatures_MatchRecognizableBehaviors()
    {
        IReadOnlyList<WildlifeMotionFrame> butterfly = WildlifeMotionCatalog.Profiles["butterfly"].Frames;
        Assert.Contains(butterfly, frame => frame.Y < -5);
        Assert.Contains(butterfly, frame => frame.Y > 0 && frame.ScaleY > 1);
        Assert.Contains(WildlifeMotionCatalog.Profiles["frog"].Frames, frame => frame.Y <= -15);
        Assert.Contains(WildlifeMotionCatalog.Profiles["woodpecker"].Frames, frame => frame.Rotation >= 18);
        Assert.Contains(WildlifeMotionCatalog.Profiles["firefly"].Frames, frame => frame.Opacity < .5);
        Assert.True(WildlifeMotionCatalog.Profiles["snail"].Seconds > 10);
    }

    [Fact]
    public void TreeVisitors_StaySmallRelativeToTheTree()
    {
        for (int stage = 1; stage <= 5; stage++)
        {
            double treeWidth = TreeIllustrationLayout.ArtworkWidth(stage) * TreeIllustrationLayout.TreeArtworkScale;
            foreach (WildlifeCatalogItem species in WildlifeAssetCatalog.LocalCatalog)
            {
                double visualSize = WildlifeMotionCatalog.VisualSize(species.Id, species.Category, stage);
                Assert.InRange(visualSize / treeWidth, .1, .21);
            }
        }
    }

    [Fact]
    public void MatureTree_DoesNotShowHarvestCountersBelowArtwork()
    {
        string source = File.ReadAllText(TestPaths.FromRepository(
            "src/DeskBox/Controls/WidgetContents/TreeWidgetContent.cs"));
        Assert.DoesNotContain("_fruitStats", source, StringComparison.Ordinal);
        Assert.DoesNotContain("Tree.FruitStats", source, StringComparison.Ordinal);
    }
}
