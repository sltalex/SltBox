using Microsoft.UI.Xaml.Controls;

namespace DeskBox.Views.SettingsSections;

public sealed partial class AquariumStoreSection : UserControl
{
    internal StackPanel ItemsHost => StoreItems;
    internal StackPanel CategoriesHost => StoreCategories;
    internal TextBlock CoinBalanceLabel => CoinBalance;
    internal TextBlock StatusLabel => StoreStatus;

    public AquariumStoreSection()
    {
        InitializeComponent();
    }
}
