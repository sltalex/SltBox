using System.Globalization;
using CommunityToolkit.WinUI.Controls;
using DeskBox.Controls.WidgetContents;
using DeskBox.Services;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
using Microsoft.UI.Xaml.Media;

namespace DeskBox.Views.SettingsSections;

public sealed class AquariumSettingsSection : UserControl, IDisposable
{
    private readonly Microsoft.UI.Dispatching.DispatcherQueue _queue = Microsoft.UI.Dispatching.DispatcherQueue.GetForCurrentThread();
    private readonly AquariumSceneView _preview = new() { IsEditing = true, Width = 420, Height = 300, HorizontalAlignment = HorizontalAlignment.Center };
    private readonly TextBlock _status = new() { TextWrapping = TextWrapping.Wrap, FontSize = 13 };
    private readonly TextBlock _coins = new() { FontSize = 22, FontWeight = Microsoft.UI.Text.FontWeights.SemiBold };
    private readonly TextBlock _selection = new() { TextWrapping = TextWrapping.Wrap };
    private readonly ToggleSwitch _animation = new() { OnContent = "", OffContent = "", MinWidth = 0 };
    private readonly Slider _scale = new() { Minimum = .5, Maximum = 1.5, StepFrequency = .05, Value = 1, IsEnabled = false, Width = 190 };
    private readonly WrapPanel _storeCategories = new()
    {
        Orientation = Orientation.Horizontal,
        HorizontalSpacing = 6,
        VerticalSpacing = 6
    };
    private readonly StackPanel _store = new() { Spacing = 8 };
    private readonly StackPanel _backpack = new() { Spacing = 8 };
    private readonly StackPanel _backpackPrimaryCategories = new() { Orientation = Orientation.Horizontal, Spacing = 6 };
    private readonly StackPanel _backpackCategories = new() { Orientation = Orientation.Horizontal, Spacing = 6 };
    private AquariumBackpackSection? _externalBackpack;
    private readonly StackPanel _wallet = new() { Spacing = 8 };
    private readonly PivotItem _storeTab = new();
    private readonly PivotItem _backpackTab = new();
    private readonly PivotItem _walletTab = new();
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
    private bool _disposed;
    private string? _noticeKey;
    private string _selectedBackpackCategory = "fish";
    private string _selectedStoreCategory = "fish";
    private readonly ComboBox _layer = new() { Width = 125, IsEnabled = false };

    public AquariumSettingsSection()
    {
        var root = new StackPanel { Spacing = 12 };
        var top = new Grid { ColumnSpacing = 12 };
        top.ColumnDefinitions.Add(new ColumnDefinition());
        top.ColumnDefinitions.Add(new ColumnDefinition { Width = GridLength.Auto });
        top.Children.Add(_coins);
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
        foreach (int layer in new[] { -1, 0, 1 })
            _layer.Items.Add(new ComboBoxItem { Tag = layer });
        _layer.SelectedIndex = 1;
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
        var tabs = new Pivot();
        var storeContent = new StackPanel { Spacing = 8 };
        storeContent.Children.Add(_storeCategories);
        storeContent.Children.Add(_store);
        _storeTab.Content = storeContent;
        var backpackContent = new StackPanel { Spacing = 8 };
        backpackContent.Children.Add(_backpackPrimaryCategories);
        backpackContent.Children.Add(_backpackCategories);
        backpackContent.Children.Add(_backpack);
        _backpackTab.Content = backpackContent;
        _walletTab.Content = _wallet;
        tabs.Items.Add(_storeTab);
        tabs.Items.Add(_backpackTab);
        tabs.Items.Add(_walletTab);
        root.Children.Add(tabs);
        Content = root;

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
    }

    internal void AttachExternalBackpack(AquariumBackpackSection page)
    {
        _externalBackpack = page;
        RenderBackpack(page.ItemsHost, page.PrimaryCategoriesHost, page.CategoriesHost);
        UpdateStatus();
    }

    public void Dispose()
    {
        if (_disposed) return;
        _disposed = true;
        Unsubscribe();
        _preview.Dispose();
    }

    private void Subscribe()
    {
        if (_subscribed || _service is null || _localization is null) return;
        _service.Changed += OnChanged;
        _localization.LanguageChanged += OnChanged;
        _subscribed = true;
    }

    private void Unsubscribe()
    {
        if (!_subscribed) return;
        if (_service is not null) _service.Changed -= OnChanged;
        if (_localization is not null) _localization.LanguageChanged -= OnChanged;
        _subscribed = false;
    }

    private void OnChanged() => _queue.TryEnqueue(() => { if (!_disposed && IsLoaded) Render(); });

    private void Render()
    {
        if (_disposed || _service is null || _localization is null) return;
        if (!string.Equals(_accountId, _service.CurrentUserId, StringComparison.Ordinal))
        {
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
        _coins.Text = F("Aquarium.Settings.Coins", state?.Coins ?? 0);
        _storeTab.Header = L("Aquarium.Settings.Store");
        _backpackTab.Header = L("Aquarium.Settings.Backpack");
        _walletTab.Header = L("Aquarium.Settings.Wallet");
        foreach (ComboBoxItem item in _layer.Items.OfType<ComboBoxItem>())
            if (item.Tag is int layer)
                item.Content = L(layer switch
                {
                    -1 => "Aquarium.Settings.Layer.Back",
                    1 => "Aquarium.Settings.Layer.Front",
                    _ => "Aquarium.Settings.Layer.Middle"
                });
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
        if (_externalBackpack is not null)
            RenderBackpack(_externalBackpack.ItemsHost, _externalBackpack.PrimaryCategoriesHost,
                _externalBackpack.CategoriesHost);
        RenderWallet();
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
        _store.Children.Clear();
        if (_service is null) return;
        _storeCategories.Children.Clear();
        foreach (string category in AquariumInventoryPresentation.Categories)
        {
            int count = _service.Catalog.Count(item => item.Category == category);
            var button = new Button
            {
                Content = $"{L("Aquarium.Category." + category)} ({count})",
                Tag = category, Padding = new Thickness(10, 5, 10, 5), MinHeight = 30,
                Opacity = string.Equals(_selectedStoreCategory, category, StringComparison.Ordinal) ? 1 : .72
            };
            button.Click += (_, _) => { _selectedStoreCategory = category; RenderStore(); };
            _storeCategories.Children.Add(button);
        }
        string selectedCategory = _selectedStoreCategory;
        foreach (AquariumCatalogItem product in _service.Catalog.Where(item => item.Category == selectedCategory))
        {
            var buy = ActionButton("Aquarium.Settings.Buy");
            buy.IsEnabled = CanOperate && (_service.State?.Coins ?? 0) >= product.Price;
            buy.Click += async (_, _) => await RunAsync(async () =>
            {
                bool success = await _service.BuyAsync(product.Id);
                _noticeKey = success ? null : ErrorKey();
            });
            _store.Children.Add(ProductRow(product, F("Aquarium.Settings.Price", product.Price), buy));
        }
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
        IReadOnlyList<AquariumOwnedProduct> ownedProducts = AquariumInventoryPresentation.Summarize(
            state.Inventory, _draft, _service.Catalog);
        string selectedCategory = AquariumInventoryPresentation.Categories.Contains(_selectedBackpackCategory,
            StringComparer.Ordinal) ? _selectedBackpackCategory : AquariumInventoryPresentation.Categories[0];
        AquariumOwnedProduct[] products = ownedProducts
            .Where(item => item.Product.Category == selectedCategory).ToArray();
        if (products.Length > 0)
        {
            var cards = new WrapPanel
            {
                Orientation = Orientation.Horizontal,
                HorizontalSpacing = 8,
                VerticalSpacing = 8
            };
            foreach (AquariumOwnedProduct product in products)
                cards.Children.Add(BackpackCard(product, ReferenceEquals(host, _backpack)));
            host.Children.Add(cards);
        }
        if (host.Children.Count == 0)
            host.Children.Add(Label("Aquarium.Settings.EmptyBackpack", 13, .7));
    }

    private void RenderBackpackCategories(StackPanel primaryCategories, StackPanel categories)
    {
        primaryCategories.Children.Clear();
        categories.Children.Clear();
        bool external = ReferenceEquals(primaryCategories, _externalBackpack?.PrimaryCategoriesHost);
        primaryCategories.Children.Add(CategoryButton(L("Aquarium.Backpack.Aquarium"), true, null,
            external ? _externalBackpack!.RequestAquariumSettings : null));
        if (_service is null) return;
        AquariumState? state = _service.State;
        var owned = AquariumInventoryPresentation.Summarize(state?.Inventory ?? [], _draft, _service.Catalog);
        foreach (string category in AquariumInventoryPresentation.Categories)
        {
            int count = owned.Where(item => item.Product.Category == category)
                .Sum(item => item.OwnedCount);
            categories.Children.Add(CategoryButton(L("Aquarium.Category." + category) + $" ({count})",
                string.Equals(_selectedBackpackCategory, category, StringComparison.Ordinal), category, null));
        }
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
            if (_externalBackpack is not null)
                RenderBackpack(_externalBackpack.ItemsHost, _externalBackpack.PrimaryCategoriesHost,
                    _externalBackpack.CategoriesHost);
        };
        return button;
    }

    private Border BackpackCard(AquariumOwnedProduct owned, bool showEdit)
    {
        AquariumCatalogItem product = owned.Product;
        var panel = new StackPanel { Spacing = 3, HorizontalAlignment = HorizontalAlignment.Stretch };
        var imageHost = new Grid { Height = 46, HorizontalAlignment = HorizontalAlignment.Stretch };
        imageHost.Children.Add(new Image
        {
            Source = AquariumSceneView.Asset(product.AssetKey), Width = 54, Height = 43,
            Stretch = Stretch.Uniform, HorizontalAlignment = HorizontalAlignment.Center
        });
        var quantity = new Border
        {
            Background = new SolidColorBrush(Microsoft.UI.Colors.Black),
            CornerRadius = new CornerRadius(9), Padding = new Thickness(5, 1, 5, 1),
            HorizontalAlignment = HorizontalAlignment.Right, VerticalAlignment = VerticalAlignment.Top,
            Child = new TextBlock { Text = $"×{owned.OwnedCount}", Foreground = new SolidColorBrush(Microsoft.UI.Colors.White), FontSize = 11 }
        };
        imageHost.Children.Add(quantity);
        if (showEdit && owned.NextToRecall is { } editable && product.Category != "fish")
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
            Text = $"{L("Aquarium.Settings.Placed")} {owned.PlacedCount}/{owned.OwnedCount}",
            FontSize = 11, Opacity = .72, TextAlignment = TextAlignment.Center
        });
        var actions = new StackPanel { Orientation = Orientation.Horizontal, Spacing = 4, HorizontalAlignment = HorizontalAlignment.Center };
        var place = SmallBackpackAction("Aquarium.Settings.Place", "+");
        place.IsEnabled = !_busy && _service?.IsAvailable == true && owned.NextToPlace is not null;
        place.Click += (_, _) => { if (owned.NextToPlace is { } item) TogglePlacement(item, product); };
        actions.Children.Add(place);
        var recall = SmallBackpackAction("Aquarium.Settings.Recall", "−");
        recall.IsEnabled = !_busy && _service?.IsAvailable == true && owned.NextToRecall is not null;
        recall.Click += (_, _) => { if (owned.NextToRecall is { } item) TogglePlacement(item, product); };
        actions.Children.Add(recall);
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

    private void RenderWallet()
    {
        _wallet.Children.Clear();
        _wallet.Children.Add(new TextBlock { Text = _coins.Text, FontSize = 18, FontWeight = Microsoft.UI.Text.FontWeights.SemiBold });
        if (_service?.State is not { } state || state.Wallet.Count == 0)
        {
            _wallet.Children.Add(Label("Aquarium.Settings.EmptyWallet", 13, .7));
            return;
        }
        foreach (AquariumWalletEntry entry in state.Wallet)
        {
            var row = new Grid { ColumnSpacing = 16, Padding = new Thickness(4, 8, 4, 8) };
            row.ColumnDefinitions.Add(new ColumnDefinition());
            row.ColumnDefinitions.Add(new ColumnDefinition { Width = GridLength.Auto });
            string reason = L(entry.Delta > 0 ? "Aquarium.Settings.Gift" : "Aquarium.Settings.Purchase");
            if (entry.ItemId is { } itemId && Product(itemId) is { } product) reason += " · " + ProductName(product);
            var labels = new StackPanel { Spacing = 3 };
            labels.Children.Add(new TextBlock { Text = reason, TextWrapping = TextWrapping.Wrap });
            string date = DateTimeOffset.TryParse(entry.CreatedAt, CultureInfo.InvariantCulture, DateTimeStyles.AssumeUniversal, out var parsed)
                ? parsed.ToLocalTime().ToString("g", CultureInfo.CurrentCulture) : entry.CreatedAt;
            labels.Children.Add(new TextBlock { Text = date, FontSize = 12, Opacity = .65 });
            row.Children.Add(labels);
            var amount = new TextBlock { Text = entry.Delta.ToString("+#,0;-#,0;0", CultureInfo.CurrentCulture), VerticalAlignment = VerticalAlignment.Center, FontWeight = Microsoft.UI.Text.FontWeights.SemiBold };
            Grid.SetColumn(amount, 1);
            row.Children.Add(amount);
            _wallet.Children.Add(row);
        }
    }

    private Grid ProductRow(AquariumCatalogItem product, string detail, FrameworkElement action)
    {
        var row = new Grid { ColumnSpacing = 12, Padding = new Thickness(10), CornerRadius = new CornerRadius(10) };
        row.Background = Application.Current.Resources["CardBackgroundFillColorDefaultBrush"] as Brush;
        row.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(68) });
        row.ColumnDefinitions.Add(new ColumnDefinition());
        row.ColumnDefinitions.Add(new ColumnDefinition { Width = GridLength.Auto });
        row.Children.Add(new Image { Source = AquariumSceneView.Asset(product.AssetKey), Width = 68, Height = 64, Stretch = Stretch.Uniform });
        var labels = new StackPanel { Spacing = 4, VerticalAlignment = VerticalAlignment.Center };
        labels.Children.Add(new TextBlock { Text = ProductName(product), FontWeight = Microsoft.UI.Text.FontWeights.SemiBold, TextWrapping = TextWrapping.Wrap });
        labels.Children.Add(new TextBlock { Text = detail, FontSize = 12, Opacity = .72, TextWrapping = TextWrapping.Wrap });
        Grid.SetColumn(labels, 1);
        row.Children.Add(labels);
        Grid.SetColumn(action, 2);
        action.VerticalAlignment = VerticalAlignment.Center;
        row.Children.Add(action);
        return row;
    }

    private void TogglePlacement(AquariumInventoryItem owned, AquariumCatalogItem product)
    {
        int index = _draft.FindIndex(item => item.InstanceId == owned.Id);
        if (index >= 0)
        {
            _draft.RemoveAt(index);
            if (_selectedId == owned.Id) _selectedId = null;
        }
        else
        {
            bool fish = product.Category == "fish";
            int count = _draft.Count(placement => IsFish(placement.InstanceId) == fish);
            _draft.Add(new AquariumPlacement(owned.Id, .25 + count % 4 * .16,
                AquariumArtworkCatalog.DefaultPlacementY(product.AssetKey, fish, count), 1));
            if (!fish) _selectedId = owned.Id;
        }
        MarkDirty();
        Render();
    }

    private bool IsFish(string instanceId)
    {
        AquariumInventoryItem? item = _service?.State?.Inventory.FirstOrDefault(item => item.Id == instanceId);
        return item is not null && Product(item.ItemId)?.Category == "fish";
    }

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
        _scale.IsEnabled = !_busy && placement is not null && product is not null && product.Category != "fish";
        _layer.IsEnabled = !_busy && placement is not null && product is not null &&
            (product.Category is "plant" or "decoration");
        _scale.Value = placement?.Scale ?? 1;
        _layer.SelectedIndex = placement?.Layer switch { -1 => 0, 1 => 2, _ => 1 };
        _selection.Text = product is null ? L("Aquarium.Settings.NoSelection") : F("Aquarium.Settings.Selected", ProductName(product));
        _updating = false;
    }

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
        string? key = _service?.IsAvailable != true ? "Aquarium.Settings.AdminRequired"
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
    }

    private string ErrorKey() => _service?.ErrorCode switch
    {
        "scene_conflict" => "Aquarium.Settings.Conflict",
        "insufficient_coins" => "Aquarium.Settings.InsufficientCoins",
        "admin_required" => "Aquarium.Settings.AdminRequired",
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
