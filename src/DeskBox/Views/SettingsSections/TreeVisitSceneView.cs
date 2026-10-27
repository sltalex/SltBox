using DeskBox.Controls.WidgetContents;
using DeskBox.Services;
using Microsoft.UI;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
using Microsoft.UI.Xaml.Media;
using Microsoft.UI.Xaml.Media.Imaging;

namespace DeskBox.Views.SettingsSections;

internal sealed partial class TreeVisitSceneView : UserControl
{
    internal TreeVisitSceneView(TreeVisitSnapshot snapshot, TreeDecorationCatalog? catalog)
    {
        var drawing = new Grid
        {
            Width = TreeIllustrationLayout.Width,
            Height = TreeIllustrationLayout.Height,
            IsHitTestVisible = false
        };
        var layers = Enumerable.Range(-2, 5).ToDictionary(index => index, _ => Layer());
        Canvas themeLayer = Layer();
        drawing.Children.Add(themeLayer);
        drawing.Children.Add(new Image
        {
            Source = Asset("ground.svg"), Width = TreeIllustrationLayout.Width,
            Height = TreeIllustrationLayout.ArtworkHeight, Stretch = Stretch.Uniform,
            VerticalAlignment = VerticalAlignment.Top,
            RenderTransform = new TranslateTransform { Y = TreeIllustrationLayout.GroundVerticalOffset }
        });

        var tree = new Grid
        {
            Width = TreeIllustrationLayout.Width,
            Height = TreeIllustrationLayout.ArtworkHeight,
            VerticalAlignment = VerticalAlignment.Top,
            RenderTransform = new TranslateTransform { Y = TreeIllustrationLayout.TreeVerticalOffset }
        };
        tree.Children.Add(new Image { Source = Asset(TreeIllustrationLayout.StageAssetName(snapshot.Stage)) });
        var canopy = new Grid();
        canopy.Children.Add(new Image { Source = Asset(TreeIllustrationLayout.CanopyAssetName(snapshot.Stage)) });
        var fruitLayer = new Canvas();
        if (snapshot.Stage == 5)
        {
            int count = Math.Min(snapshot.Fruits.Length, TreeIllustrationLayout.MaxVisibleFruit);
            for (int index = 0; index < count; index++)
            {
                (double x, double y) = TreeIllustrationLayout.FruitPositions[index];
                var fruit = new Image
                {
                    Width = 24, Height = 28, Stretch = Stretch.Uniform,
                    Source = FruitAssetCatalog.GetImageSource(snapshot.Fruits[index])
                };
                Canvas.SetLeft(fruit, x);
                Canvas.SetTop(fruit, y);
                fruitLayer.Children.Add(fruit);
            }
            long extra = TreeIllustrationLayout.ExtraFruitCount(snapshot.FruitOnTreeCount);
            if (extra > 0)
            {
                var badge = new Border
                {
                    Background = new SolidColorBrush(ColorHelper.FromArgb(240, 39, 92, 61)),
                    CornerRadius = new CornerRadius(10),
                    Padding = new Thickness(7, 3, 7, 3),
                    Child = new TextBlock
                    {
                        Text = $"+{extra}", Foreground = new SolidColorBrush(Colors.White),
                        FontSize = 12, FontWeight = Microsoft.UI.Text.FontWeights.SemiBold
                    }
                };
                var fitted = new Viewbox
                {
                    Width = 104, Height = 25, Stretch = Stretch.Uniform,
                    StretchDirection = StretchDirection.DownOnly, Child = badge
                };
                Canvas.SetLeft(fitted, 131);
                Canvas.SetTop(fitted, 19);
                fruitLayer.Children.Add(fitted);
            }
        }
        canopy.Children.Add(fruitLayer);
        tree.Children.Add(canopy);

        drawing.Children.Add(layers[-2]);
        drawing.Children.Add(layers[-1]);
        drawing.Children.Add(tree);
        drawing.Children.Add(layers[0]);
        var wildlifeLayer = Layer();
        drawing.Children.Add(wildlifeLayer);
        drawing.Children.Add(layers[1]);
        drawing.Children.Add(layers[2]);

        if (snapshot.WildlifeSpeciesId is string speciesId &&
            App.Current.WildlifeService.Catalog.FirstOrDefault(item => item.Id == speciesId) is { } species &&
            WildlifeAssetCatalog.GetImage(speciesId) is { } wildlifeSource &&
            WildlifeMotionCatalog.Profiles.ContainsKey(speciesId))
        {
            WildlifeMotionProfile motion = WildlifeMotionCatalog.ForStage(speciesId, snapshot.Stage);
            double size = WildlifeMotionCatalog.VisualSize(speciesId, species.Category, snapshot.Stage);
            (double left, double top) = WildlifeMotionCatalog.HitTargetPosition(
                motion, size, WildlifeMotionCatalog.FliesAcrossSky(speciesId));
            double inset = (Math.Max(size, 56) - size) / 2;
            var wildlife = new Image { Width = size, Height = size, Source = wildlifeSource, Stretch = Stretch.Uniform };
            Canvas.SetLeft(wildlife, left + inset);
            Canvas.SetTop(wildlife, top + inset);
            wildlifeLayer.Children.Add(wildlife);
        }

        if (snapshot.Stage == 5 && catalog is not null)
        {
            var placements = snapshot.Placements
                .Select(item => new TreeDecorationPlacement(string.Empty, item.ItemId,
                    item.X, item.Y, item.Scale, item.Rotation, item.Layer))
                .Where(TreeDecorationPlacementRules.IsValid).ToArray();
            var state = new TreeDecorationState(true, 0, 0, 0, [], [], placements, snapshot.ActiveThemeId);
            TreeDecorationDisplayScene scene = TreeDecorationSceneProjection.ForDesktop(snapshot.Stage, state, catalog);
            if (scene.Theme is { } theme)
                themeLayer.Children.Add(new Image
                {
                    Width = TreeIllustrationLayout.Width, Height = TreeIllustrationLayout.ArtworkHeight,
                    Stretch = Stretch.Fill, Source = TreeDecorationAssetCatalog.Source(theme.AssetKey), Opacity = .88
                });
            foreach ((TreeDecorationPlacement placement, TreeDecorationCatalogItem item) in scene.Placements)
            {
                double size = TreeDecorationAssetCatalog.VisualSize(item.Category);
                var visual = new Image
                {
                    Width = size, Height = size, Stretch = Stretch.Uniform,
                    Source = TreeDecorationAssetCatalog.Source(item.AssetKey),
                    RenderTransformOrigin = new Windows.Foundation.Point(.5, .5),
                    RenderTransform = new CompositeTransform
                    {
                        ScaleX = placement.Scale, ScaleY = placement.Scale, Rotation = placement.Rotation
                    }
                };
                Canvas.SetLeft(visual, placement.X * TreeIllustrationLayout.Width - size / 2);
                Canvas.SetTop(visual, placement.Y * TreeIllustrationLayout.Height - size / 2);
                layers[placement.Layer].Children.Add(visual);
            }
        }

        var panel = new StackPanel { Spacing = 2, IsHitTestVisible = false };
        panel.Children.Add(new Viewbox
        {
            Width = 390, Height = 390, Stretch = Stretch.Uniform,
            Child = drawing, IsHitTestVisible = false
        });
        if (TreeIllustrationLayout.ShowsStageProgress(snapshot.Stage))
        {
            var progress = new TreeBubbleProgressBar
            {
                Margin = new Thickness(16, 8, 16, 4),
                Value = Math.Clamp(100d * (snapshot.TotalEnergy -
                    (long)(Math.Max(1, snapshot.Stage) - 1) * TreeProgress.StageEnergyInterval)
                    / TreeProgress.StageEnergyInterval, 0, 100)
            };
            panel.Children.Add(progress);
        }
        Content = panel;
    }

    private static SvgImageSource Asset(string name) => new(new Uri($"ms-appx:///Assets/Tree/{name}"));

    private static Canvas Layer() => new()
    {
        Width = TreeIllustrationLayout.Width,
        Height = TreeIllustrationLayout.Height,
        VerticalAlignment = VerticalAlignment.Top,
        IsHitTestVisible = false
    };
}
