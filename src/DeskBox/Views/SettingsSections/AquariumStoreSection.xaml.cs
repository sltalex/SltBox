using System.Globalization;
using DeskBox.Services;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
using Microsoft.UI.Xaml.Media;

namespace DeskBox.Views.SettingsSections;

public sealed partial class AquariumStoreSection : UserControl
{
    private const int TransactionPageSize = 10;
    internal StackPanel ItemsHost => StoreItems;
    internal StackPanel CategoriesHost => StoreCategories;
    internal TextBlock CoinBalanceLabel => CoinBalance;
    internal TextBlock StatusLabel => StoreStatus;

    private IReadOnlyList<AquariumWalletEntry> _wallet = [];
    private IReadOnlyList<AquariumCatalogItem> _catalog = [];
    private IReadOnlyList<FruitCatalogItem> _fruitCatalog = [];
    private Flyout? _transactionsFlyout;
    private ScrollViewer? _transactionsScroll;
    private TextBlock? _transactionPageLabel;
    private Button? _previousPageButton;
    private Button? _nextPageButton;
    private int _transactionPage;

    public AquariumStoreSection()
    {
        InitializeComponent();
    }

    internal void SetWallet(IReadOnlyList<AquariumWalletEntry> wallet,
        IReadOnlyList<AquariumCatalogItem> catalog, IReadOnlyList<FruitCatalogItem> fruitCatalog)
    {
        _wallet = wallet;
        _catalog = catalog;
        _fruitCatalog = fruitCatalog;
        if (_transactionsScroll is not null) RenderTransactionPage(App.Current.LocalizationService);
    }

    private void TransactionsButton_Click(object sender, RoutedEventArgs e)
    {
        _transactionsFlyout?.Hide();
        _transactionPage = 0;
        LocalizationService localization = App.Current.LocalizationService;
        var content = new StackPanel { Spacing = 8, Width = 560 };
        content.Children.Add(new TextBlock
        {
            Text = localization.T("Aquarium.Settings.Transactions"),
            FontSize = 16,
            FontWeight = Microsoft.UI.Text.FontWeights.SemiBold
        });

        if (_wallet.Count == 0)
        {
            content.Children.Add(new TextBlock
            {
                Text = localization.T("Aquarium.Settings.EmptyWallet"),
                Opacity = .72
            });
        }
        else
        {
            _transactionsScroll = new ScrollViewer
            {
                MaxHeight = 420,
                VerticalScrollBarVisibility = ScrollBarVisibility.Auto,
                HorizontalScrollBarVisibility = ScrollBarVisibility.Disabled
            };
            content.Children.Add(_transactionsScroll);
            var pager = new StackPanel
            {
                Orientation = Orientation.Horizontal,
                HorizontalAlignment = HorizontalAlignment.Center,
                Spacing = 10
            };
            _previousPageButton = new Button
            {
                Content = localization.T("Settings.Admin.PreviousPage"), MinWidth = 0, Padding = new Thickness(10, 4, 10, 4)
            };
            _previousPageButton.Click += (_, _) => { _transactionPage--; RenderTransactionPage(localization); };
            _transactionPageLabel = new TextBlock { VerticalAlignment = VerticalAlignment.Center };
            _nextPageButton = new Button
            {
                Content = localization.T("Settings.Admin.NextPage"), MinWidth = 0, Padding = new Thickness(10, 4, 10, 4)
            };
            _nextPageButton.Click += (_, _) => { _transactionPage++; RenderTransactionPage(localization); };
            pager.Children.Add(_previousPageButton);
            pager.Children.Add(_transactionPageLabel);
            pager.Children.Add(_nextPageButton);
            content.Children.Add(pager);
            RenderTransactionPage(localization);
        }

        _transactionsFlyout = new Flyout { Content = content };
        _transactionsFlyout.Closed += (closed, _) =>
        {
            if (!ReferenceEquals(_transactionsFlyout, closed)) return;
            _transactionsFlyout = null;
            _transactionsScroll = null;
            _transactionPageLabel = null;
            _previousPageButton = null;
            _nextPageButton = null;
        };
        _transactionsFlyout.ShowAt(TransactionsButton);
    }

    private void RenderTransactionPage(LocalizationService localization)
    {
        if (_transactionsScroll is null || _transactionPageLabel is null ||
            _previousPageButton is null || _nextPageButton is null) return;
        int pageCount = Math.Max(1, (_wallet.Count + TransactionPageSize - 1) / TransactionPageSize);
        _transactionPage = Math.Clamp(_transactionPage, 0, pageCount - 1);
        var table = new Grid { ColumnSpacing = 12 };
        table.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(138) });
        table.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(1, GridUnitType.Star) });
        table.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(82) });
        table.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(86) });
        table.RowDefinitions.Add(new RowDefinition { Height = GridLength.Auto });
        AddTableHeader(table, localization.T("Aquarium.Settings.Transaction.Time"), 0);
        AddTableHeader(table, localization.T("Aquarium.Settings.Transaction.Description"), 1);
        AddTableHeader(table, localization.T("Aquarium.Settings.Transaction.Change"), 2);
        AddTableHeader(table, localization.T("Aquarium.Settings.Transaction.Balance"), 3);
        int row = 1;
        foreach (AquariumWalletEntry entry in _wallet.Skip(_transactionPage * TransactionPageSize).Take(TransactionPageSize))
        {
            table.RowDefinitions.Add(new RowDefinition { Height = GridLength.Auto });
            AddTableCell(table, FormatTime(entry.CreatedAt), row, 0);
            AddTableCell(table, FormatReason(entry, localization), row, 1);
            TextBlock delta = AddTableCell(table, FormatDelta(entry.Delta), row, 2);
            delta.Foreground = new SolidColorBrush(entry.Delta > 0
                ? Microsoft.UI.Colors.ForestGreen
                : entry.Delta < 0 ? Microsoft.UI.Colors.Firebrick : Microsoft.UI.Colors.Gray);
            AddTableCell(table, entry.Balance.ToString("N0", CultureInfo.CurrentCulture), row, 3);
            row++;
        }
        _transactionsScroll.Content = table;
        _transactionsScroll.ChangeView(null, 0, null);
        _transactionPageLabel.Text = $"{_transactionPage + 1} / {pageCount}";
        _previousPageButton.IsEnabled = _transactionPage > 0;
        _nextPageButton.IsEnabled = _transactionPage + 1 < pageCount;
    }

    private static void AddTableHeader(Grid table, string text, int column)
    {
        TextBlock header = AddTableCell(table, text, 0, column);
        header.FontWeight = Microsoft.UI.Text.FontWeights.SemiBold;
        header.Opacity = .75;
    }

    private static TextBlock AddTableCell(Grid table, string text, int row, int column)
    {
        TextBlock cell = new()
        {
            Text = text,
            FontSize = 12,
            TextWrapping = TextWrapping.Wrap,
            VerticalAlignment = VerticalAlignment.Center,
            Margin = new Thickness(0, 5, 0, 5)
        };
        Grid.SetRow(cell, row);
        Grid.SetColumn(cell, column);
        table.Children.Add(cell);
        return cell;
    }

    private string FormatReason(AquariumWalletEntry entry, LocalizationService localization)
    {
        string key = entry.Reason switch
        {
            "initial_grant" => "Aquarium.Settings.Transaction.InitialGrant",
            "purchase" => "Aquarium.Settings.Transaction.Purchase",
            "pack_open" => "Aquarium.Settings.Transaction.PackOpen",
            "fruit_recycle" or "fruit_atlas_sale" => "Aquarium.Settings.Transaction.FruitSale",
            "admin_adjustment" => "Aquarium.Settings.Transaction.AdminAdjustment",
            "tree_decoration_pack_purchase" => "Aquarium.Settings.Transaction.TreePackPurchase",
            "tree_decoration_pack_open" => "Aquarium.Settings.Transaction.TreePackOpen",
            _ => "Aquarium.Settings.Transaction.Other"
        };
        string label = localization.T(key);
        if (entry.ItemId is not { Length: > 0 } itemId) return label;
        if (entry.Reason is "fruit_recycle" or "fruit_atlas_sale")
        {
            FruitCatalogItem? fruit = _fruitCatalog.FirstOrDefault(candidate => candidate.Id == itemId);
            return fruit is null ? label : $"{label} · {fruit.Name}";
        }
        AquariumCatalogItem? item = _catalog.FirstOrDefault(candidate => candidate.Id == itemId);
        return item is null ? $"{label} ({itemId})" : $"{label} · {item.Name}";
    }

    private static string FormatTime(string value) => DateTimeOffset.TryParse(value,
        CultureInfo.InvariantCulture, DateTimeStyles.AssumeUniversal, out DateTimeOffset parsed)
        ? parsed.ToLocalTime().ToString("yyyy-MM-dd HH:mm", CultureInfo.CurrentCulture)
        : value;

    private static string FormatDelta(long delta) => delta > 0 ? $"+{delta:N0}" : delta.ToString("N0", CultureInfo.CurrentCulture);
}
