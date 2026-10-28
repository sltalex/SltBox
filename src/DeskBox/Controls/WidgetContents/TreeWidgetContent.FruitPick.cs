using DeskBox.Services;
using Microsoft.UI;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
using Microsoft.UI.Xaml.Media;
using Microsoft.UI.Xaml.Media.Animation;
using Microsoft.UI.Xaml.Shapes;

namespace DeskBox.Controls.WidgetContents;

public sealed partial class TreeWidgetContent
{
    private readonly List<FruitPickVisual> _fruitPickVisuals = [];

    private FruitPickVisual? BeginFruitPickVisual(long pendingId, int index, ImageSource source)
    {
        if (_disposed || !_visible || index < 0)
            return null;

        (double x, double y) = TreeIllustrationLayout.FruitPositionAt(index);
        var glowScale = new ScaleTransform { ScaleX = .55, ScaleY = .55 };
        var glow = new Ellipse
        {
            Width = 46, Height = 46,
            Fill = new SolidColorBrush(ColorHelper.FromArgb(70, 255, 229, 125)),
            Stroke = new SolidColorBrush(ColorHelper.FromArgb(230, 255, 245, 177)),
            StrokeThickness = 2,
            Opacity = .5,
            RenderTransformOrigin = new Windows.Foundation.Point(.5, .5),
            RenderTransform = glowScale,
            IsHitTestVisible = false
        };
        Canvas.SetLeft(glow, x - 11);
        Canvas.SetTop(glow, y - 9);

        var fruitScale = new ScaleTransform { ScaleX = 1, ScaleY = 1 };
        var fruit = new Image
        {
            Width = 24, Height = 28, Source = source, Stretch = Stretch.Uniform,
            RenderTransformOrigin = new Windows.Foundation.Point(.5, .5),
            RenderTransform = fruitScale,
            IsHitTestVisible = false
        };
        Canvas.SetLeft(fruit, x);
        Canvas.SetTop(fruit, y);

        var sparkle = new TextBlock
        {
            Text = "✦", FontSize = 20,
            Foreground = new SolidColorBrush(Colors.White),
            Opacity = 0, IsHitTestVisible = false
        };
        Canvas.SetLeft(sparkle, x + 18);
        Canvas.SetTop(sparkle, y - 13);

        var rewardRise = new TranslateTransform();
        var reward = new TextBlock
        {
            Width = 48, TextAlignment = TextAlignment.Center,
            FontSize = 24, FontWeight = Microsoft.UI.Text.FontWeights.Bold,
            Foreground = new SolidColorBrush(ColorHelper.FromArgb(255, 255, 238, 145)),
            Opacity = 0, RenderTransform = rewardRise, IsHitTestVisible = false
        };
        Canvas.SetLeft(reward, x - 12);
        Canvas.SetTop(reward, y - 2);

        Image? original = index < _fruitLayer.Children.Count ? _fruitLayer.Children[index] as Image : null;
        var visual = new FruitPickVisual(pendingId, index, original, fruit, fruitScale,
            glow, glowScale, sparkle, reward, rewardRise, y);
        _fruitPickLayer.Children.Add(glow);
        _fruitPickLayer.Children.Add(fruit);
        _fruitPickLayer.Children.Add(sparkle);
        _fruitPickLayer.Children.Add(reward);
        _fruitPickVisuals.Add(visual);
        if (original is not null) original.Opacity = 0;
        return visual;
    }

    private async Task<bool> AnimateFruitPickAsync(FruitPickVisual visual)
    {
        if (!WindowsCompatibilityService.ShouldAnimate)
        {
            visual.Fruit.Opacity = .6;
            visual.Sparkle.Opacity = 1;
            return true;
        }
        var animation = new Storyboard();
        visual.Animation = animation;
        AddFruitPickFrames(animation, visual.FruitScale, "ScaleX", (0, 1), (240, 1.6), (430, 1.68));
        AddFruitPickFrames(animation, visual.FruitScale, "ScaleY", (0, 1), (240, 1.6), (430, 1.68));
        AddFruitPickFrames(animation, visual.Fruit, "Opacity", (0, 1), (255, 1), (430, 0));
        AddFruitPickFrames(animation, visual.GlowScale, "ScaleX", (0, .55), (430, 1.55));
        AddFruitPickFrames(animation, visual.GlowScale, "ScaleY", (0, .55), (430, 1.55));
        AddFruitPickFrames(animation, visual.Glow, "Opacity", (0, 0), (145, .9), (430, 0));
        AddFruitPickFrames(animation, visual.Sparkle, "Opacity", (0, 0), (155, 1), (430, 0));
        return await PlayCollectionAnimationAsync(animation);
    }

    private void CompleteFruitPickVisual(FruitPickVisual? visual, long quantity)
    {
        if (visual is null || !_fruitPickVisuals.Contains(visual)) return;
        visual.Animation?.Stop();
        visual.Fruit.Opacity = visual.Glow.Opacity = visual.Sparkle.Opacity = 0;
        visual.Reward.Text = $"+{quantity}";
        var animation = new Storyboard { FillBehavior = FillBehavior.Stop };
        visual.Animation = animation;
        animation.Completed += (_, _) => RemoveFruitPickVisual(visual);
        if (WindowsCompatibilityService.ShouldAnimate)
        {
            AddFruitPickFrames(animation, visual.Reward, "Opacity", (0, 0), (100, 1), (360, 1), (790, 0));
            AddFruitPickFrames(animation, visual.RewardRise, "Y",
                (0, 0), (790, -Math.Min(34, Math.Max(0, visual.Y - 5))));
        }
        else
        {
            visual.Reward.Opacity = 1;
            AddFruitPickFrames(animation, visual.Reward, "Opacity", (0, 1), (900, 1));
        }
        animation.Begin();
    }

    private void CancelFruitPickVisual(FruitPickVisual? visual)
    {
        if (visual is null) return;
        visual.Animation?.Stop();
        if (visual.Original is not null) visual.Original.Opacity = 1;
        RemoveFruitPickVisual(visual);
    }

    private void RemoveFruitPickVisual(FruitPickVisual visual)
    {
        if (!_fruitPickVisuals.Remove(visual)) return;
        _fruitPickLayer.Children.Remove(visual.Glow);
        _fruitPickLayer.Children.Remove(visual.Fruit);
        _fruitPickLayer.Children.Remove(visual.Sparkle);
        _fruitPickLayer.Children.Remove(visual.Reward);
        _lastFruitSignature = string.Empty;
        _lastFruitInventory = -1;
        if (!_disposed && _visible && IsLoaded) Refresh();
    }

    private void ClearFruitPickVisuals()
    {
        CancelCollectionAnimations();
        foreach (FruitPickVisual visual in _fruitPickVisuals.ToArray())
        {
            visual.Animation?.Stop();
            if (visual.Animation is null && visual.Original is not null) visual.Original.Opacity = 1;
        }
        _fruitPickVisuals.Clear();
        _fruitPickLayer.Children.Clear();
        _lastFruitSignature = string.Empty;
        _lastFruitInventory = -1;
    }

    private static void AddFruitPickFrames(Storyboard storyboard, DependencyObject target,
        string property, params (int Milliseconds, double Value)[] frames)
    {
        var animation = new DoubleAnimationUsingKeyFrames();
        foreach ((int milliseconds, double value) in frames)
            animation.KeyFrames.Add(new EasingDoubleKeyFrame
            {
                KeyTime = TimeSpan.FromMilliseconds(milliseconds), Value = value,
                EasingFunction = new SineEase { EasingMode = EasingMode.EaseOut }
            });
        Storyboard.SetTarget(animation, target);
        Storyboard.SetTargetProperty(animation, property);
        storyboard.Children.Add(animation);
    }

    private sealed record FruitPickVisual(long PendingId, int Index, Image? Original,
        Image Fruit, ScaleTransform FruitScale,
        Ellipse Glow, ScaleTransform GlowScale, TextBlock Sparkle, TextBlock Reward,
        TranslateTransform RewardRise, double Y)
    {
        internal Storyboard? Animation { get; set; }
    }
}
