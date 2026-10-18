using DeskBox.Contracts;
using DeskBox.Controls.WidgetContents;
using DeskBox.Models;

namespace DeskBox.Services;

internal sealed class FourWheelDriveWidgetContentProvider : IWidgetContentProvider
{
    public WidgetKind WidgetKind => WidgetKind.FourWheelDrive;

    public bool CanCreateDetachedContent => true;

    public IWidgetContent CreateDetachedContent(
        WidgetConfig config,
        WidgetContentProviderContext context)
    {
        return new FourWheelDriveWidgetContentAdapter(
            config,
            context.LocalizationService,
            context.SettingsService);
    }
}
