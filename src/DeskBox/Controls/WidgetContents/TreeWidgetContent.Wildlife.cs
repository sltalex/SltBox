using DeskBox.Services;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
using Microsoft.UI.Xaml.Media;
using Microsoft.UI.Xaml.Media.Animation;

namespace DeskBox.Controls.WidgetContents;

public sealed partial class TreeWidgetContent
{
    private void RenderWildlife()
    {
        WildlifeService service = App.Current.WildlifeService;
        WildlifeEncounter? encounter = _visible && service.CanOffer &&
            service.Encounter is { } active && active.ExpiresAtUtc > DateTimeOffset.UtcNow
                ? active : null;
        if (_renderedEncounterId == encounter?.Id) return;
        _wildlifeMotion.Stop();
        _wildlifeMotion.Children.Clear();
        _wildlifeLayer.Children.Clear();
        _renderedEncounterId = encounter?.Id;
        if (encounter is null) return;
        WildlifeCatalogItem? species = service.Catalog.FirstOrDefault(item => item.Id == encounter.SpeciesId);
        ImageSource? source = WildlifeAssetCatalog.GetImage(encounter.SpeciesId);
        if (species is null || source is null ||
            !WildlifeMotionCatalog.Profiles.TryGetValue(encounter.SpeciesId, out WildlifeMotionProfile? motion)) return;

        var transform = new CompositeTransform();
        double visualSize = WildlifeMotionCatalog.VisualSize(encounter.SpeciesId, species.Category);
        var image = new Image
        {
            Source = source, Width = visualSize, Height = visualSize, Stretch = Stretch.Uniform,
            RenderTransform = transform, RenderTransformOrigin = new Windows.Foundation.Point(.5, .5)
        };
        Microsoft.UI.Xaml.Automation.AutomationProperties.SetName(image, $"{species.Name}，双击收藏");
        image.DoubleTapped += async (_, args) =>
        {
            args.Handled = true;
            await service.CaptureAsync(encounter.Id);
            RenderWildlife();
        };
        Canvas.SetLeft(image, motion.X);
        Canvas.SetTop(image, motion.Y);
        _wildlifeLayer.Children.Add(image);

        if (!WindowsCompatibilityService.ShouldAnimate) return;
        AddWildlifeTrack(transform, "TranslateX", motion, frame => frame.X);
        AddWildlifeTrack(transform, "TranslateY", motion, frame => frame.Y);
        AddWildlifeTrack(transform, "Rotation", motion, frame => frame.Rotation);
        AddWildlifeTrack(transform, "ScaleX", motion, frame => frame.ScaleX);
        AddWildlifeTrack(transform, "ScaleY", motion, frame => frame.ScaleY);
        AddWildlifeTrack(image, "Opacity", motion, frame => frame.Opacity);
        if (_motionRunning) _wildlifeMotion.Begin();
    }

    private void AddWildlifeTrack(DependencyObject target, string property,
        WildlifeMotionProfile motion, Func<WildlifeMotionFrame, double> value)
    {
        if (motion.Frames.Select(value).Distinct().Count() == 1) return;
        var animation = new DoubleAnimationUsingKeyFrames
        {
            RepeatBehavior = RepeatBehavior.Forever,
            Duration = new Duration(TimeSpan.FromSeconds(motion.Seconds))
        };
        foreach (WildlifeMotionFrame frame in motion.Frames)
            animation.KeyFrames.Add(new EasingDoubleKeyFrame
            {
                KeyTime = KeyTime.FromTimeSpan(TimeSpan.FromSeconds(motion.Seconds * frame.At)),
                Value = value(frame),
                EasingFunction = new SineEase { EasingMode = EasingMode.EaseInOut }
            });
        Storyboard.SetTarget(animation, target);
        Storyboard.SetTargetProperty(animation, property);
        _wildlifeMotion.Children.Add(animation);
    }
}
