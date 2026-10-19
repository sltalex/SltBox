using DeskBox.Contracts;
using DeskBox.Models;

namespace DeskBox.Services;

/// <summary>
/// Sole settings-page writer for the feature section: the music presentation
/// options, the weather options (city, units, default view, skin, data
/// source, display toggles, refresh interval — normalized through the shared
/// <see cref="WeatherSettingsPolicy"/>), the feature-card enable states and
/// the section's misc presentation picks (attachment storage, managed-drop
/// action, folder-open behavior). Every edit keeps the original semantics:
/// normalize through the existing shared normalizers, compare against the raw
/// stored value, skip unchanged writes, store on the slice, and schedule one
/// debounced save; the boolean returns report whether the persisted value
/// changed. The reset ports write the fresh-install defaults without
/// scheduling a save because the feature-card reset flow performs one
/// explicit save after applying every feature's defaults. Feature enable and
/// disable reach the host only through the shell's existing
/// <see cref="FeatureWidgetSettings"/> write plus the WidgetManager sync
/// chain — no widget, runtime or broadcast behavior lives here.
/// </summary>
public sealed class FeatureWidgetsSettingsCoordinator : IFeatureWidgetsSettings
{
    private readonly SettingsService _settings;
    private bool _stopped;

    internal bool IsStopped => _stopped;

    public SystemMonitorPresentationSettings ReadSystemMonitorPresentation()
    {
        WidgetLayoutSettingsSlice layout = _settings.Settings.WidgetLayout;
        return new SystemMonitorPresentationSettings(
            NormalizeChartStyle(string.IsNullOrWhiteSpace(layout.SystemMonitorCpuChartStyle) ? layout.SystemMonitorChartStyle : layout.SystemMonitorCpuChartStyle),
            NormalizeChartStyle(string.IsNullOrWhiteSpace(layout.SystemMonitorMemoryChartStyle) ? layout.SystemMonitorChartStyle : layout.SystemMonitorMemoryChartStyle),
            NormalizeChartStyle(string.IsNullOrWhiteSpace(layout.SystemMonitorNetworkChartStyle) ? layout.SystemMonitorChartStyle : layout.SystemMonitorNetworkChartStyle),
            NormalizeChartColor(layout.SystemMonitorCpuChartColor, "#2C8EE4"),
            NormalizeChartColor(layout.SystemMonitorMemoryChartColor, "#46AE85"),
            NormalizeChartColor(layout.SystemMonitorNetworkChartColor, "#E6A23C"),
            NormalizeRefreshInterval(layout.SystemMonitorRefreshIntervalSeconds));
    }

    public bool ReadCalendarShowTaskMarkers() => _settings.Settings.CalendarShowTaskMarkers;

    public bool SetCalendarShowTaskMarkers(bool enabled)
    {
        ThrowIfStopped();
        if (_settings.Settings.CalendarShowTaskMarkers == enabled) return false;
        _settings.Settings.CalendarShowTaskMarkers = enabled;
        _settings.SaveDebounced();
        return true;
    }

    public bool ReadCalendarShowHolidays() => _settings.Settings.CalendarShowHolidays;

    public bool SetCalendarShowHolidays(bool enabled)
    {
        ThrowIfStopped();
        if (_settings.Settings.CalendarShowHolidays == enabled) return false;
        _settings.Settings.CalendarShowHolidays = enabled;
        _settings.SaveDebounced();
        return true;
    }

    public CalendarMarkerColors ReadCalendarMarkerColors()
    {
        WidgetLayoutSettingsSlice layout = _settings.Settings.WidgetLayout;
        return new CalendarMarkerColors(
            NormalizeChartColor(layout.CalendarTaskMarkerColor, "#3791DC"),
            NormalizeChartColor(layout.CalendarHolidayMarkerColor, "#E04C4C"));
    }

    public bool SetCalendarMarkerColor(string marker, string? color)
    {
        ThrowIfStopped();
        WidgetLayoutSettingsSlice layout = _settings.Settings.WidgetLayout;
        string fallback = string.Equals(marker, "Task", StringComparison.OrdinalIgnoreCase)
            ? "#3791DC"
            : "#E04C4C";
        string normalized = NormalizeChartColor(color, fallback);
        string before = string.Equals(marker, "Task", StringComparison.OrdinalIgnoreCase)
            ? layout.CalendarTaskMarkerColor
            : layout.CalendarHolidayMarkerColor;
        if (before == normalized) return false;
        if (string.Equals(marker, "Task", StringComparison.OrdinalIgnoreCase))
            layout.CalendarTaskMarkerColor = normalized;
        else
            layout.CalendarHolidayMarkerColor = normalized;
        _settings.SaveDebounced();
        return true;
    }

    public bool SetSystemMonitorChartStyle(string? style)
    {
        ThrowIfStopped();
        string normalized = NormalizeChartStyle(style);
        WidgetLayoutSettingsSlice layout = _settings.Settings.WidgetLayout;
        if (layout.SystemMonitorChartStyle == normalized) return false;
        layout.SystemMonitorChartStyle = normalized;
        _settings.SaveDebounced();
        return true;
    }

    public bool SetSystemMonitorChartStyle(string metric, string? style)
    {
        ThrowIfStopped();
        string normalized = NormalizeChartStyle(style);
        WidgetLayoutSettingsSlice layout = _settings.Settings.WidgetLayout;
        string before = metric switch
        {
            "Cpu" => layout.SystemMonitorCpuChartStyle,
            "Memory" => layout.SystemMonitorMemoryChartStyle,
            _ => layout.SystemMonitorNetworkChartStyle
        };
        if (before == normalized) return false;
        if (metric == "Cpu") layout.SystemMonitorCpuChartStyle = normalized;
        else if (metric == "Memory") layout.SystemMonitorMemoryChartStyle = normalized;
        else layout.SystemMonitorNetworkChartStyle = normalized;
        _settings.SaveDebounced();
        return true;
    }

    public bool SetSystemMonitorRefreshInterval(int seconds)
    {
        ThrowIfStopped();
        int normalized = NormalizeRefreshInterval(seconds);
        WidgetLayoutSettingsSlice layout = _settings.Settings.WidgetLayout;
        if (layout.SystemMonitorRefreshIntervalSeconds == normalized) return false;
        layout.SystemMonitorRefreshIntervalSeconds = normalized;
        _settings.SaveDebounced();
        return true;
    }

    public bool SetSystemMonitorChartColor(string metric, string? color)
    {
        ThrowIfStopped();
        string fallback = metric switch { "Cpu" => "#2C8EE4", "Memory" => "#46AE85", _ => "#E6A23C" };
        string normalized = NormalizeChartColor(color, fallback);
        WidgetLayoutSettingsSlice layout = _settings.Settings.WidgetLayout;
        string before = metric switch { "Cpu" => layout.SystemMonitorCpuChartColor, "Memory" => layout.SystemMonitorMemoryChartColor, _ => layout.SystemMonitorNetworkChartColor };
        if (before == normalized) return false;
        if (metric == "Cpu") layout.SystemMonitorCpuChartColor = normalized;
        else if (metric == "Memory") layout.SystemMonitorMemoryChartColor = normalized;
        else layout.SystemMonitorNetworkChartColor = normalized;
        _settings.SaveDebounced();
        return true;
    }

    private static string NormalizeChartStyle(string? value) =>
        string.Equals(value, "Line", StringComparison.OrdinalIgnoreCase) ? "Line" : "Bars";

    internal static int NormalizeRefreshInterval(int seconds) => seconds is 1 or 2 or 5 or 10 ? seconds : 2;

    private static string NormalizeChartColor(string? value, string fallback)
    {
        if (string.IsNullOrWhiteSpace(value)) return fallback;
        string text = value.Trim();
        if (text.Length == 7 && text[0] == '#' && text[1..].All(Uri.IsHexDigit)) return text.ToUpperInvariant();
        return fallback;
    }

    public FeatureWidgetsSettingsCoordinator(SettingsService settings)
    {
        _settings = settings;
    }

    public MusicPresentationSettings ReadMusicPresentation()
    {
        MusicSettingsSlice music = _settings.Settings.Music;
        return new MusicPresentationSettings(
            music.MusicUseArtworkBackdrop,
            music.MusicEnableCoverHoverMotion,
            SettingsService.NormalizeMusicDisplayMode(music.MusicDisplayMode));
    }

    public WeatherPresentationSettings ReadWeatherPresentation()
    {
        WeatherSettingsSlice weather = _settings.Settings.Weather;
        return new WeatherPresentationSettings(
            weather.WeatherAutoLocation,
            weather.WeatherCityName,
            WeatherOptionKinds.NormalizeTemperatureUnit(weather.WeatherTemperatureUnit),
            WeatherOptionKinds.NormalizeWindSpeedUnit(weather.WeatherWindSpeedUnit),
            WeatherOptionKinds.NormalizeDefaultView(weather.WeatherDefaultView),
            WeatherOptionKinds.NormalizeSkin(weather.WeatherSkin),
            WeatherOptionKinds.NormalizeIconStyle(weather.WeatherIconStyle),
            WeatherOptionKinds.NormalizeDataSource(weather.WeatherDataSource),
            WeatherOptionKinds.NormalizeRefreshInterval(weather.WeatherRefreshIntervalMinutes),
            weather.WeatherShowForecast,
            weather.WeatherShowSunrise,
            weather.WeatherShowUvIndex,
            weather.WeatherShowPrecipitation,
            weather.WeatherShowHumidity,
            weather.WeatherShowWind,
            weather.WeatherShowPressure);
    }

    public void SetFeatureWidgetEnabled(WidgetKind kind, bool enabled)
    {
        ThrowIfStopped();
        // Write-only port: the caller's WidgetManager sync chain owns the
        // persistence for this path, exactly like the shell did.
        FeatureWidgetSettings.SetEnabled(_settings.Settings, kind, enabled);
    }

    public bool SetMusicDisplayMode(string? mode)
    {
        ThrowIfStopped();
        string normalized = SettingsService.NormalizeMusicDisplayMode(mode);
        MusicSettingsSlice music = _settings.Settings.Music;
        if (string.Equals(music.MusicDisplayMode, normalized, StringComparison.Ordinal))
        {
            return false;
        }

        music.MusicDisplayMode = normalized;
        _settings.SaveDebounced();
        return true;
    }

    public bool SetMusicUseArtworkBackdrop(bool value)
    {
        ThrowIfStopped();
        MusicSettingsSlice music = _settings.Settings.Music;
        if (music.MusicUseArtworkBackdrop == value)
        {
            return false;
        }

        music.MusicUseArtworkBackdrop = value;
        _settings.SaveDebounced();
        return true;
    }

    public bool SetMusicEnableCoverHoverMotion(bool value)
    {
        ThrowIfStopped();
        MusicSettingsSlice music = _settings.Settings.Music;
        if (music.MusicEnableCoverHoverMotion == value)
        {
            return false;
        }

        music.MusicEnableCoverHoverMotion = value;
        _settings.SaveDebounced();
        return true;
    }

    public void ResetMusicPresentationPreferences(bool scheduleSave = true)
    {
        ThrowIfStopped();
        MusicSettingsSlice music = _settings.Settings.Music;
        bool changed =
            music.MusicUseArtworkBackdrop != true ||
            music.MusicEnableCoverHoverMotion != true ||
            !string.Equals(
                music.MusicDisplayMode,
                SettingsService.MusicDisplayModeAuto,
                StringComparison.Ordinal);
        music.MusicUseArtworkBackdrop = true;
        music.MusicEnableCoverHoverMotion = true;
        music.MusicDisplayMode = SettingsService.MusicDisplayModeAuto;
        if (scheduleSave && changed)
        {
            _settings.SaveDebounced();
        }
    }

    public bool SetWeatherTemperatureUnit(string? unit)
    {
        ThrowIfStopped();
        string input = unit ?? string.Empty;
        return ApplyWeatherPolicy(
            weather => weather.WeatherTemperatureUnit,
            settings => WeatherSettingsPolicy.SetTemperatureUnit(settings, input));
    }

    public bool SetWeatherWindSpeedUnit(string? unit)
    {
        ThrowIfStopped();
        string input = unit ?? string.Empty;
        return ApplyWeatherPolicy(
            weather => weather.WeatherWindSpeedUnit,
            settings => WeatherSettingsPolicy.SetWindSpeedUnit(settings, input));
    }

    public bool SetWeatherDefaultView(string? view)
    {
        ThrowIfStopped();
        string input = view ?? string.Empty;
        return ApplyWeatherPolicy(
            weather => weather.WeatherDefaultView,
            settings => WeatherSettingsPolicy.SetDefaultView(settings, input));
    }

    public bool SetWeatherSkin(string? skin)
    {
        ThrowIfStopped();
        string input = skin ?? string.Empty;
        return ApplyWeatherPolicy(
            weather => weather.WeatherSkin,
            settings => WeatherSettingsPolicy.SetSkin(settings, input));
    }

    public bool SetWeatherIconStyle(string? style)
    {
        ThrowIfStopped();
        string input = style ?? string.Empty;
        return ApplyWeatherPolicy(
            weather => weather.WeatherIconStyle,
            settings => WeatherSettingsPolicy.SetIconStyle(settings, input));
    }

    public bool SetWeatherDataSource(string? source)
    {
        ThrowIfStopped();
        WeatherSettingsSlice weather = _settings.Settings.Weather;
        string normalized = source == SettingsService.WeatherDataSourceOpenMeteo
            ? SettingsService.WeatherDataSourceOpenMeteo
            : SettingsService.WeatherDataSourceMsn;
        if (string.Equals(weather.WeatherDataSource, normalized, StringComparison.Ordinal))
        {
            return false;
        }

        weather.WeatherDataSource = normalized;
        _settings.SaveDebounced();
        return true;
    }

    public bool SetWeatherRefreshInterval(int minutes)
    {
        ThrowIfStopped();
        return ApplyWeatherPolicy(
            weather => weather.WeatherRefreshIntervalMinutes,
            settings => WeatherSettingsPolicy.SetRefreshInterval(settings, minutes));
    }

    public bool SetWeatherAutoLocation(bool enabled)
    {
        ThrowIfStopped();
        return ApplyWeatherPolicy(
            weather => weather.WeatherAutoLocation,
            settings => WeatherSettingsPolicy.SetAutoLocation(settings, enabled));
    }

    public bool TrySetWeatherManualLocation(
        string cityName,
        double latitude,
        double longitude)
    {
        ThrowIfStopped();
        ArgumentNullException.ThrowIfNull(cityName);
        WeatherSettingsSlice weather = _settings.Settings.Weather;
        bool changed =
            weather.WeatherAutoLocation != false ||
            !string.Equals(weather.WeatherCityName, cityName, StringComparison.Ordinal) ||
            weather.WeatherLatitude != latitude ||
            weather.WeatherLongitude != longitude;
        if (!WeatherSettingsPolicy.TrySetManualLocation(
                _settings.Settings, cityName, latitude, longitude))
        {
            return false;
        }

        if (changed)
        {
            _settings.SaveDebounced();
        }

        return true;
    }

    public bool SetWeatherDisplayOption(string option, bool enabled)
    {
        ThrowIfStopped();
        WeatherDisplayOption displayOption = option switch
        {
            "Forecast" => WeatherDisplayOption.Forecast,
            "Sunrise" => WeatherDisplayOption.Sunrise,
            "UvIndex" => WeatherDisplayOption.UvIndex,
            "Precipitation" => WeatherDisplayOption.Precipitation,
            "Humidity" => WeatherDisplayOption.Humidity,
            "Wind" => WeatherDisplayOption.Wind,
            "Pressure" => WeatherDisplayOption.Pressure,
            _ => throw new ArgumentOutOfRangeException(nameof(option), option, null)
        };

        WeatherSettingsSlice weather = _settings.Settings.Weather;
        bool before = ReadWeatherDisplayOption(weather, displayOption);
        WeatherSettingsPolicy.SetDisplayOption(
            _settings.Settings, displayOption, enabled);
        if (before == enabled)
        {
            return false;
        }

        _settings.SaveDebounced();
        return true;
    }

    public void ResetWeatherPreferences(bool scheduleSave = true)
    {
        ThrowIfStopped();
        WeatherSettingsSlice weather = _settings.Settings.Weather;
        bool changed =
            weather.WeatherAutoLocation != true ||
            !string.Equals(weather.WeatherCityName, string.Empty, StringComparison.Ordinal) ||
            weather.WeatherLatitude != 0 ||
            weather.WeatherLongitude != 0 ||
            !string.Equals(
                weather.WeatherTemperatureUnit,
                SettingsService.WeatherTemperatureUnitCelsius,
                StringComparison.Ordinal) ||
            !string.Equals(
                weather.WeatherWindSpeedUnit,
                SettingsService.WeatherWindSpeedUnitKmh,
                StringComparison.Ordinal) ||
            !string.Equals(
                weather.WeatherDefaultView,
                SettingsService.WeatherDefaultViewToday,
                StringComparison.Ordinal) ||
            !string.Equals(
                weather.WeatherSkin,
                SettingsService.WeatherSkinRich,
                StringComparison.Ordinal) ||
            !string.Equals(
                weather.WeatherIconStyle,
                SettingsService.WeatherIconStyleFluent,
                StringComparison.Ordinal) ||
            weather.WeatherShowForecast != true ||
            weather.WeatherShowSunrise != true ||
            weather.WeatherShowUvIndex != true ||
            weather.WeatherShowPrecipitation != true ||
            weather.WeatherShowHumidity != true ||
            weather.WeatherShowWind != true ||
            weather.WeatherShowPressure != false ||
            weather.WeatherRefreshIntervalMinutes != 60;
        weather.WeatherAutoLocation = true;
        weather.WeatherCityName = string.Empty;
        weather.WeatherLatitude = 0;
        weather.WeatherLongitude = 0;
        weather.WeatherTemperatureUnit = SettingsService.WeatherTemperatureUnitCelsius;
        weather.WeatherWindSpeedUnit = SettingsService.WeatherWindSpeedUnitKmh;
        weather.WeatherDefaultView = SettingsService.WeatherDefaultViewToday;
        weather.WeatherSkin = SettingsService.WeatherSkinRich;
        weather.WeatherIconStyle = SettingsService.WeatherIconStyleFluent;
        weather.WeatherShowForecast = true;
        weather.WeatherShowSunrise = true;
        weather.WeatherShowUvIndex = true;
        weather.WeatherShowPrecipitation = true;
        weather.WeatherShowHumidity = true;
        weather.WeatherShowWind = true;
        weather.WeatherShowPressure = false;
        weather.WeatherRefreshIntervalMinutes = 60;
        if (scheduleSave && changed)
        {
            _settings.SaveDebounced();
        }
    }

    public bool SetAttachmentStorageMode(string? mode)
    {
        ThrowIfStopped();
        string normalized = SettingsService.NormalizeAttachmentStorageMode(mode);
        QuickCaptureSettingsSlice quickCapture = _settings.Settings.QuickCapture;
        if (string.Equals(
                quickCapture.AttachmentStorageMode,
                normalized,
                StringComparison.Ordinal))
        {
            return false;
        }

        quickCapture.AttachmentStorageMode = normalized;
        _settings.SaveDebounced();
        return true;
    }

    public bool SetManagedDropAction(string? action)
    {
        ThrowIfStopped();
        string normalized = action switch
        {
            SettingsService.ManagedDropActionCopy =>
                SettingsService.ManagedDropActionCopy,
            SettingsService.ManagedDropActionFollowWindows =>
                SettingsService.ManagedDropActionFollowWindows,
            _ => SettingsService.ManagedDropActionMove
        };
        FileWidgetSettingsSlice fileWidget = _settings.Settings.FileWidget;
        if (string.Equals(
                fileWidget.ManagedDropAction,
                normalized,
                StringComparison.Ordinal))
        {
            return false;
        }

        fileWidget.ManagedDropAction = normalized;
        _settings.SaveDebounced();
        return true;
    }

    public string ReadFileWidgetFolderOpenBehavior() =>
        FileWidgetFolderOpenBehaviorNames.NormalizeGlobal(
            _settings.Settings.FileWidget.FileWidgetFolderOpenBehavior);

    public string ReadAttachmentStorageMode() =>
        Contracts.AttachmentStorageModes.Normalize(
            _settings.Settings.QuickCapture.AttachmentStorageMode);

    public bool SetFileWidgetFolderOpenBehavior(string? behavior)
    {
        ThrowIfStopped();
        string normalized =
            FileWidgetFolderOpenBehaviorNames.NormalizeGlobal(behavior);
        FileWidgetSettingsSlice fileWidget = _settings.Settings.FileWidget;
        if (string.Equals(
                fileWidget.FileWidgetFolderOpenBehavior,
                normalized,
                StringComparison.Ordinal))
        {
            return false;
        }

        fileWidget.FileWidgetFolderOpenBehavior = normalized;
        _settings.SaveDebounced();
        return true;
    }

    /// <summary>
    /// Applies one <see cref="WeatherSettingsPolicy"/> write (the shared
    /// normalizer the page used before the migration), then skips the
    /// debounced save when the normalized value equals the raw stored value —
    /// the same unchanged-write skip every migrated section uses.
    /// </summary>
    private bool ApplyWeatherPolicy(
        Func<WeatherSettingsSlice, string> readString,
        Action<AppSettings> apply)
    {
        WeatherSettingsSlice weather = _settings.Settings.Weather;
        string before = readString(weather);
        apply(_settings.Settings);
        if (string.Equals(before, readString(weather), StringComparison.Ordinal))
        {
            return false;
        }

        _settings.SaveDebounced();
        return true;
    }

    private bool ApplyWeatherPolicy(
        Func<WeatherSettingsSlice, bool> readBool,
        Action<AppSettings> apply)
    {
        WeatherSettingsSlice weather = _settings.Settings.Weather;
        bool before = readBool(weather);
        apply(_settings.Settings);
        if (before == readBool(weather))
        {
            return false;
        }

        _settings.SaveDebounced();
        return true;
    }

    private bool ApplyWeatherPolicy(
        Func<WeatherSettingsSlice, int> readInt,
        Action<AppSettings> apply)
    {
        WeatherSettingsSlice weather = _settings.Settings.Weather;
        int before = readInt(weather);
        apply(_settings.Settings);
        if (before == readInt(weather))
        {
            return false;
        }

        _settings.SaveDebounced();
        return true;
    }

    private static bool ReadWeatherDisplayOption(
        WeatherSettingsSlice weather,
        WeatherDisplayOption option) => option switch
    {
        WeatherDisplayOption.Forecast => weather.WeatherShowForecast,
        WeatherDisplayOption.Sunrise => weather.WeatherShowSunrise,
        WeatherDisplayOption.UvIndex => weather.WeatherShowUvIndex,
        WeatherDisplayOption.Precipitation => weather.WeatherShowPrecipitation,
        WeatherDisplayOption.Humidity => weather.WeatherShowHumidity,
        WeatherDisplayOption.Wind => weather.WeatherShowWind,
        WeatherDisplayOption.Pressure => weather.WeatherShowPressure,
        _ => throw new ArgumentOutOfRangeException(nameof(option), option, null)
    };

    private void ThrowIfStopped() => ObjectDisposedException.ThrowIf(_stopped, this);

    internal void Stop() => _stopped = true;
}
