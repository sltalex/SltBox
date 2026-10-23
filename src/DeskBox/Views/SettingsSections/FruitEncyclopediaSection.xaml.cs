using System.Collections.ObjectModel;
using CommunityToolkit.WinUI.Controls;
using Microsoft.UI.Xaml.Controls;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Media;
using Microsoft.UI.Xaml.Media.Imaging;
using DeskBox.Services;

namespace DeskBox.Views.SettingsSections;

public sealed partial class FruitEncyclopediaSection : UserControl
{
    private const string AllCategories = "";
    private readonly ObservableCollection<AtlasEntry> _items = [];
    private readonly ObservableCollection<AtlasEntry> _aquariumItems = [];
    private readonly ObservableCollection<AtlasEntry> _wildlifeItems = [];
    private string _selectedFruitCategory = AllCategories;
    private string _selectedAquariumCategory = AllCategories;
    private string _selectedWildlifeCategory = AllCategories;

    public FruitEncyclopediaSection()
    {
        InitializeComponent();
        FruitItems.ItemsSource = _items;
        AquariumItems.ItemsSource = _aquariumItems;
        WildlifeItems.ItemsSource = _wildlifeItems;
        UpdatePrimaryTabVisuals();
        Loaded += (_, _) =>
        {
            App.Current.FruitService.Changed += Refresh;
            App.Current.AquariumService.Changed += Refresh;
            App.Current.WildlifeService.Changed += Refresh;
            _ = App.Current.FruitService.RefreshAsync();
            _ = App.Current.AquariumService.RefreshAsync();
            _ = App.Current.WildlifeService.RefreshAsync();
            Refresh();
        };
        Unloaded += (_, _) =>
        {
            App.Current.FruitService.Changed -= Refresh;
            App.Current.AquariumService.Changed -= Refresh;
            App.Current.WildlifeService.Changed -= Refresh;
        };
    }
    private void AquariumTab_Click(object sender, RoutedEventArgs e)
    {
        AquariumPanel.Visibility = Visibility.Visible;
        FruitPanel.Visibility = Visibility.Collapsed;
        WildlifePanel.Visibility = Visibility.Collapsed;
        UpdatePrimaryTabVisuals();
    }
    private void FruitTab_Click(object sender, RoutedEventArgs e)
    {
        AquariumPanel.Visibility = Visibility.Collapsed;
        FruitPanel.Visibility = Visibility.Visible;
        WildlifePanel.Visibility = Visibility.Collapsed;
        UpdatePrimaryTabVisuals();
    }

    private void WildlifeTab_Click(object sender, RoutedEventArgs e)
    {
        AquariumPanel.Visibility = Visibility.Collapsed;
        FruitPanel.Visibility = Visibility.Collapsed;
        WildlifePanel.Visibility = Visibility.Visible;
        UpdatePrimaryTabVisuals();
    }

    private void UpdatePrimaryTabVisuals()
    {
        bool aquariumSelected = AquariumPanel.Visibility == Visibility.Visible;
        AquariumTabButton.FontWeight = aquariumSelected
            ? Microsoft.UI.Text.FontWeights.SemiBold : Microsoft.UI.Text.FontWeights.Normal;
        FruitTabButton.FontWeight = aquariumSelected
            ? Microsoft.UI.Text.FontWeights.Normal : FruitPanel.Visibility == Visibility.Visible
                ? Microsoft.UI.Text.FontWeights.SemiBold : Microsoft.UI.Text.FontWeights.Normal;
        WildlifeTabButton.FontWeight = WildlifePanel.Visibility == Visibility.Visible
            ? Microsoft.UI.Text.FontWeights.SemiBold : Microsoft.UI.Text.FontWeights.Normal;
    }

    private static string ProgressText(string name, EncyclopediaCategoryProgress progress) =>
        $"{name} {progress.Obtained}/{progress.Total}";

    private static EncyclopediaCategoryProgress TotalProgress(
        IReadOnlyList<EncyclopediaCategoryProgress> categories) =>
        new(AllCategories, categories.Sum(item => item.Obtained), categories.Sum(item => item.Total));

    private void RenderCategories(
        WrapPanel host,
        IReadOnlyList<EncyclopediaCategoryProgress> categories,
        string selectedCategory,
        Func<string, string> categoryName,
        Action<string> select)
    {
        host.Children.Clear();
        foreach (EncyclopediaCategoryProgress progress in
                 new[] { TotalProgress(categories) }.Concat(categories))
        {
            string category = progress.Category;
            bool selected = string.Equals(selectedCategory, category, StringComparison.Ordinal);
            var button = new Button
            {
                Content = ProgressText(category == AllCategories ? "全部" : categoryName(category), progress),
                Padding = new Thickness(10, 5, 10, 5),
                MinHeight = 30,
                FontWeight = selected
                    ? Microsoft.UI.Text.FontWeights.SemiBold : Microsoft.UI.Text.FontWeights.Normal,
                Opacity = selected ? 1 : .72
            };
            button.Click += (_, _) => select(category);
            host.Children.Add(button);
        }
    }

    private static string AquariumCategoryName(string category)
    {
        string key = "Aquarium.Category." + category;
        string localized = App.Current.LocalizationService.T(key);
        return localized == key ? category : localized;
    }

    private void Refresh()
    {
        DispatcherQueue.TryEnqueue(() =>
        {
            FruitService fruitService = App.Current.FruitService;
            IReadOnlyList<EncyclopediaCategoryProgress> fruitCategories =
                EncyclopediaCategorySummary.Fruits(fruitService.Catalog, fruitService.Inventory);
            if (_selectedFruitCategory != AllCategories &&
                !fruitCategories.Any(item => item.Category == _selectedFruitCategory))
                _selectedFruitCategory = AllCategories;
            FruitTabButton.Content = ProgressText("果实图鉴", TotalProgress(fruitCategories));
            RenderCategories(FruitCategories, fruitCategories, _selectedFruitCategory,
                category => category, category => { _selectedFruitCategory = category; Refresh(); });

            _items.Clear();
            var inventory = fruitService.Inventory.ToDictionary(x => x.FruitId, StringComparer.Ordinal);
            foreach (FruitCatalogItem fruit in fruitService.Catalog.Where(item =>
                         _selectedFruitCategory == AllCategories || item.Category == _selectedFruitCategory))
            {
                bool discovered = inventory.TryGetValue(fruit.Id, out FruitInventoryItem? owned) &&
                    (owned.Discovered || owned.Quantity > 0);
                _items.Add(new AtlasEntry(discovered ? fruit.Name : "尚未发现", fruit.Category,
                    discovered ? $"拥有 {owned!.Quantity}" : "尚未发现",
                    FruitAssetCatalog.GetImageSource(fruit.Id, blurred: !discovered)));
            }

            _aquariumItems.Clear();
            AquariumService service = App.Current.AquariumService;
            IReadOnlyList<EncyclopediaCategoryProgress> aquariumCategories =
                EncyclopediaCategorySummary.Aquarium(service.Catalog, service.State);
            if (_selectedAquariumCategory != AllCategories &&
                !aquariumCategories.Any(item => item.Category == _selectedAquariumCategory))
                _selectedAquariumCategory = AllCategories;
            AquariumTabButton.Content = ProgressText("水族馆图鉴", TotalProgress(aquariumCategories));
            RenderCategories(AquariumCategories, aquariumCategories, _selectedAquariumCategory,
                AquariumCategoryName, category => { _selectedAquariumCategory = category; Refresh(); });

            var discoveredAquarium = new HashSet<string>(service.State?.Discovered ?? [], StringComparer.Ordinal);
            var counts = service.State?.Inventory.GroupBy(x => x.ItemId).ToDictionary(g => g.Key, g => g.Count(), StringComparer.Ordinal)
                ?? new Dictionary<string, int>(StringComparer.Ordinal);
            foreach (AquariumCatalogItem item in service.Catalog.Where(x => x.Category != "pack" &&
                         (_selectedAquariumCategory == AllCategories || x.Category == _selectedAquariumCategory)))
            {
                bool discovered = discoveredAquarium.Contains(item.Id) || counts.ContainsKey(item.Id);
                ImageSource image = discovered
                    ? new SvgImageSource(new Uri($"ms-appx:///Assets/Aquarium/Svg/{item.AssetKey}.svg"))
                    : new BitmapImage(new Uri($"ms-appx:///Assets/Aquarium/Silhouettes/{item.AssetKey}.png"));
                _aquariumItems.Add(new AtlasEntry(discovered ? item.Name : "尚未发现", AquariumCategoryName(item.Category),
                    counts.TryGetValue(item.Id, out int count) ? $"拥有 {count}" : discovered ? "已发现" : "未发现", image));
            }

            WildlifeService wildlife = App.Current.WildlifeService;
            IReadOnlyList<EncyclopediaCategoryProgress> wildlifeCategories =
                EncyclopediaCategorySummary.Wildlife(wildlife.Catalog, wildlife.Inventory);
            if (_selectedWildlifeCategory != AllCategories &&
                !wildlifeCategories.Any(item => item.Category == _selectedWildlifeCategory))
                _selectedWildlifeCategory = AllCategories;
            WildlifeTabButton.Content = ProgressText("动物图鉴", TotalProgress(wildlifeCategories));
            RenderCategories(WildlifeCategories, wildlifeCategories, _selectedWildlifeCategory,
                category => category, category => { _selectedWildlifeCategory = category; Refresh(); });
            _wildlifeItems.Clear();
            var wildlifeInventory = wildlife.Inventory.ToDictionary(item => item.SpeciesId, StringComparer.Ordinal);
            foreach (WildlifeCatalogItem species in wildlife.Catalog.Where(item =>
                         _selectedWildlifeCategory == AllCategories || item.Category == _selectedWildlifeCategory))
            {
                bool discovered = wildlifeInventory.TryGetValue(species.Id, out WildlifeInventoryItem? owned) &&
                    (owned.Discovered || owned.Quantity > 0);
                _wildlifeItems.Add(new AtlasEntry(discovered ? species.Name : "尚未发现", species.Category,
                    discovered ? $"拥有 {owned!.Quantity}" : "未发现",
                    WildlifeAssetCatalog.GetImage(species.Id, blurred: !discovered)));
            }
        });
    }
}

public sealed record AtlasEntry(string Name, string Category, string QuantityText, ImageSource? Image);
