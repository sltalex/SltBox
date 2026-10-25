using CommunityToolkit.WinUI.Controls;
using DeskBox.Controls;
using DeskBox.Contracts;
using DeskBox.Services;
using Microsoft.UI.Dispatching;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
using Microsoft.UI.Xaml.Media;

namespace DeskBox.Views.SettingsSections;

public sealed class TreeDecorationSettingsSection : UserControl
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
    private readonly WrapPanel _catalog = new()
    {
        Orientation = Orientation.Horizontal,
        HorizontalSpacing = 8,
        VerticalSpacing = 8
    };
    private readonly Border _editorSurface = new()
    {
        HorizontalAlignment = HorizontalAlignment.Stretch,
        Padding = new Thickness(16),
        CornerRadius = new CornerRadius(8),
        Background = Application.Current.Resources["CardBackgroundFillColorDefaultBrush"] as Brush
    };
    private readonly SettingsCard _collectionCard = new() { HorizontalContentAlignment = HorizontalAlignment.Stretch };
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
        _scene.SelectedItemChanged += _ => RefreshEditor();
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
        var editorActions = new StackPanel { Orientation = Orientation.Horizontal, Spacing = 8 };
        editorActions.Children.Add(_undo);
        editorActions.Children.Add(_return);
        editorActions.Children.Add(_save);
        editorActions.Children.Add(_reapply);
        editorPanel.Children.Add(editorActions);
        _editorSurface.Child = editorPanel;
        root.Children.Add(_editorSurface);

        _collectionCard.Content = _catalog;
        root.Children.Add(_collectionCard);
        Content = root;
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
            _draft.Reset(state.Placements, state.ActiveThemeId);
            _baseSceneVersion = state.SceneVersion;
            _loadedSceneVersion = state.SceneVersion;
        }
        _scene.Bind(_draft, _service.Catalog?.Items ?? [],
            item => _localization.T("Tree.Decorations.Item." + item.Id));
        RenderState();
        RenderCatalog();
        RefreshEditor();
        RefreshActions();
    }

    private void RenderLabels()
    {
        if (_localization is null) return;
        _collectionCard.Header = _localization.T("Tree.Decorations.CollectionTitle");
        _collectionCard.Description = _localization.T("Tree.Decorations.CollectionDescription");
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
        if (_service?.Catalog is not { } catalog || _localization is null) return;
        HashSet<string> owned = _service.State?.OwnedIds.ToHashSet(StringComparer.Ordinal) ?? [];
        foreach (TreeDecorationCatalogItem item in catalog.Items)
        {
            bool hasItem = owned.Contains(item.Id);
            bool activeTheme = item.Id == _draft.ActiveThemeId;
            var image = new Image
            {
                Width = 82, Height = 82, Stretch = Stretch.Uniform,
                Source = hasItem ? TreeDecorationAssetCatalog.Source(item.AssetKey) :
                    TreeDecorationAssetCatalog.Silhouette(item.AssetKey),
                Opacity = hasItem ? 1 : .58,
                IsHitTestVisible = false
            };
            string name = _localization.T("Tree.Decorations.Item." + item.Id);
            var nameBlock = new TextBlock
            {
                Text = name, FontWeight = Microsoft.UI.Text.FontWeights.SemiBold,
                TextAlignment = TextAlignment.Center, TextWrapping = TextWrapping.Wrap
            };
            var rarity = new TextBlock
            {
                Text = _localization.T("Tree.Decorations.Rarity." + item.Rarity),
                FontSize = 12, Opacity = .72, TextAlignment = TextAlignment.Center
            };
            var action = new Button
            {
                HorizontalAlignment = HorizontalAlignment.Stretch,
                Tag = item,
                Content = hasItem
                    ? item.Category == "theme"
                        ? _localization.T(activeTheme ? "Tree.Decorations.DisableTheme" : "Tree.Decorations.EnableTheme")
                        : _localization.T(_draft.Placements.Any(p => p.ItemId == item.Id)
                            ? "Tree.Decorations.Placed" : "Tree.Decorations.Place")
                    : _localization.Format("Tree.Decorations.Redeem", ShardCost(item.Rarity)),
                IsEnabled = CanMutate() && (!hasItem || item.Category == "theme" ||
                    !_draft.Placements.Any(p => p.ItemId == item.Id))
            };
            action.Click += CatalogAction_Click;
            var panel = new StackPanel { Spacing = 5 };
            panel.Children.Add(image);
            panel.Children.Add(nameBlock);
            panel.Children.Add(rarity);
            panel.Children.Add(action);
            var card = new Border
            {
                Width = 150, MinHeight = 182, Padding = new Thickness(10),
                Margin = new Thickness(4), CornerRadius = new CornerRadius(8),
                Background = Application.Current.Resources["CardBackgroundFillColorDefaultBrush"] as Brush,
                BorderBrush = activeTheme ? new SolidColorBrush(Microsoft.UI.Colors.DodgerBlue) :
                    Application.Current.Resources["CardStrokeColorDefaultBrush"] as Brush,
                BorderThickness = new Thickness(activeTheme ? 2 : 1), Child = panel
            };
            Microsoft.UI.Xaml.Automation.AutomationProperties.SetName(card, name);
            _catalog.Children.Add(card);
        }
    }

    private async void CatalogAction_Click(object sender, RoutedEventArgs e)
    {
        if (sender is not Button { Tag: TreeDecorationCatalogItem item } || _service is null) return;
        bool owned = _service.State?.OwnedIds.Contains(item.Id, StringComparer.Ordinal) == true;
        if (!owned)
        {
            await RunAsync(() => _service.RedeemAsync(item.Id));
            return;
        }
        if (item.Category == "theme")
        {
            _draft.SetTheme(_draft.ActiveThemeId == item.Id ? null : item.Id);
            _dirty = true;
        }
        else if (_draft.TryAdd(item.Id))
        {
            _dirty = true;
            _scene.Select(item.Id);
        }
        _scene.Render();
        Refresh();
    }

    private async Task SaveAsync()
    {
        if (_service is null) return;
        bool success = await RunAsync(() => _service.SaveSceneAsync(
            _draft.Placements, _draft.ActiveThemeId, _baseSceneVersion));
        if (success && _service.State is { } state)
        {
            _draft.Reset(state.Placements, state.ActiveThemeId);
            _baseSceneVersion = state.SceneVersion;
            _loadedSceneVersion = state.SceneVersion;
            _dirty = false;
            Refresh();
        }
    }

    private void ReturnSelected()
    {
        if (_scene.SelectedItemId is not string itemId || !_draft.Remove(itemId)) return;
        _dirty = true;
        _scene.Select(null);
        _scene.Render();
        Refresh();
    }

    private void ReapplyConflictDraft()
    {
        if (_service?.PendingScene is not { } pending || _service.State is not { } cloud) return;
        _draft.Reset(pending, _service.PendingThemeId);
        _baseSceneVersion = cloud.SceneVersion;
        _dirty = true;
        _scene.Render();
        Refresh();
    }

    private void ApplyEditorValues()
    {
        if (_updatingEditors || _scene.SelectedItemId is not string itemId ||
            _layer.SelectedItem is not int layer) return;
        TreeDecorationPlacement? current = _draft.Placements.FirstOrDefault(item => item.ItemId == itemId);
        if (current is null) return;
        if (_draft.TryUpdate(itemId, current.X, current.Y, _scale.Value, _rotation.Value, layer))
        {
            _dirty = true;
            _scene.Render();
            RefreshActions();
        }
    }

    private void RefreshEditor()
    {
        if (_localization is null) return;
        TreeDecorationPlacement? selected = _scene.SelectedItemId is string id
            ? _draft.Placements.FirstOrDefault(item => item.ItemId == id) : null;
        _updatingEditors = true;
        try
        {
            _selected.Text = selected is null
                ? _localization.T("Tree.Decorations.NoSelection")
                : _localization.Format("Tree.Decorations.Selected",
                    _localization.T("Tree.Decorations.Item." + selected.ItemId));
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

    private static long ShardCost(string rarity) => rarity switch
    {
        "rare" => 240,
        "theme" => 800,
        _ => 80
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
