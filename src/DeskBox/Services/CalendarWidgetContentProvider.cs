using DeskBox.Contracts;
using DeskBox.Controls.WidgetContents;
using DeskBox.Models;

namespace DeskBox.Services;

internal sealed class CalendarWidgetContentProvider : IWidgetContentProvider
{
    public WidgetKind WidgetKind => WidgetKind.Calendar;
    public bool CanCreateDetachedContent => true;

    public IWidgetContent CreateDetachedContent(WidgetConfig config, WidgetContentProviderContext context)
        => new CalendarWidgetContentAdapter(
            config,
            context.LocalizationService,
            context.SettingsService ?? App.Current.SettingsService,
            context.TodoStoreFactory);
}
