using DeskBox.Models;
using DeskBox.Services;
using Microsoft.UI.Xaml;

namespace DeskBox.Controls.WidgetContents;

public sealed class CalendarWidgetContentAdapter : WidgetContentAdapterBase
{
    private bool _visible = true;

    public CalendarWidgetContentAdapter(
        WidgetConfig config,
        LocalizationService localization,
        SettingsService settings,
        Func<WidgetConfig, TodoWidgetStore>? todoStoreFactory = null)
        : base(config, () => new CalendarWidgetContent(localization, settings, todoStoreFactory))
    {
    }

    public override void OnWindowVisibilityChanged(bool visible)
    {
        _visible = visible;
        if (MaterializedView is CalendarWidgetContent view)
            view.SetVisible(visible);
    }

    protected override void OnViewMaterialized(FrameworkElement view)
    {
        if (view is CalendarWidgetContent calendar)
            calendar.SetVisible(_visible);
    }

    protected override void Dispose(bool disposing)
    {
        if (MaterializedView is CalendarWidgetContent view)
            view.Dispose();
    }
}
