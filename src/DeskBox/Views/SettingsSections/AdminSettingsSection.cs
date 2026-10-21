using System.Globalization;
using System.Net.Http;
using System.Text;
using System.Text.Json;
using System.Text.RegularExpressions;
using CommunityToolkit.WinUI.Controls;
using DeskBox.Contracts;
using DeskBox.Services;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
using Microsoft.UI.Xaml.Media;

namespace DeskBox.Views.SettingsSections;

public sealed partial class AdminSettingsSection : UserControl
{
    private const int PageSize = 20;
    private readonly Grid _shell = new();
    private readonly StackPanel _adminMenu = new() { Spacing = 2 };
    private readonly TextBlock _pageTitle = new() { FontSize = 22, FontWeight = Microsoft.UI.Text.FontWeights.SemiBold };
    private readonly TextBlock _pageSubtitle = new() { FontSize = 12, Opacity = .62 };
    private readonly List<(Button Button, string Key, string Section)> _menuItems = [];
    private readonly List<(TextBlock Label, string Key)> _menuHeadings = [];
    private readonly StackPanel _root = new()
    {
        Spacing = 10,
        Margin = new Thickness(20, 16, 24, 26),
        HorizontalAlignment = HorizontalAlignment.Stretch
    };
    private readonly TextBlock _status = new() { TextWrapping = TextWrapping.Wrap };
    private readonly TextBlock _overviewSummary = new() { TextWrapping = TextWrapping.Wrap, Opacity = .72 };
    private readonly Grid _overviewMetrics = new() { ColumnSpacing = 10, RowSpacing = 10 };
    private readonly SettingsCard _overviewCard = new();
    private readonly SettingsCard _recycleCard = new();
    private readonly TextBox _search = new();
    private readonly StackPanel _users = new() { Spacing = 4 };
    private readonly TextBlock _pageLabel = new() { VerticalAlignment = VerticalAlignment.Center, Opacity = .72 };
    private readonly Button _previousPage = new();
    private readonly Button _nextPage = new();
    private readonly TextBox _fruitPriceInput = new() { MaxLength = 10, Width = 120 };
    private IAuthSession? _auth;
    private IAuthenticatedAccountApi? _api;
    private LocalizationService? _localization;
    private string _selectedSection = "account";
    private int _page = 1;
    private int _pageCount = 1;
    private bool _busy;

    public AdminSettingsSection()
    {
        BuildShell();
        _root.Children.Add(_status);

        var overviewContent = new StackPanel { Spacing = 12 };
        overviewContent.Children.Add(_overviewSummary);
        overviewContent.Children.Add(_overviewMetrics);
        var searchRow = new Grid { ColumnSpacing = 8 };
        searchRow.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(1, GridUnitType.Star) });
        searchRow.ColumnDefinitions.Add(new ColumnDefinition { Width = GridLength.Auto });
        _search.HorizontalAlignment = HorizontalAlignment.Stretch;
        searchRow.Children.Add(_search);
        var searchButton = ActionButton("Settings.Admin.Search", async () =>
        {
            _page = 1;
            await ReloadOverviewAsync();
        });
        Grid.SetColumn(searchButton, 1);
        searchRow.Children.Add(searchButton);
        overviewContent.Children.Add(searchRow);
        overviewContent.Children.Add(CreateTableHeader(
            L("Settings.Admin.Table.Username"), L("Settings.Admin.Table.Status"),
            L("Settings.Admin.Table.Created"), L("Settings.Admin.Table.Role"), string.Empty));
        overviewContent.Children.Add(_users);
        var pagination = new StackPanel { Orientation = Orientation.Horizontal, Spacing = 8, HorizontalAlignment = HorizontalAlignment.Right };
        _previousPage.Content = "‹";
        _nextPage.Content = "›";
        _previousPage.Click += async (_, _) =>
        {
            if (_page <= 1) return;
            _page--;
            await LoadUsersAsync();
        };
        _nextPage.Click += async (_, _) =>
        {
            if (_page >= _pageCount) return;
            _page++;
            await LoadUsersAsync();
        };
        pagination.Children.Add(_previousPage);
        pagination.Children.Add(_pageLabel);
        pagination.Children.Add(_nextPage);
        overviewContent.Children.Add(pagination);
        ConfigureCard(_overviewCard, "Settings.Admin.Overview", overviewContent);
        _root.Children.Add(_overviewCard);

        var recyclePanel = new StackPanel { Spacing = 8 };
        recyclePanel.Children.Add(new TextBlock { Text = "每个水果回收获得的金币", Opacity = .7 });
        var recycleRow = new StackPanel { Orientation = Orientation.Horizontal, Spacing = 8 };
        recycleRow.Children.Add(_fruitPriceInput);
        recycleRow.Children.Add(ActionButton("Settings.Admin.SaveFruitPrice", SaveFruitPriceAsync));
        recyclePanel.Children.Add(recycleRow);
        ConfigureCard(_recycleCard, "Settings.Admin.RecycleFruit", recyclePanel);
        _root.Children.Add(_recycleCard);
        SelectAdminSection("account");
    }

    private void BuildShell()
    {
        Content = _shell;
        _shell.RowDefinitions.Add(new RowDefinition { Height = new GridLength(66) });
        _shell.RowDefinitions.Add(new RowDefinition { Height = new GridLength(1, GridUnitType.Star) });

        var header = new Grid { Padding = new Thickness(22, 0, 24, 0), ColumnSpacing = 16 };
        header.ColumnDefinitions.Add(new ColumnDefinition());
        header.ColumnDefinitions.Add(new ColumnDefinition { Width = GridLength.Auto });
        var identity = new StackPanel { VerticalAlignment = VerticalAlignment.Center, Spacing = 2 };
        identity.Children.Add(_pageTitle);
        identity.Children.Add(_pageSubtitle);
        header.Children.Add(identity);
        var badge = new Border
        {
            Padding = new Thickness(12, 6, 12, 6),
            CornerRadius = new CornerRadius(6),
            VerticalAlignment = VerticalAlignment.Center,
            BorderThickness = new Thickness(1),
            BorderBrush = new SolidColorBrush(Microsoft.UI.Colors.LightGray),
            Child = new TextBlock { Text = "ADMIN", FontSize = 11, FontWeight = Microsoft.UI.Text.FontWeights.SemiBold, Opacity = .75 }
        };
        Grid.SetColumn(badge, 1);
        header.Children.Add(badge);
        _shell.Children.Add(new Border { Child = header, BorderThickness = new Thickness(0, 0, 0, 1), BorderBrush = new SolidColorBrush(Microsoft.UI.Colors.LightGray) });

        var body = new Grid { ColumnSpacing = 0 };
        body.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(190) });
        body.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(1, GridUnitType.Star) });
        var sidebar = new Border
        {
            Padding = new Thickness(12, 18, 12, 14),
            BorderThickness = new Thickness(0, 0, 1, 0),
            BorderBrush = new SolidColorBrush(Microsoft.UI.Colors.LightGray),
            Child = new ScrollViewer
            {
                Content = _adminMenu,
                VerticalScrollBarVisibility = ScrollBarVisibility.Auto,
                HorizontalScrollBarVisibility = ScrollBarVisibility.Disabled
            }
        };
        body.Children.Add(sidebar);
        var content = new ScrollViewer
        {
            Content = _root,
            HorizontalScrollBarVisibility = ScrollBarVisibility.Disabled,
            VerticalScrollBarVisibility = ScrollBarVisibility.Auto,
            HorizontalContentAlignment = HorizontalAlignment.Stretch
        };
        Grid.SetColumn(content, 1);
        body.Children.Add(content);
        Grid.SetRow(body, 1);
        _shell.Children.Add(body);
        BuildAdminMenu();
    }

    private void BuildAdminMenu()
    {
        AddMenuHeading("Settings.Admin.AccountManagement");
        AddMenuItem("Settings.Admin.Menu.Overview", "account", "\uE80F");
        AddMenuHeading("Settings.Admin.RecycleFruit");
        AddMenuItem("Settings.Admin.Menu.Recycle", "recycle", "\uE74D");
    }

    private void AddMenuHeading(string key)
    {
        var label = new TextBlock
        {
            Text = L(key), FontSize = 11, FontWeight = Microsoft.UI.Text.FontWeights.SemiBold,
            Opacity = .56, Margin = new Thickness(10, 14, 4, 4)
        };
        _menuHeadings.Add((label, key));
        _adminMenu.Children.Add(label);
    }

    private void AddMenuItem(string key, string section, string glyph)
    {
        var icon = new FontIcon { Glyph = glyph, FontSize = 16, Opacity = .78 };
        var button = new Button
        {
            HorizontalAlignment = HorizontalAlignment.Stretch,
            HorizontalContentAlignment = HorizontalAlignment.Left,
            Padding = new Thickness(10, 8, 10, 8),
            MinHeight = 38,
            Content = new StackPanel { Orientation = Orientation.Horizontal, Spacing = 10, Children = { icon, new TextBlock { Text = L(key), VerticalAlignment = VerticalAlignment.Center } } }
        };
        button.Click += (_, _) => SelectAdminSection(section);
        _menuItems.Add((button, key, section));
        _adminMenu.Children.Add(button);
    }

    private void SelectAdminSection(string section)
    {
        _selectedSection = section;
        _overviewCard.Visibility = section == "account" ? Visibility.Visible : Visibility.Collapsed;
        _recycleCard.Visibility = section == "recycle" ? Visibility.Visible : Visibility.Collapsed;
        _pageTitle.Text = L(section == "recycle" ? "Settings.Admin.RecycleFruit" : "Settings.Admin.AccountManagement");
        _pageSubtitle.Text = L("Settings.Admin.WorkspaceSubtitle");
        foreach ((Button button, _, string itemSection) in _menuItems)
            button.Background = new SolidColorBrush(itemSection == section ? Microsoft.UI.Colors.LightSteelBlue : Microsoft.UI.Colors.Transparent);
    }

    public void Initialize(IAuthSession auth, LocalizationService localization)
    {
        _auth = auth;
        _api = auth as IAuthenticatedAccountApi;
        _localization = localization;
        RefreshLocalizedContent();
    }

    public void RefreshLocalizedContent()
    {
        _overviewCard.Header = L("Settings.Admin.Overview");
        _recycleCard.Header = L("Settings.Admin.RecycleFruit");
        _pageSubtitle.Text = L("Settings.Admin.WorkspaceSubtitle");
        _search.PlaceholderText = L("Settings.Admin.SearchHint");
        _previousPage.Content = L("Settings.Admin.PreviousPage");
        _nextPage.Content = L("Settings.Admin.NextPage");
        foreach ((Button button, string key, _) in _menuItems)
        {
            if (button.Content is StackPanel panel && panel.Children.LastOrDefault() is TextBlock label)
                label.Text = L(key);
        }
        foreach ((TextBlock label, string key) in _menuHeadings)
            label.Text = L(key);
        SelectAdminSection(_selectedSection);
    }

    public void ResetForSession()
    {
        _page = 1;
        _pageCount = 1;
        _search.Text = string.Empty;
        _fruitPriceInput.Text = string.Empty;
        _users.Children.Clear();
        _overviewMetrics.Children.Clear();
        _overviewSummary.Text = string.Empty;
        _status.Text = string.Empty;
        SelectAdminSection("account");
    }

    public async Task RefreshAsync()
    {
        if (_busy || _auth?.CurrentUser?.Role != "admin" || _api is null) return;
        _busy = true;
        try
        {
            await LoadOverviewAsync();
            await LoadFruitPriceAsync();
            _status.Text = string.Empty;
        }
        catch (Exception error) { _status.Text = error.Message; }
        finally { _busy = false; }
    }

    private async Task ReloadOverviewAsync()
    {
        if (_busy) return;
        _busy = true;
        try { await LoadOverviewAsync(); }
        catch (Exception error) { _status.Text = error.Message; }
        finally { _busy = false; }
    }

    private async Task LoadOverviewAsync()
    {
        using JsonDocument overview = await GetAsync("overview");
        JsonElement metrics = overview.RootElement.GetProperty("overview");
        _overviewSummary.Text = string.Format(CultureInfo.CurrentCulture, L("Settings.Admin.OverviewSummary"),
            metrics.GetProperty("users").GetInt32(), metrics.GetProperty("active_users").GetInt32());
        RenderOverviewMetrics(metrics);
        await LoadUsersCoreAsync();
    }

    private async Task LoadFruitPriceAsync()
    {
        using JsonDocument result = await GetAsync("fruit_recycle");
        _fruitPriceInput.Text = result.RootElement.GetProperty("price_per_fruit").GetInt32().ToString(CultureInfo.InvariantCulture);
    }

    private async Task SaveFruitPriceAsync()
    {
        if (_api is null || _auth?.CurrentUser is not { Role: "admin" } admin) return;
        if (!int.TryParse(_fruitPriceInput.Text.Trim(), NumberStyles.Integer, CultureInfo.InvariantCulture, out int price) || price < 0)
        {
            _status.Text = L("Settings.Admin.InvalidNumber");
            return;
        }
        _busy = true;
        try
        {
            await SendMutationAsync(new AdminMutationRequest
            {
                Action = "set_fruit_recycle_price",
                TargetId = admin.Id,
                PricePerFruit = price
            });
            _status.Text = L("Settings.Admin.Success");
            await LoadFruitPriceAsync();
        }
        catch (Exception error) { _status.Text = error.Message; }
        finally { _busy = false; }
    }

    private async Task LoadUsersAsync()
    {
        if (_api is null || _busy) return;
        _busy = true;
        try { await LoadUsersCoreAsync(); }
        catch (Exception error) { _status.Text = error.Message; }
        finally { _busy = false; }
    }

    private async Task LoadUsersCoreAsync()
    {
        if (_api is null) return;
        using JsonDocument result = await GetAsync("users", "&q=" + Uri.EscapeDataString(_search.Text.Trim())
            + $"&page={_page}&page_size={PageSize}");
        JsonElement root = result.RootElement;
        _page = Math.Max(1, root.GetProperty("page").GetInt32());
        _pageCount = Math.Max(1, root.GetProperty("page_count").GetInt32());
        _users.Children.Clear();
        foreach (JsonElement user in root.GetProperty("users").EnumerateArray())
            _users.Children.Add(CreateUserRow(user));
        if (_users.Children.Count == 0) _users.Children.Add(EmptyState(L("Settings.Admin.NoUsers")));
        _pageLabel.Text = string.Format(CultureInfo.CurrentCulture, L("Settings.Admin.Page"), _page, _pageCount);
        _previousPage.IsEnabled = _page > 1;
        _nextPage.IsEnabled = _page < _pageCount;
        _status.Text = string.Empty;
    }

    private async Task<JsonDocument> GetAsync(string view, string extra = "")
    {
        using HttpResponseMessage response = await _api!.SendAsync(HttpMethod.Get,
            "../admin/index.php?view=" + view + extra, null, null);
        await EnsureSuccessAsync(response);
        return await JsonDocument.ParseAsync(await response.Content.ReadAsStreamAsync());
    }

    private async Task ShowEditDialogAsync(string id, string username, string status, bool isAdmin)
    {
        if (XamlRoot is null) return;
        var usernameBox = new TextBox { Text = username, MaxLength = 32, Header = L("Settings.Admin.EditUsername") };
        var statusBox = new ComboBox { Header = L("Settings.Admin.EditStatus"), HorizontalAlignment = HorizontalAlignment.Stretch };
        statusBox.Items.Add(new ComboBoxItem { Content = L("Settings.Admin.Status.Active"), Tag = "active" });
        statusBox.Items.Add(new ComboBoxItem { Content = L("Settings.Admin.Status.Disabled"), Tag = "disabled", IsEnabled = !isAdmin });
        statusBox.SelectedIndex = status == "disabled" ? 1 : 0;
        var dialog = new ContentDialog
        {
            XamlRoot = XamlRoot,
            Title = L("Settings.Admin.EditTitle"),
            Content = new StackPanel { Spacing = 12, Children = { usernameBox, statusBox } },
            PrimaryButtonText = L("Settings.Admin.Save"),
            CloseButtonText = L("Settings.Admin.Cancel"),
            DefaultButton = ContentDialogButton.Primary
        };
        if (await dialog.ShowAsync() != ContentDialogResult.Primary) return;
        string nextUsername = usernameBox.Text.Trim();
        string nextStatus = (statusBox.SelectedItem as ComboBoxItem)?.Tag?.ToString() ?? "active";
        if (!Regex.IsMatch(nextUsername, "\\A[A-Za-z0-9_]{3,32}\\z"))
        {
            _status.Text = L("Settings.Admin.InvalidUsername");
            return;
        }
        _busy = true;
        try
        {
            await SendMutationAsync(new AdminMutationRequest
            {
                Action = "update_user",
                TargetId = id,
                Username = nextUsername,
                Status = nextStatus
            });
            _status.Text = L("Settings.Admin.Success");
            await LoadUsersCoreAsync();
        }
        catch (Exception error) { _status.Text = error.Message; }
        finally { _busy = false; }
    }

    private async Task SendMutationAsync(AdminMutationRequest request)
    {
        using HttpResponseMessage response = await _api!.SendAsync(HttpMethod.Post, "../admin/index.php", WriteMutation(request), null);
        await EnsureSuccessAsync(response);
    }

    private static async Task EnsureSuccessAsync(HttpResponseMessage response)
    {
        if (response.IsSuccessStatusCode) return;
        string body = await response.Content.ReadAsStringAsync();
        throw new InvalidOperationException($"HTTP {(int)response.StatusCode}: {body}");
    }

    private Grid CreateUserRow(JsonElement user)
    {
        string id = user.GetProperty("id").GetString()!;
        string username = user.GetProperty("username").GetString()!;
        string status = user.GetProperty("status").GetString()!;
        string created = user.GetProperty("created_at").GetString() ?? string.Empty;
        bool isAdmin = user.GetProperty("is_admin").GetInt32() == 1;
        Button edit = ActionButton("Settings.Admin.Edit", async () => await ShowEditDialogAsync(id, username, status, isAdmin));
        return CreateTableRow(username, StatusText(status), created,
            isAdmin ? L("Settings.Admin.Role.Admin") : L("Settings.Admin.Role.User"), edit);
    }

    private string StatusText(string status) => status == "disabled"
        ? L("Settings.Admin.Status.Disabled") : L("Settings.Admin.Status.Active");

    private void RenderOverviewMetrics(JsonElement metrics)
    {
        _overviewMetrics.Children.Clear();
        _overviewMetrics.ColumnDefinitions.Clear();
        _overviewMetrics.RowDefinitions.Clear();
        string[] labels = ["Users", "Active", "Sessions", "Backups", "Backup size", "Tree participants", "DingTalk"];
        string[] values = [
            metrics.GetProperty("users").GetInt32().ToString(CultureInfo.CurrentCulture),
            metrics.GetProperty("active_users").GetInt32().ToString(CultureInfo.CurrentCulture),
            metrics.GetProperty("sessions").GetInt32().ToString(CultureInfo.CurrentCulture),
            metrics.GetProperty("backups").GetInt32().ToString(CultureInfo.CurrentCulture),
            $"{metrics.GetProperty("backup_bytes").GetInt64():N0} B",
            metrics.GetProperty("tree_participants").GetInt32().ToString(CultureInfo.CurrentCulture),
            metrics.GetProperty("dingtalk_links").GetInt32().ToString(CultureInfo.CurrentCulture)
        ];
        const int columns = 2;
        for (int column = 0; column < columns; column++)
            _overviewMetrics.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(1, GridUnitType.Star) });
        for (int row = 0; row < (labels.Length + columns - 1) / columns; row++)
            _overviewMetrics.RowDefinitions.Add(new RowDefinition { Height = GridLength.Auto });
        for (int index = 0; index < labels.Length; index++)
        {
            var metric = new Border
            {
                BorderBrush = new SolidColorBrush(Microsoft.UI.Colors.LightGray),
                BorderThickness = new Thickness(1),
                CornerRadius = new CornerRadius(8),
                Padding = new Thickness(10, 8, 10, 8),
                Child = new StackPanel { Spacing = 2, Children =
                {
                    new TextBlock { Text = labels[index], FontSize = 12, Opacity = .68, TextWrapping = TextWrapping.Wrap },
                    new TextBlock { Text = values[index], FontSize = 18, FontWeight = Microsoft.UI.Text.FontWeights.SemiBold, TextTrimming = TextTrimming.CharacterEllipsis }
                } }
            };
            Grid.SetColumn(metric, index % columns);
            Grid.SetRow(metric, index / columns);
            _overviewMetrics.Children.Add(metric);
        }
    }

    private Button ActionButton(string key, Func<Task> action)
    {
        var button = new Button { Content = L(key), HorizontalAlignment = HorizontalAlignment.Left };
        Localized.SetKey(button, key);
        button.Click += async (_, _) => await action();
        return button;
    }

    private static Grid CreateTableHeader(params string[] keys)
    {
        var grid = new Grid { Padding = new Thickness(8, 4, 8, 4), ColumnSpacing = 10, Opacity = .68 };
        for (int index = 0; index < keys.Length; index++)
        {
            grid.ColumnDefinitions.Add(new ColumnDefinition { Width = index == keys.Length - 1 ? GridLength.Auto : new GridLength(1, GridUnitType.Star) });
            var label = new TextBlock { Text = keys[index], FontWeight = Microsoft.UI.Text.FontWeights.SemiBold, TextTrimming = TextTrimming.CharacterEllipsis };
            Grid.SetColumn(label, index);
            grid.Children.Add(label);
        }
        return grid;
    }

    private static Grid CreateTableRow(params object?[] values)
    {
        var grid = new Grid { Padding = new Thickness(8, 6, 8, 6), ColumnSpacing = 10 };
        for (int index = 0; index < values.Length; index++)
        {
            bool last = index == values.Length - 1;
            grid.ColumnDefinitions.Add(new ColumnDefinition { Width = last ? GridLength.Auto : new GridLength(1, GridUnitType.Star) });
            FrameworkElement element = values[index] switch
            {
                FrameworkElement ui => ui,
                _ => new TextBlock { Text = values[index]?.ToString() ?? string.Empty, TextTrimming = TextTrimming.CharacterEllipsis, VerticalAlignment = VerticalAlignment.Center }
            };
            Grid.SetColumn(element, index);
            grid.Children.Add(element);
        }
        return grid;
    }

    private void ConfigureCard(SettingsCard card, string titleKey, UIElement content)
    {
        card.Header = L(titleKey);
        card.HorizontalContentAlignment = HorizontalAlignment.Stretch;
        card.Content = content;
    }

    private static TextBlock EmptyState(string text) => new() { Text = text, Opacity = .62, Margin = new Thickness(8, 4, 8, 4) };

    private string L(string key) => _localization?.T(key) ?? key;

    private static byte[] WriteMutation(AdminMutationRequest request)
    {
        using var stream = new MemoryStream();
        using (var writer = new Utf8JsonWriter(stream))
        {
            writer.WriteStartObject();
            writer.WriteString("action", request.Action);
            writer.WriteString("target_id", request.TargetId);
            if (!string.IsNullOrWhiteSpace(request.Reason)) writer.WriteString("reason", request.Reason);
            if (request.Username is not null) writer.WriteString("username", request.Username);
            if (request.Status is not null) writer.WriteString("status", request.Status);
            if (request.PricePerFruit is int price) writer.WriteNumber("price_per_fruit", price);
            writer.WriteEndObject();
        }
        return stream.ToArray();
    }
}
