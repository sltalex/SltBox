using DeskBox.Helpers;
using DeskBox.Models;
using DeskBox.Services;

namespace DeskBox.Tests;

public sealed class WeatherVisualStateServiceTests
{
    [Theory]
    [InlineData(0, WeatherCodeMapper.WeatherCondition.Clear)]
    [InlineData(3, WeatherCodeMapper.WeatherCondition.Cloudy)]
    [InlineData(45, WeatherCodeMapper.WeatherCondition.Fog)]
    [InlineData(55, WeatherCodeMapper.WeatherCondition.Drizzle)]
    [InlineData(61, WeatherCodeMapper.WeatherCondition.Rain)]
    [InlineData(71, WeatherCodeMapper.WeatherCondition.Snow)]
    [InlineData(95, WeatherCodeMapper.WeatherCondition.Thunderstorm)]
    public void FreshWeatherWidgetObservationIsAvailableToTree(int code,
        WeatherCodeMapper.WeatherCondition condition)
    {
        var bridge = new WeatherVisualStateService();
        bridge.Publish(Data(code, isDay: false), DateTimeOffset.UtcNow);

        WeatherVisualSnapshot snapshot = Assert.IsType<WeatherVisualSnapshot>(bridge.Snapshot);
        Assert.Equal(code, snapshot.WeatherCode);
        Assert.Equal(condition, snapshot.Condition);
        Assert.False(snapshot.IsDay);
    }

    [Fact]
    public void StaleUnknownAndExpiredWeatherDoNotReplaceFreshObservation()
    {
        var bridge = new WeatherVisualStateService();
        bridge.Publish(Data(61), DateTimeOffset.UtcNow);
        WeatherData stale = Data(71);
        stale.IsStale = true;
        bridge.Publish(stale, DateTimeOffset.UtcNow);
        bridge.Publish(Data(-1), DateTimeOffset.UtcNow);
        bridge.Publish(Data(95), DateTimeOffset.UtcNow - WeatherVisualStateService.MaximumAge - TimeSpan.FromMinutes(1));

        Assert.Equal(61, bridge.Snapshot?.WeatherCode);
        bridge.Clear();
        Assert.Null(bridge.Snapshot);
    }

    [Fact]
    public void ExpiredCacheIsNotShownAsCurrentWeather()
    {
        var bridge = new WeatherVisualStateService();
        bridge.Publish(Data(71), DateTimeOffset.UtcNow - WeatherVisualStateService.MaximumAge - TimeSpan.FromMinutes(1));

        Assert.Null(bridge.Snapshot);
    }

    private static WeatherData Data(int code, bool isDay = true) => new()
    {
        Current = new WeatherCurrent { WeatherCode = code, IsDay = isDay ? 1 : 0 }
    };
}
