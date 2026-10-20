using Microsoft.UI.Xaml;

namespace DeskBox.Views;

public sealed partial class SettingsWindow
{
    private async void DingTalkLink_Click(object sender, RoutedEventArgs e) => await ViewModel.LinkDingTalkAsync();
    private async void DingTalkRefresh_Click(object sender, RoutedEventArgs e) => await ViewModel.RefreshDingTalkAsync();
    private async void DingTalkUnlink_Click(object sender, RoutedEventArgs e) => await ViewModel.UnlinkDingTalkAsync();
    private async void AccountSignInButton_Click(object sender, RoutedEventArgs e)
    {
        try
        {
            await ViewModel.SignInAccountAsync(AccountPasswordBox.Password);
        }
        finally
        {
            AccountPasswordBox.Password = string.Empty;
        }
    }

    private async void AccountRegisterButton_Click(object sender, RoutedEventArgs e)
    {
        try
        {
            await ViewModel.RegisterAccountAsync(AccountPasswordBox.Password);
        }
        finally
        {
            AccountPasswordBox.Password = string.Empty;
        }
    }

    private async void AccountSignOutButton_Click(object sender, RoutedEventArgs e)
    {
        AccountPasswordBox.Password = string.Empty;
        await ViewModel.SignOutAccountAsync();
    }

    private async void AccountSyncDataButton_Click(object sender, RoutedEventArgs e)
    {
        if (!ViewModel.IsAccountAuthenticated) return;

        AccountSyncDataButton.IsEnabled = false;
        AccountSyncStatusText.Text = _localizationService.T("Settings.Account.Syncing");
        try
        {
            bool synchronized = await _treeCloudSyncService.SyncNowAsync();
            AccountSyncStatusText.Text = _localizationService.T(
                synchronized ? "Settings.Account.SyncSuccess" : "Settings.Account.SyncUnavailable");
        }
        catch (Exception ex) when (ex is HttpRequestException or TaskCanceledException)
        {
            App.Log($"[TreeCloud] Manual sync failed: {ex.GetType().Name}");
            AccountSyncStatusText.Text = _localizationService.T("Settings.Account.SyncError");
        }
        catch (Exception ex)
        {
            App.Log($"[TreeCloud] Manual sync failed: {ex}");
            AccountSyncStatusText.Text = _localizationService.T("Settings.Account.SyncError");
        }
        finally
        {
            AccountSyncDataButton.IsEnabled = ViewModel.IsAccountAuthenticated;
        }
    }
}
