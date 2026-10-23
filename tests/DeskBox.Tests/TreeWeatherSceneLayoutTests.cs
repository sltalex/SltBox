using DeskBox.Controls.WidgetContents;
using DeskBox.Helpers;
using DeskBox.Services;

namespace DeskBox.Tests;

public sealed class TreeWeatherSceneLayoutTests
{
    [Theory]
    [InlineData(WeatherCodeMapper.WeatherCondition.Clear, false)]
    [InlineData(WeatherCodeMapper.WeatherCondition.Cloudy, false)]
    [InlineData(WeatherCodeMapper.WeatherCondition.Drizzle, true)]
    [InlineData(WeatherCodeMapper.WeatherCondition.Rain, true)]
    [InlineData(WeatherCodeMapper.WeatherCondition.Snow, true)]
    [InlineData(WeatherCodeMapper.WeatherCondition.Thunderstorm, true)]
    public void PrecipitationStopsFallingLeaves(WeatherCodeMapper.WeatherCondition condition,
        bool suppressed)
    {
        var weather = new WeatherVisualSnapshot(0, condition, true, DateTimeOffset.UtcNow);
        Assert.Equal(suppressed, TreeWeatherSceneLayout.SuppressesFallingLeaves(weather));
    }

    [Fact]
    public void CloudLanesHaveStaggeredShapesPositionsAndSpeeds()
    {
        var lanes = TreeWeatherSceneLayout.CloudLanes;
        Assert.True(lanes.Select(lane => lane.Shape).Distinct().Count() >= 3);
        Assert.True(lanes.Select(lane => lane.DurationSeconds).Distinct().Count() >= 3);
        Assert.True(lanes.Select(lane => lane.Top).Distinct().Count() >= 3);
        Assert.True(lanes.Select(lane => lane.Width).Distinct().Count() >= 3);
        Assert.NotEqual(lanes[1].OffsetX - lanes[0].OffsetX,
            lanes[2].OffsetX - lanes[1].OffsetX);
        Assert.All(lanes, lane => Assert.True(lane.Top + lane.Height <= 82));
    }

    [Fact]
    public void EveryCloudLaneContinuouslyCrossesTheSkyAndLoopsWithoutAVisibleJump()
    {
        double viewport = TreeIllustrationLayout.Width;
        foreach (CloudLane lane in TreeWeatherSceneLayout.CloudLanes)
        {
            double[] clouds = Enumerable.Range(0, TreeWeatherSceneLayout.CloudCopies)
                .Select(copy => lane.OffsetX + copy * TreeWeatherSceneLayout.CloudLoopDistance)
                .ToArray();

            for (int shift = 0; shift <= (int)TreeWeatherSceneLayout.CloudLoopDistance; shift++)
            {
                int visible = clouds.Count(x => x - shift < viewport &&
                    x - shift + lane.Width > 0);
                Assert.True(visible >= 1,
                    $"Cloud lane {lane.Shape} disappears after a {shift}-unit shift.");
            }

            double[] start = VisiblePositions(clouds, 0, viewport, lane.Width);
            double[] end = VisiblePositions(clouds, TreeWeatherSceneLayout.CloudLoopDistance,
                viewport, lane.Width);
            Assert.Equal(start, end);
        }
    }

    private static double[] VisiblePositions(double[] clouds, double shift, double viewport,
        double cloudWidth) =>
        clouds.Select(x => x - shift)
            .Where(x => x < viewport && x + cloudWidth > 0)
            .OrderBy(x => x)
            .ToArray();
}
