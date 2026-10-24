using DeskBox.Services;
using Microsoft.UI;
using Microsoft.UI.Dispatching;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
using Microsoft.UI.Xaml.Media;
using Microsoft.UI.Xaml.Media.Animation;
using Microsoft.UI.Xaml.Media.Imaging;

namespace DeskBox.Controls.WidgetContents;

/// <summary>Five softly shaded illustrations of the same growing tree.</summary>
public sealed partial class TreeWidgetContent : UserControl, IDisposable
{
    private readonly LocalizationService _localization;
    private readonly TreeCounterService _counter = TreeCounterService.Instance;
    private readonly DispatcherQueue _uiQueue = DispatcherQueue.GetForCurrentThread();
    private readonly DispatcherTimer _timer = new() { Interval = TimeSpan.FromSeconds(1) };
    private readonly Grid _drawing = new() { Width = TreeIllustrationLayout.Width, Height = TreeIllustrationLayout.Height };
    private readonly Grid _treeSurface = new()
    {
        Width = TreeIllustrationLayout.Width,
        Height = TreeIllustrationLayout.ArtworkHeight,
        VerticalAlignment = VerticalAlignment.Top,
        Background = new SolidColorBrush(Colors.Transparent),
        RenderTransformOrigin = new Windows.Foundation.Point(.5, .86)
    };
    private readonly Image _treeImage = new() { Stretch = Stretch.Uniform, IsHitTestVisible = false };
    private readonly Grid _canopySurface = new() { IsHitTestVisible = false };
    private readonly Image _canopyImage = new() { Stretch = Stretch.Uniform, IsHitTestVisible = false };
    private readonly Canvas _fruitLayer = new() { IsHitTestVisible = false };
    private readonly Canvas _fruitPickLayer = new() { IsHitTestVisible = false };
    private readonly Canvas _wildlifeLayer = new()
    {
        Width = TreeIllustrationLayout.Width,
        Height = TreeIllustrationLayout.Height,
        Clip = new RectangleGeometry
        {
            Rect = new Windows.Foundation.Rect(0, 0, TreeIllustrationLayout.Width, TreeIllustrationLayout.Height)
        }
    };
    private readonly Storyboard _wildlifeMotion = new();
    private string? _renderedEncounterId;
    private int _renderedWildlifeStage;
    private readonly Canvas _fallingLeafLayer = new() { IsHitTestVisible = false, Visibility = Visibility.Collapsed };
    private readonly CompositeTransform _treeTransform = new();
    private readonly CompositeTransform _canopyTransform = new();
    private readonly Storyboard _sway = new();
    private readonly DoubleAnimation _swayRotation = new();
    private readonly Storyboard _celebration = new();
    private readonly Storyboard _fallingLeaves = new()
    {
        Duration = new Duration(TimeSpan.FromSeconds(TreeIllustrationLayout.LeafCycleSeconds)),
        RepeatBehavior = RepeatBehavior.Forever
    };
    private readonly TextBlock _captureStatus = new() { FontSize = 11, HorizontalAlignment = HorizontalAlignment.Center };
    private readonly TreeBubbleProgressBar _progress = new();
    private volatile bool _visible = true;
    private volatile bool _disposed;
    private bool _motionRunning;
    private int _refreshQueued;
    private int _pickInProgress;
    private long _lastCounterRefreshTicks;
    private int _lastStage;
    private long _lastFruitInventory = -1;
    private string _lastFruitSignature = string.Empty;
    private long _lastHarvested;

    public TreeWidgetContent(LocalizationService localization)
    {
        _localization = localization;
        _drawing.Children.Add(new Image
        {
            Source = Asset("ground.svg"), Width = TreeIllustrationLayout.Width,
            Height = TreeIllustrationLayout.ArtworkHeight, Stretch = Stretch.Uniform,
            VerticalAlignment = VerticalAlignment.Top, IsHitTestVisible = false,
            RenderTransform = new TranslateTransform { Y = TreeIllustrationLayout.GroundVerticalOffset }
        });
        _treeTransform.ScaleX = TreeIllustrationLayout.TreeArtworkScale;
        _treeTransform.ScaleY = TreeIllustrationLayout.TreeArtworkScale;
        _treeTransform.TranslateY = TreeIllustrationLayout.TreeVerticalOffset;
        _treeSurface.RenderTransform = _treeTransform;
        _canopySurface.RenderTransform = _canopyTransform;
        _canopySurface.Children.Add(_canopyImage);
        _canopySurface.Children.Add(_fruitLayer);
        _canopySurface.Children.Add(_fruitPickLayer);
        _treeSurface.Children.Add(_treeImage);
        _treeSurface.Children.Add(_canopySurface);
        _drawing.Children.Add(_weatherSkyLayer);
        _drawing.Children.Add(_treeSurface);
        _drawing.Children.Add(_wildlifeLayer);
        _drawing.Children.Add(_fallingLeafLayer);
        _weatherGroundLayer.RenderTransform = new TranslateTransform { Y = TreeIllustrationLayout.GroundVerticalOffset };
        _drawing.Children.Add(_weatherGroundLayer);
        _weatherFrontLayer.RenderTransform = _weatherTransform;
        _drawing.Children.Add(_weatherFrontLayer);
        ConfigureMotion();

        var stack = new StackPanel { Spacing = 2, HorizontalAlignment = HorizontalAlignment.Stretch };
        _progress.Margin = new Thickness(16, 8, 16, 4);
        stack.Children.Add(new Viewbox { Child = _drawing, Height = TreeIllustrationLayout.DisplayHeight, Stretch = Stretch.Uniform });
        stack.Children.Add(_captureStatus);
        stack.Children.Add(_progress);
        Content = stack;
        _treeSurface.Tapped += (_, args) =>
        {
            TreeProgress state = _counter.Snapshot;
            Windows.Foundation.Point point = args.GetPosition(_treeSurface);
            FruitService fruitService = App.Current.FruitService;
            int visible = fruitService.IsAvailable
                ? fruitService.OnTree.Count
                : TreeIllustrationLayout.VisibleFruitCount(state.FruitInventory);
            int index = state.Stage == 5 ? TreeIllustrationLayout.FruitIndexAt(visible, point.X, point.Y) : -1;
            if (index >= 0)
            {
                long pendingId = fruitService.IsAvailable ? fruitService.OnTree[index].Id : 0;
                ImageSource image = fruitService.IsAvailable
                    ? FruitAssetCatalog.GetImageSource(fruitService.OnTree[index].FruitId)
                    : Asset("fruit.svg");
                _ = PickFruitAsync(pendingId, index, image);
                args.Handled = true;
            }
        };
        _timer.Tick += (_, _) =>
        {
            if (!_visible) return;
            Refresh();
            _ = RefreshTreeWeatherIfDueAsync();
            _ = App.Current.WildlifeService.OfferIfDueAsync();
        };
        Loaded += (_, _) =>
        {
            _counter.Changed += CounterChanged;
            App.Current.FruitService.Changed += FruitChanged;
            App.Current.WildlifeService.Changed += FruitChanged;
            if (_visible && !_disposed) { _timer.Start(); Refresh(); }
        };
        Unloaded += (_, _) => { _counter.Changed -= CounterChanged; _timer.Stop(); StopMotion(); };
        Unloaded += (_, _) => ClearFruitPickVisuals();
        Unloaded += (_, _) =>
        {
            App.Current.WildlifeService.Changed -= FruitChanged;
            App.Current.WildlifeService.SetVisible(false);
        };
        Unloaded += (_, _) => App.Current.FruitService.Changed -= FruitChanged;
        Refresh();
    }

    public void SetVisible(bool visible)
    {
        _visible = visible;
        if (visible && IsLoaded && !_disposed) { _timer.Start(); Refresh(); }
        else { _timer.Stop(); StopMotion(); }
        if (!visible) { ClearFruitPickVisuals(); App.Current.WildlifeService.SetVisible(false); RenderWildlife(); }
    }

    private void Refresh()
    {
        if (_disposed) return;
        TreeProgress p = _counter.Snapshot;
        bool celebrate = _lastStage != 0 && (p.Stage > _lastStage || p.HarvestedFruit > _lastHarvested);
        _lastHarvested = p.HarvestedFruit;
        _progress.Visibility = TreeIllustrationLayout.ShowsStageProgress(p.Stage)
            ? Visibility.Visible : Visibility.Collapsed;
        _captureStatus.Visibility = p.Paused ? Visibility.Visible : Visibility.Collapsed;
        if (p.Paused) _captureStatus.Text = _localization.T("Tree.Settings.StatisticsPaused");
        // The desktop tile describes the current growth step.  Showing the
        // all-the-way-to-maturity percentage here made it disagree with the
        // stage wording and made each 36,000-energy stage feel stalled.
        double percentage = p.NextStagePercentage;
        _progress.Value = percentage;
        if (_lastStage != p.Stage)
        {
            StopMotion();
            double swayAmplitude = TreeIllustrationLayout.SwayAmplitudeDegrees(p.Stage);
            _swayRotation.From = -swayAmplitude;
            _swayRotation.To = swayAmplitude;
            _treeImage.Source = Asset(TreeIllustrationLayout.StageAssetName(p.Stage));
            _canopyImage.Source = Asset(TreeIllustrationLayout.CanopyAssetName(p.Stage));
            _canopySurface.RenderTransformOrigin = new Windows.Foundation.Point(
                .5, TreeIllustrationLayout.CanopyPivotY(p.Stage) / TreeIllustrationLayout.ArtworkHeight);
            Microsoft.UI.Xaml.Automation.AutomationProperties.SetName(_treeSurface, _localization.T($"Tree.Stage.{p.Stage}"));
            ConfigureFallingLeaves(p.Stage);
        }
        string fruitSignature = string.Join(',', App.Current.FruitService.OnTree.Select(x => x.Id));
        if (_lastStage != p.Stage || _lastFruitInventory != p.FruitInventory || _lastFruitSignature != fruitSignature)
            DrawFruit(p);
        _lastStage = p.Stage;
        _lastFruitInventory = p.FruitInventory;
        _lastFruitSignature = fruitSignature;

        RenderWeatherEffects();
        RenderWildlife();
        UpdateMotion();
        if (celebrate && _motionRunning)
        {
            _celebration.Stop();
            _celebration.Begin();
        }
    }

    private void CounterChanged()
    {
        if (_disposed || !_visible) return;
        long now = Environment.TickCount64;
        if (now - Volatile.Read(ref _lastCounterRefreshTicks) < 100) return;
        if (Interlocked.Exchange(ref _refreshQueued, 1) != 0) return;
        Volatile.Write(ref _lastCounterRefreshTicks, now);
        if (!_uiQueue.TryEnqueue(() =>
            {
                try { if (!_disposed && _visible && IsLoaded) Refresh(); }
                finally { Interlocked.Exchange(ref _refreshQueued, 0); }
            }))
            Interlocked.Exchange(ref _refreshQueued, 0);
    }

    private void FruitChanged()
    {
        if (!_disposed && _visible) _uiQueue.TryEnqueue(Refresh);
    }

    private static SvgImageSource Asset(string fileName) => new(new Uri($"ms-appx:///Assets/Tree/{fileName}"));

    private async Task PickFruitAsync(long pendingId, int fruitIndex, ImageSource image)
    {
        if (Interlocked.Exchange(ref _pickInProgress, 1) != 0) return;
        FruitPickVisual? visual = null;
        bool pickedSuccessfully = false;
        try
        {
            visual = BeginFruitPickVisual(pendingId, fruitIndex, image);
            var fruitService = App.Current.FruitService;
            if (fruitService.IsAvailable)
            {
                if (!await App.Current.TreeCloudSyncService.SyncProgressNowAsync())
                {
                    App.Log("[Tree] Fruit pick needs a successful tree sync; local tree state was unchanged.");
                    return;
                }
                var picked = await fruitService.PickAsync(pendingId);
                if (picked.Success)
                {
                    CompleteFruitPickVisual(visual, picked.Quantity);
                    pickedSuccessfully = true;
                    App.Log($"[Tree] Picked {picked.FruitId}; credited {picked.Quantity} fruit(s).");
                    await App.Current.TreeCloudSyncService.SyncProgressNowAsync();
                }
                else App.Log("[Tree] Fruit pick was rejected; local tree state was unchanged.");
            }
            else
            {
                long reward = _counter.Harvest();
                if (reward > 0)
                {
                    CompleteFruitPickVisual(visual, reward);
                    pickedSuccessfully = true;
                    App.Log($"[Tree] Picked one fruit; credited {reward} fruit(s) to the local backpack.");
                }
            }
            Refresh();
        }
        catch (Exception ex) when (ex is HttpRequestException or TaskCanceledException or InvalidDataException)
        {
            App.Log($"[Tree] Fruit pick failed: {ex.Message}");
        }
        finally
        {
            if (!pickedSuccessfully) CancelFruitPickVisual(visual);
            Volatile.Write(ref _pickInProgress, 0);
        }
    }

    private void DrawFruit(TreeProgress state)
    {
        _fruitLayer.Children.Clear();
        if (state.Stage != 5) return;
        FruitService fruitService = App.Current.FruitService;
        IReadOnlyList<FruitOnTreeItem> onTree = fruitService.OnTree;
        int visible = fruitService.IsAvailable
            ? Math.Min(onTree.Count, TreeIllustrationLayout.MaxVisibleFruit)
            : TreeIllustrationLayout.VisibleFruitCount(state.FruitInventory);
        for (int i = 0; i < visible; i++)
        {
            (double x, double y) = TreeIllustrationLayout.FruitPositions[i];
            ImageSource source = onTree.Count > i
                ? FruitAssetCatalog.GetImageSource(onTree[i].FruitId)
                : Asset("fruit.svg");
            bool picking = _fruitPickVisuals.Any(visual => visual.Index == i ||
                (fruitService.IsAvailable && onTree[i].Id == visual.PendingId));
            var fruit = new Image { Width = 24, Height = 28, Source = source, Stretch = Stretch.Uniform,
                Opacity = picking ? 0 : 1 };
            Canvas.SetLeft(fruit, x);
            Canvas.SetTop(fruit, y);
            _fruitLayer.Children.Add(fruit);
        }
        long extra = TreeIllustrationLayout.ExtraFruitCount(
            fruitService.IsAvailable ? onTree.Count : state.FruitInventory);
        if (extra > 0)
        {
            // Keep even a very large inventory inside the artwork's view box.
            var count = new TextBlock
            {
                Text = $"+{extra}",
                Foreground = new SolidColorBrush(Colors.White),
                FontSize = 12,
                FontWeight = Microsoft.UI.Text.FontWeights.SemiBold
            };
            var badge = new Border
            {
                Background = new SolidColorBrush(ColorHelper.FromArgb(240, 39, 92, 61)),
                CornerRadius = new CornerRadius(10),
                Padding = new Thickness(7, 3, 7, 3),
                Child = count
            };
            var fitted = new Viewbox { Width = 104, Height = 25, Stretch = Stretch.Uniform, StretchDirection = StretchDirection.DownOnly, Child = badge };
            Canvas.SetLeft(fitted, 131);
            Canvas.SetTop(fitted, 19);
            _fruitLayer.Children.Add(fitted);
        }
    }

    private void ConfigureMotion()
    {
        DoubleAnimation sway = _swayRotation;
        sway.Duration = new Duration(TimeSpan.FromSeconds(2.8));
        sway.AutoReverse = true;
        sway.RepeatBehavior = RepeatBehavior.Forever;
        sway.EasingFunction = new SineEase { EasingMode = EasingMode.EaseInOut };
        Storyboard.SetTarget(sway, _canopyTransform);
        Storyboard.SetTargetProperty(sway, "Rotation");
        _sway.Children.Add(sway);
        foreach (string property in new[] { "ScaleX", "ScaleY" })
        {
            var bounce = new DoubleAnimation
            {
                From = 1,
                To = 1.045,
                Duration = new Duration(TimeSpan.FromMilliseconds(240)),
                AutoReverse = true,
                EasingFunction = new SineEase { EasingMode = EasingMode.EaseInOut }
            };
            Storyboard.SetTarget(bounce, _treeTransform);
            Storyboard.SetTargetProperty(bounce, property);
            _celebration.Children.Add(bounce);
        }
    }

    private void ConfigureFallingLeaves(int stage)
    {
        _fallingLeaves.Children.Clear();
        _fallingLeafLayer.Children.Clear();
        SvgImageSource source = Asset("falling-leaf.svg");
        foreach (FallingLeaf leaf in TreeIllustrationLayout.FallingLeaves(stage))
        {
            var transform = new CompositeTransform();
            var image = new Image
            {
                Width = 18,
                Height = 12,
                Source = source,
                Opacity = 0,
                RenderTransform = transform,
                RenderTransformOrigin = new Windows.Foundation.Point(.5, .5)
            };
            Canvas.SetLeft(image, leaf.X);
            Canvas.SetTop(image, leaf.Y + TreeIllustrationLayout.TreeVerticalOffset);
            _fallingLeafLayer.Children.Add(image);
            double start = leaf.DelaySeconds;
            double middle = start + leaf.DurationSeconds * .5;
            double end = start + leaf.DurationSeconds;
            double drop = 199 + TreeIllustrationLayout.GroundVerticalOffset -
                leaf.Y - TreeIllustrationLayout.TreeVerticalOffset;
            AddLeafAnimation(transform, "TranslateY", (0, 0), (start, 0),
                (middle, drop * .46), (end, drop));
            AddLeafAnimation(transform, "TranslateX", (0, 0), (start, 0),
                (start + leaf.DurationSeconds * .25, leaf.Drift * .8),
                (start + leaf.DurationSeconds * .6, -leaf.Drift * .2), (end, leaf.Drift));
            AddLeafAnimation(transform, "Rotation", (0, leaf.InitialRotation), (start, leaf.InitialRotation),
                (middle, leaf.InitialRotation + 85), (end, leaf.InitialRotation + 165));
            AddLeafAnimation(image, "Opacity", (0, 0), (start, 0),
                (start + .5, .85), (end - .9, .75), (end, 0));
        }
    }

    private void AddLeafAnimation(DependencyObject target, string property, params (double Seconds, double Value)[] frames)
    {
        var animation = new DoubleAnimationUsingKeyFrames
        {
            Duration = new Duration(TimeSpan.FromSeconds(TreeIllustrationLayout.LeafCycleSeconds))
        };
        double previousTime = -1;
        foreach ((double seconds, double value) in frames)
        {
            if (seconds == previousTime) continue;
            animation.KeyFrames.Add(new EasingDoubleKeyFrame
            {
                KeyTime = KeyTime.FromTimeSpan(TimeSpan.FromSeconds(seconds)),
                Value = value,
                EasingFunction = new SineEase { EasingMode = EasingMode.EaseInOut }
            });
            previousTime = seconds;
        }
        Storyboard.SetTarget(animation, target);
        Storyboard.SetTargetProperty(animation, property);
        _fallingLeaves.Children.Add(animation);
    }

    private void UpdateMotion()
    {
        bool shouldAnimate = !_disposed && _visible && IsLoaded && WindowsCompatibilityService.ShouldAnimate;
        _progress.SetAnimationEnabled(shouldAnimate && _progress.Visibility == Visibility.Visible);
        if (shouldAnimate == _motionRunning) return;
        if (shouldAnimate)
        {
            _sway.Begin();
            if (_fallingLeaves.Children.Count > 0 && !TreeWeatherSceneLayout.SuppressesFallingLeaves(_renderedWeather))
            {
                _fallingLeafLayer.Visibility = Visibility.Visible;
                _fallingLeaves.Begin();
            }
            StartWeatherMotion();
            if (_wildlifeMotion.Children.Count > 0) _wildlifeMotion.Begin();
            _motionRunning = true;
        }
        else StopMotion();
    }

    private void StopMotion()
    {
        _progress.SetAnimationEnabled(false);
        _sway.Stop();
        _celebration.Stop();
        _fallingLeaves.Stop();
        StopWeatherMotion();
        _wildlifeMotion.Stop();
        _fallingLeafLayer.Visibility = Visibility.Collapsed;
        _canopyTransform.Rotation = 0;
        _treeTransform.Rotation = 0;
        _treeTransform.ScaleX = _treeTransform.ScaleY = TreeIllustrationLayout.TreeArtworkScale;
        _motionRunning = false;
    }

    public void Dispose()
    {
        _disposed = true;
        _counter.Changed -= CounterChanged;
        App.Current.FruitService.Changed -= FruitChanged;
        App.Current.WildlifeService.Changed -= FruitChanged;
        _timer.Stop();
        StopMotion();
        ClearFruitPickVisuals();
        _treeWeatherService.Dispose();
        _progress.Dispose();
    }
}
