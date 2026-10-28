using DeskBox.Services;
using DeskBox.Models;
using CommunityToolkit.WinUI.Controls;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
using Microsoft.UI.Xaml.Media;
using System.Globalization;

namespace DeskBox.Views.SettingsSections;

/// <summary>Compact settings surface for the DingTalk group-document index.</summary>
public sealed partial class DingTalkGroupDocsSettingsSection : UserControl
{
    private readonly SettingsCard _card = new() { HorizontalContentAlignment = HorizontalAlignment.Stretch };
    private readonly Button _syncButton = new();
    private readonly ProgressBar _progress = new() { Minimum = 0, Maximum = 1, Height = 6, Visibility = Visibility.Collapsed };
    private readonly TextBlock _status = new() { TextWrapping = TextWrapping.Wrap };
    private readonly TextBlock _hint = new() { TextWrapping = TextWrapping.Wrap, Opacity = 0.72, Visibility = Visibility.Collapsed };
    private readonly TextBlock _intervalLabel = new() { VerticalAlignment = VerticalAlignment.Center };
    private readonly NumberBox _interval = new() { Minimum = 1, Maximum = 168, SmallChange = 1, SpinButtonPlacementMode = NumberBoxSpinButtonPlacementMode.Compact };
    private readonly TextBlock _fontSizeLabel = new() { VerticalAlignment = VerticalAlignment.Center };
    private readonly NumberBox _fontSize = new() { Minimum = 10, Maximum = 24, SmallChange = 0.5, SpinButtonPlacementMode = NumberBoxSpinButtonPlacementMode.Compact };
    private readonly TextBlock _paddingLabel = new() { VerticalAlignment = VerticalAlignment.Center };
    private readonly NumberBox _padding = new() { Minimum = 0, Maximum = 24, SmallChange = 1, SpinButtonPlacementMode = NumberBoxSpinButtonPlacementMode.Compact };
    private readonly TextBlock _rowSpacingLabel = new() { VerticalAlignment = VerticalAlignment.Center };
    private readonly NumberBox _rowSpacing = new() { Minimum = 0, Maximum = 24, SmallChange = 1, SpinButtonPlacementMode = NumberBoxSpinButtonPlacementMode.Compact };
    private readonly TextBlock _pageSizeLabel = new() { VerticalAlignment = VerticalAlignment.Center };
    private readonly NumberBox _pageSize = new()
    {
        Minimum = DingTalkGroupDocsPresentationSettings.MinPageSize,
        Maximum = DingTalkGroupDocsPresentationSettings.MaxPageSize,
        SmallChange = 1,
        SpinButtonPlacementMode = NumberBoxSpinButtonPlacementMode.Compact
    };
    private DingTalkGroupDocumentSyncService? _sync;
    private LocalizationService? _localization;
    private SettingsService? _settings;
    private WidgetConfig? _widget;
    private bool _updating;

    public DingTalkGroupDocsSettingsSection()
    {
        _card.Padding = (Thickness)Application.Current.Resources["WidgetCompactCardPadding"];
        _syncButton.Click += SyncButton_Click;
        _interval.ValueChanged += Interval_ValueChanged;
        _fontSize.ValueChanged += LinkStyle_ValueChanged;
        _padding.ValueChanged += LinkStyle_ValueChanged;
        _rowSpacing.ValueChanged += LinkStyle_ValueChanged;
        _pageSize.ValueChanged += PageSize_ValueChanged;

        var intervalRow = new Grid { ColumnSpacing = 8 };
        intervalRow.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(1, GridUnitType.Star) });
        intervalRow.ColumnDefinitions.Add(new ColumnDefinition { Width = GridLength.Auto });
        intervalRow.ColumnDefinitions.Add(new ColumnDefinition { Width = GridLength.Auto });
        intervalRow.Children.Add(_intervalLabel);
        Grid.SetColumn(_interval, 1);
        intervalRow.Children.Add(_interval);
        Grid.SetColumn(_syncButton, 2);
        intervalRow.Children.Add(_syncButton);

        var panel = new StackPanel { Spacing = (double)Application.Current.Resources["SettingsCardSpacing"] };
        panel.Children.Add(intervalRow);
        panel.Children.Add(CreateStyleRow(_fontSizeLabel, _fontSize));
        panel.Children.Add(CreateStyleRow(_paddingLabel, _padding));
        panel.Children.Add(CreateStyleRow(_rowSpacingLabel, _rowSpacing));
        panel.Children.Add(CreateStyleRow(_pageSizeLabel, _pageSize));
        panel.Children.Add(_status);
        panel.Children.Add(_hint);
        panel.Children.Add(_progress);
        _card.Content = panel;
        Content = new StackPanel
        {
            Spacing = (double)Application.Current.Resources["SettingsCardSpacing"],
            Margin = new Thickness(24)
        };
        ((StackPanel)Content).Children.Add(_card);
        Loaded += OnLoaded;
        Unloaded += OnUnloaded;
    }

    internal void Configure(LocalizationService localization, DingTalkGroupDocumentSyncService sync, SettingsService settings)
    {
        _localization = localization;
        _sync = sync;
        _settings = settings;
        _widget = settings.Settings.WidgetLayout.Widgets.FirstOrDefault(item => item.WidgetKind == WidgetKind.DingTalkGroupDocs);
        RenderLabels();
        RefreshState();
    }

    private void OnLoaded(object sender, RoutedEventArgs e)
    {
        if (_sync is not null) _sync.ProgressChanged += OnProgressChanged;
        if (_localization is not null) _localization.LanguageChanged += OnLanguageChanged;
        RenderLabels();
        RefreshState();
    }

    private void OnUnloaded(object sender, RoutedEventArgs e)
    {
        if (_sync is not null) _sync.ProgressChanged -= OnProgressChanged;
        if (_localization is not null) _localization.LanguageChanged -= OnLanguageChanged;
    }

    private async void SyncButton_Click(object sender, RoutedEventArgs e)
    {
        if (_sync is null) return;
        _syncButton.IsEnabled = false;
        string? resultKey = null;
        try
        {
            bool started = await _sync.SyncNowAsync();
            if (!started) resultKey = "DingTalkGroupDocs.Sync.NotReady";
        }
        catch (Exception ex) when (ex is DingTalkDwsAuthorizationRequiredException or DingTalkDwsMessageReadException or
            HttpRequestException or InvalidDataException or IOException or AuthApiException or TimeoutException or
            FileNotFoundException or System.ComponentModel.Win32Exception or OperationCanceledException)
        {
            App.Log($"[DingTalkGroupDocs] Manual sync failed: {ex.GetType().Name}");
            resultKey = "DingTalkGroupDocs.Sync.Error";
        }
        finally
        {
            _syncButton.IsEnabled = true;
            RefreshState();
            if (resultKey is not null) _status.Text = _localization?.T(resultKey) ?? string.Empty;
        }
    }

    private async void Interval_ValueChanged(NumberBox sender, NumberBoxValueChangedEventArgs args)
    {
        if (_updating || _sync is null || double.IsNaN(args.NewValue)) return;
        try
        {
            await _sync.SetIntervalHoursAsync((int)Math.Round(args.NewValue));
            RefreshState();
        }
        catch (IOException ex)
        {
            App.Log($"[DingTalkGroupDocs] Sync interval save failed: {ex.GetType().Name}");
            _status.Text = _localization?.T("DingTalkGroupDocs.Sync.Error") ?? string.Empty;
        }
    }

    private void LinkStyle_ValueChanged(NumberBox sender, NumberBoxValueChangedEventArgs args)
    {
        if (_updating || _settings is null || _widget is null || double.IsNaN(args.NewValue)) return;
        _widget.Metadata["DingTalkGroupDocs.LinkFontSize"] =
            Math.Clamp(_fontSize.Value, 10, 24).ToString(CultureInfo.InvariantCulture);
        double padding = Math.Clamp(_padding.Value, 0, 24);
        _widget.Metadata["DingTalkGroupDocs.LinkPadding"] =
            string.Join(",", Enumerable.Repeat(padding.ToString(CultureInfo.InvariantCulture), 4));
        _widget.Metadata["DingTalkGroupDocs.RowSpacing"] =
            Math.Clamp(_rowSpacing.Value, 0, 24).ToString(CultureInfo.InvariantCulture);
        _settings.SaveDebounced();
    }

    private void PageSize_ValueChanged(NumberBox sender, NumberBoxValueChangedEventArgs args)
    {
        if (_updating || _settings is null || _widget is null || double.IsNaN(args.NewValue)) return;
        int pageSize = DingTalkGroupDocsPresentationSettings.NormalizePageSize(args.NewValue);
        if (Math.Abs(args.NewValue - pageSize) > double.Epsilon)
        {
            _updating = true;
            try { _pageSize.Value = pageSize; }
            finally { _updating = false; }
        }
        DingTalkGroupDocsPresentationSettings.WritePageSize(_widget, pageSize);
        _settings.SaveDebounced();
    }

    private void OnProgressChanged(DingTalkGroupDocumentSyncProgress progress)
    {
        DispatcherQueue.TryEnqueue(() =>
        {
            if (_localization is null) return;
            _syncButton.IsEnabled = !progress.IsRunning;
            if (!progress.IsRunning)
            {
                _progress.IsIndeterminate = false;
                _progress.Visibility = Visibility.Collapsed;
                RefreshState();
                return;
            }
            _progress.Visibility = Visibility.Visible;
            _progress.IsIndeterminate = progress.TotalSegments <= 0;
            _progress.Value = progress.EstimatedCompletion;
            _status.Text = progress.FormatDetails(_localization);
            _hint.Text = progress.IsInitialSync
                ? _localization.T("DingTalkGroupDocs.Sync.InitialHint")
                : string.Empty;
            _hint.Visibility = progress.IsInitialSync ? Visibility.Visible : Visibility.Collapsed;
        });
    }

    private void OnLanguageChanged()
    {
        RenderLabels();
        RefreshState();
    }

    private void RenderLabels()
    {
        if (_localization is null) return;
        _card.Header = _localization.T("DingTalkGroupDocs.Title");
        _card.Description = _localization.T("DingTalkGroupDocs.Settings.Description");
        _syncButton.Content = _localization.T("DingTalkGroupDocs.Sync.Button");
        _intervalLabel.Text = _localization.T("DingTalkGroupDocs.Sync.Interval");
        _fontSizeLabel.Text = _localization.T("DingTalkGroupDocs.LinkFontSize");
        _paddingLabel.Text = _localization.T("DingTalkGroupDocs.LinkPadding");
        _rowSpacingLabel.Text = _localization.T("DingTalkGroupDocs.RowSpacing");
        _pageSizeLabel.Text = _localization.T("DingTalkGroupDocs.PageSize");
    }

    private void RefreshState()
    {
        if (_sync is null) return;
        _updating = true;
        try
        {
            _interval.Value = _sync.Documents.SyncIntervalHours;
            _fontSize.Value = ReadFontSize();
            _padding.Value = ReadPadding();
            _rowSpacing.Value = ReadRowSpacing();
            _pageSize.Value = DingTalkGroupDocsPresentationSettings.ReadPageSize(_widget);
            _pageSize.IsEnabled = _widget is not null;
            _syncButton.IsEnabled = !_sync.Documents.IsSyncing;
            _progress.Visibility = _sync.Documents.IsSyncing ? Visibility.Visible : Visibility.Collapsed;
            if (_sync.Documents.LastSyncCompletedAt is null && _localization is not null)
            {
                _status.Text = _localization.T("DingTalkGroupDocs.Sync.Ready");
                _hint.Text = _localization.T("DingTalkGroupDocs.Sync.InitialHint");
                _hint.Visibility = Visibility.Visible;
            }
            if (_sync.Documents.LastSyncCompletedAt is { } last)
            {
                _status.Text = _localization?.Format("DingTalkGroupDocs.Sync.Last", last.ToLocalTime()) ?? string.Empty;
                _hint.Visibility = Visibility.Collapsed;
            }
        }
        finally { _updating = false; }
    }

    private double ReadFontSize()
    {
        return _widget?.Metadata.TryGetValue("DingTalkGroupDocs.LinkFontSize", out string? value) == true &&
            double.TryParse(value, NumberStyles.Float, CultureInfo.InvariantCulture, out double size)
            ? Math.Clamp(size, 10, 24)
            : 13;
    }

    private double ReadPadding()
    {
        if (_widget?.Metadata.TryGetValue("DingTalkGroupDocs.LinkPadding", out string? value) == true)
        {
            string first = value.Split(',', StringSplitOptions.TrimEntries).FirstOrDefault() ?? string.Empty;
            if (double.TryParse(first, NumberStyles.Float, CultureInfo.InvariantCulture, out double padding))
            {
                return Math.Clamp(padding, 0, 24);
            }
        }

        return 2;
    }

    private double ReadRowSpacing()
    {
        return _widget?.Metadata.TryGetValue("DingTalkGroupDocs.RowSpacing", out string? value) == true &&
            double.TryParse(value, NumberStyles.Float, CultureInfo.InvariantCulture, out double spacing)
            ? Math.Clamp(spacing, 0, 24)
            : (double)Application.Current.Resources["WidgetCompactRowSpacing"];
    }

    private static Grid CreateStyleRow(TextBlock label, NumberBox value)
    {
        var row = new Grid { ColumnSpacing = 8 };
        row.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(1, GridUnitType.Star) });
        row.ColumnDefinitions.Add(new ColumnDefinition { Width = GridLength.Auto });
        row.Children.Add(label);
        Grid.SetColumn(value, 1);
        row.Children.Add(value);
        return row;
    }
}
