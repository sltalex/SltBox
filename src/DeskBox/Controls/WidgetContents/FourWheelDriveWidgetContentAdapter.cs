using DeskBox.Models;
using DeskBox.Services;
using Microsoft.UI.Xaml;

namespace DeskBox.Controls.WidgetContents;

internal sealed class FourWheelDriveWidgetContentAdapter : WidgetContentAdapterBase
{
    internal FourWheelDriveWidgetContentAdapter(
        WidgetConfig config,
        LocalizationService localization,
        SettingsService? settings)
        : base(config, () => new FourWheelDriveWidgetContent(config, localization, settings))
    {
    }

    public override void ApplyAppearance()
    {
        if (MaterializedView is FourWheelDriveWidgetContent view)
        {
            view.Refresh();
        }
    }

    public override Task RefreshAsync()
    {
        if (MaterializedView is FourWheelDriveWidgetContent view)
        {
            view.Refresh();
        }

        return Task.CompletedTask;
    }
}
