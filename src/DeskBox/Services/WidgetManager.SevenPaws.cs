using DeskBox.Models;

namespace DeskBox.Services;

public sealed partial class WidgetManager
{
    private SevenPawsDesktopService? _sevenPawsService;
    private SevenPawsDesktopService _sevenPawsDesktop => _sevenPawsService ?? throw new InvalidOperationException("The desktop cat service has not been configured.");
    internal void ConfigureSevenPaws(SevenPawsDesktopService service) => _sevenPawsService = service;

    private async Task<IDesktopWidgetWindow?> OpenSevenPawsDesktopAsync(bool reveal)
    {
        await SetSevenPawsDesktopEnabledAsync(true, reveal);
        return null;
    }

    private async Task SetSevenPawsDesktopEnabledAsync(bool enabled, bool reveal = true)
    {
        await DetachFeatureWidgetsFromGroupsAsync(WidgetKind.SevenPaws);
        CloseLoadedFeatureWidgetWindows(WidgetKind.SevenPaws);
        foreach (var config in _settingsService.Settings.WidgetLayout.Widgets.Where(widget => widget.WidgetKind == WidgetKind.SevenPaws))
            config.IsVisible = false;
        if (enabled) await _sevenPawsDesktop.OpenAsync();
        else _sevenPawsDesktop.Stop();
        await CommitFeatureWidgetStateAsync(WidgetKind.SevenPaws, enabled);
        await _settingsService.SaveAsync();
    }
}
