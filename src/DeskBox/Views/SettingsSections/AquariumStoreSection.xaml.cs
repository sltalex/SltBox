using Microsoft.UI.Xaml.Controls;

namespace DeskBox.Views.SettingsSections;

public sealed partial class AquariumStoreSection : UserControl
{
    internal StackPanel ItemsHost => StoreItems;
    internal StackPanel RecyclePanel => RecycleHost;
    internal StackPanel CategoriesHost => StoreCategories;
    internal TextBlock StatusLabel => StoreStatus;

    public AquariumStoreSection()
    {
        InitializeComponent();
    }
}
