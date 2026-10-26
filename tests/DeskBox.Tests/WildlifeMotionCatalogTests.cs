using DeskBox.Controls.WidgetContents;
using DeskBox.Services;

namespace DeskBox.Tests;

public sealed class WildlifeMotionCatalogTests
{
    [Fact]
    public void EveryCatalogAnimal_HasSafeMotionOrSkyFlight()
    {
        var boundaryViolations = new List<string>();
        Assert.Equal(184, WildlifeMotionCatalog.Profiles.Count);
        Assert.Equal(WildlifeAssetCatalog.SpeciesIds.Order(StringComparer.Ordinal),
            WildlifeMotionCatalog.Profiles.Keys.Order(StringComparer.Ordinal));
        Assert.Equal(184, WildlifeMotionCatalog.Profiles.Values
            .Select(profile => profile.Behavior).Distinct(StringComparer.Ordinal).Count());

        foreach ((string id, WildlifeMotionProfile motion) in WildlifeMotionCatalog.Profiles)
        {
            WildlifeCatalogItem species = Assert.Single(WildlifeAssetCatalog.LocalCatalog, item => item.Id == id);
            double visualSize = WildlifeMotionCatalog.VisualSize(id, species.Category, 5);
            double hitSize = Math.Max(visualSize, 56);
            bool skyFlight = WildlifeMotionCatalog.FliesAcrossSky(id);
            (double left, double top) = WildlifeMotionCatalog.HitTargetPosition(motion, visualSize, skyFlight);
            Assert.InRange(motion.Seconds, 2, 11);
            Assert.InRange(motion.Frames.Count, 4, 7);
            Assert.Equal(0, motion.Frames[0].At);
            Assert.Equal(1, motion.Frames[^1].At);
            if (skyFlight)
            {
                double start = left + motion.Frames[0].X;
                double finish = left + motion.Frames[^1].X;
                Assert.True((start + hitSize <= 0 && finish >= TreeIllustrationLayout.Width) ||
                    (start >= TreeIllustrationLayout.Width && finish + hitSize <= 0), id);
                double direction = Math.Sign(finish - start);
                Assert.True(motion.Frames.Zip(motion.Frames.Skip(1),
                    (left, right) => (right.X - left.X) * direction > 0).All(movesForward => movesForward), id);
                Assert.InRange(left, 0, TreeIllustrationLayout.Width - hitSize);
            }
            else Assert.Equal(motion.Frames[0] with { At = 1 }, motion.Frames[^1]);
            Assert.True(motion.Frames.Zip(motion.Frames.Skip(1), (a, b) => a.At < b.At).All(x => x), id);
            Assert.True(motion.Frames.Select(frame => (frame.X, frame.Y, frame.Rotation,
                frame.ScaleX, frame.ScaleY, frame.Opacity)).Distinct().Count() >= 3, id);
            foreach (WildlifeMotionFrame frame in motion.Frames)
            {
                double y = top + frame.Y;
                if (y < 0 || y > TreeIllustrationLayout.Height - hitSize)
                    boundaryViolations.Add($"{id} at y={y}, hitSize={hitSize}, frame={frame.At}");
                if (!skyFlight)
                {
                    double x = left + frame.X;
                    if (x < 0 || x > TreeIllustrationLayout.Width - hitSize)
                        boundaryViolations.Add($"{id} at x={x}, hitSize={hitSize}, frame={frame.At}");
                }
                Assert.InRange(frame.Opacity, .35, 1);
            }
        }
        Assert.True(boundaryViolations.Count == 0, string.Join("; ", boundaryViolations));
    }

    [Fact]
    public void FlightCapableBirdsCrossTheSkyWhileGroundAndWadingBirdsKeepTheirBehavior()
    {
        foreach (string id in new[] { "swallow", "eagle", "goose", "swan", "seagull", "bat", "fruit-bat" })
            Assert.True(WildlifeMotionCatalog.FliesAcrossSky(id), id);
        foreach (string id in new[] { "quail", "pheasant", "heron", "woodpecker", "egret", "crane", "blue-morpho" })
            Assert.False(WildlifeMotionCatalog.FliesAcrossSky(id), id);
        Assert.All(WildlifeAssetCatalog.LocalCatalog.Where(species => species.Placement == "sky"),
            species => Assert.True(WildlifeMotionCatalog.FliesAcrossSky(species.Id), species.Id));
        Assert.All(WildlifeAssetCatalog.LocalCatalog.Where(species => species.Placement != "sky"),
            species => Assert.False(WildlifeMotionCatalog.FliesAcrossSky(species.Id), species.Id));
    }

    [Fact]
    public void SpeciesSignatures_MatchRecognizableBehaviors()
    {
        IReadOnlyList<WildlifeMotionFrame> butterfly = WildlifeMotionCatalog.Profiles["blue-morpho"].Frames;
        Assert.Contains(butterfly, frame => frame.Y < -5);
        Assert.Contains(butterfly, frame => frame.Y > 0 && frame.ScaleY > 1);
        Assert.Contains(WildlifeMotionCatalog.Profiles["woodpecker"].Frames, frame => frame.Rotation >= 18);
        Assert.Contains(WildlifeMotionCatalog.Profiles["rabbit"].Frames, frame => frame.Y <= -15);
    }

    [Fact]
    public void TreeVisitors_AreTwiceThePreviousDisplaySize()
    {
        Assert.Equal(31 * 1.64, WildlifeMotionCatalog.VisualSize("blue-morpho", "蝴蝶", 5), 5);
        for (int stage = 1; stage <= 5; stage++)
        {
            double treeWidth = TreeIllustrationLayout.ArtworkWidth(stage) * TreeIllustrationLayout.TreeArtworkScale;
            foreach (WildlifeCatalogItem species in WildlifeAssetCatalog.LocalCatalog)
            {
                double visualSize = WildlifeMotionCatalog.VisualSize(species.Id, species.Category, stage);
                Assert.InRange(visualSize / treeWidth, .2, .42);
                double hitSize = Math.Max(visualSize, 56);
                WildlifeMotionProfile motion = WildlifeMotionCatalog.ForStage(species.Id, stage);
                bool skyFlight = WildlifeMotionCatalog.FliesAcrossSky(species.Id);
                (double left, double top) = WildlifeMotionCatalog.HitTargetPosition(motion, visualSize, skyFlight);
                foreach (WildlifeMotionFrame frame in motion.Frames)
                {
                    double radians = frame.Rotation * Math.PI / 180;
                    double cosine = Math.Abs(Math.Cos(radians));
                    double sine = Math.Abs(Math.Sin(radians));
                    double overhangX = Math.Max(0, hitSize / 2 *
                        (Math.Abs(frame.ScaleX) * cosine + Math.Abs(frame.ScaleY) * sine - 1));
                    double overhangY = Math.Max(0, hitSize / 2 *
                        (Math.Abs(frame.ScaleX) * sine + Math.Abs(frame.ScaleY) * cosine - 1));
                    Assert.InRange(top + frame.Y - overhangY, -1e-8, TreeIllustrationLayout.Height);
                    Assert.InRange(top + frame.Y + hitSize + overhangY, 0, TreeIllustrationLayout.Height + 1e-8);
                    if (skyFlight) continue;
                    Assert.InRange(left + frame.X - overhangX, -1e-8, TreeIllustrationLayout.Width);
                    Assert.InRange(left + frame.X + hitSize + overhangX, 0, TreeIllustrationLayout.Width + 1e-8);
                }
            }
        }
    }

    [Fact]
    public void PlacementControlsEveryAnchorIncludingOriginalSpeciesAtEveryTreeStage()
    {
        for (int stage = 1; stage <= 5; stage++)
        foreach (WildlifeCatalogItem species in WildlifeAssetCatalog.LocalCatalog)
        {
            WildlifeMotionProfile motion = WildlifeMotionCatalog.ForStage(species.Id, stage);
            switch (species.Placement)
            {
                case "sky": Assert.InRange(motion.Y, 0, 20); break;
                case "tree":
                    Assert.InRange(motion.Y, 40, 125);
                    Assert.InRange(motion.X, 130, 175);
                    break;
                case "grass": Assert.InRange(motion.Y, 160, 175); break;
                default: Assert.Fail($"Invalid placement for {species.Id}"); break;
            }
        }
        Assert.Equal(165, WildlifeMotionCatalog.Profiles["heron"].Y);
        Assert.Equal(112, WildlifeMotionCatalog.Profiles["squirrel"].Y);
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
