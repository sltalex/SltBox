using DeskBox.Services;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
using Microsoft.UI.Xaml.Media;

namespace DeskBox.Controls.WidgetContents;

public sealed partial class TreeWidgetContent
{
    private void VisualSettingsChanged()
    {
        if (_disposed) return;
        _uiQueue.TryEnqueue(ApplyVisualMode);
    }

    private void ApplyVisualMode()
    {
        if (_disposed || !IsLoaded) return;
        if (!_visualSettings.UseThreeD && _threeDFailed)
        {
            if (_tree3D is not null)
            {
                _threeDSurface.Children.Remove(_tree3D);
                _tree3D.Dispose();
                _tree3D = null;
            }
            _threeDFailed = false;
        }
        bool useThreeD = _visualSettings.UseThreeD && !_threeDFailed;
        SetSceneLayout(useThreeD);
        if (!useThreeD)
        {
            _threeDSurface.Visibility = Visibility.Collapsed;
            _drawingViewbox.Visibility = Visibility.Visible;
            if (_tree3D is not null)
            {
                _tree3D.SetVisible(false);
                _tree3D.Visibility = Visibility.Collapsed;
            }
            return;
        }
        try
        {
            _threeDSurface.Visibility = Visibility.Visible;
            if (_tree3D is null)
            {
                _tree3D = new Tree3DView();
                _tree3D.FruitTapped += Tree3DFruitTapped;
                _tree3D.WildlifeTapped += Tree3DWildlifeTappedAsync;
                _tree3D.InitializationFailed += Tree3DInitializationFailed;
                _tree3D.SceneReady += ApplyVisualMode;
                _threeDSurface.Children.Add(_tree3D);
            }
            _tree3D.Visibility = Visibility.Visible;
            _drawingViewbox.Visibility = _tree3D.IsReady ? Visibility.Collapsed : Visibility.Visible;
            _tree3D.SetVisible(_visible);
            _ = _tree3D.InitializeAsync();
            PublishTree3DState(_counter.Snapshot);
        }
        catch (Exception ex) { Tree3DInitializationFailed(ex); }
    }

    private void Tree3DInitializationFailed(Exception exception)
    {
        if (_disposed) return;
        _threeDFailed = true;
        SetSceneLayout(false);
        if (_tree3D is not null)
        {
            _tree3D.SetVisible(false);
            _tree3D.Visibility = Visibility.Collapsed;
        }
        _threeDSurface.Visibility = Visibility.Collapsed;
        _drawingViewbox.Visibility = Visibility.Visible;
        App.Log($"[Tree3D] Falling back to 2D: {exception.GetType().Name}");
        if (s_threeDFailureReported) return;
        s_threeDFailureReported = true;
        if (XamlRoot is not null) _ = ShowThreeDFallbackNoticeAsync();
    }

    private void SetSceneLayout(bool useThreeD)
    {
        _sceneRow.Height = useThreeD ? new GridLength(1, GridUnitType.Star) : GridLength.Auto;
        _threeDSurface.Height = useThreeD ? double.NaN : TreeIllustrationLayout.DisplayHeight;
    }

    private async Task ShowThreeDFallbackNoticeAsync()
    {
        try
        {
            var dialog = new ContentDialog
            {
                XamlRoot = XamlRoot,
                Title = _localization.T("Tree.Settings.ThreeD.Title"),
                Content = new TextBlock
                {
                    Text = _localization.T("Tree.Settings.ThreeD.Fallback"),
                    TextWrapping = TextWrapping.Wrap
                },
                CloseButtonText = _localization.T("Common.Close")
            };
            await dialog.ShowAsync();
        }
        catch (Exception ex) { App.Log($"[Tree3D] Fallback notice failed: {ex.Message}"); }
    }

    private void Tree3DFruitTapped(int index, string expectedPendingId)
    {
        if (_disposed || !_visible || !_visualSettings.UseThreeD || _threeDFailed ||
            _counter.Snapshot.Stage != 5) return;
        TreeProgress state = _counter.Snapshot;
        FruitService fruitService = App.Current.FruitService;
        IReadOnlyList<FruitOnTreeGroup> onTree = fruitService.OnTreeGroups;
        bool fruitAvailable = fruitService.IsAvailable;
        int visible = fruitAvailable ? onTree.Count : 0;
        if (index < 0 || index >= visible) return;
        long pendingId = fruitAvailable ? onTree[index].Id : 0;
        if (expectedPendingId != pendingId.ToString(System.Globalization.CultureInfo.InvariantCulture)) return;
        ImageSource image = fruitAvailable
            ? FruitAssetCatalog.GetImageSource(onTree[index].FruitId)
            : Asset("fruit.svg");
        _ = PickFruitAsync(pendingId, index, image);
    }

    private async Task Tree3DWildlifeTappedAsync(string encounterId)
    {
        if (_disposed || !_visible || !_visualSettings.UseThreeD || _threeDFailed ||
            !string.Equals(_renderedEncounterId, encounterId, StringComparison.Ordinal)) return;
        if (_wildlifeCapture is { } capture) await capture();
    }

    private void PublishTree3DState(TreeProgress progress)
    {
        if (!_visualSettings.UseThreeD || _threeDFailed || _tree3D is null) return;
        FruitService fruitService = App.Current.FruitService;
        IReadOnlyList<FruitOnTreeGroup> onTree = fruitService.OnTreeGroups;
        bool fruitAvailable = fruitService.IsAvailable;
        int visible = progress.Stage == 5 && fruitAvailable ? onTree.Count : 0;
        var fruits = Enumerable.Range(0, visible).Select(index =>
        {
            (double x, double y) = TreeIllustrationLayout.FruitPositionAt(onTree[index].PositionIndex);
            string fruitId = fruitAvailable ? onTree[index].FruitId : "fruit";
            long pendingId = fruitAvailable ? onTree[index].Id : 0;
            string asset = FruitAssetCatalog.ResolveRelativePath(fruitId)?.Replace('\\', '/') ?? "fruit.svg";
            bool picking = _fruitPickVisuals.Any(item => pendingId != 0 && item.PendingId == pendingId);
            return new Tree3DFruitState(index,
                pendingId.ToString(System.Globalization.CultureInfo.InvariantCulture), fruitId, asset,
                x + 12, y + 14 + TreeIllustrationLayout.TreeVerticalOffset, picking,
                onTree[index].Quantity.ToString(System.Globalization.CultureInfo.InvariantCulture));
        }).ToArray();

        Tree3DWildlifeState? wildlife = null;
        WildlifeService wildlifeService = App.Current.WildlifeService;
        if (_visible && wildlifeService.CanOffer && wildlifeService.Encounter is { } encounter &&
            encounter.ExpiresAtUtc > DateTimeOffset.UtcNow &&
            wildlifeService.Catalog.FirstOrDefault(item => item.Id == encounter.SpeciesId) is { } species &&
            WildlifeMotionCatalog.Profiles.ContainsKey(encounter.SpeciesId))
        {
            WildlifeMotionProfile motion = WildlifeMotionCatalog.ForEncounter(
                encounter.SpeciesId, species.Placement, progress.Stage, encounter.Id);
            bool skyFlight = WildlifeMotionCatalog.FliesAcrossSky(encounter.SpeciesId, species.Placement);
            double visualSize = WildlifeMotionCatalog.VisualSize(encounter.SpeciesId, species.Category, progress.Stage);
            double hitSize = WildlifeMotionCatalog.HitTargetSize(visualSize, skyFlight);
            (double left, double top) = WildlifeMotionCatalog.HitTargetPosition(motion, visualSize, skyFlight);
            wildlife = new Tree3DWildlifeState(encounter.Id, encounter.SpeciesId, species.Placement,
                $"Wildlife/Source/{encounter.SpeciesId}.png", left + hitSize / 2, top + hitSize / 2,
                visualSize, hitSize, motion.Seconds, motion.Frames, skyFlight, _capturingEncounterId == encounter.Id);
        }

        TreeDecorationService decorationService = App.Current.TreeDecorationService;
        Tree3DDecorationState[] decorations = [];
        if (decorationService.IsVisibleToCurrentUser)
        {
            TreeDecorationDisplayScene scene = TreeDecorationSceneProjection.ForDesktop(
                progress.Stage, decorationService.State, decorationService.Catalog);
            decorations = scene.Placements.Select(item => new Tree3DDecorationState(
                item.Product.Category, item.Product.AssetKey, $"Decorations/Source/{item.Product.AssetKey}.svg",
                TreeDecorationAssetCatalog.VisualSize(item.Product.Category), item.Placement.X, item.Placement.Y,
                item.Placement.Scale, item.Placement.Rotation, item.Placement.Layer)).ToArray();
        }

        WeatherVisualSnapshot? weather = WeatherVisualStateService.Instance.Snapshot;
        _tree3D.SetSceneState(new Tree3DSceneState(
            progress.Stage, progress.TotalEnergy, progress.ShowEnergy, progress.Paused,
            fruits, wildlife, decorations, weather?.Condition.ToString(), weather?.IsDay ?? true,
            WindowsCompatibilityService.ShouldAnimate && _visible));
        _tree3D.SetAnimationEnabled(WindowsCompatibilityService.ShouldAnimate && _visible);
    }
}
