using DeskBox.Controls;
using DeskBox.Contracts;
using DeskBox.Services;
using Microsoft.UI.Dispatching;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
using Microsoft.UI.Xaml.Media;

namespace DeskBox.Views.SettingsSections;

public sealed partial class TreeDecorationSettingsSection : UserControl
{
    private readonly DispatcherQueue _uiQueue = DispatcherQueue.GetForCurrentThread();
    private readonly TextBlock _status = new() { TextWrapping = TextWrapping.Wrap };
    private readonly Button _signIn = new();
    private readonly Button _undo = new();
    private readonly Button _save = new();
    private readonly Button _return = new();
    private readonly Button _reapply = new();
    private readonly TextBlock _selected = new() { FontWeight = Microsoft.UI.Text.FontWeights.SemiBold };
    private readonly TextBlock _scaleLabel = new();
    private readonly TextBlock _rotationLabel = new();
    private readonly TextBlock _layerLabel = new();
    private readonly Slider _scale = new() { Minimum = .5, Maximum = 1.5, StepFrequency = .05, Width = 220 };
    private readonly Slider _rotation = new() { Minimum = -45, Maximum = 45, StepFrequency = 1, Width = 220 };
    private readonly ComboBox _layer = new() { Width = 100, ItemsSource = new object[] { -2, -1, 0, 1, 2 } };
    private readonly TreeDecorationSceneView _scene = new();
    private readonly Grid _catalog = new()
    {
        Width = TreeDecorationInventoryLayout.SettingsCardWidth,
        HorizontalAlignment = HorizontalAlignment.Left,
        ColumnSpacing = TreeDecorationInventoryLayout.SettingsCardSpacing,
        RowSpacing = TreeDecorationInventoryLayout.SettingsCardSpacing
    };
    private readonly TextBlock _collectionTitle = new()
    {
        FontSize = 16, FontWeight = Microsoft.UI.Text.FontWeights.SemiBold,
        TextWrapping = TextWrapping.Wrap
    };
    private readonly TextBlock _collectionDescription = new()
    {
        FontSize = 12, Opacity = .72, TextWrapping = TextWrapping.Wrap
    };
    private readonly Border _editorSurface = new()
    {
        HorizontalAlignment = HorizontalAlignment.Stretch,
        Padding = new Thickness(16),
        CornerRadius = new CornerRadius(8),
        Background = Application.Current.Resources["CardBackgroundFillColorDefaultBrush"] as Brush
    };
    private readonly Border _collectionCard = new()
    {
        HorizontalAlignment = HorizontalAlignment.Stretch,
        Padding = new Thickness(16),
        CornerRadius = new CornerRadius(8),
        Background = Application.Current.Resources["CardBackgroundFillColorDefaultBrush"] as Brush
    };
    private int _catalogColumnCount = 1;
    private TreeDecorationService? _service;
    private AquariumService? _aquarium;
    private LocalizationService? _localization;
    private IAuthSession? _auth;
    private TreeDecorationSceneDraft _draft = new([], null);
    private long _baseSceneVersion;
    private long _loadedSceneVersion = -1;
    private string? _loadedIdentity;
    private bool _dirty;
    private bool _updatingEditors;
    private int _busy;

    public event EventHandler<SettingsSectionNavigationRequestedEventArgs>? NavigationRequested;

    public TreeDecorationSettingsSection()
    {
        _catalog.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(TreeDecorationInventoryLayout.SettingsCardWidth) });
        var root = new StackPanel { Spacing = 10 };
        root.Children.Add(_status);

        _signIn.Click += (_, _) => NavigationRequested?.Invoke(this, new("General"));
        _undo.Click += (_, _) =>
        {
            if (_draft.Undo()) { _dirty = true; _scene.Render(); RefreshEditor(); RefreshActions(); }
        };
        _save.Click += async (_, _) => await SaveAsync();
        _return.Click += (_, _) => ReturnSelected();
        _reapply.Click += (_, _) => ReapplyConflictDraft();
        _scene.SelectedPlacementChanged += _ => RefreshEditor();
        _scene.SceneEdited += () => { _dirty = true; RefreshEditor(); RefreshActions(); };
        _scale.ValueChanged += (_, _) => ApplyEditorValues();
        _rotation.ValueChanged += (_, _) => ApplyEditorValues();
        _layer.SelectionChanged += (_, _) => ApplyEditorValues();

        root.Children.Add(_signIn);

        var previewBorder = new Border
        {
            Height = 390,
            MaxWidth = 620,
            HorizontalAlignment = HorizontalAlignment.Center,
            Background = Application.Current.Resources["LayerFillColorDefaultBrush"] as Brush,
            BorderBrush = Application.Current.Resources["CardStrokeColorDefaultBrush"] as Brush,
            BorderThickness = new Thickness(1),
            CornerRadius = new CornerRadius(10),
            Padding = new Thickness(8),
            Child = new Viewbox { Child = _scene, Stretch = Stretch.Uniform }
        };
        var editorPanel = new StackPanel { Spacing = 8 };
        editorPanel.Children.Add(previewBorder);
        editorPanel.Children.Add(_selected);
        var editorFields = new StackPanel { Spacing = 2 };
        editorFields.Children.Add(EditorRow(_scaleLabel, _scale));
        editorFields.Children.Add(EditorRow(_rotationLabel, _rotation));
        editorFields.Children.Add(EditorRow(_layerLabel, _layer));
        editorPanel.Children.Add(editorFields);
        var editorActions = new CommunityToolkit.WinUI.Controls.WrapPanel
        {
            Orientation = Orientation.Horizontal, HorizontalSpacing = 8, VerticalSpacing = 8
        };
        editorActions.Children.Add(_undo);
        editorActions.Children.Add(_return);
        editorActions.Children.Add(_save);
        editorActions.Children.Add(_reapply);
        editorPanel.Children.Add(editorActions);
        _editorSurface.Child = editorPanel;
        root.Children.Add(_editorSurface);

        var collectionContent = new StackPanel { Spacing = 8 };
        collectionContent.Children.Add(_collectionTitle);
        collectionContent.Children.Add(_collectionDescription);
        collectionContent.Children.Add(_catalog);
        _collectionCard.Child = collectionContent;
        root.Children.Add(_collectionCard);
        Content = root;
        SizeChanged += (_, args) => UpdateCatalogColumns(args.NewSize.Width);
        Loaded += OnLoaded;
        Unloaded += OnUnloaded;
    }

    internal void Configure(TreeDecorationService service, AquariumService aquarium,
        LocalizationService localization, IAuthSession auth)
    {
        _service = service;
        _aquarium = aquarium;
        _localization = localization;
        _auth = auth;
        RenderLabels();
        Refresh();
    }

    private async void OnLoaded(object sender, RoutedEventArgs e)
    {
        if (_service is null || _localization is null || _auth is null) return;
        _service.Changed += OnServiceChanged;
        _localization.LanguageChanged += OnLanguageChanged;
        _auth.SessionChanged += OnServiceChanged;
        Refresh();
        if (_service.IsAvailable)
        {
            if (_aquarium?.State is not { Initialized: true })
                await _aquarium!.InitializeAsync();
            await RunAsync(() => _service.RefreshAsync());
        }
    }

    private void OnUnloaded(object sender, RoutedEventArgs e)
    {
        if (_service is not null) _service.Changed -= OnServiceChanged;
        if (_localization is not null) _localization.LanguageChanged -= OnLanguageChanged;
        if (_auth is not null) _auth.SessionChanged -= OnServiceChanged;
    }

    private void OnLanguageChanged()
    {
        RenderLabels();
        Refresh();
    }

    private void OnServiceChanged() => _uiQueue.TryEnqueue(Refresh);

    private void Refresh()
    {
        if (_service is null || _localization is null) return;
        TreeDecorationState? state = _service.State;
        string? identity = _service.CurrentUserId;
        bool accountChanged = _loadedIdentity != identity;
        if (accountChanged)
        {
            _loadedIdentity = identity;
            _dirty = false;
            _loadedSceneVersion = -1;
        }
        if (state is not null && (!_dirty || accountChanged) && _loadedSceneVersion != state.SceneVersion)
        {
            _draft.Reset(state.Placements, null);
            _baseSceneVersion = state.SceneVersion;
            _loadedSceneVersion = state.SceneVersion;
        }
        _scene.Bind(_draft, _service.Catalog?.Items ?? [],
            item => TreeDecorationInventoryPresentation.DisplayName(item, _localization.T));
        RenderState();
        RenderCatalog();
        RefreshEditor();
        RefreshActions();
    }

    private void RenderLabels()
    {
        if (_localization is null) return;
        _collectionTitle.Text = _localization.T("Tree.Decorations.CollectionTitle");
        _collectionDescription.Text = _localization.T("Tree.Decorations.CollectionDescription");
        _signIn.Content = _localization.T("Tree.Decorations.SignInAction");
        _undo.Content = _localization.T("Tree.Decorations.Undo");
        _save.Content = _localization.T("Tree.Decorations.Save");
        _return.Content = _localization.T("Tree.Decorations.Return");
        _reapply.Content = _localization.T("Tree.Decorations.Reapply");
        _scaleLabel.Text = _localization.T("Tree.Decorations.Scale");
        _rotationLabel.Text = _localization.T("Tree.Decorations.Rotation");
        _layerLabel.Text = _localization.T("Tree.Decorations.Layer");
        Microsoft.UI.Xaml.Automation.AutomationProperties.SetName(_scene,
            _localization.T("Tree.Decorations.SceneAutomationName"));
    }

    private void RenderState()
    {
        if (_service is null || _localization is null) return;
        TreeDecorationState? state = _service.State;
        bool signedIn = _service.IsAvailable;
        _signIn.Visibility = signedIn ? Visibility.Collapsed : Visibility.Visible;
        _status.Text = !signedIn
            ? _localization.T("Tree.Decorations.SignInRequired")
            : _service.IsOffline
                ? _localization.T("Tree.Decorations.Offline")
                : _service.ErrorCode is string errorCode
                    ? _localization.T(ErrorMessageKey(errorCode))
                    : state is null
                        ? _localization.T("Tree.Decorations.Loading")
                        : !state.Mature
                            ? _localization.T("Tree.Decorations.MatureRequired")
                            : _dirty
                                ? _localization.T("Tree.Decorations.Unsaved")
                                : string.Empty;
        _status.Visibility = string.IsNullOrWhiteSpace(_status.Text)
            ? Visibility.Collapsed : Visibility.Visible;
    }

    private void RenderCatalog()
    {
        _catalog.Children.Clear();
        _catalog.RowDefinitions.Clear();
        if (_service?.Catalog is not { } catalog || _service.State is not { } state ||
            _localization is null) return;
        IReadOnlyList<TreeDecorationOwnedProduct> products = TreeDecorationInventoryPresentation.Summarize(
            state.Inventory, _draft.Placements, catalog.Items);
        for (int index = 0; index < products.Count; index++)
        {
            TreeDecorationOwnedProduct owned = products[index];
            TreeDecorationCatalogItem item = owned.Product;
            (int row, int column) = TreeDecorationInventoryLayout.PositionOf(index, _catalogColumnCount);
            if (column == 0)
                _catalog.RowDefinitions.Add(new RowDefinition { Height = GridLength.Auto });
            var imageHost = new Grid { Height = 46, HorizontalAlignment = HorizontalAlignment.Stretch };
            imageHost.Children.Add(new Image
            {
                Width = 54, Height = 43, Stretch = Stretch.Uniform,
                HorizontalAlignment = HorizontalAlignment.Center,
                Source = TreeDecorationAssetCatalog.Source(item.AssetKey),
                IsHitTestVisible = false
            });
            imageHost.Children.Add(BackpackQuantityBadge(owned.OwnedCount));
            string name = TreeDecorationInventoryPresentation.DisplayName(item, _localization.T);
            var nameBlock = new TextBlock
            {
                Text = name, FontSize = 12, FontWeight = Microsoft.UI.Text.FontWeights.SemiBold,
                TextAlignment = TextAlignment.Center, TextWrapping = TextWrapping.Wrap, MaxLines = 2
            };
            var panel = new StackPanel { Spacing = 2, HorizontalAlignment = HorizontalAlignment.Stretch };
            panel.Children.Add(imageHost);
            panel.Children.Add(nameBlock);
            {
                panel.Children.Add(new TextBlock
                {
                    Text = $"{_localization.T("Aquarium.Settings.Placed")} {owned.PlacedCount}/{owned.OwnedCount}",
                    FontSize = 11, Opacity = .72, TextAlignment = TextAlignment.Center
                });
                var actions = new StackPanel
                {
                    Orientation = Orientation.Horizontal, Spacing = 4,
                    HorizontalAlignment = HorizontalAlignment.Center
                };
                var place = SmallBackpackAction("+");
                ToolTipService.SetToolTip(place, _localization.T("Aquarium.Settings.Place"));
                place.IsEnabled = CanMutate() && owned.CanPlace;
                place.Click += (_, _) =>
                {
                    if (!_draft.TryAdd(item.Id, out string placementId)) return;
                    _dirty = true;
                    _scene.Select(placementId);
                    _scene.Render();
                    Refresh();
                };
                actions.Children.Add(place);
                var recall = SmallBackpackAction("−");
                ToolTipService.SetToolTip(recall, _localization.T("Aquarium.Settings.Recall"));
                recall.IsEnabled = CanMutate() && owned.CanRecall;
                recall.Click += (_, _) => RecallOne(item.Id);
                actions.Children.Add(recall);
                panel.Children.Add(actions);
            }
            var card = new Border
            {
                Width = TreeDecorationInventoryLayout.SettingsCardWidth,
                Height = 142, Padding = new Thickness(4),
                HorizontalAlignment = HorizontalAlignment.Stretch,
                CornerRadius = new CornerRadius(10),
                Background = Application.Current.Resources["CardBackgroundFillColorDefaultBrush"] as Brush,
                BorderBrush = Application.Current.Resources["CardStrokeColorDefaultBrush"] as Brush,
                BorderThickness = new Thickness(1), Child = panel
            };
            Microsoft.UI.Xaml.Automation.AutomationProperties.SetName(card, name);
            Grid.SetColumn(card, column);
            Grid.SetRow(card, row);
            _catalog.Children.Add(card);
        }
    }

    private void UpdateCatalogColumns(double sectionWidth)
    {
        int columns = TreeDecorationInventoryLayout.SettingsColumnsForWidth(
            Math.Max(0, sectionWidth - _collectionCard.Padding.Left - _collectionCard.Padding.Right));
        if (columns == _catalogColumnCount) return;
        _catalogColumnCount = columns;
        _catalog.Width = TreeDecorationInventoryLayout.SettingsRowWidthForColumns(columns);
        _catalog.ColumnDefinitions.Clear();
        for (int column = 0; column < columns; column++)
            _catalog.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(TreeDecorationInventoryLayout.SettingsCardWidth) });
        RenderCatalog();
    }

    private void RecallOne(string itemId)
    {
        TreeDecorationPlacement? selected = _scene.SelectedPlacementId is string selectedId
            ? _draft.Placements.FirstOrDefault(item => item.PlacementId == selectedId && item.ItemId == itemId)
            : null;
        TreeDecorationPlacement? placement = selected ?? _draft.Placements.LastOrDefault(item => item.ItemId == itemId);
        if (placement is null || !_draft.Remove(placement.PlacementId)) return;
        _dirty = true;
        if (placement.PlacementId == _scene.SelectedPlacementId) _scene.Select(null);
        _scene.Render();
        Refresh();
    }

    private async Task SaveAsync()
    {
        if (_service is null) return;
        bool success = await RunAsync(() => _service.SaveSceneAsync(
            _draft.Placements, null, _baseSceneVersion));
        if (success && _service.State is { } state)
        {
            _draft.Reset(state.Placements, null);
            _baseSceneVersion = state.SceneVersion;
            _loadedSceneVersion = state.SceneVersion;
            _dirty = false;
            Refresh();
        }
    }

    private void ReturnSelected()
    {
        if (_scene.SelectedPlacementId is not string placementId || !_draft.Remove(placementId)) return;
        _dirty = true;
        _scene.Select(null);
        _scene.Render();
        Refresh();
    }

    private void ReapplyConflictDraft()
    {
        if (_service?.PendingScene is not { } pending || _service.State is not { } cloud) return;
        _draft.Reset(pending, null);
        _baseSceneVersion = cloud.SceneVersion;
        _dirty = true;
        _scene.Render();
        Refresh();
    }

    private void ApplyEditorValues()
    {
        if (_updatingEditors || _scene.SelectedPlacementId is not string placementId ||
            _layer.SelectedItem is not int layer) return;
        TreeDecorationPlacement? current = _draft.Placements.FirstOrDefault(
            item => item.PlacementId == placementId);
        if (current is null) return;
        if (_draft.TryUpdate(placementId, current.X, current.Y, _scale.Value, _rotation.Value, layer))
        {
            _dirty = true;
            _scene.Render();
            RefreshActions();
        }
    }

    private void RefreshEditor()
    {
        if (_localization is null) return;
        TreeDecorationPlacement? selected = _scene.SelectedPlacementId is string id
            ? _draft.Placements.FirstOrDefault(item => item.PlacementId == id) : null;
        _updatingEditors = true;
        try
        {
            TreeDecorationCatalogItem? product = selected is null ? null : _service?.Catalog?.Items
                .FirstOrDefault(item => item.Id == selected.ItemId);
            _selected.Text = product is null
                ? _localization.T("Tree.Decorations.NoSelection")
                : _localization.Format("Tree.Decorations.Selected",
                    TreeDecorationInventoryPresentation.DisplayName(product, _localization.T));
            _scale.IsEnabled = selected is not null;
            _rotation.IsEnabled = selected is not null;
            _layer.IsEnabled = selected is not null;
            _return.IsEnabled = selected is not null && CanMutate();
            if (selected is not null)
            {
                _scale.Value = selected.Scale;
                _rotation.Value = selected.Rotation;
                _layer.SelectedItem = selected.Layer;
            }
        }
        finally { _updatingEditors = false; }
    }

    private void RefreshActions()
    {
        if (_service is null) return;
        bool enabled = CanMutate();
        _undo.IsEnabled = enabled && _draft.CanUndo;
        _save.IsEnabled = enabled && _dirty;
        _reapply.Visibility = _service.PendingScene is null ? Visibility.Collapsed : Visibility.Visible;
        _reapply.IsEnabled = enabled && _service.PendingScene is not null;
    }

    private bool CanMutate() => _service is
    {
        IsAvailable: true, IsOffline: false, HasPendingOperation: false,
        State.Mature: true
    } && Volatile.Read(ref _busy) == 0;

    private async Task<bool> RunAsync(Func<Task<bool>> operation)
    {
        if (Interlocked.Exchange(ref _busy, 1) != 0) return false;
        RefreshActions();
        try { return await operation(); }
        finally { Interlocked.Exchange(ref _busy, 0); Refresh(); }
    }

    private static Grid EditorRow(TextBlock label, FrameworkElement control)
    {
        var row = new Grid { ColumnSpacing = 16 };
        row.ColumnDefinitions.Add(new ColumnDefinition());
        row.ColumnDefinitions.Add(new ColumnDefinition { Width = GridLength.Auto });
        control.HorizontalAlignment = HorizontalAlignment.Right;
        Grid.SetColumn(control, 1);
        row.Children.Add(label);
        row.Children.Add(control);
        return row;
    }

    private static Border BackpackQuantityBadge(int count) => new()
    {
        Background = new SolidColorBrush(Microsoft.UI.Colors.Black),
        CornerRadius = new CornerRadius(9), Padding = new Thickness(5, 1, 5, 1),
        HorizontalAlignment = HorizontalAlignment.Right, VerticalAlignment = VerticalAlignment.Top,
        Child = new TextBlock
        {
            Text = $"×{count}", Foreground = new SolidColorBrush(Microsoft.UI.Colors.White), FontSize = 11
        }
    };

    private static Button SmallBackpackAction(string symbol) => new()
    {
        Content = symbol, Width = 48, Height = 26, FontSize = 18,
        Padding = new Thickness(0)
    };

    private static string ErrorMessageKey(string code) => code switch
    {
        "scene_conflict" => "Tree.Decorations.SceneConflict",
        "insufficient_coins" => "Tree.Decorations.InsufficientCoins",
        "insufficient_shards" => "Tree.Decorations.InsufficientShards",
        "already_owned" => "Tree.Decorations.AlreadyOwned",
        "tree_not_mature" => "Tree.Decorations.MatureRequired",
        "pending_operation" => "Tree.Decorations.PendingOperation",
        "catalog_conflict" => "Tree.Decorations.CatalogChanged",
        _ => "Tree.Decorations.OperationFailed"
    };
}
