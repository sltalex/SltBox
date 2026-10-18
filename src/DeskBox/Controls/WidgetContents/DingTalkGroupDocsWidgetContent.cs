using DeskBox.Models;
using DeskBox.Services;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
using Microsoft.Extensions.DependencyInjection;
using Microsoft.UI.Xaml.Automation;
using Microsoft.UI.Xaml.Media;
using Microsoft.UI.Text;
using System.Globalization;
using Windows.System;

namespace DeskBox.Controls.WidgetContents;

public sealed partial class DingTalkGroupDocsWidgetContent : UserControl, IDisposable
{
    private readonly LocalizationService _localization;
    private readonly DingTalkGroupDocumentService _service;
    private readonly DingTalkGroupDocumentFavorites _favorites;
    private readonly WidgetConfig _config;
    private readonly SettingsService? _settings;
    private readonly DingTalkGroupDocumentSyncService? _sync;
    private readonly TextBlock _status = new() { TextWrapping = TextWrapping.Wrap, Visibility = Visibility.Collapsed };
    private readonly TextBox _search;
    private readonly StackPanel _groups = new();
    private readonly Button _refresh = new();
    private readonly Button _allTab = new() { MinWidth = 0, MinHeight = 28, Padding = new Thickness(10, 2, 10, 2) };
    private readonly Button _favoritesTab = new() { MinWidth = 0, MinHeight = 28, Padding = new Thickness(10, 2, 10, 2) };
    private readonly ProgressBar _progress = new() { Minimum = 0, Maximum = 1, Height = 6, Visibility = Visibility.Collapsed };
    private readonly TextBlock _progressText = new() { TextWrapping = TextWrapping.Wrap, Opacity = 0.75, Visibility = Visibility.Collapsed };
    private bool _loaded;
    private bool _disposed;
    private bool _showFavorites;
    private int _lastRenderedSegment;

    public DingTalkGroupDocsWidgetContent(
        LocalizationService localization,
        WidgetConfig? config = null,
        DingTalkGroupDocumentService? service = null,
        DingTalkGroupDocumentFavorites? favorites = null)
    {
        _localization = localization;
        _config = config ?? new WidgetConfig { WidgetKind = WidgetKind.DingTalkGroupDocs };
        _settings = App.Current?.SettingsService;
        _service = service ?? App.Current?.Services.GetService<DingTalkGroupDocumentService>()
            ?? new DingTalkGroupDocumentService(new DingTalkDwsClient());
        _favorites = favorites ?? App.Current?.Services.GetService<DingTalkGroupDocumentFavorites>()
            ?? new DingTalkGroupDocumentFavorites(new DingTalkDwsClient());
        _sync = App.Current?.Services.GetService<DingTalkGroupDocumentSyncService>();
        _search = new TextBox { PlaceholderText = _localization.T("DingTalkGroupDocs.Search") };
        _groups.Spacing = (double)Application.Current.Resources["SettingsCardSpacing"];

        _refresh.Content = _localization.T("DingTalkGroupDocs.Sync.Button");
        _allTab.Content = _localization.T("DingTalkGroupDocs.Tab.All");
        _favoritesTab.Content = _localization.T("DingTalkGroupDocs.Tab.Favorites");
        _refresh.Click += Refresh_Click;
        _service.ProgressChanged += ProgressChanged;
        _favorites.Changed += FavoritesChanged;
        if (_settings is not null) _settings.SettingsChanged += SettingsChanged;
        _search.TextChanged += Search_TextChanged;
        _allTab.Click += (_, _) => { _showFavorites = false; UpdateTabs(); Render(_service.Documents); };
        _favoritesTab.Click += (_, _) => { _showFavorites = true; UpdateTabs(); Render(_service.Documents); };
        var header = new Grid { ColumnSpacing = 8 };
        header.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(1, GridUnitType.Star) });
        header.ColumnDefinitions.Add(new ColumnDefinition { Width = GridLength.Auto });
        _search.MinWidth = 0;
        header.Children.Add(_search);
        Grid.SetColumn(_refresh, 1);
        header.Children.Add(_refresh);

        var tabs = new StackPanel { Orientation = Orientation.Horizontal, Spacing = 4 };
        tabs.Children.Add(_allTab);
        tabs.Children.Add(_favoritesTab);
        UpdateTabs();

        var root = new StackPanel
        {
            Spacing = (double)Application.Current.Resources["SettingsCardSpacing"],
            Padding = (Thickness)Application.Current.Resources["WidgetCompactSectionPadding"]
        };
        root.Children.Add(header);
        root.Children.Add(tabs);
        root.Children.Add(_status);
        root.Children.Add(_progress);
        root.Children.Add(_progressText);
        root.Children.Add(new ScrollViewer
        {
            Content = _groups,
            VerticalScrollBarVisibility = ScrollBarVisibility.Auto,
            HorizontalScrollBarVisibility = ScrollBarVisibility.Disabled,
            MaxHeight = 620
        });
        Content = root;
        Loaded += LoadedHandler;
        Unloaded += UnloadedHandler;
    }

    private async void LoadedHandler(object sender, RoutedEventArgs e)
    {
        if (_loaded || _disposed) return;
        _loaded = true;
        await RefreshAsync(loadOnly: true);
    }

    private void UnloadedHandler(object sender, RoutedEventArgs e) => _loaded = false;

    private async void Refresh_Click(object sender, RoutedEventArgs e) => await RefreshAsync(loadOnly: false);

    private void Search_TextChanged(object sender, TextChangedEventArgs e) => Render(_service.Documents);

    private void SettingsChanged()
    {
        if (_disposed) return;
        if (!DispatcherQueue.HasThreadAccess)
        {
            DispatcherQueue.TryEnqueue(SettingsChanged);
            return;
        }

        Render(_service.Documents);
    }

    private async Task RefreshAsync(bool loadOnly)
    {
        if (_disposed) return;
        _refresh.IsEnabled = false;
        _progress.Visibility = _service.IsSyncing || !loadOnly ? Visibility.Visible : Visibility.Collapsed;
        _progress.IsIndeterminate = _progress.Visibility == Visibility.Visible;
        SetStatus(_localization.T("DingTalkGroupDocs.Loading"));
        try
        {
            // The first six-month scan may take a long time. Keep the already
            // loaded local index visible instead of waiting for its state lock.
            IReadOnlyList<DingTalkGroupDocument> documents = _service.IsSyncing
                ? _service.Documents
                : await _service.LoadAsync();
            await _favorites.LoadAsync();
            await _favorites.UpdateDocumentMetadataAsync(documents);
            bool syncUnavailable = false;
            if (!loadOnly)
            {
                syncUnavailable = _sync is null || !await _sync.SyncNowAsync();
                documents = _service.Documents;
                await _favorites.UpdateDocumentMetadataAsync(documents);
            }
            Render(documents);
            SetStatus(syncUnavailable
                ? _localization.T("DingTalkGroupDocs.Sync.NotReady")
                : _service.Documents.Count == 0
                ? _localization.T("DingTalkGroupDocs.Empty")
                : string.Empty);
        }
        catch (DingTalkDwsAuthorizationRequiredException)
        {
            SetStatus(_localization.T("DingTalkGroupDocs.SignIn"));
            Render([]);
        }
        catch (DingTalkDwsMessageReadException)
        {
            SetStatus(_localization.T("DingTalkGroupDocs.Error"));
            Render(_service.Documents);
        }
        catch (InvalidOperationException ex)
        {
            App.Log($"[DingTalkGroupDocs] Refresh failed: {ex.GetType().Name}");
            SetStatus(_localization.T("DingTalkGroupDocs.Error"));
            Render(_service.Documents);
        }
        catch (Exception ex) when (ex is IOException or InvalidDataException or TimeoutException or HttpRequestException or
            AuthApiException or FileNotFoundException or System.ComponentModel.Win32Exception or OperationCanceledException or
            System.Text.Json.JsonException)
        {
            App.Log($"[DingTalkGroupDocs] Refresh failed: {ex.GetType().Name}");
            SetStatus(_localization.T("DingTalkGroupDocs.Error"));
            Render(_service.Documents);
        }
        finally
        {
            if (!_service.IsSyncing)
            {
                _progress.IsIndeterminate = false;
                _progress.Visibility = Visibility.Collapsed;
                _progressText.Visibility = Visibility.Collapsed;
            }
            _refresh.IsEnabled = !_service.IsSyncing;
        }
    }

    private void ProgressChanged(DingTalkGroupDocumentSyncProgress progress)
    {
        DispatcherQueue.TryEnqueue(() =>
        {
            if (_disposed) return;
            _refresh.IsEnabled = !progress.IsRunning;
            _progress.Visibility = progress.IsRunning ? Visibility.Visible : Visibility.Collapsed;
            _progressText.Visibility = progress.IsRunning ? Visibility.Visible : Visibility.Collapsed;
            _progress.IsIndeterminate = progress.IsRunning && progress.TotalSegments <= 0;
            _progress.Value = progress.IsRunning && progress.TotalSegments > 0
                ? progress.EstimatedCompletion
                : 0;
            if (progress.IsRunning && progress.CompletedSegments == 0 && progress.PagesFetched == 0)
                _lastRenderedSegment = 0;
            if (progress.IsRunning && progress.CompletedSegments > _lastRenderedSegment)
            {
                _lastRenderedSegment = progress.CompletedSegments;
                Render(_service.Documents);
                SetStatus(_service.Documents.Count == 0
                    ? _localization.T("DingTalkGroupDocs.Empty")
                    : string.Empty);
            }
            _progressText.Text = !progress.IsRunning
                ? string.Empty
                : (progress.IsInitialSync
                    ? _localization.T("DingTalkGroupDocs.Sync.InitialProgress")
                    : _localization.T("DingTalkGroupDocs.Sync.Progress")) + "\n" +
                  progress.FormatDetails(_localization);
        });
    }

    private void FavoritesChanged() => DispatcherQueue.TryEnqueue(() =>
    {
        if (!_disposed) Render(_service.Documents);
    });

    private void SetStatus(string text)
    {
        _status.Text = text;
        _status.Visibility = string.IsNullOrWhiteSpace(text) ? Visibility.Collapsed : Visibility.Visible;
    }

    private void UpdateTabs()
    {
        _allTab.FontWeight = _showFavorites ? Microsoft.UI.Text.FontWeights.Normal : Microsoft.UI.Text.FontWeights.SemiBold;
        _favoritesTab.FontWeight = _showFavorites ? Microsoft.UI.Text.FontWeights.SemiBold : Microsoft.UI.Text.FontWeights.Normal;
        _allTab.Opacity = _showFavorites ? 0.65 : 1;
        _favoritesTab.Opacity = _showFavorites ? 1 : 0.65;
    }

    private void Render(IReadOnlyList<DingTalkGroupDocument> documents)
    {
        if (_disposed) return;
        string filter = _search.Text?.Trim() ?? string.Empty;
        IEnumerable<DingTalkGroupDocument> visibleDocuments = _showFavorites
            ? _favorites.FavoriteDocuments.Concat(documents)
                .DistinctBy(document => DingTalkGroupDocumentFavorites.CanonicalKey(document.Url))
            : documents;
        var groups = visibleDocuments
            .Where(document => !_showFavorites || _favorites.IsFavorite(document.Url))
            .Where(document => string.IsNullOrWhiteSpace(filter) ||
                document.GroupName.Contains(filter, StringComparison.CurrentCultureIgnoreCase) ||
                document.Title.Contains(filter, StringComparison.CurrentCultureIgnoreCase))
            .GroupBy(document => document.GroupName, StringComparer.CurrentCultureIgnoreCase)
            .OrderBy(group => group.Key, StringComparer.CurrentCultureIgnoreCase)
            .ToArray();

        _groups.Children.Clear();
        foreach (IGrouping<string, DingTalkGroupDocument> group in groups)
        {
            var card = new Border
            {
                Style = (Style)Application.Current.Resources["WidgetGroupDocumentCardStyle"]
            };
            var panel = new StackPanel
            {
                Spacing = (double)Application.Current.Resources["WidgetCompactRowSpacing"]
            };
            var documentRows = new StackPanel { Spacing = ReadRowSpacing() };
            panel.Children.Add(new TextBlock
            {
                Text = group.Key,
                FontWeight = Microsoft.UI.Text.FontWeights.SemiBold
            });
            panel.Children.Add(documentRows);
            foreach (DingTalkGroupDocument document in group.OrderByDescending(item => item.LastSeenAt))
            {
                var row = new Grid { ColumnSpacing = 2 };
                row.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(1, GridUnitType.Star) });
                row.ColumnDefinitions.Add(new ColumnDefinition { Width = GridLength.Auto });
                var link = new HyperlinkButton
                {
                    Content = new TextBlock { Text = document.Title, TextTrimming = TextTrimming.CharacterEllipsis },
                    MinHeight = 28,
                    Padding = ReadLinkPadding(),
                    HorizontalAlignment = HorizontalAlignment.Stretch,
                    HorizontalContentAlignment = HorizontalAlignment.Left
                };
                if (link.Content is TextBlock linkText)
                {
                    linkText.FontSize = ReadLinkFontSize();
                    linkText.FontWeight = FontWeights.Normal;
                    linkText.Foreground = (Brush)Application.Current.Resources["TextFillColorPrimaryBrush"];
                }
                ToolTipService.SetToolTip(link, document.Url);
                link.Click += async (_, _) => await Launcher.LaunchUriAsync(new Uri(document.Url));
                row.Children.Add(link);

                var star = new Button
                {
                    Style = (Style)Application.Current.Resources["WidgetTitleActionButtonStyle"],
                    Content = new FontIcon { Glyph = _favorites.IsFavorite(document.Url) ? "\uE735" : "\uE734", FontSize = 14 },
                    VerticalAlignment = VerticalAlignment.Center
                };
                string action = _localization.T(_favorites.IsFavorite(document.Url)
                    ? "DingTalkGroupDocs.Favorite.Remove" : "DingTalkGroupDocs.Favorite.Add");
                ToolTipService.SetToolTip(star, action);
                AutomationProperties.SetName(star, action);
                star.Click += async (_, _) => await ToggleFavoriteAsync(star, document);
                Grid.SetColumn(star, 1);
                row.Children.Add(star);
                documentRows.Children.Add(row);
            }
            card.Child = panel;
            _groups.Children.Add(card);
        }
        if (groups.Length == 0 && _showFavorites)
            _groups.Children.Add(new TextBlock
            {
                Text = _localization.T("DingTalkGroupDocs.Favorite.Empty"),
                Opacity = 0.7,
                Margin = new Thickness(4, 8, 4, 0)
            });
    }

    private double ReadLinkFontSize()
    {
        return _config.Metadata.TryGetValue("DingTalkGroupDocs.LinkFontSize", out string? value) &&
            double.TryParse(value, NumberStyles.Float, CultureInfo.InvariantCulture, out double size)
            ? Math.Clamp(size, 10, 24)
            : 13;
    }

    private Thickness ReadLinkPadding()
    {
        if (_config.Metadata.TryGetValue("DingTalkGroupDocs.LinkPadding", out string? value))
        {
            string[] parts = value.Split(',', StringSplitOptions.TrimEntries);
            if (parts.Length == 4 && parts.All(part => double.TryParse(part, NumberStyles.Float, CultureInfo.InvariantCulture, out _)))
            {
                return new Thickness(
                    double.Parse(parts[0], CultureInfo.InvariantCulture),
                    double.Parse(parts[1], CultureInfo.InvariantCulture),
                    double.Parse(parts[2], CultureInfo.InvariantCulture),
                    double.Parse(parts[3], CultureInfo.InvariantCulture));
            }
        }

        return (Thickness)Application.Current.Resources["WidgetCompactDocumentPadding"];
    }

    private double ReadRowSpacing()
    {
        return _config.Metadata.TryGetValue("DingTalkGroupDocs.RowSpacing", out string? value) &&
            double.TryParse(value, NumberStyles.Float, CultureInfo.InvariantCulture, out double spacing)
            ? Math.Clamp(spacing, 0, 24)
            : (double)Application.Current.Resources["WidgetCompactRowSpacing"];
    }

    private async Task ToggleFavoriteAsync(Button button, DingTalkGroupDocument document)
    {
        button.IsEnabled = false;
        try
        {
            await _favorites.SetFavoriteAsync(document, !_favorites.IsFavorite(document.Url));
            SetStatus(string.Empty);
        }
        catch (Exception ex) when (ex is IOException or InvalidDataException or InvalidOperationException or
            System.Text.Json.JsonException or ArgumentException or OperationCanceledException or
            System.ComponentModel.Win32Exception)
        {
            App.Log($"[DingTalkGroupDocs] Favorite save failed: {ex.GetType().Name}");
            SetStatus(_localization.T("DingTalkGroupDocs.Favorite.Error"));
        }
        finally { button.IsEnabled = true; }
    }

    public void Dispose()
    {
        if (_disposed) return;
        _disposed = true;
        _refresh.Click -= Refresh_Click;
        _search.TextChanged -= Search_TextChanged;
        _service.ProgressChanged -= ProgressChanged;
        _favorites.Changed -= FavoritesChanged;
        if (_settings is not null) _settings.SettingsChanged -= SettingsChanged;
        Loaded -= LoadedHandler;
        Unloaded -= UnloadedHandler;
    }
}

public sealed class DingTalkGroupDocsWidgetContentAdapter : WidgetContentAdapterBase
{
    public DingTalkGroupDocsWidgetContentAdapter(
        WidgetConfig config,
        LocalizationService localization,
        DingTalkGroupDocumentService? service = null,
        DingTalkGroupDocumentFavorites? favorites = null)
        : base(config, () => new DingTalkGroupDocsWidgetContent(localization, config, service, favorites))
    {
    }

    protected override void Dispose(bool disposing)
    {
        if (MaterializedView is DingTalkGroupDocsWidgetContent view) view.Dispose();
    }
}
