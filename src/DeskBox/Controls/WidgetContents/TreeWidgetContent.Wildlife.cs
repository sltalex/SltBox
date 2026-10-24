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

        var transform = new CompositeTransform { ScaleX = motion.Frames[0].ScaleX };
        double visualSize = WildlifeMotionCatalog.VisualSize(encounter.SpeciesId, species.Category, treeStage);
        double hitSize = Math.Max(visualSize, 56);
        var image = new Image
        {
            Source = source, Width = visualSize, Height = visualSize, Stretch = Stretch.Uniform,
            HorizontalAlignment = HorizontalAlignment.Center, VerticalAlignment = VerticalAlignment.Center,
            IsHitTestVisible = false
        };
        var hitTarget = new Border
        {
            Width = hitSize, Height = hitSize, Child = image,
            Background = new SolidColorBrush(Microsoft.UI.Colors.Transparent),
            RenderTransform = transform, RenderTransformOrigin = new Windows.Foundation.Point(.5, .5)
        };
        Microsoft.UI.Xaml.Automation.AutomationProperties.SetName(hitTarget, $"{species.Name}，点击收藏");
        bool capturing = false;
        hitTarget.Tapped += async (_, args) =>
        {
            args.Handled = true;
            if (capturing) return;
            capturing = true;
            hitTarget.Opacity = .6;
            try
            {
                await service.CaptureAsync(encounter.Id);
                RenderWildlife();
            }
            finally
            {
                capturing = false;
                hitTarget.Opacity = 1;
            }
        };
        double inset = (hitSize - visualSize) / 2;
        Canvas.SetLeft(hitTarget, motion.X - inset);
        Canvas.SetTop(hitTarget, motion.Y - inset + (WildlifeMotionCatalog.FliesAcrossSky(encounter.SpeciesId)
            ? 0 : TreeIllustrationLayout.TreeVerticalOffset));
        _wildlifeLayer.Children.Add(hitTarget);

        if (!WindowsCompatibilityService.ShouldAnimate) return;
        AddWildlifeTrack(transform, "TranslateX", motion, frame => frame.X,
            linear: WildlifeMotionCatalog.FliesAcrossSky(encounter.SpeciesId));
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
