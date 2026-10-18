using DeskBox.Models;
using DeskBox.Services;
using Microsoft.UI.Xaml;

namespace DeskBox.Controls.WidgetContents;

internal sealed class AquariumWidgetContentAdapter : WidgetContentAdapterBase
{
    private bool _visible = true;
    private bool _occluded;
    private bool _compact;

    internal AquariumWidgetContentAdapter(WidgetConfig config, Func<AquariumService> service, Func<bool> animationEnabled)
        : base(config, () => new AquariumWidgetContent(service(), animationEnabled)) { }

    // Focus/activation is intentionally not part of the animation policy:
    // this is a desktop scene, so clicking another application must not freeze
    // it. Window visibility and complete occlusion are handled separately.
    public override void OnActivated() { }
    public override void OnDeactivated() { }
    public override void OnWindowVisibilityChanged(bool visible) { _visible = visible; UpdateVisibility(); }
    public override void OnWindowOcclusionChanged(bool occluded) { _occluded = occluded; UpdateVisibility(); }
    public override void OnCompactStateChanged(bool collapsed) { _compact = collapsed; UpdateVisibility(); }
    public override void ApplyAppearance()
    {
        if (MaterializedView is AquariumWidgetContent view) view.Refresh();
    }

    protected override void OnViewMaterialized(FrameworkElement view) => UpdateVisibility();

    internal bool IsSceneVisible => _visible && !_compact && !_occluded;

    private void UpdateVisibility()
    {
        if (MaterializedView is AquariumWidgetContent view) view.SetVisible(IsSceneVisible);
    }

    protected override void Dispose(bool disposing)
    {
        if (MaterializedView is AquariumWidgetContent view) view.Dispose();
    }
}
