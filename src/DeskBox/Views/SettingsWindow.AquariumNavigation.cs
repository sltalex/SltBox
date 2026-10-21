using Microsoft.UI.Xaml;

namespace DeskBox.Views;

/// <summary>Controls only the account-gated aquarium feature routes, not administrator management.</summary>
public sealed partial class SettingsWindow
{
    private bool HasAquariumAccess => _aquariumService.IsAvailable;
    private static bool IsAquariumRoute(string sectionTag) =>
        sectionTag is "AquariumSettings" or "Backpack" or "AquariumStore";
    private bool CanAccessSettingsRoute(string sectionTag) => !IsAquariumRoute(sectionTag) || HasAquariumAccess;

    private void InitializeAquariumNavigation()
    {
        _authSession.SessionChanged += OnAquariumAccessChanged;
        _aquariumService.Changed += OnAquariumAccessChanged;
        RefreshAquariumNavigation();
    }

    private void OnAquariumAccessChanged() => DispatcherQueue.TryEnqueue(RefreshAquariumNavigation);

    private void RefreshAquariumNavigation()
    {
        if (_isClosed) return;
        bool hasAccess = HasAquariumAccess;
        if (!hasAccess && IsAquariumRoute(_currentSettingsSection))
            NavigateToSettingsSection("General");

        if (hasAccess)
        {
            if (!SettingsNavigationView.MenuItems.Contains(BackpackNavItem))
                SettingsNavigationView.MenuItems.Insert(SettingsNavigationView.MenuItems.IndexOf(GeneralNavItem) + 1, BackpackNavItem);
            if (!SettingsNavigationView.MenuItems.Contains(AquariumStoreNavItem))
                SettingsNavigationView.MenuItems.Insert(SettingsNavigationView.MenuItems.IndexOf(BackpackNavItem) + 1, AquariumStoreNavItem);
        }
        else
        {
            SettingsNavigationView.MenuItems.Remove(BackpackNavItem);
            SettingsNavigationView.MenuItems.Remove(AquariumStoreNavItem);
        }
        BackpackNavItem.Visibility = hasAccess ? Visibility.Visible : Visibility.Collapsed;
        AquariumStoreNavItem.Visibility = hasAccess ? Visibility.Visible : Visibility.Collapsed;
        RefreshFeatureWidgetList();
        RefreshSettingsSearchResults();
    }
}
