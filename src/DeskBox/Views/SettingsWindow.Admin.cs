using DeskBox.Views.SettingsSections;
using Microsoft.UI.Xaml;

namespace DeskBox.Views;

public sealed partial class SettingsWindow
{
    private bool IsVerifiedAdmin => _authSession.CurrentUser?.Role == "admin";

    private void InitializeAdminNavigation()
    {
        _authSession.SessionChanged += OnAdminSessionChanged;
        RefreshAdminNavigation();
    }

    private void OnAdminSessionChanged()
    {
        DispatcherQueue.TryEnqueue(RefreshAdminNavigation);
    }

    private void RefreshAdminNavigation()
    {
        if (_isClosed) return;
        AdminNavItem.Visibility = IsVerifiedAdmin ? Visibility.Visible : Visibility.Collapsed;
        if (!IsVerifiedAdmin && _currentSettingsSection == "Admin") NavigateToSettingsSection("General");
        RefreshSettingsSearchResults();
    }
}
