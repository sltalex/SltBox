using Microsoft.UI.Xaml;

namespace DeskBox.Views;

public sealed partial class SettingsWindow
{
    private int _accountSyncInProgress;

    private async void DingTalkLink_Click(object sender, RoutedEventArgs e) => await ViewModel.LinkDingTalkAsync();
    private async void DingTalkRefresh_Click(object sender, RoutedEventArgs e) => await ViewModel.RefreshDingTalkAsync();
    private async void DingTalkUnlink_Click(object sender, RoutedEventArgs e) => await ViewModel.UnlinkDingTalkAsync();
    private async void AccountSignInButton_Click(object sender, RoutedEventArgs e)
    {
        SetAccountSyncStatus(string.Empty);
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
        SetAccountSyncStatus(string.Empty);
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
        SetAccountSyncStatus(string.Empty);
        AccountPasswordBox.Password = string.Empty;
        await ViewModel.SignOutAccountAsync();
    }

    private async void AccountSyncDataButton_Click(object sender, RoutedEventArgs e)
    {
        string? ownerId = _authSession.CurrentUser?.Id;
        if (ownerId is null || Interlocked.Exchange(ref _accountSyncInProgress, 1) != 0) return;

        AccountSyncDataButton.IsEnabled = false;
        AccountSignOutButton.IsEnabled = false;
        SetAccountSyncStatus(_localizationService.T("Settings.Account.Syncing"));
        try
        {
            bool synchronized = await _treeCloudSyncService.SyncNowAsync();
            if (_authSession.CurrentUser?.Id != ownerId) return;
            SetAccountSyncStatus(_localizationService.T(
                !synchronized ? "Settings.Account.SyncUnavailable" :
                    _treeCloudSyncService.AdditionalDataSynced
                        ? "Settings.Account.SyncSuccess" : "Settings.Account.SyncPartial"));
        }
        catch (Exception ex) when (ex is HttpRequestException or TaskCanceledException)
        {
            App.Log($"[TreeCloud] Manual sync failed: {ex.GetType().Name}");
            if (_authSession.CurrentUser?.Id == ownerId)
                SetAccountSyncStatus(_localizationService.T("Settings.Account.SyncError"));
        }
        catch (Exception ex)
        {
            App.Log($"[TreeCloud] Manual sync failed: {ex}");
            if (_authSession.CurrentUser?.Id == ownerId)
                SetAccountSyncStatus(_localizationService.T("Settings.Account.SyncError"));
        }
        finally
        {
            Interlocked.Exchange(ref _accountSyncInProgress, 0);
            AccountSyncDataButton.IsEnabled = ViewModel.AccountActionsEnabled && _authSession.IsAuthenticated;
            AccountSignOutButton.IsEnabled = ViewModel.AccountActionsEnabled;
        }
    }

    private void SetAccountSyncStatus(string message)
    {
        AccountSyncStatusText.Text = message;
        AccountSyncStatusText.Visibility = string.IsNullOrEmpty(message)
            ? Visibility.Collapsed : Visibility.Visible;
    }

}
