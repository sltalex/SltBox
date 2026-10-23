using DeskBox.Controls.WidgetContents;
using DeskBox.Services;

namespace DeskBox.Tests;

public sealed class WildlifeMotionCatalogTests
{
    [Fact]
    public void EveryCatalogAnimal_HasItsOwnSafeLoopingMotion()
    {
        Assert.Equal(180, WildlifeMotionCatalog.Profiles.Count);
        Assert.Equal(WildlifeAssetCatalog.SpeciesIds.Order(StringComparer.Ordinal),
            WildlifeMotionCatalog.Profiles.Keys.Order(StringComparer.Ordinal));
        Assert.Equal(180, WildlifeMotionCatalog.Profiles.Values
            .Select(profile => profile.Behavior).Distinct(StringComparer.Ordinal).Count());

        foreach ((string id, WildlifeMotionProfile motion) in WildlifeMotionCatalog.Profiles)
        {
            Assert.InRange(motion.Seconds, 2, 11);
            Assert.InRange(motion.Frames.Count, 4, 7);
            Assert.Equal(0, motion.Frames[0].At);
            Assert.Equal(1, motion.Frames[^1].At);
            Assert.Equal(motion.Frames[0] with { At = 1 }, motion.Frames[^1]);
            Assert.True(motion.Frames.Zip(motion.Frames.Skip(1), (a, b) => a.At < b.At).All(x => x), id);
            Assert.True(motion.Frames.Select(frame => (frame.X, frame.Y, frame.Rotation,
                frame.ScaleX, frame.ScaleY, frame.Opacity)).Distinct().Count() >= 3, id);
            foreach (WildlifeMotionFrame frame in motion.Frames)
            {
                Assert.InRange(motion.X + frame.X, 0, TreeIllustrationLayout.Width - 48);
                Assert.InRange(motion.Y + frame.Y, 0, TreeIllustrationLayout.Height - 48);
                Assert.InRange(frame.Opacity, .35, 1);
            }
        }
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
    public void MatureTree_DoesNotShowHarvestCountersBelowArtwork()
    {
        string source = File.ReadAllText(TestPaths.FromRepository(
            "src/DeskBox/Controls/WidgetContents/TreeWidgetContent.cs"));
        Assert.DoesNotContain("_fruitStats", source, StringComparison.Ordinal);
        Assert.DoesNotContain("Tree.FruitStats", source, StringComparison.Ordinal);
    }
}
