using System.Globalization;
using CommunityToolkit.WinUI.Controls;
using DeskBox.Controls.WidgetContents;
using DeskBox.Services;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
using Microsoft.UI.Xaml.Input;
using Microsoft.UI.Xaml.Media;
using Microsoft.UI.Xaml.Media.Animation;
using Microsoft.UI.Xaml.Shapes;

namespace DeskBox.Views.SettingsSections;

public sealed partial class AquariumSettingsSection : UserControl, IDisposable
{
    private static readonly Windows.UI.Color PackDeepColor = Windows.UI.Color.FromArgb(255, 16, 47, 54);
    private static readonly Windows.UI.Color PackTealColor = Windows.UI.Color.FromArgb(255, 23, 107, 115);
    private static readonly Windows.UI.Color PackGoldColor = Windows.UI.Color.FromArgb(255, 242, 213, 155);
    private static readonly Windows.UI.Color PackAquaColor = Windows.UI.Color.FromArgb(255, 152, 221, 212);
    private readonly Microsoft.UI.Dispatching.DispatcherQueue _queue = Microsoft.UI.Dispatching.DispatcherQueue.GetForCurrentThread();
    private readonly AquariumSceneView _preview = new() { IsEditing = true, Width = 420, Height = 300, HorizontalAlignment = HorizontalAlignment.Center };
    private readonly TextBlock _status = new() { TextWrapping = TextWrapping.Wrap, FontSize = 13 };
    private readonly TextBlock _selection = new() { TextWrapping = TextWrapping.Wrap };
    private readonly Grid _packOverlay = new()
    {
        Visibility = Visibility.Collapsed,
        IsHitTestVisible = true,
        HorizontalAlignment = HorizontalAlignment.Stretch,
        VerticalAlignment = VerticalAlignment.Stretch
    };
    private readonly Border _packCard = new()
    {
        Width = 380,
        MinHeight = 310,
        Padding = new Thickness(28),
        CornerRadius = new CornerRadius(22),
        HorizontalAlignment = HorizontalAlignment.Center,
        VerticalAlignment = VerticalAlignment.Center,
        Opacity = 0,
        RenderTransformOrigin = new Windows.Foundation.Point(.5, .5)
    };
    private readonly TextBlock _packRevealTitle = new() { FontSize = 21, Foreground = new SolidColorBrush(Microsoft.UI.Colors.White), FontWeight = Microsoft.UI.Text.FontWeights.SemiBold, TextAlignment = TextAlignment.Center };
    private readonly TextBlock _packRevealResult = new() { FontSize = 17, Foreground = new SolidColorBrush(Microsoft.UI.Colors.White), TextWrapping = TextWrapping.Wrap, TextAlignment = TextAlignment.Center, MaxLines = 2 };
    private readonly Image _packRevealImage = new() { Width = 200, Height = 140, Stretch = Stretch.Uniform, HorizontalAlignment = HorizontalAlignment.Center };
    private readonly Button _packConfirm = ActionButton("Aquarium.Settings.CardPack.Confirm");
    private readonly ScaleTransform _packCardScale = new() { ScaleX = .72, ScaleY = .72 };
    private readonly List<FrameworkElement> _packParticles = [];
    private readonly List<Ellipse> _packRipples = [];
    private readonly List<Storyboard> _packStoryboards = [];
    private Panel? _packOverlayHost;
    private Control? _packPreviousFocus;
    private bool _packEffectRunning;
    private readonly ToggleSwitch _animation = new() { OnContent = "", OffContent = "", MinWidth = 0 };
    private readonly Slider _scale = new() { Minimum = .5, Maximum = 1.5, StepFrequency = .05, Value = 1, IsEnabled = false, Width = 190 };
    private readonly StackPanel _backpack = new() { Spacing = 8 };
    private readonly StackPanel _backpackPrimaryCategories = new() { Orientation = Orientation.Horizontal, Spacing = 6 };
    private readonly StackPanel _backpackCategories = new() { Orientation = Orientation.Horizontal, Spacing = 6 };
    private AquariumBackpackSection? _externalBackpack;
    private AquariumStoreSection? _externalStore;
    private readonly Button _refresh = ActionButton("Aquarium.Settings.Refresh");
    private readonly Button _save = ActionButton("Aquarium.Settings.Save");
    private readonly Button _discard = ActionButton("Aquarium.Settings.Discard");
    private readonly Button _reapply = ActionButton("Aquarium.Settings.Reapply");
    private readonly List<AquariumPlacement> _draft = [];
    private AquariumService? _service;
    private LocalizationService? _localization;
    private Func<bool>? _animationEnabled;
    private Action<bool>? _setAnimationEnabled;
    private string? _accountId;
    private string? _selectedId;
    private long _baseSceneVersion;
    private bool _hasDraft;
    private bool _dirty;
    private bool _conflict;
    private bool _busy;
    private bool _updating;
    private bool _subscribed;
    private bool _active;
    private bool _backpackActive;
    private bool _storeActive;
    private bool _disposed;
    private string? _noticeKey;
    private string _selectedBackpackCategory = "fish";
    private string _selectedStoreGroup = "pack";
    private string _selectedStoreSubcategory = "all";
    private string _selectedBackpackHabitat = "all";
    private string _selectedExternalBackpackGroup = "general";
    private readonly ComboBox _layer = new() { Width = 125, IsEnabled = false };

    public AquariumSettingsSection()
    {
        var root = new StackPanel { Spacing = 12 };
        var top = new Grid { ColumnSpacing = 12 };
        top.ColumnDefinitions.Add(new ColumnDefinition());
        top.ColumnDefinitions.Add(new ColumnDefinition { Width = GridLength.Auto });
        Grid.SetColumn(_refresh, 1);
        top.Children.Add(_refresh);
        root.Children.Add(top);
        root.Children.Add(Label("Aquarium.Settings.Hint", 13, .72));
        root.Children.Add(Label("Aquarium.Settings.Preview", 16));
        root.Children.Add(new Border { CornerRadius = new CornerRadius(12), Child = _preview });
        root.Children.Add(_selection);
        var editRow = new StackPanel { Orientation = Orientation.Horizontal, Spacing = 12 };
        editRow.Children.Add(Label("Aquarium.Settings.Scale", 13));
        editRow.Children.Add(_scale);
        editRow.Children.Add(Label("Aquarium.Settings.Layer", 13));
        foreach (int layer in Enumerable.Range(AquariumLayer.Min, AquariumLayer.Count))
            _layer.Items.Add(new ComboBoxItem { Tag = layer });
        _layer.SelectedIndex = -AquariumLayer.Min;
        editRow.Children.Add(_layer);
        root.Children.Add(editRow);
        var actions = new StackPanel { Orientation = Orientation.Horizontal, Spacing = 8 };
        actions.Children.Add(_save);
        actions.Children.Add(_discard);
        actions.Children.Add(_reapply);
        root.Children.Add(actions);
        _reapply.Visibility = Visibility.Collapsed;
        var animationRow = new Grid { ColumnSpacing = 12 };
        animationRow.ColumnDefinitions.Add(new ColumnDefinition());
        animationRow.ColumnDefinitions.Add(new ColumnDefinition { Width = GridLength.Auto });
        animationRow.Children.Add(Label("Aquarium.Settings.Animation", 14));
        Grid.SetColumn(_animation, 1);
        animationRow.Children.Add(_animation);
        root.Children.Add(animationRow);
        root.Children.Add(_status);
        root.Children.Add(Label("Aquarium.Settings.Backpack", 18));
        var backpackContent = new StackPanel { Spacing = 8 };
        backpackContent.Children.Add(_backpackPrimaryCategories);
        backpackContent.Children.Add(_backpackCategories);
        backpackContent.Children.Add(_backpack);
        root.Children.Add(backpackContent);
        var shell = new Grid();
        shell.Children.Add(root);
        BuildPackRevealOverlay();
        Content = shell;

        _preview.SelectionChanged += Select;
        _preview.PlacementChanged += placement =>
        {
            int index = _draft.FindIndex(item => item.InstanceId == placement.InstanceId);
            if (index < 0) return;
            _draft[index] = placement;
            MarkDirty();
        };
        _scale.ValueChanged += (_, args) =>
        {
            if (_updating || _selectedId is null) return;
            int index = _draft.FindIndex(item => item.InstanceId == _selectedId);
            if (index < 0) return;
            var placement = _draft[index] with { Scale = Math.Round(args.NewValue, 2) };
            _draft[index] = placement;
            _preview.UpdatePlacement(placement);
            MarkDirty();
        };
        _layer.SelectionChanged += (_, _) =>
        {
            if (_updating || _selectedId is null || _layer.SelectedItem is not ComboBoxItem { Tag: int layer }) return;
            int index = _draft.FindIndex(item => item.InstanceId == _selectedId);
            if (index < 0) return;
            _draft[index] = _draft[index] with { Layer = layer };
            RenderPreview();
            MarkDirty();
        };
        _animation.Toggled += (_, _) =>
        {
            if (_updating) return;
            _setAnimationEnabled?.Invoke(_animation.IsOn);
        };
        _refresh.Click += async (_, _) => await RefreshAsync();
        _save.Click += async (_, _) => await SaveAsync();
        _discard.Click += async (_, _) => await RunAsync(async () =>
        {
            // A conflict draft is persisted by AquariumService so it can
            // survive a restart. Discarding it in the editor must clear that
            // durable copy as well as the in-memory preview.
            if (_service?.PendingScene is not null)
                await _service.ClearConflictDraftAsync();
            ResetDraft();
        });
        _reapply.Click += async (_, _) =>
        {
            if (_service?.State is not { } state) return;
            _baseSceneVersion = state.SceneVersion;
            _conflict = false;
            await SaveAsync();
        };
        Loaded += (_, _) =>
        {
            if (_disposed) return;
            Subscribe();
            _preview.SetVisible(_active);
            Render();
        };
        Unloaded += (_, _) =>
        {
            HidePackReveal();
            Unsubscribe();
            _preview.SetVisible(false);
        };
    }

    internal void Initialize(AquariumService service, LocalizationService localization,
        Func<bool> animationEnabled, Action<bool> setAnimationEnabled)
    {
        Unsubscribe();
        _service = service;
        _localization = localization;
        _animationEnabled = animationEnabled;
        _setAnimationEnabled = setAnimationEnabled;
        if (IsLoaded) Subscribe();
        Render();
    }

    public async Task RefreshAsync()
    {
        if (_busy || _service is null) return;
        await RunAsync(async () =>
        {
            bool success = await _service.RefreshAsync();
            if (success && _service.State?.Initialized != true)
                success = await _service.InitializeAsync();
            if (!success) _noticeKey = ErrorKey();
        });
    }

    public void RefreshLocalizedContent() => Render();

    internal void SetActive(bool active)
    {
        bool becameActive = active && !_active;
        _active = active && !_disposed;
        _preview.SetVisible(_active);
        if (becameActive && !_disposed) _ = RefreshAsync();
    }

    internal void SetBackpackActive(bool active)
    {
        bool becameActive = active && !_backpackActive;
        _backpackActive = active && !_disposed;
        if (becameActive && !_disposed) _ = RefreshAsync();
        if (becameActive && !_disposed) _ = App.Current.WildlifeService.RefreshAsync();
        if (becameActive && !_disposed) _ = App.Current.FruitService.RefreshAsync();
    }

    internal void SetStoreActive(bool active)
    {
        bool becameActive = active && !_storeActive;
        _storeActive = active && !_disposed;
        if (becameActive && !_disposed) _ = RefreshAsync();
    }

    internal void AttachExternalBackpack(AquariumBackpackSection page)
    {
        _externalBackpack = page;
        RenderExternalBackpack();
        UpdateStatus();
    }

    internal void AttachExternalStore(AquariumStoreSection page)
    {
        _externalStore = page;
        RenderStore();
        UpdateStatus();
    }

    public void Dispose()
    {
        if (_disposed) return;
        _disposed = true;
        HidePackReveal();
        Unsubscribe();
        _preview.Dispose();
    }

    private void Subscribe()
    {
        if (_subscribed || _service is null || _localization is null) return;
        _service.Changed += OnChanged;
        App.Current.WildlifeService.Changed += OnChanged;
        App.Current.FruitService.Changed += OnChanged;
        _service.Opened += OnPackOpened;
        _localization.LanguageChanged += OnChanged;
        _subscribed = true;
    }

    private void Unsubscribe()
    {
        if (!_subscribed) return;
        if (_service is not null) _service.Changed -= OnChanged;
        App.Current.WildlifeService.Changed -= OnChanged;
        App.Current.FruitService.Changed -= OnChanged;
        if (_service is not null) _service.Opened -= OnPackOpened;
        if (_localization is not null) _localization.LanguageChanged -= OnChanged;
        _subscribed = false;
    }

    private void OnChanged() => _queue.TryEnqueue(() => { if (!_disposed && IsLoaded) Render(); });

    private void Render()
    {
        if (_disposed || _service is null || _localization is null) return;
        if (!string.Equals(_accountId, _service.CurrentUserId, StringComparison.Ordinal))
        {
            HidePackReveal();
            _accountId = _service.CurrentUserId;
            _draft.Clear();
            _hasDraft = _dirty = _conflict = false;
            _selectedId = null;
            _noticeKey = null;
        }
        AquariumState? state = _service.State;
        if (state is not null && (!_hasDraft || !_dirty))
        {
            _draft.Clear();
            _draft.AddRange(state.Scene);
            _baseSceneVersion = state.SceneVersion;
            if (!_hasDraft && _service.PendingScene is { } pending)
            {
                _draft.Clear();
                _draft.AddRange(pending);
                _dirty = true;
                _conflict = _service.ErrorCode == "scene_conflict";
            }
            _hasDraft = true;
        }
        foreach (ComboBoxItem item in _layer.Items.OfType<ComboBoxItem>())
            if (item.Tag is int layer)
                item.Content = LayerLabel(layer);
        _updating = true;
        _animation.IsOn = _animationEnabled?.Invoke() ?? true;
        _updating = false;
        // The settings editor is a second scene tree rendered alongside the
        // desktop widget. Keep this preview static: running a second set of
        // WinUI Composition loops while the desktop scene is being refreshed
        // can corrupt native composition state on older Windows App SDK builds.
        _preview.SetAnimationEnabled(false);
        _preview.IsHitTestVisible = !_busy;
        RenderPreview();
        UpdateSelection();
        RenderStore();
        RenderBackpack(_backpack, _backpackPrimaryCategories, _backpackCategories);
        RenderExternalBackpack();
        UpdateStatus();
    }

    private void RenderPreview()
    {
        if (_service is null) return;
        AquariumState? state = _service.State;
        IReadOnlyList<AquariumPlacement> displayDraft = state is null
            ? _draft
            : AquariumSceneProjection.ForDisplay(
                state with { Scene = _draft.ToArray() },
                _service.Catalog,
                includeFirstUseFish: true);
        if (_active)
        {
            _preview.Render(displayDraft, state?.Inventory ?? [], _service.Catalog);
            _preview.Select(_selectedId, notify: false);
        }
    }

    private void RenderStore()
    {
        if (_externalStore is not { } store) return;
        store.CategoriesHost.Children.Clear();
        store.ItemsHost.Children.Clear();
        if (_service is null) return;
        if (!AquariumStoreBrowse.Groups.Any(group => group.Id == _selectedStoreGroup))
        {
            _selectedStoreGroup = AquariumStoreBrowse.Groups[0].Id;
            _selectedStoreSubcategory = AquariumStoreBrowse.Groups[0].Subcategories.FirstOrDefault() ?? "all";
        }
        var primaryCategories = new WrapPanel { HorizontalSpacing = 6, VerticalSpacing = 6 };
        store.CategoriesHost.Children.Add(primaryCategories);
        foreach (AquariumStoreGroup group in AquariumStoreBrowse.Groups)
        {
            int count = _service.Catalog.Count(item => AquariumStoreBrowse.Matches(item, group.Id, "all"));
            primaryCategories.Children.Add(StoreFilterButton(
                $"{L("Aquarium.Store.Group." + group.Id)} ({count})",
                group.Id == _selectedStoreGroup, () =>
                {
                    _selectedStoreGroup = group.Id;
                    _selectedStoreSubcategory = group.Subcategories.FirstOrDefault() ?? "all";
                    RenderStore();
                }));
        }
        IReadOnlyList<string> filters = AquariumStoreBrowse.Subcategories(_selectedStoreGroup);
        if (filters.Count > 0)
        {
            var subcategories = new WrapPanel { HorizontalSpacing = 6, VerticalSpacing = 6 };
            store.CategoriesHost.Children.Add(subcategories);
            foreach (string subcategory in filters)
            {
                int count = _service.Catalog.Count(item =>
                    AquariumStoreBrowse.Matches(item, _selectedStoreGroup, subcategory));
                string labelKey = _selectedStoreGroup == "fish"
                    ? "Aquarium.Habitat." + subcategory
                    : subcategory == "all" ? "Aquarium.Store.All" : "Aquarium.Category." + subcategory;
                subcategories.Children.Add(StoreFilterButton($"{L(labelKey)} ({count})",
                    subcategory == _selectedStoreSubcategory, () =>
                    {
                        _selectedStoreSubcategory = subcategory;
                        RenderStore();
                    }));
            }
        }
        var cards = new WrapPanel
        {
            Orientation = Orientation.Horizontal,
            HorizontalSpacing = 8,
            VerticalSpacing = 8
        };
        foreach (AquariumCatalogItem product in _service.Catalog.Where(item =>
            AquariumStoreBrowse.Matches(item, _selectedStoreGroup, _selectedStoreSubcategory)))
        {
            var buy = ActionButton("Aquarium.Settings.Buy");
            buy.IsEnabled = CanOperate && (_service.State?.Coins ?? 0) >= product.Price;
            buy.Click += async (_, _) => await RunAsync(async () =>
            {
                bool success = await _service.BuyAsync(product.Id);
                _noticeKey = success ? null : ErrorKey();
            });
            cards.Children.Add(StoreProductCard(product, F("Aquarium.Settings.Price", product.Price), buy));
        }
        store.ItemsHost.Children.Add(cards);
    }

    private Button StoreFilterButton(string text, bool selected, Action clicked)
    {
        var button = new Button
        {
            Content = text,
            Padding = new Thickness(10, 5, 10, 5),
            MinHeight = 30,
            FontWeight = selected ? Microsoft.UI.Text.FontWeights.SemiBold : Microsoft.UI.Text.FontWeights.Normal,
            Opacity = selected ? 1 : .72,
            IsEnabled = !_busy
        };
        button.Click += (_, _) => clicked();
        return button;
    }

    private void RenderBackpack(StackPanel host, StackPanel primaryCategories, StackPanel categories)
    {
        RenderBackpackCategories(primaryCategories, categories);
        host.Children.Clear();
        if (_service?.State is not { } state || state.Inventory.Count == 0)
        {
            host.Children.Add(Label("Aquarium.Settings.EmptyBackpack", 13, .7));
            return;
        }
        IReadOnlyList<AquariumOwnedProduct> ownedProducts = AquariumInventoryPresentation.AquariumItems(
            AquariumInventoryPresentation.Summarize(state.Inventory, _draft, _service.Catalog));
        string selectedCategory = AquariumInventoryPresentation.AquariumCategories.Contains(_selectedBackpackCategory,
            StringComparer.Ordinal) ? _selectedBackpackCategory : AquariumInventoryPresentation.AquariumCategories[0];
        if (selectedCategory == "fish")
            host.Children.Add(HabitatFilter(_selectedBackpackHabitat, habitat =>
            {
                _selectedBackpackHabitat = habitat;
                RenderBackpack(_backpack, _backpackPrimaryCategories, _backpackCategories);
            }));
        AquariumOwnedProduct[] products = ownedProducts
            .Where(item => item.Product.Category == selectedCategory &&
                (selectedCategory != "fish" || AquariumInventoryPresentation.MatchesHabitat(item.Product, _selectedBackpackHabitat)))
            .ToArray();
        AddBackpackCards(host, products, showEdit: true);
    }

    private void RenderExternalBackpack()
    {
        if (_externalBackpack is not { } page) return;
        page.CoinBalanceLabel.Text = F("Aquarium.Settings.Coins", _service?.State?.Coins ?? 0);
        page.PrimaryCategoriesHost.Children.Clear();
        page.ItemsHost.Children.Clear();
        page.PrimaryCategoriesHost.Children.Add(CategoryButton(L("Aquarium.Backpack.Aquarium"), false, null,
            page.RequestAquariumSettings));
        page.PrimaryCategoriesHost.Children.Add(CategoryButton(L("Aquarium.Backpack.General"),
            _selectedExternalBackpackGroup == "general", null, () =>
            { _selectedExternalBackpackGroup = "general"; RenderExternalBackpack(); }));
        page.PrimaryCategoriesHost.Children.Add(CategoryButton("动物",
            _selectedExternalBackpackGroup == "wildlife", null, () =>
            { _selectedExternalBackpackGroup = "wildlife"; RenderExternalBackpack(); }));
        page.PrimaryCategoriesHost.Children.Add(CategoryButton("果实",
            _selectedExternalBackpackGroup == "fruit", null, () =>
            { _selectedExternalBackpackGroup = "fruit"; RenderExternalBackpack(); }));
        if (_selectedExternalBackpackGroup == "wildlife")
        {
            RenderWildlifeBackpack(page.ItemsHost);
            return;
        }
        if (_selectedExternalBackpackGroup == "fruit")
        {
            RenderFruitBackpack(page.ItemsHost);
            return;
        }
        var owned = AquariumInventoryPresentation.Summarize(_service?.State?.Inventory ?? [], _draft,
            _service?.Catalog ?? []);
        AddBackpackCards(page.ItemsHost, AquariumInventoryPresentation.GeneralItems(owned), showEdit: false);
    }

    private static void RenderWildlifeBackpack(StackPanel host)
    {
        WildlifeService service = App.Current.WildlifeService;
        var catalog = service.Catalog.ToDictionary(item => item.Id, StringComparer.Ordinal);
        var cards = new WrapPanel { Orientation = Orientation.Horizontal, HorizontalSpacing = 8, VerticalSpacing = 8 };
        foreach (WildlifeInventoryItem item in service.Inventory.Where(item => item.Quantity > 0))
        {
            if (!catalog.TryGetValue(item.SpeciesId, out WildlifeCatalogItem? species)) continue;
            var content = new StackPanel { Spacing = 4, HorizontalAlignment = HorizontalAlignment.Center };
            if (WildlifeAssetCatalog.GetImage(item.SpeciesId) is { } source)
                content.Children.Add(new Image { Source = source, Width = 54, Height = 54, Stretch = Stretch.Uniform });
            content.Children.Add(new TextBlock { Text = species.Name, FontSize = 13, TextAlignment = TextAlignment.Center });
            content.Children.Add(new TextBlock { Text = $"×{item.Quantity}", FontSize = 12, Opacity = .7, TextAlignment = TextAlignment.Center });
            cards.Children.Add(new Border
            {
                Width = 112, MinHeight = 86, Padding = new Thickness(8), CornerRadius = new CornerRadius(8),
                Background = (Brush)Application.Current.Resources["CardBackgroundFillColorDefaultBrush"], Child = content
            });
        }
        if (cards.Children.Count == 0)
            host.Children.Add(new TextBlock { Text = "尚未收集动物", Opacity = .7 });
        else host.Children.Add(cards);
    }

    private static void RenderFruitBackpack(StackPanel host)
    {
        FruitService service = App.Current.FruitService;
        var catalog = service.Catalog.ToDictionary(item => item.Id, StringComparer.Ordinal);
        var cards = new WrapPanel { Orientation = Orientation.Horizontal, HorizontalSpacing = 8, VerticalSpacing = 8 };
        foreach (FruitInventoryItem item in service.Inventory.Where(item => item.Quantity > 0))
        {
            if (!catalog.TryGetValue(item.FruitId, out FruitCatalogItem? fruit)) continue;
            var content = new StackPanel { Spacing = 4, HorizontalAlignment = HorizontalAlignment.Center };
            content.Children.Add(new Image
            {
                Source = FruitAssetCatalog.GetImageSource(item.FruitId), Width = 54, Height = 54,
                Stretch = Stretch.Uniform
            });
            content.Children.Add(new TextBlock { Text = fruit.Name, FontSize = 13, TextAlignment = TextAlignment.Center });
            content.Children.Add(new TextBlock { Text = $"×{item.Quantity}", FontSize = 12, Opacity = .7, TextAlignment = TextAlignment.Center });
            cards.Children.Add(new Border
            {
                Width = 112, MinHeight = 86, Padding = new Thickness(8), CornerRadius = new CornerRadius(8),
                Background = (Brush)Application.Current.Resources["CardBackgroundFillColorDefaultBrush"], Child = content
            });
        }
        if (cards.Children.Count == 0)
            host.Children.Add(new TextBlock { Text = "尚未收集果实", Opacity = .7 });
        else host.Children.Add(cards);
    }

    private void AddBackpackCards(StackPanel host, IReadOnlyList<AquariumOwnedProduct> products, bool showEdit)
    {
        if (products.Count > 0)
        {
            var cards = new WrapPanel
            {
                Orientation = Orientation.Horizontal,
                HorizontalSpacing = 8,
                VerticalSpacing = 8
            };
            foreach (AquariumOwnedProduct product in products)
                cards.Children.Add(BackpackCard(product, showEdit));
            host.Children.Add(cards);
        }
        if (products.Count == 0)
            host.Children.Add(Label("Aquarium.Settings.EmptyBackpack", 13, .7));
    }

    private void RenderBackpackCategories(StackPanel primaryCategories, StackPanel categories)
    {
        primaryCategories.Children.Clear();
        categories.Orientation = Orientation.Vertical;
        categories.Children.Clear();
        primaryCategories.Children.Add(CategoryButton(L("Aquarium.Backpack.Aquarium"), true, null, null));
        if (_service is null) return;
        AquariumState? state = _service.State;
        var owned = AquariumInventoryPresentation.Summarize(state?.Inventory ?? [], _draft, _service.Catalog);
        var categoryButtons = new WrapPanel { HorizontalSpacing = 6, VerticalSpacing = 6 };
        categories.Children.Add(categoryButtons);
        foreach (string category in AquariumInventoryPresentation.AquariumCategories)
        {
            int count = owned.Where(item => item.Product.Category == category)
                .Sum(item => item.OwnedCount);
            categoryButtons.Children.Add(CategoryButton(L("Aquarium.Category." + category) + $" ({count})",
                string.Equals(_selectedBackpackCategory, category, StringComparison.Ordinal), category, null));
        }
    }

    private ComboBox HabitatFilter(string selected, Action<string> changed)
    {
        var filter = new ComboBox { MinWidth = 160, HorizontalAlignment = HorizontalAlignment.Left };
        Microsoft.UI.Xaml.Automation.AutomationProperties.SetName(filter, L("Aquarium.Category.fish"));
        foreach (string habitat in AquariumInventoryPresentation.FishHabitats)
        {
            var option = new ComboBoxItem { Content = L("Aquarium.Habitat." + habitat), Tag = habitat };
            filter.Items.Add(option);
            if (habitat == selected) filter.SelectedItem = option;
        }
        filter.SelectionChanged += (_, _) =>
        {
            if (filter.SelectedItem is ComboBoxItem { Tag: string habitat } && habitat != selected)
                changed(habitat);
        };
        return filter;
    }

    private Button CategoryButton(string text, bool selected, string? category, Action? primaryAction)
    {
        var button = new Button
        {
            Content = text,
            Tag = category,
            Padding = new Thickness(10, 5, 10, 5),
            MinHeight = 30,
            IsEnabled = category is not null && !_busy
        };
        if (category is null) button.IsEnabled = true;
        button.Opacity = selected ? 1 : .72;
        button.Click += (_, _) =>
        {
            if (category is null)
            {
                primaryAction?.Invoke();
                return;
            }
            _selectedBackpackCategory = category;
            RenderBackpack(_backpack, _backpackPrimaryCategories, _backpackCategories);
        };
        return button;
    }

    private Border BackpackCard(AquariumOwnedProduct owned, bool showEdit)
    {
        AquariumCatalogItem product = owned.Product;
        var panel = new StackPanel { Spacing = 3, HorizontalAlignment = HorizontalAlignment.Stretch };
        var imageHost = new Grid { Height = 46, HorizontalAlignment = HorizontalAlignment.Stretch };
        imageHost.Children.Add(ProductVisual(product, 54, 43));
        var quantity = new Border
        {
            Background = new SolidColorBrush(Microsoft.UI.Colors.Black),
            CornerRadius = new CornerRadius(9), Padding = new Thickness(5, 1, 5, 1),
            HorizontalAlignment = HorizontalAlignment.Right, VerticalAlignment = VerticalAlignment.Top,
            Child = new TextBlock { Text = $"×{owned.OwnedCount}", Foreground = new SolidColorBrush(Microsoft.UI.Colors.White), FontSize = 11 }
        };
        imageHost.Children.Add(quantity);
        if (showEdit && owned.NextToRecall is { } editable && product.Category is not ("fish" or "jellyfish") && !IsCardPack(product.Category))
        {
            var edit = ActionButton("Aquarium.Settings.Edit");
            edit.FontSize = 11;
            edit.MinWidth = 0;
            edit.MinHeight = 22;
            edit.Padding = new Thickness(3, 0, 3, 0);
            edit.HorizontalAlignment = HorizontalAlignment.Right;
            edit.VerticalAlignment = VerticalAlignment.Bottom;
            edit.IsEnabled = !_busy;
            edit.Click += (_, _) => { Select(editable.Id); _preview.Select(editable.Id, notify: false); };
            imageHost.Children.Add(edit);
        }
        panel.Children.Add(imageHost);
        panel.Children.Add(new TextBlock
        {
            Text = ProductName(product), FontSize = 12,
            FontWeight = Microsoft.UI.Text.FontWeights.SemiBold,
            TextWrapping = TextWrapping.Wrap, TextAlignment = TextAlignment.Center, MaxLines = 2
        });
        panel.Children.Add(new TextBlock
        {
            Text = IsCardPack(product.Category) ? L("Aquarium.Settings.CardPack.Hint")
                : $"{L("Aquarium.Settings.Placed")} {owned.PlacedCount}/{owned.OwnedCount}",
            FontSize = 11, Opacity = .72, TextAlignment = TextAlignment.Center
        });
        var actions = new StackPanel { Orientation = Orientation.Horizontal, Spacing = 4, HorizontalAlignment = HorizontalAlignment.Center };
        if (IsCardPack(product.Category))
        {
            var open = ActionButton("Aquarium.Settings.CardPack.Open");
            open.FontSize = 11;
            open.MinHeight = 26;
            open.Padding = new Thickness(8, 2, 8, 2);
            open.IsEnabled = !_busy && !_packEffectRunning && CanOperate && owned.NextToPlace is not null;
            open.Click += async (_, _) =>
            {
                if (owned.NextToPlace is not { } item || _service is null) return;
                await RunAsync(async () =>
                {
                    bool success = await _service.OpenPackAsync(item.Id);
                    if (!success) _noticeKey = ErrorKey();
                });
            };
            actions.Children.Add(open);
        }
        else
        {
            var place = SmallBackpackAction("Aquarium.Settings.Place", "+");
            place.IsEnabled = !_busy && _service?.IsAvailable == true && owned.NextToPlace is not null;
            place.Click += (_, _) => { if (owned.NextToPlace is { } item) TogglePlacement(item, product); };
            actions.Children.Add(place);
            var recall = SmallBackpackAction("Aquarium.Settings.Recall", "−");
            recall.IsEnabled = !_busy && _service?.IsAvailable == true && owned.NextToRecall is not null;
            recall.Click += (_, _) => { if (owned.NextToRecall is { } item) TogglePlacement(item, product); };
            actions.Children.Add(recall);
        }
        panel.Children.Add(actions);
        return new Border
        {
            Width = 146, Height = 150, Padding = new Thickness(6), CornerRadius = new CornerRadius(10),
            Background = Application.Current.Resources["CardBackgroundFillColorDefaultBrush"] as Brush,
            Child = panel
        };
    }

    private Button SmallBackpackAction(string key, string symbol)
    {
        var button = new Button
        {
            Content = symbol,
            Width = 48,
            Height = 26,
            FontSize = 18,
            Padding = new Thickness(0)
        };
        ToolTipService.SetToolTip(button, L(key));
        Microsoft.UI.Xaml.Automation.AutomationProperties.SetName(button, L(key));
        return button;
    }

    private Border StoreProductCard(AquariumCatalogItem product, string detail, Button action)
    {
        var panel = new StackPanel { Spacing = 4 };
        panel.Children.Add(new Grid
        {
            Height = 62,
            Children = { ProductVisual(product, 68, 60) }
        });
        var name = new TextBlock
        {
            Text = ProductName(product),
            Height = 34,
            FontSize = 12,
            FontWeight = Microsoft.UI.Text.FontWeights.SemiBold,
            TextWrapping = TextWrapping.Wrap,
            TextAlignment = TextAlignment.Center,
            MaxLines = 2
        };
        ToolTipService.SetToolTip(name, name.Text);
        panel.Children.Add(name);
        panel.Children.Add(new TextBlock
        {
            Text = detail, FontSize = 11, Opacity = .72,
            TextAlignment = TextAlignment.Center, TextWrapping = TextWrapping.NoWrap
        });
        action.HorizontalAlignment = HorizontalAlignment.Stretch;
        action.MinHeight = 28;
        action.MinWidth = 0;
        action.FontSize = 12;
        panel.Children.Add(action);
        return new Border
        {
            Width = 150, Height = 170, Padding = new Thickness(8),
            CornerRadius = new CornerRadius(10),
            Background = Application.Current.Resources["CardBackgroundFillColorDefaultBrush"] as Brush,
            Child = panel
        };
    }

    private FrameworkElement ProductVisual(AquariumCatalogItem product, double width, double height)
    {
        return IsCardPack(product.Category) ? PackVisual(width, height) : new Image
        {
            Source = AquariumSceneView.Asset(product.AssetKey), Width = width, Height = height,
            Stretch = Stretch.Uniform, HorizontalAlignment = HorizontalAlignment.Center
        };
    }

    private static Image PackVisual(double width, double height) => new()
    {
        Source = AquariumSceneView.Asset(AquariumService.AquariumCardPackItemId),
        Width = width, Height = height, Stretch = Stretch.Uniform,
        HorizontalAlignment = HorizontalAlignment.Center, IsHitTestVisible = false
    };

    private void TogglePlacement(AquariumInventoryItem owned, AquariumCatalogItem product)
    {
        if (IsCardPack(product.Category)) return;
        int index = _draft.FindIndex(item => item.InstanceId == owned.Id);
        if (index >= 0)
        {
            _draft.RemoveAt(index);
            if (_selectedId == owned.Id) _selectedId = null;
        }
        else
        {
            bool fish = product.Category is "fish" or "jellyfish";
            int count = _draft.Count(placement => IsFish(placement.InstanceId) == fish);
            _draft.Add(new AquariumPlacement(owned.Id, .25 + count % 4 * .16,
                AquariumArtworkCatalog.DefaultPlacementY(product.AssetKey, fish, count), 1));
            if (!fish) _selectedId = owned.Id;
        }
        MarkDirty();
        Render();
    }

    private void BuildPackRevealOverlay()
    {
        _packOverlay.TabFocusNavigation = KeyboardNavigationMode.Cycle;
        _packOverlay.Background = new SolidColorBrush(Microsoft.UI.Colors.Transparent);
        _packOverlay.Children.Add(new Border
        {
            Background = new LinearGradientBrush
            {
                StartPoint = new Windows.Foundation.Point(0, 0),
                EndPoint = new Windows.Foundation.Point(1, 1),
                GradientStops =
                {
                    new GradientStop { Color = Windows.UI.Color.FromArgb(240, 16, 47, 54), Offset = 0 },
                    new GradientStop { Color = Windows.UI.Color.FromArgb(232, 23, 107, 115), Offset = .5 },
                    new GradientStop { Color = Windows.UI.Color.FromArgb(245, 10, 31, 37), Offset = 1 }
                }
            },
            HorizontalAlignment = HorizontalAlignment.Stretch,
            VerticalAlignment = VerticalAlignment.Stretch
        });

        for (int index = 0; index < 3; index++)
        {
            var ripple = new Ellipse
            {
                Width = 420, Height = 420,
                Stroke = new SolidColorBrush(index == 0 ? PackGoldColor : PackAquaColor),
                StrokeThickness = index == 0 ? 2 : 1,
                HorizontalAlignment = HorizontalAlignment.Center,
                VerticalAlignment = VerticalAlignment.Center,
                RenderTransformOrigin = new Windows.Foundation.Point(.5, .5),
                RenderTransform = new ScaleTransform(),
                Opacity = 0,
                IsHitTestVisible = false
            };
            _packRipples.Add(ripple);
            _packOverlay.Children.Add(ripple);
        }

        var particles = new Canvas
        {
            HorizontalAlignment = HorizontalAlignment.Stretch,
            VerticalAlignment = VerticalAlignment.Stretch,
            IsHitTestVisible = false
        };
        _packOverlay.Children.Add(particles);
        Random random = new(80617);
        for (int index = 0; index < 40; index++)
        {
            double size = 4 + random.NextDouble() * 8;
            var particle = new Ellipse
            {
                Width = size,
                Height = size,
                Fill = new SolidColorBrush(index % 3 == 0 ? PackGoldColor : PackAquaColor),
                Opacity = 0,
                RenderTransform = new TranslateTransform()
            };
            Canvas.SetLeft(particle, 50);
            Canvas.SetTop(particle, 50);
            particles.Children.Add(particle);
            _packParticles.Add(particle);
        }

        _packCard.Background = new LinearGradientBrush
        {
            StartPoint = new Windows.Foundation.Point(0, 0),
            EndPoint = new Windows.Foundation.Point(1, 1),
            GradientStops =
            {
                new GradientStop { Color = PackDeepColor, Offset = 0 },
                new GradientStop { Color = PackTealColor, Offset = 1 }
            }
        };
        _packCard.BorderBrush = new SolidColorBrush(PackGoldColor);
        _packCard.BorderThickness = new Thickness(1.5);
        _packCard.RenderTransform = _packCardScale;
        var content = new StackPanel { Spacing = 8, HorizontalAlignment = HorizontalAlignment.Center };
        content.Children.Add(PackVisual(76, 64));
        content.Children.Add(_packRevealTitle);
        content.Children.Add(_packRevealImage);
        content.Children.Add(_packRevealResult);
        _packConfirm.HorizontalAlignment = HorizontalAlignment.Center;
        _packConfirm.MinWidth = 120;
        _packConfirm.Click += (_, _) =>
        {
            HidePackReveal();
            if (!_disposed) Render();
        };
        content.Children.Add(_packConfirm);
        _packCard.Child = content;
        _packOverlay.Children.Add(_packCard);
    }

    private void OnPackOpened(AquariumOpenedItem reward)
    {
        AquariumService? service = _service;
        string? accountId = service?.CurrentUserId;
        _queue.TryEnqueue(() =>
        {
            if (!_disposed && IsLoaded && (_active || _backpackActive) && service is not null && accountId is not null &&
                ReferenceEquals(service, _service) && service.CurrentUserId == accountId &&
                ReferenceEquals(service.LastOpenedItem, reward)) ShowPackRevealCore(reward);
        });
    }

    private void ShowPackRevealCore(AquariumOpenedItem reward)
    {
        if (_packEffectRunning || XamlRoot?.Content is not Panel) return;
        _packEffectRunning = true;
        _packPreviousFocus = FocusManager.GetFocusedElement(XamlRoot) as Control;
        _packRevealTitle.Text = L("Aquarium.Settings.CardPack.RevealTitle");
        AquariumCatalogItem? product = Product(reward.ItemId);
        string name = product is null ? reward.Name : ProductName(product);
        _packRevealResult.Text = F("Aquarium.Settings.CardPack.Reward", name);
        _packRevealImage.Source = product is null ? null : AquariumSceneView.Asset(product.AssetKey);
        AttachPackOverlayToSettingsRoot();
        _packOverlay.Visibility = Visibility.Visible;
        _packOverlay.Opacity = 0;
        _packConfirm.Focus(FocusState.Programmatic);
        _packCard.Opacity = 1;
        _packCardScale.ScaleX = .72;
        _packCardScale.ScaleY = .72;
        _packRevealImage.Opacity = 1;

        if (!WindowsCompatibilityService.ShouldAnimate)
        {
            _packOverlay.Opacity = 1;
            _packCardScale.ScaleX = 1;
            _packCardScale.ScaleY = 1;
            foreach (FrameworkElement particle in _packParticles) particle.Opacity = 0;
            return;
        }

        var intro = new Storyboard();
        _packStoryboards.Add(intro);
        var fade = new DoubleAnimation { From = 0, To = 1, Duration = TimeSpan.FromMilliseconds(260) };
        Storyboard.SetTarget(fade, _packOverlay);
        Storyboard.SetTargetProperty(fade, nameof(UIElement.Opacity));
        intro.Children.Add(fade);
        var scaleX = new DoubleAnimationUsingKeyFrames();
        scaleX.KeyFrames.Add(new EasingDoubleKeyFrame { KeyTime = TimeSpan.FromMilliseconds(0), Value = .72 });
        scaleX.KeyFrames.Add(new EasingDoubleKeyFrame { KeyTime = TimeSpan.FromMilliseconds(380), Value = 1.06, EasingFunction = new BackEase { Amplitude = .4 } });
        scaleX.KeyFrames.Add(new EasingDoubleKeyFrame { KeyTime = TimeSpan.FromMilliseconds(600), Value = 1 });
        Storyboard.SetTarget(scaleX, _packCardScale);
        Storyboard.SetTargetProperty(scaleX, nameof(ScaleTransform.ScaleX));
        intro.Children.Add(scaleX);
        var scaleY = new DoubleAnimationUsingKeyFrames();
        scaleY.KeyFrames.Add(new EasingDoubleKeyFrame { KeyTime = TimeSpan.FromMilliseconds(0), Value = .72 });
        scaleY.KeyFrames.Add(new EasingDoubleKeyFrame { KeyTime = TimeSpan.FromMilliseconds(380), Value = 1.06, EasingFunction = new BackEase { Amplitude = .4 } });
        scaleY.KeyFrames.Add(new EasingDoubleKeyFrame { KeyTime = TimeSpan.FromMilliseconds(600), Value = 1 });
        Storyboard.SetTarget(scaleY, _packCardScale);
        Storyboard.SetTargetProperty(scaleY, nameof(ScaleTransform.ScaleY));
        intro.Children.Add(scaleY);
        var rewardFade = new DoubleAnimation { From = 0, To = 1, BeginTime = TimeSpan.FromMilliseconds(380), Duration = TimeSpan.FromMilliseconds(420) };
        Storyboard.SetTarget(rewardFade, _packRevealImage);
        Storyboard.SetTargetProperty(rewardFade, nameof(UIElement.Opacity));
        intro.Children.Add(rewardFade);
        intro.Begin();

        for (int index = 0; index < _packRipples.Count; index++)
        {
            Ellipse ripple = _packRipples[index];
            var expansion = new Storyboard();
            _packStoryboards.Add(expansion);
            var transform = (ScaleTransform)ripple.RenderTransform;
            foreach (string axis in new[] { nameof(ScaleTransform.ScaleX), nameof(ScaleTransform.ScaleY) })
            {
                var scale = new DoubleAnimation
                {
                    From = .35, To = 2.4, BeginTime = TimeSpan.FromMilliseconds(160 + index * 160),
                    Duration = TimeSpan.FromMilliseconds(1400),
                    EasingFunction = new CubicEase { EasingMode = EasingMode.EaseOut }
                };
                Storyboard.SetTarget(scale, transform);
                Storyboard.SetTargetProperty(scale, axis);
                expansion.Children.Add(scale);
            }
            var opacity = new DoubleAnimation
            {
                From = .65, To = 0, BeginTime = TimeSpan.FromMilliseconds(160 + index * 160),
                Duration = TimeSpan.FromMilliseconds(1400)
            };
            Storyboard.SetTarget(opacity, ripple);
            Storyboard.SetTargetProperty(opacity, nameof(UIElement.Opacity));
            expansion.Children.Add(opacity);
            expansion.Begin();
        }

        foreach (FrameworkElement particle in _packParticles)
        {
            Canvas.SetLeft(particle, Math.Max(100, XamlRoot?.Size.Width / 2 ?? 350));
            Canvas.SetTop(particle, Math.Max(100, XamlRoot?.Size.Height / 2 ?? 300));
            particle.Opacity = 1;
            if (particle.RenderTransform is not TranslateTransform transform) continue;
            transform.X = 0;
            transform.Y = 0;
            double angle = Random.Shared.NextDouble() * Math.PI * 2;
            double distance = 220 + Random.Shared.NextDouble() * 260;
            var burst = new Storyboard();
            _packStoryboards.Add(burst);
            var x = new DoubleAnimation { From = 0, To = Math.Cos(angle) * distance, Duration = TimeSpan.FromMilliseconds(1000 + Random.Shared.Next(450)), EasingFunction = new CubicEase { EasingMode = EasingMode.EaseOut } };
            Storyboard.SetTarget(x, transform);
            Storyboard.SetTargetProperty(x, nameof(TranslateTransform.X));
            burst.Children.Add(x);
            var y = new DoubleAnimation { From = 0, To = Math.Sin(angle) * distance, Duration = x.Duration, EasingFunction = new CubicEase { EasingMode = EasingMode.EaseOut } };
            Storyboard.SetTarget(y, transform);
            Storyboard.SetTargetProperty(y, nameof(TranslateTransform.Y));
            burst.Children.Add(y);
            var opacity = new DoubleAnimation { From = 1, To = 0, BeginTime = TimeSpan.FromMilliseconds(350), Duration = TimeSpan.FromMilliseconds(1000) };
            Storyboard.SetTarget(opacity, particle);
            Storyboard.SetTargetProperty(opacity, nameof(UIElement.Opacity));
            burst.Children.Add(opacity);
            burst.Begin();
        }
    }

    private void AttachPackOverlayToSettingsRoot()
    {
        if (_packOverlayHost is not null) return;
        if (XamlRoot?.Content is Panel root)
        {
            _packOverlayHost = root;
            root.Children.Add(_packOverlay);
            Canvas.SetZIndex(_packOverlay, 999);
            if (root is Grid grid)
            {
                Grid.SetRowSpan(_packOverlay, Math.Max(1, grid.RowDefinitions.Count));
                Grid.SetColumnSpan(_packOverlay, Math.Max(1, grid.ColumnDefinitions.Count));
            }
        }
    }

    private void HidePackReveal()
    {
        foreach (Storyboard storyboard in _packStoryboards) storyboard.Stop();
        _packStoryboards.Clear();
        _packOverlay.Visibility = Visibility.Collapsed;
        _packEffectRunning = false;
        foreach (FrameworkElement particle in _packParticles) particle.Opacity = 0;
        foreach (Ellipse ripple in _packRipples) ripple.Opacity = 0;
        if (_packOverlayHost is { } host)
        {
            host.Children.Remove(_packOverlay);
            _packOverlayHost = null;
        }
        if (!_disposed && _packPreviousFocus is { IsLoaded: true } previousFocus)
            previousFocus.Focus(FocusState.Programmatic);
        _packPreviousFocus = null;
    }

    private bool IsFish(string instanceId)
    {
        AquariumInventoryItem? item = _service?.State?.Inventory.FirstOrDefault(item => item.Id == instanceId);
        return item is not null && Product(item.ItemId)?.Category is "fish" or "jellyfish";
    }

    private static bool IsCardPack(string? category) => category == "pack";

    private static bool CategoryMatches(string? actual, string selected) =>
        string.Equals(actual, selected, StringComparison.Ordinal);

    private void Select(string? instanceId)
    {
        _selectedId = instanceId;
        UpdateSelection();
    }

    private void UpdateSelection()
    {
        AquariumPlacement? placement = _draft.FirstOrDefault(item => item.InstanceId == _selectedId);
        AquariumInventoryItem? owned = _service?.State?.Inventory.FirstOrDefault(item => item.Id == _selectedId);
        AquariumCatalogItem? product = owned is null ? null : Product(owned.ItemId);
        _updating = true;
        _scale.IsEnabled = !_busy && placement is not null && product is not null && product.Category is not ("fish" or "jellyfish");
        _layer.IsEnabled = !_busy && placement is not null && product is not null &&
            (product.Category is "plant" or "decoration" or "coral" or "algae" or "snail");
        _scale.Value = placement?.Scale ?? 1;
        int selectedLayer = placement?.Layer is int layer &&
            layer is >= AquariumLayer.Min and <= AquariumLayer.Max
            ? layer
            : 0;
        _layer.SelectedIndex = selectedLayer - AquariumLayer.Min;
        _selection.Text = product is null ? L("Aquarium.Settings.NoSelection") : F("Aquarium.Settings.Selected", ProductName(product));
        _updating = false;
    }

    private string LayerLabel(int layer) => layer switch
    {
        -1 => L("Aquarium.Settings.Layer.Back"),
        0 => L("Aquarium.Settings.Layer.Middle"),
        1 => L("Aquarium.Settings.Layer.Front"),
        _ => $"{L("Aquarium.Settings.Layer")} {layer - AquariumLayer.Min + 1}"
    };

    private void MarkDirty()
    {
        _dirty = true;
        _noticeKey = null;
        UpdateStatus();
    }

    private void ResetDraft()
    {
        _hasDraft = false;
        _dirty = _conflict = false;
        _noticeKey = null;
        _selectedId = null;
        if (_service?.State is { } state)
        {
            _draft.Clear();
            _draft.AddRange(state.Scene);
            _baseSceneVersion = state.SceneVersion;
            _hasDraft = true;
        }
    }

    private async Task SaveAsync()
    {
        if (_service is null || !_dirty)
        {
            return;
        }
        // Keep the draft until the user explicitly retries a failed save.
        if (_busy || !CanOperate)
            return;
        await RunAsync(async () =>
        {
            bool success = await _service.SaveSceneAsync(_draft.ToArray(), _baseSceneVersion);
            if (success) { ResetDraft(); _noticeKey = "Aquarium.Settings.Saved"; }
            else
            {
                App.Log($"[Aquarium] Settings scene save rejected code={_service.ErrorCode ?? "unknown"} " +
                    $"draft={_draft.Count} baseVersion={_baseSceneVersion}");
                _conflict = _service.ErrorCode == "scene_conflict";
                _noticeKey = ErrorKey();
            }
        });
    }

    private async Task RunAsync(Func<Task> action)
    {
        if (_busy) return;
        _busy = true;
        Render();
        try { await action(); }
        catch (Exception error)
        {
            App.Log($"[Aquarium] Settings operation failed: {error.GetType().Name}");
            _noticeKey = "Aquarium.Settings.Error";
        }
        finally { _busy = false; Render(); }
    }

    private bool CanOperate => !_busy && _service?.IsAvailable == true && !_service.IsOffline && _service.State?.Initialized == true && !_service.HasPendingOperation;

    private void UpdateStatus()
    {
        _save.IsEnabled = CanOperate && _dirty;
        _discard.IsEnabled = !_busy && _dirty && _service?.HasPendingOperation != true;
        _refresh.IsEnabled = !_busy;
        _reapply.Visibility = _conflict ? Visibility.Visible : Visibility.Collapsed;
        _reapply.IsEnabled = CanOperate;
        string? key = _service?.IsAvailable != true ? "Aquarium.Settings.SignInRequired"
            : _service.IsOffline ? "Aquarium.Settings.Offline"
            : _service.HasPendingOperation ? "Aquarium.Settings.Pending"
            : _conflict ? "Aquarium.Settings.Conflict"
            : _noticeKey ?? (_dirty ? "Aquarium.Settings.Unsaved" : null);
        _status.Text = key is null ? string.Empty : L(key);
        _status.Visibility = key is null ? Visibility.Collapsed : Visibility.Visible;
        if (_externalBackpack is { } external)
        {
            external.StatusLabel.Text = _status.Text;
            external.StatusLabel.Visibility = _status.Visibility;
        }
        if (_externalStore is { } store)
        {
            store.StatusLabel.Text = _status.Text;
            store.StatusLabel.Visibility = _status.Visibility;
        }
    }

    private string ErrorKey() => _service?.ErrorCode switch
    {
        "scene_conflict" => "Aquarium.Settings.Conflict",
        "insufficient_coins" => "Aquarium.Settings.InsufficientCoins",
        "sign_in_required" => "Aquarium.Settings.SignInRequired",
        _ => _service?.IsOffline == true ? "Aquarium.Settings.Offline" : "Aquarium.Settings.Error"
    };

    private AquariumCatalogItem? Product(string itemId) => _service?.Catalog.FirstOrDefault(item => item.Id == itemId);
    private string ProductName(AquariumCatalogItem product)
    {
        string key = "Aquarium.Item." + product.Id;
        string text = L(key);
        return text == key ? product.Name : text;
    }
    private string L(string key) => _localization?.T(key) ?? LocalizationService.DefaultText(key);
    private string F(string key, params object[] args) => string.Format(CultureInfo.CurrentCulture, L(key), args);

    private static TextBlock Label(string key, double size, double opacity = 1)
    {
        var label = new TextBlock { FontSize = size, Opacity = opacity, TextWrapping = TextWrapping.Wrap, VerticalAlignment = VerticalAlignment.Center };
        Localized.SetKey(label, key);
        return label;
    }

    private static Button ActionButton(string key)
    {
        var button = new Button();
        Localized.SetKey(button, key);
        return button;
    }
}
