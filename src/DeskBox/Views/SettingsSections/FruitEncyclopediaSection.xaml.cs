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
    private const int WildlifePageSize = 10;
    private const int TreeDecorationPageSize = 10;
    private string _selectedFruitCategory = AllCategories;
    private string _selectedAquariumCategory = AllCategories;
    private string _selectedWildlifeCategory = AllCategories;
    private string _selectedTreeDecorationCategory = AllCategories;
    private string _selectedTreeDecorationSubcategory = AllCategories;
    private int _refreshQueued;
    private volatile bool _fruitDirty = true;
    private volatile bool _aquariumDirty = true;
    private volatile bool _wildlifeDirty = true;
    private volatile bool _treeDecorationDirty = true;
    private int _wildlifePage;
    private IReadOnlyList<AtlasEntry> _wildlifeEntries = [];
    private int _treeDecorationPage;
    private IReadOnlyList<AtlasEntry> _treeDecorationEntries = [];

    public FruitEncyclopediaSection()
    {
        InitializeComponent();
        UpdatePrimaryTabVisuals();
        Loaded += (_, _) =>
        {
            _treeDecorationDirty = true;
            App.Current.FruitService.Changed += FruitChanged;
            App.Current.AquariumService.Changed += AquariumChanged;
            App.Current.WildlifeService.Changed += WildlifeChanged;
            App.Current.TreeDecorationService.Changed += TreeDecorationChanged;
            _ = App.Current.FruitService.RefreshAsync();
            _ = App.Current.AquariumService.RefreshAsync();
            _ = App.Current.WildlifeService.RefreshAsync();
            if (App.Current.TreeDecorationService.IsVisibleToCurrentUser)
                _ = App.Current.TreeDecorationService.RefreshAsync();
            Refresh();
        };
        Unloaded += (_, _) =>
        {
            App.Current.FruitService.Changed -= FruitChanged;
            App.Current.AquariumService.Changed -= AquariumChanged;
            App.Current.WildlifeService.Changed -= WildlifeChanged;
            App.Current.TreeDecorationService.Changed -= TreeDecorationChanged;
        };
    }
    private void AquariumTab_Click(object sender, RoutedEventArgs e)
    {
        AquariumPanel.Visibility = Visibility.Visible;
        FruitPanel.Visibility = Visibility.Collapsed;
        WildlifePanel.Visibility = Visibility.Collapsed;
        TreeDecorationPanel.Visibility = Visibility.Collapsed;
        UpdatePrimaryTabVisuals();
        Refresh();
    }
    private void FruitTab_Click(object sender, RoutedEventArgs e)
    {
        AquariumPanel.Visibility = Visibility.Collapsed;
        FruitPanel.Visibility = Visibility.Visible;
        WildlifePanel.Visibility = Visibility.Collapsed;
        TreeDecorationPanel.Visibility = Visibility.Collapsed;
        UpdatePrimaryTabVisuals();
        Refresh();
    }

    private void WildlifeTab_Click(object sender, RoutedEventArgs e)
    {
        AquariumPanel.Visibility = Visibility.Collapsed;
        FruitPanel.Visibility = Visibility.Collapsed;
        WildlifePanel.Visibility = Visibility.Visible;
        TreeDecorationPanel.Visibility = Visibility.Collapsed;
        UpdatePrimaryTabVisuals();
        Refresh();
    }

    private void TreeDecorationTab_Click(object sender, RoutedEventArgs e)
    {
        if (!App.Current.TreeDecorationService.IsVisibleToCurrentUser) return;
        AquariumPanel.Visibility = Visibility.Collapsed;
        FruitPanel.Visibility = Visibility.Collapsed;
        WildlifePanel.Visibility = Visibility.Collapsed;
        TreeDecorationPanel.Visibility = Visibility.Visible;
        UpdatePrimaryTabVisuals();
        Refresh();
    }

    private void WildlifePreviousPage_Click(object sender, RoutedEventArgs e)
    {
        if (_wildlifePage <= 0) return;
        _wildlifePage--;
        RenderWildlifePage();
    }

    private void WildlifeNextPage_Click(object sender, RoutedEventArgs e)
    {
        int pageCount = Math.Max(1, (_wildlifeEntries.Count + WildlifePageSize - 1) / WildlifePageSize);
        if (_wildlifePage + 1 >= pageCount) return;
        _wildlifePage++;
        RenderWildlifePage();
    }

    private void TreeDecorationPreviousPage_Click(object sender, RoutedEventArgs e)
    {
        if (_treeDecorationPage <= 0) return;
        _treeDecorationPage--;
        RenderTreeDecorationPage();
    }

    private void TreeDecorationNextPage_Click(object sender, RoutedEventArgs e)
    {
        int pageCount = Math.Max(1, (_treeDecorationEntries.Count + TreeDecorationPageSize - 1) / TreeDecorationPageSize);
        if (_treeDecorationPage + 1 >= pageCount) return;
        _treeDecorationPage++;
        RenderTreeDecorationPage();
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
        TreeDecorationTabButton.FontWeight = TreeDecorationPanel.Visibility == Visibility.Visible
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

    private static string TreeDecorationCategoryName(string category) => category switch
    {
        "tree" => "树上",
        "ground" => "地面",
        "large" => "大型",
        _ => category
    };

    private static string TreeDecorationSubcategoryName(string subcategory) =>
        TreeDecorationInventoryPresentation.SubcategoryName(
            subcategory, App.Current.LocalizationService.T);

    private void FruitChanged() { _fruitDirty = true; Refresh(); }

    private void AquariumChanged() { _aquariumDirty = true; Refresh(); }

    private void WildlifeChanged() { _wildlifeDirty = true; Refresh(); }
    private void TreeDecorationChanged() { _treeDecorationDirty = true; Refresh(); }

    private void Refresh()
    {
        if (Interlocked.Exchange(ref _refreshQueued, 1) != 0) return;
        if (!DispatcherQueue.TryEnqueue(() =>
            {
                Interlocked.Exchange(ref _refreshQueued, 0);
                if (IsLoaded) RefreshCore();
            }))
            Interlocked.Exchange(ref _refreshQueued, 0);
    }

    private void RefreshCore()
    {
        TreeDecorationService decorations = App.Current.TreeDecorationService;
        bool showDecorations = decorations.IsVisibleToCurrentUser;
        TreeDecorationTabButton.Visibility = showDecorations ? Visibility.Visible : Visibility.Collapsed;
        if (!showDecorations && TreeDecorationPanel.Visibility == Visibility.Visible)
        {
            TreeDecorationPanel.Visibility = Visibility.Collapsed;
            AquariumPanel.Visibility = Visibility.Visible;
            _treeDecorationEntries = [];
            _treeDecorationDirty = true;
            TreeDecorationItems.ItemsSource = null;
            UpdatePrimaryTabVisuals();
        }
        FruitService fruitService = App.Current.FruitService;
        IReadOnlyList<EncyclopediaCategoryProgress> fruitCategories =
            EncyclopediaCategorySummary.Fruits(fruitService.Catalog, fruitService.Inventory);
        if (_selectedFruitCategory != AllCategories &&
            !fruitCategories.Any(item => item.Category == _selectedFruitCategory))
        {
            _selectedFruitCategory = AllCategories;
            _fruitDirty = true;
        }
        FruitTabButton.Content = ProgressText("果实图鉴", TotalProgress(fruitCategories));
        if (FruitPanel.Visibility == Visibility.Visible)
        {
            RenderCategories(FruitCategories, fruitCategories, _selectedFruitCategory,
                category => category, category => { _selectedFruitCategory = category; _fruitDirty = true; Refresh(); });
            if (_fruitDirty)
            {
                var inventory = fruitService.Inventory.ToDictionary(x => x.FruitId, StringComparer.Ordinal);
                var entries = new List<AtlasEntry>();
                foreach (FruitCatalogItem fruit in fruitService.Catalog.Where(item =>
                             _selectedFruitCategory == AllCategories || item.Category == _selectedFruitCategory))
                {
                    bool discovered = inventory.TryGetValue(fruit.Id, out FruitInventoryItem? owned) &&
                        (owned.Discovered || owned.Quantity > 0);
                    entries.Add(new AtlasEntry(discovered ? fruit.Name : "尚未发现", fruit.Category,
                        discovered ? $"拥有 {owned!.Quantity}" : "尚未发现",
                        () => FruitAssetCatalog.GetImageSource(fruit.Id, blurred: !discovered)));
                }
                FruitItems.ItemsSource = entries;
                _fruitDirty = false;
            }
        }

        AquariumService aquarium = App.Current.AquariumService;
        IReadOnlyList<EncyclopediaCategoryProgress> aquariumCategories =
            EncyclopediaCategorySummary.Aquarium(aquarium.Catalog, aquarium.State);
        if (_selectedAquariumCategory != AllCategories &&
            !aquariumCategories.Any(item => item.Category == _selectedAquariumCategory))
        {
            _selectedAquariumCategory = AllCategories;
            _aquariumDirty = true;
        }
        AquariumTabButton.Content = ProgressText("水族馆图鉴", TotalProgress(aquariumCategories));
        if (AquariumPanel.Visibility == Visibility.Visible)
        {
            RenderCategories(AquariumCategories, aquariumCategories, _selectedAquariumCategory,
                AquariumCategoryName, category => { _selectedAquariumCategory = category; _aquariumDirty = true; Refresh(); });
            if (_aquariumDirty)
            {
                var discoveredAquarium = new HashSet<string>(aquarium.State?.Discovered ?? [], StringComparer.Ordinal);
                var counts = aquarium.State?.Inventory.GroupBy(x => x.ItemId)
                    .ToDictionary(g => g.Key, g => g.Count(), StringComparer.Ordinal)
                    ?? new Dictionary<string, int>(StringComparer.Ordinal);
                var entries = new List<AtlasEntry>();
                foreach (AquariumCatalogItem item in aquarium.Catalog.Where(x => x.Category != "pack" &&
                             (_selectedAquariumCategory == AllCategories || x.Category == _selectedAquariumCategory)))
                {
                    bool discovered = discoveredAquarium.Contains(item.Id) || counts.ContainsKey(item.Id);
                    entries.Add(new AtlasEntry(discovered ? item.Name : "尚未发现", AquariumCategoryName(item.Category),
                        counts.TryGetValue(item.Id, out int count) ? $"拥有 {count}" : discovered ? "已发现" : "未发现",
                        () => discovered
                            ? new SvgImageSource(new Uri($"ms-appx:///Assets/Aquarium/Svg/{item.AssetKey}.svg"))
                            : new BitmapImage(new Uri($"ms-appx:///Assets/Aquarium/Silhouettes/{item.AssetKey}.png"))));
                }
                AquariumItems.ItemsSource = entries;
                _aquariumDirty = false;
            }
        }

        WildlifeService wildlife = App.Current.WildlifeService;
        IReadOnlyList<EncyclopediaCategoryProgress> wildlifeCategories =
            EncyclopediaCategorySummary.Wildlife(wildlife.Catalog, wildlife.Inventory);
        if (_selectedWildlifeCategory != AllCategories &&
            !wildlifeCategories.Any(item => item.Category == _selectedWildlifeCategory))
        {
            _selectedWildlifeCategory = AllCategories;
            _wildlifeDirty = true;
        }
        WildlifeTabButton.Content = ProgressText("动物图鉴", TotalProgress(wildlifeCategories));
        if (WildlifePanel.Visibility == Visibility.Visible)
        {
            RenderCategories(WildlifeCategories, wildlifeCategories, _selectedWildlifeCategory,
                category => category, category =>
                {
                    _selectedWildlifeCategory = category;
                    _wildlifePage = 0;
                    _wildlifeDirty = true;
                    Refresh();
                });
            if (_wildlifeDirty)
            {
                var inventory = wildlife.Inventory.ToDictionary(item => item.SpeciesId, StringComparer.Ordinal);
                var entries = new List<AtlasEntry>();
                foreach (WildlifeCatalogItem species in wildlife.Catalog.Where(item =>
                             _selectedWildlifeCategory == AllCategories || item.Category == _selectedWildlifeCategory))
                {
                    bool discovered = inventory.TryGetValue(species.Id, out WildlifeInventoryItem? owned) &&
                        (owned.Discovered || owned.Quantity > 0);
                    entries.Add(new AtlasEntry(discovered ? species.Name : "尚未发现", species.Category,
                        discovered ? WildlifeAvailabilityPresentation.EncyclopediaDetails(species) : "未发现",
                        () => WildlifeAssetCatalog.GetImage(species.Id, blurred: !discovered)));
                }
                _wildlifeEntries = entries;
                _wildlifePage = 0;
                _wildlifeDirty = false;
            }
            RenderWildlifePage();
        }

        if (!showDecorations) return;
        IReadOnlyList<TreeDecorationCatalogItem> decorationCatalog = decorations.Catalog?.Items
            .Where(item => item.Category != "theme").ToArray() ?? [];
        IReadOnlyList<TreeDecorationInventoryItem> decorationInventory = decorations.State?.Inventory ?? [];
        IReadOnlyList<EncyclopediaCategoryProgress> decorationCategories =
            EncyclopediaCategorySummary.TreeDecorations(decorationCatalog, decorationInventory);
        if (_selectedTreeDecorationCategory != AllCategories &&
            !decorationCategories.Any(item => item.Category == _selectedTreeDecorationCategory))
        {
            _selectedTreeDecorationCategory = AllCategories;
            _selectedTreeDecorationSubcategory = AllCategories;
            _treeDecorationDirty = true;
        }
        IReadOnlyList<EncyclopediaCategoryProgress> decorationSubcategories =
            EncyclopediaCategorySummary.TreeDecorationSubcategories(
                decorationCatalog, decorationInventory, _selectedTreeDecorationCategory);
        if (_selectedTreeDecorationSubcategory != AllCategories &&
            !decorationSubcategories.Any(item => item.Category == _selectedTreeDecorationSubcategory))
        {
            _selectedTreeDecorationSubcategory = AllCategories;
            _treeDecorationDirty = true;
        }
        TreeDecorationTabButton.Content = ProgressText("树木装饰图鉴", TotalProgress(decorationCategories));
        if (TreeDecorationPanel.Visibility != Visibility.Visible) return;
        RenderCategories(TreeDecorationCategories, decorationCategories, _selectedTreeDecorationCategory,
            TreeDecorationCategoryName, category =>
            {
                _selectedTreeDecorationCategory = category;
                _selectedTreeDecorationSubcategory = AllCategories;
                _treeDecorationPage = 0;
                _treeDecorationDirty = true;
                Refresh();
            });
        RenderCategories(TreeDecorationSubcategories, decorationSubcategories,
            _selectedTreeDecorationSubcategory, TreeDecorationSubcategoryName, subcategory =>
            {
                _selectedTreeDecorationSubcategory = subcategory;
                _treeDecorationPage = 0;
                _treeDecorationDirty = true;
                Refresh();
            });
        TreeDecorationSubcategories.Visibility = decorationSubcategories.Count > 0
            ? Visibility.Visible : Visibility.Collapsed;
        if (_treeDecorationDirty)
        {
            var counts = decorationInventory.Where(item => item.Quantity > 0)
                .ToDictionary(item => item.ItemId, item => item.Quantity, StringComparer.Ordinal);
            _treeDecorationEntries = decorationCatalog.Where(item =>
                    (_selectedTreeDecorationCategory == AllCategories || item.Category == _selectedTreeDecorationCategory) &&
                    (_selectedTreeDecorationSubcategory == AllCategories ||
                        item.Subcategory == _selectedTreeDecorationSubcategory))
                .Select(item =>
                {
                    bool discovered = counts.TryGetValue(item.Id, out int count);
                    string name = TreeDecorationInventoryPresentation.DisplayName(item, App.Current.LocalizationService.T);
                    string rarity = App.Current.LocalizationService.T("Tree.Decorations.Rarity." + item.Rarity);
                    return new AtlasEntry(discovered ? name : "尚未发现",
                        $"{TreeDecorationCategoryName(item.Category)} · " +
                            TreeDecorationSubcategoryName(item.Subcategory),
                        discovered ? $"{rarity} · 拥有 {count}" : $"{rarity} · 未发现",
                        () => discovered ? TreeDecorationAssetCatalog.Source(item.AssetKey)
                            : TreeDecorationAssetCatalog.Silhouette(item.AssetKey));
                }).ToArray();
            _treeDecorationPage = 0;
            _treeDecorationDirty = false;
        }
        RenderTreeDecorationPage();
    }

    private void RenderWildlifePage()
    {
        int pageCount = Math.Max(1, (_wildlifeEntries.Count + WildlifePageSize - 1) / WildlifePageSize);
        _wildlifePage = Math.Clamp(_wildlifePage, 0, pageCount - 1);
        WildlifeItems.ItemsSource = _wildlifeEntries
            .Skip(_wildlifePage * WildlifePageSize).Take(WildlifePageSize).ToArray();
        WildlifePager.Visibility = pageCount > 1 ? Visibility.Visible : Visibility.Collapsed;
        WildlifePageLabel.Text = $"{_wildlifePage + 1}/{pageCount}";
        WildlifePreviousPageButton.IsEnabled = _wildlifePage > 0;
        WildlifeNextPageButton.IsEnabled = _wildlifePage + 1 < pageCount;
    }

    private void RenderTreeDecorationPage()
    {
        int pageCount = Math.Max(1, (_treeDecorationEntries.Count + TreeDecorationPageSize - 1) / TreeDecorationPageSize);
        _treeDecorationPage = Math.Clamp(_treeDecorationPage, 0, pageCount - 1);
        TreeDecorationItems.ItemsSource = _treeDecorationEntries
            .Skip(_treeDecorationPage * TreeDecorationPageSize).Take(TreeDecorationPageSize).ToArray();
        TreeDecorationPager.Visibility = pageCount > 1 ? Visibility.Visible : Visibility.Collapsed;
        TreeDecorationPageLabel.Text = $"{_treeDecorationPage + 1}/{pageCount}";
        TreeDecorationPreviousPageButton.IsEnabled = _treeDecorationPage > 0;
        TreeDecorationNextPageButton.IsEnabled = _treeDecorationPage + 1 < pageCount;
    }
}

public sealed class AtlasEntry
{
    private readonly Func<ImageSource?> _imageFactory;
    private ImageSource? _image;
    private bool _imageResolved;

    public AtlasEntry(string name, string category, string quantityText, Func<ImageSource?> imageFactory)
    {
        Name = name;
        Category = category;
        QuantityText = quantityText;
        _imageFactory = imageFactory;
    }

    public string Name { get; }
    public string Category { get; }
    public string QuantityText { get; }
    public ImageSource? Image
    {
        get
        {
            if (_imageResolved) return _image;
            _image = _imageFactory();
            _imageResolved = true;
            return _image;
        }
    }
}
