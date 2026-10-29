using DeskBox.Services;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
using Microsoft.UI.Xaml.Media;
using Microsoft.UI.Xaml.Media.Animation;

namespace DeskBox.Controls.WidgetContents;

public sealed partial class TreeWidgetContent
{
    private string? _capturingEncounterId;

    private void RenderWildlife()
    {
        WildlifeService service = App.Current.WildlifeService;
        WildlifeEncounter? encounter = _visible && service.CanOffer &&
            service.Encounter is { } active && active.ExpiresAtUtc > DateTimeOffset.UtcNow
                ? active : null;
        int treeStage = _counter.Snapshot.Stage;
        if (_renderedEncounterId == encounter?.Id && _renderedWildlifeStage == treeStage) return;
        _wildlifeMotion.Stop();
        _wildlifeMotion.Children.Clear();
        _wildlifeLayer.Children.Clear();
        _renderedEncounterId = encounter?.Id;
        _renderedWildlifeStage = treeStage;
        if (encounter is null) return;
        WildlifeCatalogItem? species = service.Catalog.FirstOrDefault(item => item.Id == encounter.SpeciesId);
        ImageSource? source = WildlifeAssetCatalog.GetImage(encounter.SpeciesId);
        if (species is null || source is null ||
            !WildlifeMotionCatalog.Profiles.TryGetValue(encounter.SpeciesId, out WildlifeMotionProfile? motion)) return;
        motion = WildlifeMotionCatalog.ForEncounter(encounter.SpeciesId, species.Placement,
            treeStage, encounter.Id);
        bool skyFlight = WildlifeMotionCatalog.FliesAcrossSky(encounter.SpeciesId, species.Placement);

        var transform = new CompositeTransform { ScaleX = motion.Frames[0].ScaleX };
        double visualSize = WildlifeMotionCatalog.VisualSize(encounter.SpeciesId, species.Category, treeStage);
        double hitSize = Math.Max(visualSize, 56);
        var captureScale = new ScaleTransform();
        var captureVisual = new Grid
        {
            RenderTransform = captureScale,
            RenderTransformOrigin = new Windows.Foundation.Point(.5, .5),
            IsHitTestVisible = false
        };
        var image = new Image
        {
            Source = source, Width = visualSize, Height = visualSize, Stretch = Stretch.Uniform,
            HorizontalAlignment = HorizontalAlignment.Center, VerticalAlignment = VerticalAlignment.Center,
            IsHitTestVisible = false
        };
        captureVisual.Children.Add(image);
        var hitTarget = new Border
        {
            Width = hitSize, Height = hitSize, Child = captureVisual,
            Background = new SolidColorBrush(Microsoft.UI.Colors.Transparent),
            RenderTransform = transform, RenderTransformOrigin = new Windows.Foundation.Point(.5, .5)
        };
        Microsoft.UI.Xaml.Automation.AutomationProperties.SetName(hitTarget, $"{species.Name}，点击收藏");
        hitTarget.Tapped += async (_, args) =>
        {
            args.Handled = true;
            if (_capturingEncounterId is not null) return;
            _capturingEncounterId = encounter.Id;
            hitTarget.IsHitTestVisible = false;
            Storyboard? feedback = null;
            try
            {
                if (WindowsCompatibilityService.ShouldAnimate)
                {
                    _wildlifeMotion.Pause();
                    feedback = new Storyboard();
                    AddFruitPickFrames(feedback, captureScale, "ScaleX", (0, 1), (150, .94), (320, .65));
                    AddFruitPickFrames(feedback, captureScale, "ScaleY", (0, 1), (150, .94), (320, .65));
                    AddFruitPickFrames(feedback, captureVisual, "Opacity", (0, 1), (150, 1), (320, 0));
                    if (!await PlayCollectionAnimationAsync(feedback)) return;
                }
                else captureVisual.Opacity = .6;
                if (_disposed || !_visible || !IsLoaded) return;
                bool captured = await service.CaptureAsync(encounter.Id);
                _wildlifeCaptureConfirmed = captured;
                _wildlifeFeedbackUntil = DateTimeOffset.UtcNow.AddSeconds(4);
                Refresh();
            }
            finally
            {
                feedback?.Stop();
                captureVisual.Opacity = 1;
                captureScale.ScaleX = captureScale.ScaleY = 1;
                hitTarget.IsHitTestVisible = true;
                _capturingEncounterId = null;
                if (!_disposed && _visible && IsLoaded && _motionRunning &&
                    _renderedEncounterId == encounter.Id) _wildlifeMotion.Resume();
            }
        };
        (double left, double top) = WildlifeMotionCatalog.HitTargetPosition(
            motion, visualSize, skyFlight);
        Canvas.SetLeft(hitTarget, left);
        Canvas.SetTop(hitTarget, top);
        _wildlifeLayer.Children.Add(hitTarget);

        if (!WindowsCompatibilityService.ShouldAnimate) return;
        AddWildlifeTrack(transform, "TranslateX", motion, frame => frame.X,
            linear: skyFlight);
        AddWildlifeTrack(transform, "TranslateY", motion, frame => frame.Y);
        AddWildlifeTrack(transform, "Rotation", motion, frame => frame.Rotation);
        AddWildlifeTrack(transform, "ScaleX", motion, frame => frame.ScaleX);
        AddWildlifeTrack(transform, "ScaleY", motion, frame => frame.ScaleY);
        AddWildlifeTrack(image, "Opacity", motion, frame => frame.Opacity);
        if (_motionRunning) _wildlifeMotion.Begin();
    }

    private void AddWildlifeTrack(DependencyObject target, string property,
        WildlifeMotionProfile motion, Func<WildlifeMotionFrame, double> value, bool linear = false)
    {
        if (motion.Frames.Select(value).Distinct().Count() == 1) return;
        var animation = new DoubleAnimationUsingKeyFrames
        {
            RepeatBehavior = RepeatBehavior.Forever,
            Duration = new Duration(TimeSpan.FromSeconds(motion.Seconds))
        };
        foreach (WildlifeMotionFrame frame in motion.Frames)
        {
            KeyTime time = KeyTime.FromTimeSpan(TimeSpan.FromSeconds(motion.Seconds * frame.At));
            animation.KeyFrames.Add(linear
                ? new LinearDoubleKeyFrame { KeyTime = time, Value = value(frame) }
                : new EasingDoubleKeyFrame
                {
                    KeyTime = time,
                    Value = value(frame),
                    EasingFunction = new SineEase { EasingMode = EasingMode.EaseInOut }
                });
        }
        Storyboard.SetTarget(animation, target);
        Storyboard.SetTargetProperty(animation, property);
        _wildlifeMotion.Children.Add(animation);
    }
}
