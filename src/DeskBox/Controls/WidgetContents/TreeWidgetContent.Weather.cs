using DeskBox.Helpers;
using DeskBox.Models;
using DeskBox.Services;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
using Microsoft.UI.Xaml.Media;
using Microsoft.UI.Xaml.Media.Animation;
using Microsoft.UI.Xaml.Shapes;

namespace DeskBox.Controls.WidgetContents;

public sealed partial class TreeWidgetContent
{
    private readonly WeatherService _treeWeatherService = new();
    private DateTimeOffset _nextTreeWeatherAttempt;
    private bool _treeWeatherRefreshRunning;
    private readonly Canvas _weatherGroundLayer = WeatherLayer();
    private readonly Canvas _weatherSkyLayer = WeatherLayer(82);
    private readonly Canvas _weatherFrontLayer = WeatherLayer();
    private readonly CompositeTransform _weatherTransform = new();
    private readonly List<CompositeTransform> _cloudTransforms = [];
    private readonly Storyboard _weatherMotion = new();
    private readonly Storyboard _cloudMotion = new();
    private readonly Storyboard _sunMotion = new();
    private WeatherVisualSnapshot? _renderedWeather;

    private async Task RefreshTreeWeatherIfDueAsync()
    {
        DateTimeOffset now = DateTimeOffset.UtcNow;
        if (_disposed || !_visible || _treeWeatherRefreshRunning || now < _nextTreeWeatherAttempt ||
            WeatherVisualStateService.Instance.Snapshot is { } current &&
            now - current.FetchedAtUtc < TimeSpan.FromMinutes(30)) return;

        _treeWeatherRefreshRunning = true;
        _nextTreeWeatherAttempt = now.AddMinutes(2);
        try
        {
            AppSettings settings = App.Current.SettingsService.Settings;
            WeatherCacheState cached = await WeatherCacheStore.Current.LoadAsync();
            (double latitude, double longitude, string name)? location = null;
            if (!settings.Weather.WeatherAutoLocation)
            {
                if (settings.Weather.WeatherLatitude != 0 || settings.Weather.WeatherLongitude != 0)
                    location = (settings.Weather.WeatherLatitude, settings.Weather.WeatherLongitude, settings.Weather.WeatherCityName);
                else if (!string.IsNullOrWhiteSpace(settings.Weather.WeatherCityName))
                {
                    var city = await _treeWeatherService.ResolveCityAsync(settings.Weather.WeatherCityName,
                        App.Current.LocalizationService.ApiLanguageCode);
                    if (city is not null)
                        location = (city.Latitude, city.Longitude, city.DisplayName);
                }
            }
            else
            {
                var detected = await WindowsLocationHelper.GetLocationAsync(App.Current.LocalizationService);
                if (detected is { } found)
                {
                    location = (found.Lat, found.Lon, found.Name);
                    await _treeWeatherService.SaveResolvedLocationAsync(found.Lat, found.Lon, found.Name, now);
                }
            }

            if (location is null && cached.LastLocation is { IsValid: true } saved)
                location = (saved.Latitude, saved.Longitude, saved.Name);
            if (location is null && cached.LastForecast is { IsValid: true } forecast)
                location = (forecast.Latitude, forecast.Longitude, forecast.LocationName);
            location ??= (39.9042, 116.4074, "北京");

            WeatherData? data = await _treeWeatherService.GetWeatherAsync(
                location.Value.latitude, location.Value.longitude, location.Value.name,
                cacheDuration: TimeSpan.FromMinutes(30));
            if (data is null || data.IsStale || _disposed) return;
            WeatherVisualStateService.Instance.Publish(data, _treeWeatherService.ForecastFetchedAtUtc);
            if (WeatherVisualStateService.Instance.Snapshot is not null)
                _nextTreeWeatherAttempt = DateTimeOffset.UtcNow.AddMinutes(30);
        }
        catch (Exception ex)
        {
            App.Log($"[Tree] Weather refresh failed: {ex.GetType().Name}");
        }
        finally { _treeWeatherRefreshRunning = false; }
    }

    private static Canvas WeatherLayer(double clipHeight = TreeIllustrationLayout.Height) => new()
    {
        Width = TreeIllustrationLayout.Width,
        Height = TreeIllustrationLayout.Height,
        IsHitTestVisible = false,
        Clip = new RectangleGeometry
        {
            Rect = new Windows.Foundation.Rect(0, 0, TreeIllustrationLayout.Width, clipHeight)
        }
    };

    private void RenderWeatherEffects()
    {
        WeatherVisualSnapshot? weather = WeatherVisualStateService.Instance.Snapshot;
        if (_renderedWeather == weather) return;
        _renderedWeather = weather;
        StopWeatherMotion();
        _weatherMotion.Children.Clear();
        _cloudMotion.Children.Clear();
        _cloudTransforms.Clear();
        _sunMotion.Children.Clear();
        _weatherGroundLayer.Children.Clear();
        _weatherSkyLayer.Children.Clear();
        _weatherFrontLayer.Children.Clear();

        if (weather is { } snapshot)
        {
            switch (snapshot.Condition)
            {
                case WeatherCodeMapper.WeatherCondition.Clear:
                    if (snapshot.IsDay) AddSunshine();
                    else AddNightSky();
                    break;
                case WeatherCodeMapper.WeatherCondition.Cloudy:
                    AddClouds(dark: false);
                    break;
                case WeatherCodeMapper.WeatherCondition.Fog:
                    AddFog();
                    break;
                case WeatherCodeMapper.WeatherCondition.Drizzle:
                    AddClouds(dark: true);
                    AddRain(10, .55);
                    AddFallAnimation(4.5);
                    break;
                case WeatherCodeMapper.WeatherCondition.Rain:
                    AddClouds(dark: true);
                    AddRain(22, .8);
                    AddFallAnimation(2.6);
                    break;
                case WeatherCodeMapper.WeatherCondition.Snow:
                    AddClouds(dark: true);
                    AddSnow();
                    AddSnowGround();
                    AddSnowman();
                    AddFallAnimation(12);
                    break;
                case WeatherCodeMapper.WeatherCondition.Thunderstorm:
                    AddClouds(dark: true);
                    AddRain(24, .9);
                    AddLightning();
                    AddFallAnimation(2.2);
                    break;
            }
        }

        UpdateFallingLeavesForWeather();
        if (_motionRunning) StartWeatherMotion();
    }

    private void StartWeatherMotion()
    {
        if (!_visible || !IsLoaded || !WindowsCompatibilityService.ShouldAnimate) return;
        if (_weatherMotion.Children.Count > 0) _weatherMotion.Begin();
        if (_cloudMotion.Children.Count > 0) _cloudMotion.Begin();
        if (_sunMotion.Children.Count > 0) _sunMotion.Begin();
    }

    private void StopWeatherMotion()
    {
        _weatherMotion.Stop();
        _cloudMotion.Stop();
        _sunMotion.Stop();
        _weatherTransform.TranslateY = 0;
        foreach (CompositeTransform transform in _cloudTransforms)
            transform.TranslateX = 0;
    }

    private void UpdateFallingLeavesForWeather()
    {
        if (TreeWeatherSceneLayout.SuppressesFallingLeaves(_renderedWeather))
        {
            _fallingLeaves.Stop();
            _fallingLeafLayer.Visibility = Visibility.Collapsed;
        }
        else if (_motionRunning && _visible && IsLoaded && WindowsCompatibilityService.ShouldAnimate &&
                 _fallingLeaves.Children.Count > 0)
        {
            _fallingLeafLayer.Visibility = Visibility.Visible;
            _fallingLeaves.Begin();
        }
    }

    private void AddClouds(bool dark)
    {
        foreach (CloudLane lane in TreeWeatherSceneLayout.CloudLanes)
        {
            var transform = new CompositeTransform();
            _cloudTransforms.Add(transform);
            var train = new Canvas
            {
                Width = TreeWeatherSceneLayout.CloudLoopDistance * TreeWeatherSceneLayout.CloudCopies,
                Height = lane.Height,
                IsHitTestVisible = false,
                RenderTransform = transform
            };
            Canvas.SetTop(train, lane.Top);
            for (int copy = 0; copy < TreeWeatherSceneLayout.CloudCopies; copy++)
            {
                var cloud = CreateCloud(dark, lane);
                cloud.Opacity = lane.Opacity;
                Canvas.SetLeft(cloud, lane.OffsetX + copy * TreeWeatherSceneLayout.CloudLoopDistance);
                train.Children.Add(cloud);
            }
            _weatherSkyLayer.Children.Add(train);
            AddLoop(_cloudMotion, transform, "TranslateX", 0,
                -TreeWeatherSceneLayout.CloudLoopDistance, lane.DurationSeconds);
        }
    }

    private static Canvas CreateCloud(bool dark, CloudLane lane)
    {
        var cloud = new Canvas { Width = lane.Width, Height = lane.Height, IsHitTestVisible = false };
        byte red = dark ? (byte)85 : (byte)237;
        byte green = dark ? (byte)102 : (byte)244;
        byte blue = dark ? (byte)116 : (byte)248;
        switch (lane.Shape)
        {
            case CloudShape.Puffy:
                AddEllipse(cloud, 2, 22, 44, 18, 215, red, green, blue);
                AddEllipse(cloud, 17, 7, 46, 32, 235, red, green, blue);
                AddEllipse(cloud, 51, 19, 37, 21, 225, red, green, blue);
                AddRoundedRectangle(cloud, 12, 26, 68, 14, 230, red, green, blue);
                break;
            case CloudShape.Wispy:
                AddEllipse(cloud, 1, 17, 32, 10, 200, red, green, blue);
                AddEllipse(cloud, 19, 8, 29, 17, 220, red, green, blue);
                AddEllipse(cloud, 45, 15, 25, 11, 205, red, green, blue);
                AddRoundedRectangle(cloud, 9, 19, 54, 8, 225, red, green, blue);
                break;
            case CloudShape.Clustered:
                AddEllipse(cloud, 1, 17, 28, 17, 215, red, green, blue);
                AddEllipse(cloud, 15, 9, 27, 24, 230, red, green, blue);
                AddEllipse(cloud, 33, 3, 26, 29, 235, red, green, blue);
                AddEllipse(cloud, 50, 16, 27, 18, 215, red, green, blue);
                AddRoundedRectangle(cloud, 9, 24, 63, 10, 230, red, green, blue);
                break;
        }
        return cloud;
    }

    private void AddSunshine()
    {
        var glow = new Ellipse
        {
            Width = 80,
            Height = 80,
            Fill = Brush(100, 255, 204, 74),
            Opacity = .55,
            RenderTransformOrigin = new Windows.Foundation.Point(.5, .5),
            IsHitTestVisible = false
        };
        Canvas.SetLeft(glow, 180);
        Canvas.SetTop(glow, -5);
        _weatherSkyLayer.Children.Add(glow);

        const double centerX = 220;
        const double centerY = 35;
        for (int i = 0; i < 8; i++)
        {
            double angle = i * Math.PI / 4;
            _weatherSkyLayer.Children.Add(new Line
            {
                X1 = centerX + Math.Cos(angle) * 29,
                Y1 = centerY + Math.Sin(angle) * 29,
                X2 = centerX + Math.Cos(angle) * 36,
                Y2 = centerY + Math.Sin(angle) * 36,
                Stroke = Brush(225, 255, 192, 71),
                StrokeThickness = 3,
                StrokeStartLineCap = PenLineCap.Round,
                StrokeEndLineCap = PenLineCap.Round
            });
        }
        AddEllipse(_weatherSkyLayer, 195, 10, 50, 50, 245, 255, 210, 90);
        AddEllipse(_weatherSkyLayer, 204, 17, 22, 14, 80, 255, 245, 197);

        var pulse = new DoubleAnimation
        {
            From = .38,
            To = .82,
            Duration = new Duration(TimeSpan.FromSeconds(2.6)),
            AutoReverse = true,
            RepeatBehavior = RepeatBehavior.Forever,
            EasingFunction = new SineEase { EasingMode = EasingMode.EaseInOut }
        };
        Storyboard.SetTarget(pulse, glow);
        Storyboard.SetTargetProperty(pulse, "Opacity");
        _sunMotion.Children.Add(pulse);
    }

    private void AddNightSky()
    {
        AddEllipse(_weatherSkyLayer, 201, 10, 43, 43, 90, 146, 182, 226);
        AddEllipse(_weatherSkyLayer, 209, 17, 29, 29, 190, 220, 232, 248);
        foreach ((double x, double y) in new[] { (25d, 18d), (137d, 8d), (249d, 61d) })
            AddEllipse(_weatherSkyLayer, x, y, 3, 3, 175, 222, 236, 255);
    }

    private void AddFog()
    {
        foreach (double y in new[] { 55d, 113d, 160d })
            AddRoundedRectangle(_weatherFrontLayer, 33, y, 195, 5, 90, 219, 231, 230);
    }

    private void AddRain(int count, double opacity)
    {
        for (int i = 0; i < count; i++)
        {
            double x = 12 + (i * 47) % 236;
            double y = 8 + (i * 71) % (TreeIllustrationLayout.Height - 22);
            for (int copy = 0; copy < 2; copy++)
            {
                double top = y - copy * TreeIllustrationLayout.Height;
                _weatherFrontLayer.Children.Add(new Line
                {
                    X1 = x, Y1 = top,
                    X2 = x - 4, Y2 = top + 13,
                    Stroke = Brush(210, 128, 190, 235),
                    StrokeThickness = 2,
                    StrokeStartLineCap = PenLineCap.Round,
                    StrokeEndLineCap = PenLineCap.Round,
                    Opacity = opacity
                });
            }
        }
    }

    private void AddSnow()
    {
        for (int i = 0; i < 19; i++)
        {
            double x = 12 + (i * 61) % 236;
            double y = 8 + (i * 83) % (TreeIllustrationLayout.Height - 20);
            double size = 3 + i % 3;
            for (int copy = 0; copy < 2; copy++)
                AddEllipse(_weatherFrontLayer, x, y - copy * TreeIllustrationLayout.Height,
                    size, size, 225, 246, 250, 255);
        }
    }

    private void AddSnowGround()
    {
        AddEllipse(_weatherGroundLayer, 34, 169, 192, 40, 235, 239, 248, 252);
        AddEllipse(_weatherGroundLayer, 55, 190, 150, 18, 190, 217, 232, 240);
    }

    private void AddSnowman()
    {
        var snowman = new Canvas { Width = 35, Height = 42, IsHitTestVisible = false };
        AddEllipse(snowman, 7, 20, 22, 20, 250, 250, 253, 255);
        AddEllipse(snowman, 10, 8, 17, 17, 255, 251, 254, 255);
        AddRoundedRectangle(snowman, 9, 5, 19, 4, 245, 59, 77, 91);
        AddRoundedRectangle(snowman, 12, 0, 13, 7, 245, 59, 77, 91);
        AddEllipse(snowman, 15, 14, 2, 2, 255, 42, 60, 69);
        AddEllipse(snowman, 22, 14, 2, 2, 255, 42, 60, 69);
        AddRoundedRectangle(snowman, 17, 21, 13, 3, 245, 206, 85, 82);
        Canvas.SetLeft(snowman, 177);
        Canvas.SetTop(snowman, 163);
        _weatherGroundLayer.Children.Add(snowman);
    }

    private void AddLightning()
    {
        (double x1, double y1, double x2, double y2)[] segments =
        [
            (216, 36, 205, 54), (205, 54, 214, 54), (214, 54, 201, 76)
        ];
        foreach ((double x1, double y1, double x2, double y2) in segments)
            _weatherSkyLayer.Children.Add(new Line
            {
                X1 = x1, Y1 = y1, X2 = x2, Y2 = y2,
                Stroke = Brush(235, 255, 224, 122),
                StrokeThickness = 3
            });
    }

    private void AddFallAnimation(double seconds) =>
        AddLoop(_weatherMotion, _weatherTransform, "TranslateY", 0,
            TreeIllustrationLayout.Height, seconds);

    private static void AddLoop(Storyboard storyboard, DependencyObject target, string property,
        double from, double to, double seconds)
    {
        var animation = new DoubleAnimation
        {
            From = from,
            To = to,
            Duration = new Duration(TimeSpan.FromSeconds(seconds)),
            RepeatBehavior = RepeatBehavior.Forever
        };
        Storyboard.SetTarget(animation, target);
        Storyboard.SetTargetProperty(animation, property);
        storyboard.Children.Add(animation);
    }

    private static void AddRoundedRectangle(Canvas host, double x, double y, double width, double height,
        byte alpha, byte red, byte green, byte blue)
    {
        var rectangle = new Rectangle
        {
            Width = width, Height = height, RadiusX = height / 2, RadiusY = height / 2,
            Fill = Brush(alpha, red, green, blue)
        };
        Canvas.SetLeft(rectangle, x);
        Canvas.SetTop(rectangle, y);
        host.Children.Add(rectangle);
    }

    private static void AddEllipse(Canvas host, double x, double y, double width, double height,
        byte alpha, byte red, byte green, byte blue)
    {
        var ellipse = new Ellipse { Width = width, Height = height, Fill = Brush(alpha, red, green, blue) };
        Canvas.SetLeft(ellipse, x);
        Canvas.SetTop(ellipse, y);
        host.Children.Add(ellipse);
    }

    private static SolidColorBrush Brush(byte alpha, byte red, byte green, byte blue) =>
        new(Windows.UI.Color.FromArgb(alpha, red, green, blue));
}
