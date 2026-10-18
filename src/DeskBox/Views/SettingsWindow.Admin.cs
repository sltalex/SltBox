using DeskBox.Views.SettingsSections;
using Microsoft.UI.Xaml;

namespace DeskBox.Views;

public sealed partial class SettingsWindow
{
    private bool IsVerifiedAdmin => _authSession.IsAuthenticated &&
        _authSession.CurrentUser?.Role == "admin";

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
        bool isAdmin = IsVerifiedAdmin;
        if (!isAdmin && _currentSettingsSection is "Admin" or "AquariumSettings" or "Backpack")
            NavigateToSettingsSection("General");
        if (isAdmin)
        {
            if (!SettingsNavigationView.MenuItems.Contains(BackpackNavItem))
                SettingsNavigationView.MenuItems.Insert(
                    SettingsNavigationView.MenuItems.IndexOf(GeneralNavItem) + 1, BackpackNavItem);
            if (!SettingsNavigationView.MenuItems.Contains(AdminNavItem))
                SettingsNavigationView.MenuItems.Insert(
                    SettingsNavigationView.MenuItems.IndexOf(AboutNavItem), AdminNavItem);
        }
        else
        {
            SettingsNavigationView.MenuItems.Remove(BackpackNavItem);
            SettingsNavigationView.MenuItems.Remove(AdminNavItem);
        }
        BackpackNavItem.Visibility = isAdmin ? Visibility.Visible : Visibility.Collapsed;
        AdminNavItem.Visibility = isAdmin ? Visibility.Visible : Visibility.Collapsed;
        RefreshFeatureWidgetList();
        RefreshSettingsSearchResults();
    }
}
