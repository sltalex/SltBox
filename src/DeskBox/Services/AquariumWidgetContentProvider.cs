using DeskBox.Contracts;
using DeskBox.Controls.WidgetContents;
using DeskBox.Models;

namespace DeskBox.Services;

internal sealed class AquariumWidgetContentProvider(Func<AquariumService> service, Func<bool> animationEnabled) : IWidgetContentProvider
{
    public WidgetKind WidgetKind => WidgetKind.Aquarium;
    public bool CanCreateDetachedContent => true;
    public IWidgetContent CreateDetachedContent(WidgetConfig config, WidgetContentProviderContext context)
        => new AquariumWidgetContentAdapter(config, service, animationEnabled);
}
