using DeskBox.Helpers;
using DeskBox.Models;

namespace DeskBox.Services;

/// <summary>Shares fresh weather-widget observations with decorative surfaces only.</summary>
internal sealed class WeatherVisualStateService
{
    internal static WeatherVisualStateService Instance { get; } = new();
    internal static readonly TimeSpan MaximumAge = TimeSpan.FromHours(4);

    private readonly object _gate = new();
    private WeatherVisualSnapshot? _snapshot;

    internal WeatherVisualSnapshot? Snapshot
    {
        get
        {
            lock (_gate)
            {
                return _snapshot is { } snapshot &&
                    DateTimeOffset.UtcNow - snapshot.FetchedAtUtc <= MaximumAge
                    ? snapshot : null;
            }
        }
    }

    internal void Publish(WeatherData data, DateTimeOffset fetchedAtUtc)
    {
        if (data.IsStale || data.Current is not { } current) return;
        DateTimeOffset now = DateTimeOffset.UtcNow;
        if (fetchedAtUtc == default || fetchedAtUtc > now.AddMinutes(5) ||
            now - fetchedAtUtc > MaximumAge) return;

        WeatherCodeMapper.WeatherCondition condition = WeatherCodeMapper.GetCondition(current.WeatherCode);
        if (condition == WeatherCodeMapper.WeatherCondition.Unknown) return;
        lock (_gate)
            _snapshot = new WeatherVisualSnapshot(current.WeatherCode, condition,
                current.IsDay == 1, fetchedAtUtc);
    }

    internal void Clear()
    {
        lock (_gate) _snapshot = null;
    }
}

internal readonly record struct WeatherVisualSnapshot(
    int WeatherCode,
    WeatherCodeMapper.WeatherCondition Condition,
    bool IsDay,
    DateTimeOffset FetchedAtUtc);
