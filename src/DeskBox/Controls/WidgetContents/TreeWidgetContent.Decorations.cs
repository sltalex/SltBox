using System.Globalization;
using DeskBox.Services;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
using Microsoft.UI.Xaml.Media;

namespace DeskBox.Controls.WidgetContents;

public sealed partial class TreeWidgetContent
{
    private readonly Canvas _decorationThemeLayer = DecorationLayer();
    private readonly Dictionary<int, Canvas> _decorationLayers = Enumerable.Range(-2, 5)
        .ToDictionary(layer => layer, _ => DecorationLayer());
    private string _renderedDecorationSignature = string.Empty;

    private void DecorationChanged()
    {
        if (!_disposed && _visible) _uiQueue.TryEnqueue(Refresh);
    }

    private void RenderDecorations(int treeStage)
    {
        TreeDecorationService service = App.Current.TreeDecorationService;
        TreeDecorationState? state = service.State;
        TreeDecorationCatalog? catalog = service.Catalog;
        string signature = BuildDecorationSignature(treeStage, state, catalog);
        if (_renderedDecorationSignature == signature) return;
        _renderedDecorationSignature = signature;
        _decorationThemeLayer.Children.Clear();
        foreach (Canvas layer in _decorationLayers.Values) layer.Children.Clear();
        if (treeStage != 5 || state is not { Mature: true } || catalog is null) return;

        IReadOnlyDictionary<string, TreeDecorationCatalogItem> items = catalog.Items
            .ToDictionary(item => item.Id, StringComparer.Ordinal);
        if (state.ActiveThemeId is string themeId && items.TryGetValue(themeId, out var theme))
        {
            _decorationThemeLayer.Children.Add(new Image
            {
                Width = TreeIllustrationLayout.Width,
                Height = TreeIllustrationLayout.ArtworkHeight,
                Source = TreeDecorationAssetCatalog.Source(theme.AssetKey),
                Stretch = Stretch.Fill,
                IsHitTestVisible = false,
                Opacity = .88
            });
        }

        foreach (TreeDecorationPlacement placement in state.Placements.OrderBy(item => item.Layer))
        {
            if (!items.TryGetValue(placement.ItemId, out var item) || item.Category == "theme") continue;
            double size = TreeDecorationAssetCatalog.VisualSize(item.Category);
            var visual = new Image
            {
                Width = size,
                Height = size,
                Source = TreeDecorationAssetCatalog.Source(item.AssetKey),
                Stretch = Stretch.Uniform,
                IsHitTestVisible = false,
                RenderTransformOrigin = new Windows.Foundation.Point(.5, .5),
                RenderTransform = new CompositeTransform
                {
                    ScaleX = placement.Scale,
                    ScaleY = placement.Scale,
                    Rotation = placement.Rotation
                }
            };
            Canvas.SetLeft(visual, placement.X * TreeIllustrationLayout.Width - size / 2);
            Canvas.SetTop(visual, placement.Y * TreeIllustrationLayout.ArtworkHeight - size / 2);
            _decorationLayers[placement.Layer].Children.Add(visual);
        }
    }

    private static string BuildDecorationSignature(int stage, TreeDecorationState? state,
        TreeDecorationCatalog? catalog)
    {
        if (stage != 5 || state is null || catalog is null) return stage.ToString(CultureInfo.InvariantCulture);
        return string.Join('|', new[]
        {
            stage.ToString(CultureInfo.InvariantCulture),
            catalog.Version.ToString(CultureInfo.InvariantCulture),
            state.SceneVersion.ToString(CultureInfo.InvariantCulture),
            state.ActiveThemeId ?? string.Empty,
            string.Join(';', state.Placements.Select(item => string.Join(',',
                item.PlacementId,
                item.ItemId,
                item.X.ToString("R", CultureInfo.InvariantCulture),
                item.Y.ToString("R", CultureInfo.InvariantCulture),
                item.Scale.ToString("R", CultureInfo.InvariantCulture),
                item.Rotation.ToString("R", CultureInfo.InvariantCulture),
                item.Layer.ToString(CultureInfo.InvariantCulture))))
        });
    }

    private static Canvas DecorationLayer() => new()
    {
        Width = TreeIllustrationLayout.Width,
        Height = TreeIllustrationLayout.ArtworkHeight,
        IsHitTestVisible = false,
        Clip = new RectangleGeometry
        {
            Rect = new Windows.Foundation.Rect(
                0, 0, TreeIllustrationLayout.Width, TreeIllustrationLayout.ArtworkHeight)
        }
    };
}
