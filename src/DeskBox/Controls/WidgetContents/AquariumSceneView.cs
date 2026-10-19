using System.Numerics;
using System.Diagnostics;
using DeskBox.Services;
using Microsoft.UI;
using Microsoft.UI.Composition;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
using Microsoft.UI.Xaml.Hosting;
using Microsoft.UI.Xaml.Input;
using Microsoft.UI.Xaml.Media;
using Microsoft.UI.Xaml.Media.Imaging;
using Microsoft.UI.Xaml.Shapes;
using Windows.Foundation;

namespace DeskBox.Controls.WidgetContents;

/// <summary>A bounded bitmap scene shared by the desktop and the layout editor.</summary>
public sealed partial class AquariumSceneView : UserControl, IDisposable
{
    private const double SceneWidth = 840;
    private const double SceneHeight = 600;
    private readonly Canvas _canvas = new() { Width = SceneWidth, Height = SceneHeight };
    private readonly Grid _root = new() { Background = new SolidColorBrush(ColorHelper.FromArgb(255, 9, 42, 49)) };
    private readonly List<(Visual Visual, string Property, CompositionAnimation Animation)> _motions = [];
    private readonly List<DepthMotion> _depthMotions = [];
    private readonly Dictionary<string, SceneObject> _objects = new(StringComparer.Ordinal);
    private IReadOnlyDictionary<string, AquariumFishMotionPlan> _fishPlans = new Dictionary<string, AquariumFishMotionPlan>();
    private readonly List<Image> _images = [];
    private readonly DispatcherTimer _policyTimer = new() { Interval = TimeSpan.FromSeconds(5) };
    private readonly DispatcherTimer _depthTimer = new() { Interval = TimeSpan.FromMilliseconds(250) };
    private int _motionSeed = Random.Shared.Next();

    private sealed class DepthMotion(Visual visual, Border host, AquariumPlacement placement,
        AquariumFishMotionPlan swim, double width, AquariumDepthMotionPlan plan)
    {
        internal Visual Visual { get; } = visual;
        internal Border Host { get; } = host;
        internal AquariumPlacement Placement { get; } = placement;
        internal AquariumFishMotionPlan Swim { get; } = swim;
        internal double Width { get; } = width;
        internal AquariumDepthMotionPlan Plan { get; set; } = plan;
        internal long StartedAt { get; set; }
    }
    private bool _visible = true;
    private bool _animationEnabled = true;
    private bool _motionRunning;
    private bool _disposed;
    private string? _selected;
    private SceneObject? _dragging;
    private Point _dragOrigin;
    private AquariumPlacement? _dragPlacement;

    internal event Action<string?>? SelectionChanged;
    internal event Action<AquariumPlacement>? PlacementChanged;
    internal bool IsEditing { get; set; }

    public AquariumSceneView()
    {
        _root.Children.Add(new Viewbox { Child = _canvas, Stretch = Stretch.Uniform });
        Content = _root;
        _root.SizeChanged += (_, _) => _root.Clip = new RectangleGeometry { Rect = new Rect(0, 0, _root.ActualWidth, _root.ActualHeight) };
        _policyTimer.Tick += (_, _) => UpdateMotion();
        _depthTimer.Tick += (_, _) => UpdateDepthLayers();
        Loaded += (_, _) => UpdateMotion();
        Unloaded += (_, _) => { _policyTimer.Stop(); StopMotion(); };
        Render([], [], []);
    }

    internal void Render(IReadOnlyList<AquariumPlacement> placements,
        IReadOnlyList<AquariumInventoryItem> inventory, IReadOnlyList<AquariumCatalogItem> catalog)
    {
        if (_disposed) return;
        App.Log($"[Aquarium] Scene render placements={placements.Count} inventory={inventory.Count} catalog={catalog.Count} loaded={IsLoaded} visible={_visible}");
        ClearScene();
        var background = new Image { Width = SceneWidth, Height = SceneHeight, Source = Asset("background"), Stretch = Stretch.UniformToFill, IsHitTestVisible = false };
        Canvas.SetZIndex(background, -100);
        _images.Add(background);
        _canvas.Children.Add(background);
        AddCausticLights();
        var products = catalog.ToDictionary(item => item.Id, StringComparer.Ordinal);
        var owned = inventory.ToDictionary(item => item.Id, StringComparer.Ordinal);
        var categories = placements.Where(item => owned.ContainsKey(item.InstanceId))
            .Where(item => products.ContainsKey(owned[item.InstanceId].ItemId))
            .ToDictionary(item => item.InstanceId,
                item => products[owned[item.InstanceId].ItemId].Category, StringComparer.Ordinal);
        IReadOnlyList<AquariumPlacement> visible = AquariumSwimDepth.ForDisplay(
            placements.Where(item => categories.ContainsKey(item.InstanceId)).ToArray(), categories);
        _fishPlans = AquariumFishMotionPlanner.Build(
            visible.Where(item => IsAnimatedCreature(products[owned[item.InstanceId].ItemId].Category))
                .Select(item => new AquariumFishMotionInput(item.InstanceId, owned[item.InstanceId].ItemId,
                    item.X, item.Y, item.Layer)).ToList(), SceneWidth, SceneHeight, NextMotionSeed());
        foreach (AquariumPlacement placement in visible)
        {
            AquariumCatalogItem product = products[owned[placement.InstanceId].ItemId];
            AddObject(placement, product);
        }
        AddBubbles();
        Select(_objects.ContainsKey(_selected ?? string.Empty) ? _selected : null, notify: false);
        UpdateMotion();
    }

    private void AddCausticLights()
    {
        // Slowly moving translucent bands give the otherwise photographic
        // background the soft caustic shimmer seen on a real seabed. They
        // remain a separate layer so the animation policy can pause them
        // together with fish, plants and bubbles.
        for (int index = 0; index < 4; index++)
        {
            var light = new Rectangle
            {
                Width = 170 + index * 24,
                Height = SceneHeight + 120,
                IsHitTestVisible = false,
                Opacity = .16 - index * .018,
                Fill = new LinearGradientBrush
                {
                    StartPoint = new Point(0, 0),
                    EndPoint = new Point(1, 0),
                    GradientStops =
                    {
                        new GradientStop { Offset = 0, Color = ColorHelper.FromArgb(0, 211, 255, 244) },
                        new GradientStop { Offset = .45, Color = ColorHelper.FromArgb(150, 218, 255, 247) },
                        new GradientStop { Offset = 1, Color = ColorHelper.FromArgb(0, 211, 255, 244) }
                    }
                }
            };
            Canvas.SetLeft(light, -180 + index * 250);
            Canvas.SetTop(light, -60);
            Canvas.SetZIndex(light, -99);
            _canvas.Children.Add(light);
            ElementCompositionPreview.SetIsTranslationEnabled(light, true);
            Visual visual = ElementCompositionPreview.GetElementVisual(light);
            visual.CenterPoint = new Vector3(85 + index * 12, (float)(SceneHeight / 2), 0);
            double duration = 18 + index * 3.5;
            AddScalar(visual, "Translation.X", duration,
                (0, -55), (.5f, 80), (1, -55));
            AddScalar(visual, "RotationAngleInDegrees", duration + 3,
                (0, -9 + index), (.5f, 7 - index), (1, -9 + index));
        }
    }

    internal void SetAnimationEnabled(bool enabled)
    {
        _animationEnabled = enabled;
        UpdateMotion();
    }

    public void SetVisible(bool visible)
    {
        _visible = visible;
        UpdateMotion();
    }

    internal void UpdatePlacement(AquariumPlacement placement)
    {
        if (!_objects.TryGetValue(placement.InstanceId, out SceneObject? item)) return;
        item.Placement = placement;
        Arrange(item);
    }

    internal void Select(string? instanceId, bool notify = true)
    {
        _selected = instanceId;
        foreach (SceneObject item in _objects.Values)
            item.Host.BorderThickness = new Thickness(IsEditing && item.Placement.InstanceId == instanceId ? 2 : 0);
        if (notify) SelectionChanged?.Invoke(instanceId);
    }

    private void AddObject(AquariumPlacement placement, AquariumCatalogItem product)
    {
        bool animatedCreature = IsAnimatedCreature(product.Category);
        (double width, double height) = AquariumArtworkCatalog.TryGetProfile(product.AssetKey, out var artwork)
            ? (artwork.Width, artwork.Height) : product.Id switch
        {
            // Keep the fish subordinate to the scene so the aquarium reads
            // as a wide slice of seabed rather than a close-up tank.
            "clownfish" => (88, 59), "blue-tang" => (106, 71), "butterflyfish" => (94, 78),
            "angelfish" => (96, 112), "lionfish" => (112, 86), "pufferfish" => (92, 92),
            "seahorse" => (82, 142),
            "yellow-tang" => (112, 82), "royal-gramma" => (112, 68),
            "mandarinfish" => (104, 76), "moorish-idol" => (112, 92),
            "cardinalfish" => (104, 76),
            "seagrass" => (150, 220), "fan-algae" => (186, 210),
            "anubias" => (180, 170), "java-fern" => (170, 190), "hornwort" => (180, 190),
            "amazon-sword" => (170, 220), "vallisneria" => (170, 220),
            "cherry-shrimp" => (108, 74), "amano-shrimp" => (108, 74),
            "hermit-crab" => (126, 105), "fiddler-crab" => (142, 105),
            "porcelain-crab" => (150, 110), "emerald-crab" => (150, 110),
            "scallop" => (170, 136), "oyster" => (170, 136),
            "rock-arch" => (238, 170), _ => (220, 154)
        };
        var image = new Image { Source = Asset(product.AssetKey), Stretch = Stretch.Uniform, IsHitTestVisible = false };
        _images.Add(image);
        var host = new Border
        {
            Child = image, Background = new SolidColorBrush(Colors.Transparent),
            BorderBrush = new SolidColorBrush(ColorHelper.FromArgb(220, 185, 241, 234)),
            CornerRadius = new CornerRadius(8), IsHitTestVisible = IsEditing && !animatedCreature
        };
        var item = new SceneObject(placement, product, host, width, height);
        _objects.Add(placement.InstanceId, item);
        Canvas.SetZIndex(host, placement.Layer);
        _canvas.Children.Add(host);
        Arrange(item);
        Microsoft.UI.Xaml.Automation.AutomationProperties.SetName(host, product.Name);
        if (IsEditing && !animatedCreature)
        {
            host.PointerPressed += (_, args) => BeginDrag(item, args);
            host.PointerMoved += (_, args) => MoveDrag(item, args);
            host.PointerReleased += (_, args) => EndDrag(item, args);
            host.PointerCanceled += (_, args) => EndDrag(item, args);
            host.PointerCaptureLost += (_, _) => { _dragging = null; _dragPlacement = null; };
        }
        Visual visual = ElementCompositionPreview.GetElementVisual(host);
        ElementCompositionPreview.SetIsTranslationEnabled(host, true);
        visual.CenterPoint = new Vector3((float)(width * placement.Scale / 2),
            (float)(height * placement.Scale * AquariumArtworkCatalog.VerticalAnchor(product.AssetKey, animatedCreature)), 0);
        if (animatedCreature)
        {
            AddRandomFishMotion(visual, host, placement, width, height);
        }
        else if (product.Category is "plant" or "algae")
            AddPlantSway(visual, placement);
    }

    private static int StableSeed(string value)
        => (int)(AquariumSwimDepth.StableHash(value) & 0x7fffffff);

    private static bool IsAnimatedCreature(string category) => AquariumSwimDepth.IsSwimming(category);

    private void AddPlantSway(Visual visual, AquariumPlacement placement)
    {
        // Rooted plants sway from the base; floating plants use their leaf
        // plane as the pivot so dangling roots remain below the water surface.
        int seed = StableSeed(placement.InstanceId);
        float amplitude = 3.5f + seed % 25 / 10f;
        double seconds = 5.5 + seed % 35 / 10d;
        AddScalar(visual, "RotationAngleInDegrees", seconds,
            (0, -amplitude), (.24f, amplitude * .65f), (.5f, amplitude),
            (.76f, -amplitude * .55f), (1, -amplitude));
    }

    private void AddRandomFishMotion(Visual visual, Border host, AquariumPlacement placement, double width, double height)
    {
        if (_fishPlans.TryGetValue(placement.InstanceId, out AquariumFishMotionPlan? plan))
        {
            AquariumDepthMotionPlan depth = AquariumDepthMotionPlanner.Build(plan, placement,
                width * placement.Scale, SceneWidth, SceneHeight, NextMotionSeed());
            AddDepthAnimations(visual, depth);
            _depthMotions.Add(new DepthMotion(visual, host, placement, plan,
                width * placement.Scale, depth));
            return;
        }
        var random = new Random(StableSeed(placement.InstanceId) ^ _motionSeed);
        double startX = placement.X * SceneWidth;
        double startY = placement.Y * SceneHeight;
        int segments = 5;
        double[] durations = Enumerable.Range(0, segments)
            .Select(_ => .75 + random.NextDouble() * 1.35)
            .ToArray();
        double restoreTurn = .72;
        double total = durations.Sum() + restoreTurn;
        double elapsed = 0;
        var path = new List<(float Progress, Vector3 Value)> { (0, Vector3.Zero) };
        int initialFacing = random.Next(2) == 0 ? -1 : 1;
        int currentFacing = initialFacing;
        var facing = new List<(float Progress, float Value)> { (0, currentFacing) };
        double currentX = startX;
        double currentY = startY;
        // A swimming creature drifts within its depth band while travelling
        // mainly horizontally, rather than crossing the whole water column.
        (double minY, double maxY) = AquariumSwimDepth.Band(placement.Layer);
        double swimTop = minY * SceneHeight;
        double swimBottom = maxY * SceneHeight;

        for (int segment = 1; segment <= segments; segment++)
        {
            elapsed += durations[segment - 1];
            float progress = (float)(elapsed / total);
            double targetX;
            double targetY;
            if (segment == segments)
            {
                // Return to the starting point so the loop is seamless.
                targetX = startX;
                targetY = startY;
            }
            else
            {
                // Change direction occasionally, but keep every leg mainly
                // horizontal so the fish never appears to swim backwards.
                if (segment > 1 && random.NextDouble() < .30)
                    currentFacing = -currentFacing;
                double travel = 150 + random.NextDouble() * 250;
                bool leaveFrame = random.NextDouble() < .36;
                targetX = leaveFrame
                    ? (currentFacing < 0
                        ? -width * (1.2 + random.NextDouble() * 2.3)
                        : SceneWidth + width * (1.2 + random.NextDouble() * 2.3))
                    : currentX + currentFacing * travel;
                targetY = Math.Clamp(currentY + NextRange(random, -45, 45), swimTop, swimBottom);
            }

            double offsetX = targetX - startX;
            double offsetY = targetY - startY;
            path.Add((progress, new Vector3((float)offsetX, (float)offsetY, 0)));
            int segmentFacing = Math.Abs(targetX - currentX) > 4
                ? (targetX >= currentX ? 1 : -1)
                : currentFacing;
            // Hold the old facing until the waypoint, then flip at the
            // waypoint. The final waypoint keeps the direction of travel;
            // the initial orientation is restored during a stationary turn.
            facing.Add((Math.Max(0, progress - .015f), currentFacing));
            facing.Add((progress, segmentFacing));
            currentFacing = segmentFacing;
            currentX = targetX;
            currentY = targetY;
        }

        float restoreStart = (float)(durations.Sum() / total);
        facing.Add((restoreStart, currentFacing));
        facing.Add((1, initialFacing));

        double seconds = 18 + random.NextDouble() * 18;
        AddVector(visual, "Translation", seconds, path.ToArray(), linear: true);
        AddScalar(visual, "Scale.X", seconds, facing.ToArray());
    }

    private static double NextRange(Random random, double minimum, double maximum) =>
        minimum + random.NextDouble() * Math.Max(0, maximum - minimum);

    private int NextMotionSeed() => unchecked(_motionSeed++);

    private static void Arrange(SceneObject item)
    {
        double width = item.Width * item.Placement.Scale;
        double height = item.Height * item.Placement.Scale;
        item.Host.Width = width;
        item.Host.Height = height;
        Canvas.SetLeft(item.Host, item.Placement.X * SceneWidth - width / 2);
        Canvas.SetTop(item.Host, item.Placement.Y * SceneHeight - height *
            AquariumArtworkCatalog.VerticalAnchor(item.Product.AssetKey, IsAnimatedCreature(item.Product.Category)));
    }

    private void BeginDrag(SceneObject item, PointerRoutedEventArgs args)
    {
        if (!args.GetCurrentPoint(_canvas).Properties.IsLeftButtonPressed) return;
        Select(item.Placement.InstanceId);
        _dragging = item;
        _dragOrigin = args.GetCurrentPoint(_canvas).Position;
        _dragPlacement = item.Placement;
        item.Host.CapturePointer(args.Pointer);
        args.Handled = true;
    }

    private void MoveDrag(SceneObject item, PointerRoutedEventArgs args)
    {
        if (!ReferenceEquals(_dragging, item) || _dragPlacement is null) return;
        Point position = args.GetCurrentPoint(_canvas).Position;
        double horizontalMargin = Math.Min(.45, item.Host.Width / (2 * SceneWidth));
        var placement = _dragPlacement with
        {
            X = Math.Clamp(_dragPlacement.X + (position.X - _dragOrigin.X) / SceneWidth, horizontalMargin, 1 - horizontalMargin),
            Y = AquariumArtworkCatalog.ClampPlacementY(item.Product.AssetKey, IsAnimatedCreature(item.Product.Category),
                _dragPlacement.Y + (position.Y - _dragOrigin.Y) / SceneHeight, item.Host.Height / SceneHeight)
        };
        UpdatePlacement(placement);
        PlacementChanged?.Invoke(placement);
        args.Handled = true;
    }

    private void EndDrag(SceneObject item, PointerRoutedEventArgs args)
    {
        if (!ReferenceEquals(_dragging, item)) return;
        _dragging = null;
        _dragPlacement = null;
        item.Host.ReleasePointerCapture(args.Pointer);
        args.Handled = true;
    }

    private void AddBubbles()
    {
        for (int index = 0; index < 14; index++)
        {
            double size = 5 + index % 5 * 2;
            var bubble = new Ellipse
            {
                Width = size, Height = size, IsHitTestVisible = false,
                Stroke = new SolidColorBrush(ColorHelper.FromArgb(150, 205, 246, 247)), StrokeThickness = 1.1,
                Fill = new SolidColorBrush(ColorHelper.FromArgb(22, 225, 255, 255))
            };
            Canvas.SetLeft(bubble, 40 + index * 57 % 760);
            Canvas.SetTop(bubble, SceneHeight + 20);
            Canvas.SetZIndex(bubble, 100);
            _canvas.Children.Add(bubble);
            ElementCompositionPreview.SetIsTranslationEnabled(bubble, true);
            Visual visual = ElementCompositionPreview.GetElementVisual(bubble);
            double seconds = 10 + index % 6 * 1.3;
            var up = visual.Compositor.CreateScalarKeyFrameAnimation();
            up.Duration = TimeSpan.FromSeconds(seconds);
            up.DelayTime = TimeSpan.FromSeconds(index * .65);
            up.IterationBehavior = AnimationIterationBehavior.Forever;
            up.InsertKeyFrame(0, 0);
            up.InsertKeyFrame(1, -660, visual.Compositor.CreateLinearEasingFunction());
            _motions.Add((visual, "Translation.Y", up));
            AddScalar(visual, "Translation.X", seconds, (0, 0), (.33f, 9), (.67f, -7), (1, 0));
        }
    }

    private void AddScalar(Visual visual, string property, double seconds, params (float Progress, float Value)[] frames)
        => AddScalar(visual, property, seconds, repeat: true, frames);

    private void AddScalar(Visual visual, string property, double seconds, bool repeat,
        params (float Progress, float Value)[] frames)
    {
        var animation = visual.Compositor.CreateScalarKeyFrameAnimation();
        animation.Duration = TimeSpan.FromSeconds(seconds);
        animation.IterationBehavior = repeat ? AnimationIterationBehavior.Forever : AnimationIterationBehavior.Count;
        if (!repeat)
        {
            animation.IterationCount = 1;
            animation.StopBehavior = AnimationStopBehavior.SetToFinalValue;
        }
        var ease = visual.Compositor.CreateCubicBezierEasingFunction(new Vector2(.42f, 0), new Vector2(.58f, 1));
        foreach (var frame in frames) animation.InsertKeyFrame(frame.Progress, frame.Value, ease);
        _motions.Add((visual, property, animation));
    }

    private void AddVector(Visual visual, string property, double seconds,
        (float Progress, Vector3 Value)[] frames, bool linear = false, bool repeat = true)
    {
        var animation = visual.Compositor.CreateVector3KeyFrameAnimation();
        animation.Duration = TimeSpan.FromSeconds(seconds);
        animation.IterationBehavior = repeat ? AnimationIterationBehavior.Forever : AnimationIterationBehavior.Count;
        if (!repeat)
        {
            animation.IterationCount = 1;
            animation.StopBehavior = AnimationStopBehavior.SetToFinalValue;
        }
        CompositionEasingFunction ease = linear
            ? visual.Compositor.CreateLinearEasingFunction()
            : visual.Compositor.CreateCubicBezierEasingFunction(new Vector2(.42f, 0), new Vector2(.58f, 1));
        foreach (var frame in frames) animation.InsertKeyFrame(frame.Progress, frame.Value, ease);
        _motions.Add((visual, property, animation));
    }

    private void AddDepthAnimations(Visual visual, AquariumDepthMotionPlan depth)
    {
        AddVector(visual, "Translation", depth.DurationSeconds, depth.Translation.ToArray(), linear: true, repeat: false);
        AddScalar(visual, "Scale.X", depth.DurationSeconds, repeat: false, depth.HorizontalScale.ToArray());
        AddScalar(visual, "Scale.Y", depth.DurationSeconds, repeat: false, depth.VerticalScale.ToArray());
    }

    private void UpdateMotion()
    {
        bool eligible = !_disposed && _visible && IsLoaded && _animationEnabled;
        if (eligible) _policyTimer.Start(); else _policyTimer.Stop();
        bool animate = eligible && WindowsCompatibilityService.ShouldAnimate;
        if (animate == _motionRunning) return;
        App.Log($"[Aquarium] Motion {(animate ? "start" : "stop")} eligible={eligible} loaded={IsLoaded} visible={_visible} enabled={_animationEnabled} system={WindowsCompatibilityService.ShouldAnimate} motions={_motions.Count}");
        if (!animate) { StopMotion(); return; }
        foreach (var motion in _motions) motion.Visual.StartAnimation(motion.Property, motion.Animation);
        long startedAt = Stopwatch.GetTimestamp();
        foreach (DepthMotion motion in _depthMotions) motion.StartedAt = startedAt;
        _motionRunning = true;
        if (_depthMotions.Count > 0) _depthTimer.Start();
    }

    private void UpdateDepthLayers()
    {
        if (!_motionRunning) return;
        foreach (DepthMotion motion in _depthMotions)
        {
            double elapsed = Stopwatch.GetElapsedTime(motion.StartedAt).TotalSeconds;
            if (elapsed >= motion.Plan.DurationSeconds)
            {
                int nextInitialLayer = motion.Plan.LayerChanges[^1].Layer;
                foreach (var active in _motions.Where(item => item.Visual == motion.Visual &&
                    item.Property is "Translation" or "Scale.X" or "Scale.Y").ToArray())
                {
                    motion.Visual.StopAnimation(active.Property);
                    active.Animation.Dispose();
                    _motions.Remove(active);
                }
                motion.Plan = AquariumDepthMotionPlanner.Build(motion.Swim, motion.Placement,
                    motion.Width, SceneWidth, SceneHeight, NextMotionSeed(), nextInitialLayer);
                AddDepthAnimations(motion.Visual, motion.Plan);
                foreach (var active in _motions.Where(item => item.Visual == motion.Visual &&
                    item.Property is "Translation" or "Scale.X" or "Scale.Y"))
                    active.Visual.StartAnimation(active.Property, active.Animation);
                motion.StartedAt = Stopwatch.GetTimestamp();
                elapsed = 0;
            }
            int layer = motion.Plan.LayerAt(elapsed);
            if (Canvas.GetZIndex(motion.Host) != layer) Canvas.SetZIndex(motion.Host, layer);
        }
    }

    private void StopMotion()
    {
        _depthTimer.Stop();
        foreach (var motion in _motions)
        {
            motion.Visual.StopAnimation(motion.Property);
            if (motion.Property is "Scale.X" or "Scale.Y") motion.Visual.Scale = Vector3.One;
            else if (motion.Property == "RotationAngleInDegrees") motion.Visual.RotationAngleInDegrees = 0;
        }
        foreach (DepthMotion motion in _depthMotions)
            Canvas.SetZIndex(motion.Host, motion.Plan.LayerChanges[0].Layer);
        _motionRunning = false;
    }

    private void ClearScene()
    {
        StopMotion();
        foreach (var motion in _motions) motion.Animation.Dispose();
        _motions.Clear();
        _depthMotions.Clear();
        foreach (Image image in _images) image.Source = null;
        _images.Clear();
        _objects.Clear();
        _canvas.Children.Clear();
        _dragging = null;
        _dragPlacement = null;
    }

    internal static BitmapImage Asset(string key)
    {
        string file = System.IO.Path.GetFileNameWithoutExtension(key);
        return new BitmapImage(new Uri($"ms-appx:///{AquariumArtworkCatalog.AssetRelativePath(file)}"));
    }

    public void Dispose()
    {
        if (_disposed) return;
        _disposed = true;
        _policyTimer.Stop();
        ClearScene();
        SelectionChanged = null;
        PlacementChanged = null;
    }

    private sealed class SceneObject(AquariumPlacement placement, AquariumCatalogItem product, Border host, double width, double height)
    {
        public AquariumPlacement Placement { get; set; } = placement;
        public AquariumCatalogItem Product { get; } = product;
        public Border Host { get; } = host;
        public double Width { get; } = width;
        public double Height { get; } = height;
    }
}
