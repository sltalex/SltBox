using System.Collections.ObjectModel;
using Microsoft.UI.Xaml.Controls;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Media;
using Microsoft.UI.Xaml.Media.Imaging;
using DeskBox.Services;

namespace DeskBox.Views.SettingsSections;

public sealed partial class FruitEncyclopediaSection : UserControl
{
    private readonly ObservableCollection<AtlasEntry> _items = [];
    private readonly ObservableCollection<AtlasEntry> _aquariumItems = [];
    public FruitEncyclopediaSection()
    {
        InitializeComponent(); FruitItems.ItemsSource = _items; AquariumItems.ItemsSource = _aquariumItems;
        Loaded += (_, _) =>
        {
            App.Current.FruitService.Changed += Refresh;
            App.Current.AquariumService.Changed += Refresh;
            _ = App.Current.FruitService.RefreshAsync();
            _ = App.Current.AquariumService.RefreshAsync();
            Refresh();
        };
        Unloaded += (_, _) =>
        {
            App.Current.FruitService.Changed -= Refresh;
            App.Current.AquariumService.Changed -= Refresh;
        };
    }
    private void AquariumTab_Click(object sender, RoutedEventArgs e)
    {
        AquariumPanel.Visibility = Visibility.Visible;
        FruitPanel.Visibility = Visibility.Collapsed;
    }
    private void FruitTab_Click(object sender, RoutedEventArgs e)
    {
        AquariumPanel.Visibility = Visibility.Collapsed;
        FruitPanel.Visibility = Visibility.Visible;
    }
    private void Refresh()
    {
        DispatcherQueue.TryEnqueue(() =>
        {
            _items.Clear();
            var inventory = App.Current.FruitService.Inventory.ToDictionary(x => x.FruitId, StringComparer.Ordinal);
            foreach (var fruit in App.Current.FruitService.Catalog)
            {
                bool discovered = inventory.TryGetValue(fruit.Id, out var owned) && owned.Discovered;
                _items.Add(new AtlasEntry(discovered ? fruit.Name : "尚未发现", fruit.Category,
                    discovered ? $"拥有 {owned!.Quantity}" : "尚未发现",
                    FruitAssetCatalog.GetImageSource(fruit.Id, blurred: !discovered)));
            }
            _aquariumItems.Clear();
            AquariumService service = App.Current.AquariumService;
            var discoveredAquarium = new HashSet<string>(service.State?.Discovered ?? [], StringComparer.Ordinal);
            var counts = service.State?.Inventory.GroupBy(x => x.ItemId).ToDictionary(g => g.Key, g => g.Count(), StringComparer.Ordinal)
                ?? new Dictionary<string, int>(StringComparer.Ordinal);
            foreach (AquariumCatalogItem item in service.Catalog.Where(x => x.Category != "pack"))
            {
                bool discovered = discoveredAquarium.Contains(item.Id) || counts.ContainsKey(item.Id);
                ImageSource image = discovered
                    ? new SvgImageSource(new Uri($"ms-appx:///Assets/Aquarium/Svg/{item.AssetKey}.svg"))
                    : new BitmapImage(new Uri($"ms-appx:///Assets/Aquarium/Silhouettes/{item.AssetKey}.png"));
                _aquariumItems.Add(new AtlasEntry(discovered ? item.Name : "尚未发现", item.Category,
                    counts.TryGetValue(item.Id, out int count) ? $"拥有 {count}" : discovered ? "已发现" : "未发现", image));
            }
        });
    }
}

public sealed record AtlasEntry(string Name, string Category, string QuantityText, ImageSource Image);
