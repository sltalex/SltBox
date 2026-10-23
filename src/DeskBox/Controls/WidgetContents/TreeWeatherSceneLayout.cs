using DeskBox.Helpers;
using DeskBox.Services;

namespace DeskBox.Controls.WidgetContents;

internal static class TreeWeatherSceneLayout
{
    public const double CloudLoopDistance = TreeIllustrationLayout.Width;
    public const int CloudCopies = 3;
    public static IReadOnlyList<CloudLane> CloudLanes { get; } = Array.AsReadOnly<CloudLane>(
    [
        new(-18, 3, 90, 43, 64, CloudShape.Puffy, .96),
        new(87, 29, 71, 29, 44, CloudShape.Wispy, .82),
        new(171, 12, 78, 36, 82, CloudShape.Clustered, .91)
    ]);

    public static bool SuppressesFallingLeaves(WeatherVisualSnapshot? weather) =>
        weather?.Condition is WeatherCodeMapper.WeatherCondition.Drizzle or
            WeatherCodeMapper.WeatherCondition.Rain or
            WeatherCodeMapper.WeatherCondition.Snow or
            WeatherCodeMapper.WeatherCondition.Thunderstorm;
}

internal enum CloudShape { Puffy, Wispy, Clustered }

internal readonly record struct CloudLane(
    double OffsetX,
    double Top,
    double Width,
    double Height,
    double DurationSeconds,
    CloudShape Shape,
    double Opacity);
