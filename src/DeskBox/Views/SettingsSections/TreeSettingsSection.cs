using CommunityToolkit.WinUI.Controls;
using DeskBox.Contracts;
using DeskBox.Services;
using Microsoft.UI.Dispatching;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
using Microsoft.UI.Xaml.Media;

namespace DeskBox.Views.SettingsSections;

public sealed partial class TreeSettingsSection : UserControl
{
    private readonly DispatcherQueue _uiQueue = DispatcherQueue.GetForCurrentThread();
    private readonly TreeCounterService _counter = TreeCounterService.Instance;
    private readonly ToggleSwitch _showEnergy = new() { OnContent = "", OffContent = "", MinWidth = 0 };
    private readonly TextBlock _showEnergyLabel = new()
    {
        Width = 128,
        TextWrapping = TextWrapping.NoWrap,
        TextTrimming = TextTrimming.CharacterEllipsis,
        VerticalAlignment = VerticalAlignment.Center
    };
    private readonly ToggleSwitch _participation = new() { OnContent = "", OffContent = "", MinWidth = 0 };
    private readonly TextBlock _participationLabel = new() { TextWrapping = TextWrapping.Wrap, VerticalAlignment = VerticalAlignment.Center };
    private readonly TextBlock _todayKeysValue = new()
    {
        Text = "⌨ 0",
        FontWeight = Microsoft.UI.Text.FontWeights.SemiBold,
        HorizontalAlignment = HorizontalAlignment.Center,
        VerticalAlignment = VerticalAlignment.Center
    };
    private readonly TextBlock _leaderboardTitle = new() { FontSize = 16, FontWeight = Microsoft.UI.Text.FontWeights.SemiBold };
    private readonly TextBlock _leaderboardHint = new() { FontSize = 12, Opacity = 0.72, TextWrapping = TextWrapping.Wrap };
    private readonly TextBlock _currentRankHint = new() { FontSize = 13, FontWeight = Microsoft.UI.Text.FontWeights.SemiBold, TextWrapping = TextWrapping.Wrap };
    private readonly TextBlock _syncStatus = new()
    {
        FontSize = 12, Opacity = 0.78, TextWrapping = TextWrapping.Wrap,
        HorizontalAlignment = HorizontalAlignment.Right, Visibility = Visibility.Collapsed
    };
    private readonly Button _syncButton = new() { MinWidth = 0, Padding = new Thickness(10, 4, 10, 4) };
    private readonly Button _repairButton = new() { MinWidth = 0, Padding = new Thickness(10, 4, 10, 4) };
    private readonly Button _loginButton = new() { HorizontalAlignment = HorizontalAlignment.Left };
    private readonly ListView _leaderboard = new()
    {
        SelectionMode = ListViewSelectionMode.None,
        HorizontalContentAlignment = HorizontalAlignment.Stretch
    };
    private readonly SettingsCard _switchesCard = new() { HorizontalContentAlignment = HorizontalAlignment.Stretch };
    private readonly Border _leaderboardCard = new() { HorizontalAlignment = HorizontalAlignment.Stretch };
    private readonly TreeDecorationSettingsSection _decorations = new();
    private readonly Grid _participationRow;
    private LocalizationService? _localization;
    private IAuthSession? _auth;
    private TreeCloudSyncService? _cloud;
    private TreeDecorationService? _decorationService;
    private bool _updating;
    private int _leaderboardRefreshQueued;
    private int _counterRefreshQueued;
    private int _syncOperationInProgress;

    public event EventHandler<SettingsSectionNavigationRequestedEventArgs>? NavigationRequested;

    public TreeSettingsSection()
    {
        _syncButton.Click += SyncButton_Click;
        _syncButton.Content = new TextBlock();
        _repairButton.Click += RepairButton_Click;
        _repairButton.Content = new TextBlock();
        _participationRow = new Grid
        {
            MinHeight = 46, ColumnSpacing = 6,
            HorizontalAlignment = HorizontalAlignment.Stretch
        };
        _participationRow.ColumnDefinitions.Add(new ColumnDefinition { Width = GridLength.Auto });
        _participationRow.ColumnDefinitions.Add(new ColumnDefinition { Width = GridLength.Auto });
        _participationRow.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(1, GridUnitType.Star) });
        for (int i = 0; i < 4; i++)
            _participationRow.ColumnDefinitions.Add(new ColumnDefinition { Width = GridLength.Auto });
        _participationLabel.TextWrapping = TextWrapping.NoWrap;
        _participationLabel.TextTrimming = TextTrimming.CharacterEllipsis;
        _showEnergy.VerticalAlignment = VerticalAlignment.Center;
        _participation.VerticalAlignment = VerticalAlignment.Center;
        Grid.SetColumn(_showEnergy, 1);
        Grid.SetColumn(_participationLabel, 2);
        Grid.SetColumn(_participation, 3);
        Grid.SetColumn(_todayKeysValue, 4);
        Grid.SetColumn(_syncButton, 5);
        Grid.SetColumn(_repairButton, 6);
        _participationRow.Children.Add(_showEnergyLabel);
        _participationRow.Children.Add(_showEnergy);
        _participationRow.Children.Add(_participationLabel);
        _participationRow.Children.Add(_participation);
        _participationRow.Children.Add(_todayKeysValue);
        _participationRow.Children.Add(_syncButton);
        _participationRow.Children.Add(_repairButton);
        var cardContent = new StackPanel { Spacing = 2 };
        cardContent.Children.Add(_participationRow);
        cardContent.Children.Add(_syncStatus);
        _switchesCard.Content = cardContent;

        _loginButton.Click += (_, _) => NavigationRequested?.Invoke(this, new("General"));
        var leaderboardPanel = new StackPanel { Spacing = 10 };
        leaderboardPanel.Children.Add(_leaderboardTitle);
        leaderboardPanel.Children.Add(_leaderboardHint);
        leaderboardPanel.Children.Add(_currentRankHint);
        leaderboardPanel.Children.Add(_loginButton);
        leaderboardPanel.Children.Add(_leaderboard);
        _leaderboardCard.Padding = new Thickness(8);
        _leaderboardCard.CornerRadius = new CornerRadius(8);
        _leaderboardCard.Background = Application.Current.Resources["CardBackgroundFillColorDefaultBrush"] as Brush;
        _leaderboardCard.Child = leaderboardPanel;

        var panel = new StackPanel { Spacing = 12 };
        _decorations.Visibility = Visibility.Collapsed;
        panel.Children.Add(_decorations);
        panel.Children.Add(_switchesCard);
        panel.Children.Add(_leaderboardCard);
        Content = panel;

        _decorations.NavigationRequested += (_, args) => NavigationRequested?.Invoke(this, args);

        _participation.Toggled += async (_, _) =>
        {
            if (_updating || _cloud is null || _auth?.IsAuthenticated != true) return;
            try { await _cloud.SetParticipationAsync(_participation.IsOn); }
            catch (Exception ex) { App.Log($"[TreeCloud] Participation update failed: {ex.GetType().Name}"); Refresh(); }
        };
        _showEnergy.Toggled += (_, _) =>
        {
            if (_updating) return;
            _counter.SetShowEnergy(_showEnergy.IsOn);
        };
        Loaded += TreeSettingsSection_Loaded;
        Unloaded += TreeSettingsSection_Unloaded;
    }

    internal void Configure(LocalizationService localization, IAuthSession auth,
        TreeCloudSyncService cloud, TreeDecorationService decorations, AquariumService aquarium)
    {
        _localization = localization;
        _auth = auth;
        _cloud = cloud;
        _decorationService = decorations;
        _decorations.Configure(decorations, aquarium, localization, auth);
        RenderLabels();
        Refresh();
    }

    private void TreeSettingsSection_Loaded(object sender, RoutedEventArgs e)
    {
        if (_localization is not null) _localization.LanguageChanged += OnLanguageChanged;
        if (_auth is not null) _auth.SessionChanged += OnLeaderboardChanged;
        if (_cloud is not null) _cloud.LeaderboardChanged += OnLeaderboardChanged;
        _counter.Changed += OnCounterChanged;
        RenderLabels();
        Refresh();
        if (_auth?.IsAuthenticated == true && _cloud is not null)
        {
            _ = _cloud.RefreshStateAsync();
            _ = _cloud.RefreshLeaderboardAsync();
        }
    }

    private void TreeSettingsSection_Unloaded(object sender, RoutedEventArgs e)
    {
        if (_localization is not null) _localization.LanguageChanged -= OnLanguageChanged;
        if (_auth is not null) _auth.SessionChanged -= OnLeaderboardChanged;
        if (_cloud is not null) _cloud.LeaderboardChanged -= OnLeaderboardChanged;
        _counter.Changed -= OnCounterChanged;
    }

    private void RenderLabels()
    {
        if (_localization is null) return;
        _switchesCard.Header = _localization.T("Tree.Settings.Card.Switches");
        _leaderboardTitle.Text = _localization.T("Tree.Settings.Card.Leaderboard");
        _showEnergyLabel.Text = _localization.T("Tree.Settings.ShowEnergy");
        _participationLabel.Text = _localization.T("Tree.Settings.Leaderboard.Participate");
        ToolTipService.SetToolTip(_todayKeysValue, _localization.T("Tree.Settings.TodayKeys"));
        _loginButton.Content = _localization.T("Tree.Settings.Leaderboard.SignInAction");
        if (_syncButton.Content is TextBlock syncText)
            syncText.Text = _localization.T("Tree.SyncCalibrate");
        if (_repairButton.Content is TextBlock repairText)
            repairText.Text = _localization.T("Tree.SyncRepair");
        RefreshSyncActions();
    }

    private async void SyncButton_Click(object sender, RoutedEventArgs e)
    {
        string? ownerId = _auth?.CurrentUser?.Id;
        if (_cloud is null || ownerId is null || Interlocked.Exchange(ref _syncOperationInProgress, 1) != 0) return;

        RefreshSyncActions();
        SetSyncStatus(_localization?.T("Tree.SyncCalibrating") ?? string.Empty);
        try
        {
            bool synchronized = await _cloud.SyncProgressNowAsync();
            if (_auth?.CurrentUser?.Id != ownerId) return;
            SetSyncStatus(_localization?.T(synchronized
                ? "Tree.SyncCalibrateSuccess"
                : "Tree.SyncCalibrateUnavailable") ?? string.Empty);
        }
        catch (Exception ex) when (ex is HttpRequestException or TaskCanceledException)
        {
            App.Log($"[TreeCloud] Manual tree calibration failed: {ex.GetType().Name}");
            if (_auth?.CurrentUser?.Id == ownerId)
                SetSyncStatus(_localization?.T("Tree.SyncCalibrateError") ?? string.Empty);
        }
        catch (Exception ex)
        {
            App.Log($"[TreeCloud] Manual tree calibration failed: {ex}");
            if (_auth?.CurrentUser?.Id == ownerId)
                SetSyncStatus(_localization?.T("Tree.SyncCalibrateError") ?? string.Empty);
        }
        finally
        {
            Interlocked.Exchange(ref _syncOperationInProgress, 0);
            RefreshSyncActions();
        }
    }

    private async void RepairButton_Click(object sender, RoutedEventArgs e)
    {
        if (_cloud is null || Interlocked.Exchange(ref _syncOperationInProgress, 1) != 0) return;

        _repairButton.IsEnabled = false;
        try
        {
            int deleted = _cloud.DeleteLocalStateFiles();
            if (XamlRoot is null)
            {
                SetSyncStatus(_localization?.T("Tree.SyncRepairFailed") ?? string.Empty);
                return;
            }

            var dialog = new ContentDialog
            {
                XamlRoot = XamlRoot,
                Title = _localization?.T("Tree.SyncRepairTitle") ?? string.Empty,
                Content = new TextBlock
                {
                    Text = _localization?.Format("Tree.SyncRepairBody", deleted) ?? string.Empty,
                    TextWrapping = TextWrapping.Wrap
                },
                PrimaryButtonText = _localization?.T("Tree.SyncRepairConfirm") ?? string.Empty,
                CloseButtonText = _localization?.T("Common.Cancel") ?? string.Empty,
                DefaultButton = ContentDialogButton.Primary
            };

            if (await dialog.ShowAsync() != ContentDialogResult.Primary) return;

            AppRelaunchScheduleResult relaunch = AppRelaunchService.ScheduleAfterCurrentProcessExit();
            if (!relaunch.Started)
            {
                SetSyncStatus(_localization?.Format(
                    "Tree.SyncRepairRestartFailed", relaunch.ErrorMessage ?? string.Empty) ?? string.Empty);
                return;
            }

            await App.Current.ShutdownForRestartAsync();
        }
        catch (Exception ex)
        {
            App.Log($"[TreeCloud] Sync repair failed: {ex}");
            SetSyncStatus(_localization?.T("Tree.SyncRepairFailed") ?? string.Empty);
        }
        finally
        {
            Interlocked.Exchange(ref _syncOperationInProgress, 0);
            RefreshSyncActions();
        }
    }

    private void RefreshSyncActions()
    {
        bool busy = Volatile.Read(ref _syncOperationInProgress) != 0;
        _syncButton.IsEnabled = !busy && _auth?.IsAuthenticated == true;
        _repairButton.IsEnabled = !busy;
    }

    private void SetSyncStatus(string text)
    {
        _syncStatus.Text = text;
        _syncStatus.Visibility = string.IsNullOrWhiteSpace(text) ? Visibility.Collapsed : Visibility.Visible;
    }

    private void OnLeaderboardChanged()
    {
        if (Interlocked.Exchange(ref _leaderboardRefreshQueued, 1) != 0) return;
        if (!_uiQueue.TryEnqueue(() =>
            {
                try { if (IsLoaded) RefreshLeaderboardSurface(); }
                finally { Interlocked.Exchange(ref _leaderboardRefreshQueued, 0); }
            })) Interlocked.Exchange(ref _leaderboardRefreshQueued, 0);
    }

    private void OnLanguageChanged() { RenderLabels(); Refresh(); }

    private void OnCounterChanged()
    {
        if (Interlocked.Exchange(ref _counterRefreshQueued, 1) != 0) return;
        if (!_uiQueue.TryEnqueue(() =>
            {
                try { if (IsLoaded) { RefreshTodayKeys(); RefreshShowEnergy(); } }
                finally { Interlocked.Exchange(ref _counterRefreshQueued, 0); }
            }))
            Interlocked.Exchange(ref _counterRefreshQueued, 0);
    }

    private void Refresh()
    {
        RefreshTodayKeys();
        RefreshShowEnergy();
        RefreshLeaderboardSurface();
    }

    private void RefreshTodayKeys() => _todayKeysValue.Text = $"⌨ {_counter.Snapshot.TodayKeys:N0}";

    private void RefreshShowEnergy()
    {
        bool showEnergy = _counter.Snapshot.ShowEnergy;
        if (_showEnergy.IsOn == showEnergy) return;
        bool wasUpdating = _updating;
        _updating = true;
        try { _showEnergy.IsOn = showEnergy; }
        finally { _updating = wasUpdating; }
    }

    private void RefreshLeaderboardSurface()
    {
        if (_localization is null) return;
        _updating = true;
        try
        {
            bool signedIn = _auth?.IsAuthenticated == true;
            _decorations.Visibility = _decorationService?.IsVisibleToCurrentUser == true
                ? Visibility.Visible : Visibility.Collapsed;
            RefreshSyncActions();
            RefreshShowEnergy();
            _participationLabel.Visibility = signedIn ? Visibility.Visible : Visibility.Collapsed;
            _participation.Visibility = signedIn ? Visibility.Visible : Visibility.Collapsed;
            _leaderboard.Visibility = signedIn ? Visibility.Visible : Visibility.Collapsed;
            _loginButton.Visibility = signedIn ? Visibility.Collapsed : Visibility.Visible;
            _participation.IsOn = _cloud?.CurrentUserRank.Participating ?? true;
            _leaderboardHint.Text = !signedIn
                ? _localization.T("Tree.Settings.Leaderboard.SignIn")
                : _cloud?.IsOffline == true
                    ? _localization.T("Tree.Settings.Leaderboard.Offline")
                    : string.Empty;
            _leaderboardHint.Visibility = string.IsNullOrEmpty(_leaderboardHint.Text)
                ? Visibility.Collapsed : Visibility.Visible;
            int? currentRank = _cloud?.CurrentUserRank.Rank;
            _currentRankHint.Visibility = signedIn && currentRank.HasValue ? Visibility.Visible : Visibility.Collapsed;
            _currentRankHint.Text = currentRank.HasValue
                ? _localization.Format("Tree.Settings.Leaderboard.CurrentRank", currentRank.Value)
                : string.Empty;
            RenderLeaderboard();
        }
        finally { _updating = false; }
    }

    private void RenderLeaderboard()
    {
        _leaderboard.Items.Clear();
        if (_cloud is null || _localization is null) return;
        foreach (TreeLeaderboardEntryDto entry in _cloud.Leaderboard)
        {
            string medal = entry.Rank switch { 1 => "🥇", 2 => "🥈", 3 => "🥉", _ => "🌿" };
            var grid = new Grid
            {
                Padding = new Thickness(4, 5, 4, 5),
                ColumnSpacing = 4,
                HorizontalAlignment = HorizontalAlignment.Stretch,
                Background = entry.IsCurrentUser ? new SolidColorBrush(Microsoft.UI.Colors.PaleGreen) : null,
                CornerRadius = new CornerRadius(8)
            };
            grid.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(42) });
            grid.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(1, GridUnitType.Star) });
            grid.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(74) });
            grid.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(54) });
            grid.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(78) });
            grid.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(65) });
            grid.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(54) });
            grid.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(50) });
            AddCell(grid, $"{medal} {entry.Rank}", 0, true);
            AddCell(grid, entry.IsCurrentUser ? _localization.Format("Tree.Settings.Leaderboard.Me", entry.Username) : entry.Username, 1, entry.IsCurrentUser);
            AddStageCell(grid, _localization.Format("Tree.Settings.Leaderboard.Stage", entry.Stage), entry.Stage, 2);
            AddCell(grid, $"+{entry.TodayGrowth:N0}", 3, false);
            AddCell(grid, $"⚡{entry.TotalEnergy:N0}", 4, false);
            AddCell(grid, $"🍎{entry.FruitInventory ?? entry.FruitTotal:N0}", 5, false);
            var visits = new TextBlock
            {
                Text = $"👁 {entry.VisitCount:N0}", FontSize = 12,
                TextWrapping = TextWrapping.NoWrap, TextTrimming = TextTrimming.CharacterEllipsis,
                VerticalAlignment = VerticalAlignment.Center
            };
            ToolTipService.SetToolTip(visits,
                _localization.Format("Tree.Settings.Leaderboard.VisitCount", entry.VisitCount));
            Grid.SetColumn(visits, 6);
            grid.Children.Add(visits);
            if (_cloud.CanVisit(entry))
            {
                var visit = new Button
                {
                    Content = _localization.T("Tree.Settings.Leaderboard.Visit"),
                    MinWidth = 0, MinHeight = 28, FontSize = 11,
                    Padding = new Thickness(3, 1, 3, 1),
                    HorizontalAlignment = HorizontalAlignment.Stretch
                };
                visit.Click += async (_, _) => await VisitAsync(entry, visit, visits);
                Grid.SetColumn(visit, 7);
                grid.Children.Add(visit);
            }
            _leaderboard.Items.Add(grid);
        }
    }

    private async Task VisitAsync(TreeLeaderboardEntryDto entry, Button button, TextBlock visits)
    {
        if (_cloud is null || _localization is null || _auth?.IsAuthenticated != true ||
            !_cloud.CanVisit(entry)) return;
        string? viewerId = _auth.CurrentUser?.Id;
        var status = new TextBlock
        {
            Text = _localization.T("Tree.Settings.Leaderboard.VisitLoading"),
            TextWrapping = TextWrapping.Wrap
        };
        var panel = new StackPanel { Spacing = 8, Width = 390 };
        var header = new Grid { ColumnSpacing = 5, MinHeight = 34 };
        header.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(1, GridUnitType.Star) });
        header.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(70) });
        header.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(82) });
        header.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(72) });
        header.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(30) });
        var name = new TextBlock
        {
            Text = entry.Username,
            FontSize = 14, FontWeight = Microsoft.UI.Text.FontWeights.SemiBold,
            TextWrapping = TextWrapping.NoWrap, TextTrimming = TextTrimming.CharacterEllipsis,
            VerticalAlignment = VerticalAlignment.Center
        };
        header.Children.Add(name);
        TextBlock stage = AddCell(header,
            _localization.Format("Tree.Settings.Leaderboard.Stage", entry.Stage), 1, false);
        TextBlock energy = AddCell(header, $"⚡{entry.TotalEnergy:N0}", 2, false);
        var like = new Button
        {
            Content = "👍 0", MinWidth = 0, MinHeight = 28,
            Padding = new Thickness(3, 1, 3, 1), IsEnabled = false,
            HorizontalAlignment = HorizontalAlignment.Stretch
        };
        Grid.SetColumn(like, 3);
        header.Children.Add(like);
        ToolTipService.SetToolTip(like, _localization.T("Tree.Settings.Leaderboard.Like"));
        var close = new Button
        {
            Content = "×", MinWidth = 0, MinHeight = 28, FontSize = 18,
            Padding = new Thickness(0), HorizontalAlignment = HorizontalAlignment.Stretch
        };
        Grid.SetColumn(close, 4);
        header.Children.Add(close);
        ToolTipService.SetToolTip(close, _localization.T("Common.Close"));
        Microsoft.UI.Xaml.Automation.AutomationProperties.SetName(close,
            _localization.T("Common.Close"));
        panel.Children.Add(header);
        panel.Children.Add(status);
        var flyout = new Flyout { Content = panel };
        close.Click += (_, _) => flyout.Hide();
        flyout.ShowAt(button);
        button.IsEnabled = false;
        try
        {
            TreeVisitSnapshot snapshot = await _cloud.VisitAsync(entry.Account);
            entry.VisitCount = snapshot.VisitCount;
            visits.Text = $"👁 {entry.VisitCount:N0}";
            ToolTipService.SetToolTip(visits,
                _localization.Format("Tree.Settings.Leaderboard.VisitCount", entry.VisitCount));
            if (!flyout.IsOpen || _auth.CurrentUser?.Id != viewerId) return;
            name.Text = snapshot.Username;
            stage.Text = _localization.Format("Tree.Settings.Leaderboard.Stage", snapshot.Stage);
            energy.Text = $"⚡{snapshot.TotalEnergy:N0}";
            like.Content = $"{(snapshot.LikedByMe ? "❤️" : "👍")} {snapshot.LikeCount:N0}";
            ToolTipService.SetToolTip(like, _localization.T(snapshot.LikedByMe
                ? "Tree.Settings.Leaderboard.LikedToday" : "Tree.Settings.Leaderboard.Like"));
            like.IsEnabled = !snapshot.LikedByMe;
            like.Click += async (_, _) =>
            {
                if (_cloud is null || _auth?.CurrentUser?.Id != viewerId || snapshot.LikedByMe) return;
                like.IsEnabled = false;
                try
                {
                    TreeVisitLikeResponse result = await _cloud.LikeVisitAsync(entry.Account);
                    snapshot.LikedByMe = result.LikedByMe;
                    snapshot.LikeCount = result.LikeCount;
                    if (flyout.IsOpen && _auth?.CurrentUser?.Id == viewerId)
                    {
                        like.Content = $"{(result.LikedByMe ? "❤️" : "👍")} {result.LikeCount:N0}";
                        ToolTipService.SetToolTip(like, _localization.T(result.LikedByMe
                            ? "Tree.Settings.Leaderboard.LikedToday" : "Tree.Settings.Leaderboard.Like"));
                    }
                }
                catch (Exception ex)
                {
                    App.Log($"[TreeCloud] Tree like failed: {ex.GetType().Name}");
                    if (flyout.IsOpen)
                    {
                        status.Text = _localization.T("Tree.Settings.Leaderboard.LikeError");
                        if (status.Parent is null) panel.Children.Insert(1, status);
                    }
                }
                finally { like.IsEnabled = flyout.IsOpen && _auth?.CurrentUser?.Id == viewerId && !snapshot.LikedByMe; }
            };
            panel.Children.Remove(status);
            panel.Children.Add(new TreeVisitSceneView(snapshot, _decorationService?.Catalog));
            panel.Children.Add(new TextBlock
            {
                Text = _localization.Format("Tree.Settings.Leaderboard.VisitFruit", snapshot.FruitInventory),
                HorizontalAlignment = HorizontalAlignment.Center
            });
        }
        catch (Exception ex)
        {
            App.Log($"[TreeCloud] Tree visit failed: {ex.GetType().Name}");
            if (flyout.IsOpen && _auth.CurrentUser?.Id == viewerId)
                status.Text = _localization.T("Tree.Settings.Leaderboard.VisitError");
        }
        finally { button.IsEnabled = true; }
    }

    private static TextBlock AddCell(Grid grid, string text, int column, bool strong)
    {
        var block = new TextBlock
        {
            Text = text, VerticalAlignment = VerticalAlignment.Center,
            TextWrapping = TextWrapping.NoWrap, TextTrimming = TextTrimming.CharacterEllipsis,
            FontSize = 12
        };
        if (strong) block.FontWeight = Microsoft.UI.Text.FontWeights.SemiBold;
        Grid.SetColumn(block, column);
        grid.Children.Add(block);
        return block;
    }

    private static void AddStageCell(Grid grid, string text, int stage, int column)
    {
        var badge = new Border
        {
            Background = new SolidColorBrush(stage switch
            {
                1 => Microsoft.UI.Colors.LightGreen,
                2 => Microsoft.UI.Colors.LightBlue,
                3 => Microsoft.UI.Colors.Khaki,
                4 => Microsoft.UI.Colors.PeachPuff,
                _ => Microsoft.UI.Colors.Plum
            }),
            CornerRadius = new CornerRadius(10),
            Padding = new Thickness(4, 3, 4, 3),
            HorizontalAlignment = HorizontalAlignment.Stretch,
            VerticalAlignment = VerticalAlignment.Center,
            Child = new TextBlock
            {
                Text = text, FontSize = 11, TextWrapping = TextWrapping.NoWrap,
                TextTrimming = TextTrimming.CharacterEllipsis,
                TextAlignment = TextAlignment.Center,
                HorizontalAlignment = HorizontalAlignment.Stretch,
                VerticalAlignment = VerticalAlignment.Center,
                Foreground = new SolidColorBrush(Microsoft.UI.Colors.DarkSlateGray)
            }
        };
        Grid.SetColumn(badge, column);
        grid.Children.Add(badge);
    }
}
