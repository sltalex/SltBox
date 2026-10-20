using DeskBox.Views.SettingsSections;
using Microsoft.UI.Xaml;

namespace DeskBox.Views;

public sealed partial class SettingsWindow
{
    private string? _adminIdentity;

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
        string? adminIdentity = isAdmin ? _authSession.CurrentUser?.Id : null;
        if (!string.Equals(_adminIdentity, adminIdentity, StringComparison.Ordinal) &&
            _settingsSectionElements.TryGetValue("Admin", out var adminElement) &&
            adminElement is AdminSettingsSection adminSection)
        {
            adminSection.ResetForSession();
        }
        _adminIdentity = adminIdentity;
        if (!isAdmin && _currentSettingsSection is ("Admin" or "AquariumSettings" or "Backpack" or "AquariumStore"))
            NavigateToSettingsSection("General");
        if (isAdmin)
        {
            if (!SettingsNavigationView.MenuItems.Contains(BackpackNavItem))
                SettingsNavigationView.MenuItems.Insert(
                    SettingsNavigationView.MenuItems.IndexOf(GeneralNavItem) + 1, BackpackNavItem);
            if (!SettingsNavigationView.MenuItems.Contains(AquariumStoreNavItem))
                SettingsNavigationView.MenuItems.Insert(
                    SettingsNavigationView.MenuItems.IndexOf(BackpackNavItem) + 1, AquariumStoreNavItem);
            if (!SettingsNavigationView.MenuItems.Contains(AdminNavItem))
                SettingsNavigationView.MenuItems.Insert(
                    SettingsNavigationView.MenuItems.IndexOf(AboutNavItem), AdminNavItem);
        }
        else
        {
            SettingsNavigationView.MenuItems.Remove(BackpackNavItem);
            SettingsNavigationView.MenuItems.Remove(AquariumStoreNavItem);
            SettingsNavigationView.MenuItems.Remove(AdminNavItem);
        }
        BackpackNavItem.Visibility = isAdmin ? Visibility.Visible : Visibility.Collapsed;
        AquariumStoreNavItem.Visibility = isAdmin ? Visibility.Visible : Visibility.Collapsed;
        AdminNavItem.Visibility = isAdmin ? Visibility.Visible : Visibility.Collapsed;
        RefreshFeatureWidgetList();
        RefreshSettingsSearchResults();
    }
}
