using CommunityToolkit.WinUI.Controls;
using DeskBox.Contracts;
using DeskBox.Models;
using DeskBox.Services;
using DeskBox.ViewModels;
using Microsoft.UI.Dispatching;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
using Microsoft.UI.Xaml.Media;

namespace DeskBox.Views.SettingsSections;

public sealed partial class TreeSettingsSection : UserControl
{
    private readonly TreeCounterService _counter = TreeCounterService.Instance;
    private readonly DispatcherQueue _uiQueue = DispatcherQueue.GetForCurrentThread();
    private readonly ToggleSwitch _enable = new() { OnContent = "", OffContent = "", MinWidth = 0 };
    private readonly ToggleSwitch _statistics = new() { MinWidth = 0 };
    private readonly ToggleSwitch _participation = new() { OnContent = "", OffContent = "", MinWidth = 0 };
    private readonly TextBlock _enableLabel = new() { TextWrapping = TextWrapping.Wrap, VerticalAlignment = VerticalAlignment.Center };
    private readonly TextBlock _statisticsLabel = new() { TextWrapping = TextWrapping.Wrap, VerticalAlignment = VerticalAlignment.Center };
    private readonly TextBlock _participationLabel = new() { TextWrapping = TextWrapping.Wrap, VerticalAlignment = VerticalAlignment.Center };
    private readonly TextBlock _status = new() { FontSize = 17, FontWeight = Microsoft.UI.Text.FontWeights.SemiBold };
    private readonly TextBlock _growthSummary = new() { FontSize = 13, Opacity = 0.75, TextWrapping = TextWrapping.Wrap };
    private readonly TextBlock _leaderboardTitle = new() { FontSize = 16, FontWeight = Microsoft.UI.Text.FontWeights.SemiBold };
    private readonly TextBlock _leaderboardHint = new() { FontSize = 12, Opacity = 0.72, TextWrapping = TextWrapping.Wrap };
    private readonly TextBlock _currentRankHint = new() { FontSize = 13, FontWeight = Microsoft.UI.Text.FontWeights.SemiBold, TextWrapping = TextWrapping.Wrap };
    private readonly Button _loginButton = new() { HorizontalAlignment = HorizontalAlignment.Left };
    private readonly ListView _leaderboard = new() { SelectionMode = ListViewSelectionMode.None };
    private readonly SettingsCard _currentCard = new() { HorizontalContentAlignment = HorizontalAlignment.Stretch };
    private readonly SettingsCard _switchesCard = new() { HorizontalContentAlignment = HorizontalAlignment.Stretch };
    private readonly Border _leaderboardCard = new() { HorizontalAlignment = HorizontalAlignment.Stretch };
    private readonly Grid _participationRow;
    private SettingsViewModel? _viewModel;
    private LocalizationService? _localization;
    private IAuthSession? _auth;
    private TreeCloudSyncService? _cloud;
    private bool _updating;
    private int _progressRefreshQueued;
    private int _leaderboardRefreshQueued;

    public event EventHandler<SettingsSectionNavigationRequestedEventArgs>? NavigationRequested;

    public TreeSettingsSection()
    {
        var current = new StackPanel { Spacing = 4 };
        current.Children.Add(_status);
        current.Children.Add(_growthSummary);
        _currentCard.Content = current;

        var switches = new Grid { ColumnSpacing = 18 };
        switches.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(1, GridUnitType.Star) });
        switches.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(1, GridUnitType.Star) });
        Grid enableColumn = CreateToggleRow(_enableLabel, _enable);
        Grid statisticsColumn = CreateToggleRow(_statisticsLabel, _statistics);
        Grid.SetColumn(statisticsColumn, 1);
        switches.Children.Add(enableColumn);
        switches.Children.Add(statisticsColumn);
        _switchesCard.Content = switches;

        _loginButton.Click += (_, _) => NavigationRequested?.Invoke(this, new("General"));
        var leaderboardPanel = new StackPanel { Spacing = 10 };
        leaderboardPanel.Children.Add(_leaderboardTitle);
        _participationRow = CreateToggleRow(_participationLabel, _participation);
        leaderboardPanel.Children.Add(_participationRow);
        leaderboardPanel.Children.Add(_leaderboardHint);
        leaderboardPanel.Children.Add(_currentRankHint);
        leaderboardPanel.Children.Add(_loginButton);
        leaderboardPanel.Children.Add(_leaderboard);
        _leaderboardCard.Padding = new Thickness(16);
        _leaderboardCard.CornerRadius = new CornerRadius(8);
        _leaderboardCard.Background = Application.Current.Resources["CardBackgroundFillColorDefaultBrush"] as Brush;
        _leaderboardCard.Child = leaderboardPanel;

        var panel = new StackPanel { Spacing = 10, Margin = new Thickness(24) };
        panel.Children.Add(_currentCard);
        panel.Children.Add(_switchesCard);
        panel.Children.Add(_leaderboardCard);
        Content = panel;

        _enable.Toggled += (_, _) =>
        {
            if (_updating || _viewModel is null) return;
            bool wasEnabled = _viewModel.IsWidgetEnabled(WidgetKind.Tree);
            _viewModel.SetWidgetEnabled(WidgetKind.Tree, _enable.IsOn);
            if (_enable.IsOn && !wasEnabled) _counter.SetPaused(false);
            _counter.SetEnabled(_enable.IsOn);
            RefreshProgress();
        };
        _statistics.Toggled += (_, _) =>
        {
            if (_updating || !_statistics.IsEnabled) return;
            _counter.SetPaused(TreeStatisticsState.PausedFromToggle(_statistics.IsOn));
            RefreshProgress();
        };
        _participation.Toggled += async (_, _) =>
        {
            if (_updating || _cloud is null || _auth?.IsAuthenticated != true) return;
            try { await _cloud.SetParticipationAsync(_participation.IsOn); }
            catch (Exception ex) { App.Log($"[TreeCloud] Participation update failed: {ex.GetType().Name}"); Refresh(); }
        };
        Loaded += TreeSettingsSection_Loaded;
        Unloaded += TreeSettingsSection_Unloaded;
    }

    internal void Configure(SettingsViewModel viewModel, LocalizationService localization, IAuthSession auth, TreeCloudSyncService cloud)
    {
        _viewModel = viewModel;
        _localization = localization;
        _auth = auth;
        _cloud = cloud;
        RenderLabels();
        Refresh();
    }

    private void TreeSettingsSection_Loaded(object sender, RoutedEventArgs e)
    {
        _counter.Changed += OnProgressChanged;
        if (_localization is not null) _localization.LanguageChanged += OnLanguageChanged;
        if (_auth is not null) _auth.SessionChanged += OnLeaderboardChanged;
        if (_cloud is not null) _cloud.LeaderboardChanged += OnLeaderboardChanged;
        RenderLabels();
        Refresh();
        if (_auth?.IsAuthenticated == true && _cloud is not null) _ = _cloud.RefreshLeaderboardAsync();
    }

    private void TreeSettingsSection_Unloaded(object sender, RoutedEventArgs e)
    {
        _counter.Changed -= OnProgressChanged;
        if (_localization is not null) _localization.LanguageChanged -= OnLanguageChanged;
        if (_auth is not null) _auth.SessionChanged -= OnLeaderboardChanged;
        if (_cloud is not null) _cloud.LeaderboardChanged -= OnLeaderboardChanged;
    }

    private void RenderLabels()
    {
        if (_localization is null) return;
        _currentCard.Header = _localization.T("Tree.Settings.Card.Current");
        _currentCard.Description = _localization.T("Tree.Settings.Card.Current.Description");
        _switchesCard.Header = _localization.T("Tree.Settings.Card.Switches");
        _leaderboardTitle.Text = _localization.T("Tree.Settings.Card.Leaderboard");
        _enableLabel.Text = _localization.T("Tree.Settings.Enable");
        _statisticsLabel.Text = _localization.T("Tree.Settings.EnableStatistics");
        _statistics.OnContent = _localization.T("Tree.Settings.StatisticsRunning");
        _statistics.OffContent = _localization.T("Tree.Settings.StatisticsPaused");
        _participationLabel.Text = _localization.T("Tree.Settings.Leaderboard.Participate");
        _loginButton.Content = _localization.T("Tree.Settings.Leaderboard.SignInAction");
    }

    private static Grid CreateToggleRow(TextBlock label, ToggleSwitch toggle)
    {
        var row = new Grid
        {
            MinHeight = 46,
            ColumnSpacing = 16,
            HorizontalAlignment = HorizontalAlignment.Stretch
        };
        row.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(1, GridUnitType.Star) });
        row.ColumnDefinitions.Add(new ColumnDefinition { Width = GridLength.Auto });
        toggle.HorizontalAlignment = HorizontalAlignment.Right;
        toggle.VerticalAlignment = VerticalAlignment.Center;
        Grid.SetColumn(label, 0);
        Grid.SetColumn(toggle, 1);
        row.Children.Add(label);
        row.Children.Add(toggle);
        return row;
    }

    private void OnProgressChanged()
    {
        if (Interlocked.Exchange(ref _progressRefreshQueued, 1) != 0) return;
        if (!_uiQueue.TryEnqueue(() =>
            {
                try { if (IsLoaded) RefreshProgress(); }
                finally { Interlocked.Exchange(ref _progressRefreshQueued, 0); }
            })) Interlocked.Exchange(ref _progressRefreshQueued, 0);
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

    private void Refresh()
    {
        RefreshProgress();
        RefreshLeaderboardSurface();
    }

    private void RefreshProgress()
    {
        if (_viewModel is null || _localization is null) return;
        _updating = true;
        try
        {
            _enable.IsOn = _viewModel.IsWidgetEnabled(WidgetKind.Tree);
            TreeProgress snapshot = _counter.Snapshot;
            TreeStatisticsState statistics = TreeStatisticsState.FromProgress(_enable.IsOn, snapshot.Paused);
            _statistics.IsEnabled = statistics.ToggleEnabled;
            _statistics.IsOn = statistics.ToggleOn;
            _statistics.OffContent = _localization.T(statistics.OffContentKey);
            _status.Text = _localization.T($"Tree.Stage.{snapshot.Stage}");
            _growthSummary.Text = _localization.Format("Tree.Settings.Current.Summary", snapshot.TodayKeys, snapshot.TotalEnergy, snapshot.FruitInventory);
        }
        finally { _updating = false; }
    }

    private void RefreshLeaderboardSurface()
    {
        if (_viewModel is null || _localization is null) return;
        _updating = true;
        try
        {
            bool signedIn = _auth?.IsAuthenticated == true;
            _participationRow.Visibility = signedIn ? Visibility.Visible : Visibility.Collapsed;
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
                Padding = new Thickness(10, 7, 10, 7),
                ColumnSpacing = 12,
                Background = entry.IsCurrentUser ? new SolidColorBrush(Microsoft.UI.Colors.PaleGreen) : null,
                CornerRadius = new CornerRadius(8)
            };
            grid.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(54) });
            grid.ColumnDefinitions.Add(new ColumnDefinition());
            grid.ColumnDefinitions.Add(new ColumnDefinition { Width = GridLength.Auto });
            grid.ColumnDefinitions.Add(new ColumnDefinition { Width = GridLength.Auto });
            grid.ColumnDefinitions.Add(new ColumnDefinition { Width = GridLength.Auto });
            AddCell(grid, $"{medal} {entry.Rank}", 0, true);
            AddCell(grid, entry.IsCurrentUser ? _localization.Format("Tree.Settings.Leaderboard.Me", entry.Username) : entry.Username, 1, entry.IsCurrentUser);
            AddStageCell(grid, _localization.Format("Tree.Settings.Leaderboard.Stage", entry.Stage), entry.Stage, 2);
            AddCell(grid, $"+{entry.TodayGrowth:N0}", 3, false);
            AddCell(grid, $"⚡ {entry.TotalEnergy:N0}  🍎 {entry.FruitTotal:N0}", 4, false);
            _leaderboard.Items.Add(grid);
        }
    }

    private static void AddCell(Grid grid, string text, int column, bool strong)
    {
        var block = new TextBlock { Text = text, VerticalAlignment = VerticalAlignment.Center, Margin = new Thickness(2), TextTrimming = TextTrimming.CharacterEllipsis };
        if (strong) block.FontWeight = Microsoft.UI.Text.FontWeights.SemiBold;
        Grid.SetColumn(block, column);
        grid.Children.Add(block);
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
            Padding = new Thickness(8, 3, 8, 3),
            Child = new TextBlock { Text = text, FontSize = 12, Foreground = new SolidColorBrush(Microsoft.UI.Colors.DarkSlateGray) }
        };
        Grid.SetColumn(badge, column);
        grid.Children.Add(badge);
    }
}
