using DeskBox.Controls.WidgetContents;
using DeskBox.Services;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
using Microsoft.UI.Xaml.Input;
using Microsoft.UI.Xaml.Media;
using Microsoft.UI.Xaml.Media.Imaging;
using Windows.Foundation;
using Windows.System;

namespace DeskBox.Controls;

internal sealed partial class TreeDecorationSceneView : UserControl
{
    private readonly Grid _scene = new()
    {
        Width = TreeIllustrationLayout.Width,
        Height = TreeIllustrationLayout.Height,
        Background = new SolidColorBrush(Microsoft.UI.Colors.Transparent)
    };
    private readonly Canvas _themeLayer = Layer();
    private readonly Dictionary<int, Canvas> _layers = Enumerable.Range(-2, 5)
        .ToDictionary(layer => layer, _ => Layer());
    private readonly Dictionary<string, FrameworkElement> _visuals = new(StringComparer.Ordinal);
    private readonly Image _tree = new()
    {
        Source = TreeAsset("stage-5.svg"), Stretch = Stretch.Uniform, IsHitTestVisible = false
    };
    private readonly Image _canopy = new()
    {
        Source = TreeAsset("stage-5-canopy.svg"), Stretch = Stretch.Uniform, IsHitTestVisible = false
    };
    private TreeDecorationSceneDraft? _draft;
    private IReadOnlyDictionary<string, TreeDecorationCatalogItem> _catalog =
        new Dictionary<string, TreeDecorationCatalogItem>();
    private Func<TreeDecorationCatalogItem, string> _displayName = item => item.Name;
    private string? _selectedPlacementId;
    private string? _dragPlacementId;
    private Point _dragOffset;

    internal event Action<string?>? SelectedPlacementChanged;
    internal event Action? SceneEdited;
    internal string? SelectedPlacementId => _selectedPlacementId;

    internal TreeDecorationSceneView()
    {
        IsTabStop = true;
        _scene.Children.Add(_themeLayer);
        _scene.Children.Add(new Image
        {
            Source = TreeAsset("ground.svg"), Width = TreeIllustrationLayout.Width,
            Height = TreeIllustrationLayout.ArtworkHeight, Stretch = Stretch.Uniform,
            VerticalAlignment = VerticalAlignment.Top,
            IsHitTestVisible = false,
            RenderTransform = new TranslateTransform { Y = TreeIllustrationLayout.GroundVerticalOffset }
        });
        _scene.Children.Add(_layers[-2]);
        _scene.Children.Add(_layers[-1]);
        var treeSurface = new Grid
        {
            Width = TreeIllustrationLayout.Width,
            Height = TreeIllustrationLayout.ArtworkHeight,
            VerticalAlignment = VerticalAlignment.Top,
            RenderTransformOrigin = new Point(.5, .86),
            RenderTransform = new CompositeTransform
            {
                ScaleX = TreeIllustrationLayout.TreeArtworkScale,
                ScaleY = TreeIllustrationLayout.TreeArtworkScale,
                TranslateY = TreeIllustrationLayout.TreeVerticalOffset
            },
            IsHitTestVisible = false
        };
        treeSurface.Children.Add(_tree);
        treeSurface.Children.Add(_canopy);
        _scene.Children.Add(treeSurface);
        _scene.Children.Add(_layers[0]);
        _scene.Children.Add(_layers[1]);
        _scene.Children.Add(_layers[2]);
        Content = _scene;
        KeyDown += OnKeyDown;
    }

    internal void Bind(TreeDecorationSceneDraft draft,
        IEnumerable<TreeDecorationCatalogItem> catalog,
        Func<TreeDecorationCatalogItem, string>? displayName = null)
    {
        _draft = draft;
        _catalog = catalog.ToDictionary(item => item.Id, StringComparer.Ordinal);
        _displayName = displayName ?? (item => item.Name);
        if (_selectedPlacementId is not null &&
            !draft.Placements.Any(item => item.PlacementId == _selectedPlacementId))
            _selectedPlacementId = null;
        Render();
    }

    internal void Select(string? placementId)
    {
        _selectedPlacementId = placementId;
        RenderSelection();
        SelectedPlacementChanged?.Invoke(placementId);
    }

    internal void Render()
    {
        _themeLayer.Children.Clear();
        foreach (Canvas layer in _layers.Values) layer.Children.Clear();
        _visuals.Clear();
        if (_draft is null) return;
        if (_draft.ActiveThemeId is string themeId && _catalog.TryGetValue(themeId, out var theme))
        {
            _themeLayer.Children.Add(new Image
            {
                Width = TreeIllustrationLayout.Width,
                Height = TreeIllustrationLayout.ArtworkHeight,
                Source = TreeDecorationAssetCatalog.Source(theme.AssetKey),
                Stretch = Stretch.Fill,
                IsHitTestVisible = false,
                Opacity = .88
            });
        }
        foreach (TreeDecorationPlacement placement in _draft.Placements.OrderBy(item => item.Layer))
        {
            if (!_catalog.TryGetValue(placement.ItemId, out var item) || item.Category == "theme") continue;
            FrameworkElement visual = CreateVisual(item, placement);
            _layers[placement.Layer].Children.Add(visual);
            _visuals[placement.PlacementId] = visual;
        }
        RenderSelection();
    }

    private FrameworkElement CreateVisual(TreeDecorationCatalogItem item, TreeDecorationPlacement placement)
    {
        double size = TreeDecorationAssetCatalog.VisualSize(item.Category);
        var image = new Image
        {
            Width = size, Height = size,
            Source = TreeDecorationAssetCatalog.Source(item.AssetKey),
            Stretch = Stretch.Uniform,
            IsHitTestVisible = false
        };
        var border = new Border
        {
            Width = size, Height = size, Child = image,
            Background = new SolidColorBrush(Microsoft.UI.Colors.Transparent),
            BorderThickness = new Thickness(1.5), CornerRadius = new CornerRadius(5),
            RenderTransformOrigin = new Point(.5, .5),
            RenderTransform = new CompositeTransform
            {
                ScaleX = placement.Scale, ScaleY = placement.Scale, Rotation = placement.Rotation
            },
            Tag = placement.PlacementId,
            IsTabStop = true
        };
        Microsoft.UI.Xaml.Automation.AutomationProperties.SetName(border, _displayName(item));
        Position(border, placement);
        border.PointerPressed += Decoration_PointerPressed;
        border.PointerMoved += Decoration_PointerMoved;
        border.PointerReleased += Decoration_PointerReleased;
        border.PointerCanceled += Decoration_PointerReleased;
        border.GotFocus += (_, _) => Select(placement.PlacementId);
        return border;
    }

    private void Decoration_PointerPressed(object sender, PointerRoutedEventArgs e)
    {
        if (sender is not FrameworkElement { Tag: string placementId } visual || _draft is null) return;
        TreeDecorationPlacement? placement = _draft.Placements.FirstOrDefault(item => item.PlacementId == placementId);
        if (placement is null) return;
        Select(placementId);
        _draft.BeginContinuousEdit();
        Point point = e.GetCurrentPoint(_scene).Position;
        _dragOffset = new Point(point.X - placement.X * _scene.Width, point.Y - placement.Y * _scene.Height);
        _dragPlacementId = placementId;
        visual.CapturePointer(e.Pointer);
        e.Handled = true;
    }

    private void Decoration_PointerMoved(object sender, PointerRoutedEventArgs e)
    {
        if (_draft is null || _dragPlacementId is null || sender is not FrameworkElement visual) return;
        Point point = e.GetCurrentPoint(_scene).Position;
        double x = Math.Clamp((point.X - _dragOffset.X) / _scene.Width, 0, 1);
        double y = Math.Clamp((point.Y - _dragOffset.Y) / _scene.Height, 0, 1);
        if (_draft.MoveWithoutUndo(_dragPlacementId, x, y))
        {
            TreeDecorationPlacement placement = _draft.Placements.First(item => item.PlacementId == _dragPlacementId);
            Position(visual, placement);
        }
        e.Handled = true;
    }

    private void Decoration_PointerReleased(object sender, PointerRoutedEventArgs e)
    {
        if (_dragPlacementId is null) return;
        if (sender is UIElement visual) visual.ReleasePointerCapture(e.Pointer);
        _dragPlacementId = null;
        SceneEdited?.Invoke();
        e.Handled = true;
    }

    private void OnKeyDown(object sender, KeyRoutedEventArgs e)
    {
        if (_draft is null || _selectedPlacementId is null) return;
        TreeDecorationPlacement? current = _draft.Placements.FirstOrDefault(
            item => item.PlacementId == _selectedPlacementId);
        if (current is null) return;
        double step = e.Key is VirtualKey.Left or VirtualKey.Right ? 1d / TreeIllustrationLayout.Width : 0;
        double stepY = e.Key is VirtualKey.Up or VirtualKey.Down ? 1d / TreeIllustrationLayout.Height : 0;
        double x = current.X + (e.Key == VirtualKey.Right ? step : e.Key == VirtualKey.Left ? -step : 0);
        double y = current.Y + (e.Key == VirtualKey.Down ? stepY : e.Key == VirtualKey.Up ? -stepY : 0);
        if (x == current.X && y == current.Y) return;
        _draft.TryUpdate(current.PlacementId, x, y, current.Scale, current.Rotation, current.Layer);
        Render();
        SceneEdited?.Invoke();
        e.Handled = true;
    }

    private void RenderSelection()
    {
        foreach ((string placementId, FrameworkElement visual) in _visuals)
        {
            if (visual is Border border)
                border.BorderBrush = placementId == _selectedPlacementId
                    ? new SolidColorBrush(Microsoft.UI.Colors.DodgerBlue) : null;
        }
    }

    private static void Position(FrameworkElement visual, TreeDecorationPlacement placement)
    {
        Canvas.SetLeft(visual, placement.X * TreeIllustrationLayout.Width - visual.Width / 2);
        Canvas.SetTop(visual, placement.Y * TreeIllustrationLayout.Height - visual.Height / 2);
    }

    private static Canvas Layer() => new()
    {
        Width = TreeIllustrationLayout.Width,
        Height = TreeIllustrationLayout.Height,
        VerticalAlignment = VerticalAlignment.Top
    };

    private static SvgImageSource TreeAsset(string name) =>
        new(new Uri($"ms-appx:///Assets/Tree/{name}"));
}
