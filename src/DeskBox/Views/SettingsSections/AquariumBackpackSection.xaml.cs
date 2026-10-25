using Microsoft.UI.Xaml.Controls;

namespace DeskBox.Views.SettingsSections;

public sealed partial class AquariumBackpackSection : UserControl
{
    internal StackPanel ItemsHost => BackpackItems;
    internal StackPanel PrimaryCategoriesHost => BackpackPrimaryCategories;
    internal TextBlock CoinBalanceLabel => CoinBalance;
    internal TextBlock StatusLabel => DraftStatus;
    internal event Action? AquariumRequested;
    internal event Action? TreeRequested;

    public AquariumBackpackSection()
    {
        InitializeComponent();
    }

    internal void RequestAquariumSettings() => AquariumRequested?.Invoke();
    internal void RequestTreeSettings() => TreeRequested?.Invoke();
}
