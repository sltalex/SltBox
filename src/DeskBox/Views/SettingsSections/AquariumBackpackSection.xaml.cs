using Microsoft.UI.Xaml.Controls;

namespace DeskBox.Views.SettingsSections;

public sealed partial class AquariumBackpackSection : UserControl
{
    internal StackPanel ItemsHost => BackpackItems;
    internal StackPanel PrimaryCategoriesHost => BackpackPrimaryCategories;
    internal TextBlock StatusLabel => DraftStatus;
    internal event Action? AquariumRequested;

    public AquariumBackpackSection()
    {
        InitializeComponent();
    }

    internal void RequestAquariumSettings() => AquariumRequested?.Invoke();
}
