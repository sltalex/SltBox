using System.Globalization;
using System.Net.Http;
using System.Text.Json;
using CommunityToolkit.WinUI.Controls;
using DeskBox.Contracts;
using DeskBox.Services;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
using Microsoft.UI.Xaml.Media;

namespace DeskBox.Views.SettingsSections;

public sealed partial class AdminSettingsSection : UserControl
{
    private readonly Grid _shell = new();
    private readonly StackPanel _adminMenu = new() { Spacing = 2 };
    private readonly TextBlock _pageTitle = new() { FontSize = 22, FontWeight = Microsoft.UI.Text.FontWeights.SemiBold };
    private readonly TextBlock _pageSubtitle = new() { FontSize = 12, Opacity = .62 };
    private readonly List<(Button Button, string Key, string Section)> _menuItems = [];
    private readonly List<(TextBlock Label, string Key)> _menuHeadings = [];
    private string _selectedSection = "account";
    private readonly StackPanel _root = new()
    {
        Spacing = 10,
        Margin = new Thickness(20, 16, 24, 26),
        HorizontalAlignment = HorizontalAlignment.Stretch,
        MaxWidth = double.PositiveInfinity
    };
    private readonly TextBlock _status = new() { TextWrapping = TextWrapping.Wrap };
    private readonly TextBlock _overview = new() { TextWrapping = TextWrapping.Wrap, Opacity = .72 };
    private readonly Grid _overviewMetrics = new() { ColumnSpacing = 10, RowSpacing = 10 };
    private readonly SettingsCard _overviewCard = new();
    private readonly SettingsCard _usersCard = new();
    private readonly SettingsCard _detailCard = new() { Visibility = Visibility.Collapsed };
    private readonly SettingsCard _auditCard = new();
    private readonly TextBox _search = new() { Width = 220 };
    private readonly StackPanel _users = new() { Spacing = 5 };
    private readonly Grid _detail = new() { RowSpacing = 10 };
    private readonly StackPanel _audit = new() { Spacing = 5 };
    private readonly TextBox _reason = new() { MaxLength = 250 };
    private readonly TextBox _confirm = new();
    private readonly PasswordBox _password = new();
    private readonly TextBox[] _treeInputs = [new(), new(), new(), new(), new()];
    private readonly TextBox _coinInput = new() { MaxLength = 10 };
    private readonly TextBox _fruitPriceInput = new() { MaxLength = 10, Width = 120 };
    private readonly TextBox _fruitPriceReason = new() { MaxLength = 250, Width = 240 };
    private readonly SettingsCard _fruitRecycleCard = new();
    private IAuthSession? _auth;
    private IAuthenticatedAccountApi? _api;
    private LocalizationService? _localization;
    private string? _targetId;
    private string? _targetName;
    private int _treeVersion;
    private string _treeDay = string.Empty;
    private bool _busy;

    public AdminSettingsSection()
    {
        BuildShell();
        _root.Children.Add(_status);
        ConfigureCard(_overviewCard, "Settings.Admin.Overview", new StackPanel { Spacing = 12, Children = { _overview, _overviewMetrics } });
        _root.Children.Add(_overviewCard);

        var searchRow = new StackPanel { Orientation = Orientation.Horizontal, Spacing = 8 };
        searchRow.Children.Add(_search);
        searchRow.Children.Add(ActionButton("Settings.Admin.Search", async () => await LoadUsersAsync()));
        ConfigureCard(_usersCard, "Settings.Admin.User", new StackPanel { Spacing = 10, Children = { searchRow, CreateTableHeader(L("Settings.Admin.User"), "Status", "Role", string.Empty), _users } });
        _root.Children.Add(_usersCard);
        ConfigureCard(_detailCard, "Settings.Admin.User", _detail);
        _root.Children.Add(_detailCard);
        var recyclePanel = new StackPanel { Spacing = 6 };
        recyclePanel.Children.Add(new TextBlock { Text = "每个果实回收获得的金币", Opacity = .7 });
        var recycleRow = new StackPanel { Orientation = Orientation.Horizontal, Spacing = 8 };
        recycleRow.Children.Add(_fruitPriceInput);
        recycleRow.Children.Add(ActionButton("Settings.Admin.SaveFruitPrice", async () => await SaveFruitPriceAsync()));
        recyclePanel.Children.Add(recycleRow);
        _fruitPriceReason.PlaceholderText = "填写调整原因";
        recyclePanel.Children.Add(_fruitPriceReason);
        ConfigureCard(_fruitRecycleCard, "Settings.Admin.RecycleFruit", recyclePanel);
        _root.Children.Add(_fruitRecycleCard);
        ConfigureCard(_auditCard, "Settings.Admin.Audit", new StackPanel { Spacing = 10, Children = { CreateTableHeader("Time", "Actor", "Target", "Action", "Reason", "Result"), _audit } });
        _root.Children.Add(_auditCard);
        SelectAdminSection("account");
    }

    private void BuildShell()
    {
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
        body.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(220) });
        body.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(1, GridUnitType.Star) });
        var sidebar = new Border
        {
            Padding = new Thickness(12, 18, 12, 14),
            BorderThickness = new Thickness(0, 0, 1, 0),
            BorderBrush = new SolidColorBrush(Microsoft.UI.Colors.LightGray),
            Child = _adminMenu
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
        AddMenuItem("Settings.Admin.Menu.Users", "users", "\uE77B");
        AddMenuItem("Settings.Admin.Menu.Audit", "audit", "\uE8D7");
        AddMenuHeading("Settings.Admin.TreeManagement");
        AddMenuItem("Settings.Admin.Menu.Tree", "tree", "\uE8B7");
        AddMenuHeading("Settings.Admin.StoreManagement");
        AddMenuItem("Settings.Admin.Menu.Store", "store", "\uE719");
        AddMenuHeading("Settings.Admin.RecycleFruit");
        AddMenuItem("Settings.Admin.Menu.Recycle", "recycle", "\uE74D");
        AddMenuHeading("Settings.Admin.AquariumManagement");
        AddMenuItem("Settings.Admin.Menu.Aquarium", "aquarium", "\uE7B7");
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
        _usersCard.Visibility = section is "account" or "users" ? Visibility.Visible : Visibility.Collapsed;
        _auditCard.Visibility = section == "audit" ? Visibility.Visible : Visibility.Collapsed;
        _fruitRecycleCard.Visibility = section == "recycle" ? Visibility.Visible : Visibility.Collapsed;
        _detailCard.Visibility = _targetId is not null && section is ("users" or "tree" or "store" or "aquarium")
            ? Visibility.Visible : Visibility.Collapsed;
        _pageTitle.Text = L(section switch
        {
            "tree" => "Settings.Admin.TreeManagement",
            "store" => "Settings.Admin.StoreManagement",
            "recycle" => "Settings.Admin.RecycleFruit",
            "aquarium" => "Settings.Admin.AquariumManagement",
            "audit" => "Settings.Admin.Menu.Audit",
            "users" => "Settings.Admin.Menu.Users",
            _ => "Settings.Admin.AccountManagement"
        });
        _pageSubtitle.Text = L("Settings.Admin.WorkspaceSubtitle");
        foreach ((Button button, _, string itemSection) in _menuItems)
        {
            button.Background = new SolidColorBrush(itemSection == section
                ? Microsoft.UI.Colors.LightSteelBlue : Microsoft.UI.Colors.Transparent);
        }
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
        _usersCard.Header = L("Settings.Admin.User");
        _detailCard.Header = _targetName is null
            ? L("Settings.Admin.User")
            : $"{L("Settings.Admin.User")}: {_targetName}";
        _auditCard.Header = L("Settings.Admin.Audit");
        _fruitRecycleCard.Header = L("Settings.Admin.RecycleFruit");
        _pageSubtitle.Text = L("Settings.Admin.WorkspaceSubtitle");
        foreach ((Button button, string key, _) in _menuItems)
        {
            if (button.Content is StackPanel panel && panel.Children.LastOrDefault() is TextBlock label)
                label.Text = L(key);
        }
        foreach ((TextBlock label, string key) in _menuHeadings)
            label.Text = L(key);
        SelectAdminSection(_selectedSection);
        _reason.PlaceholderText = L("Settings.Admin.Reason");
        _search.PlaceholderText = L("Settings.Admin.SearchHint");
        _confirm.PlaceholderText = L("Settings.Admin.ConfirmUsername");
        _password.PlaceholderText = L("Settings.Admin.AdminPassword");
    }

    public async Task RefreshAsync()
    {
        if (_busy || _auth?.CurrentUser?.Role != "admin" || _api is null) return;
        _busy = true;
        try
        {
            using JsonDocument overview = await GetAsync("overview");
            JsonElement metrics = overview.RootElement.GetProperty("overview");
            _overview.Text = string.Format(CultureInfo.CurrentCulture, L("Settings.Admin.Overview"),
                metrics.GetProperty("users").GetInt32(), metrics.GetProperty("active_users").GetInt32(),
                metrics.GetProperty("sessions").GetInt32(), metrics.GetProperty("backups").GetInt32(),
                metrics.GetProperty("backup_bytes").GetInt64(), metrics.GetProperty("tree_participants").GetInt32(),
                metrics.GetProperty("dingtalk_links").GetInt32());
            RenderOverviewMetrics(metrics);
            await LoadFruitPriceAsync();
            await LoadUsersCoreAsync();
            await LoadAuditCoreAsync();
            if (_targetId is not null) await LoadDetailCoreAsync(_targetId);
            _status.Text = string.Empty;
        }
        catch (Exception error) { _status.Text = error.Message; }
        finally { _busy = false; }
    }

    private async Task LoadFruitPriceAsync()
    {
        using JsonDocument result = await GetAsync("fruit_recycle");
        _fruitPriceInput.Text = result.RootElement.GetProperty("price_per_fruit").GetInt32().ToString(CultureInfo.CurrentCulture);
    }

    private async Task SaveFruitPriceAsync()
    {
        if (_api is null || _auth?.CurrentUser is not { Role: "admin" } admin) return;
        if (!long.TryParse(_fruitPriceInput.Text, NumberStyles.None, CultureInfo.InvariantCulture, out long price)
            || price < 0 || price > int.MaxValue || string.IsNullOrWhiteSpace(_fruitPriceReason.Text))
        { _status.Text = L("Settings.Admin.InvalidNumber"); return; }
        _busy = true;
        try
        {
            var request = new AdminMutationRequest { Action = "set_fruit_recycle_price", TargetId = admin.Id,
                Reason = _fruitPriceReason.Text.Trim(), PricePerFruit = price };
            using HttpResponseMessage response = await _api.SendAsync(HttpMethod.Post, "../admin/index.php", WriteMutation(request), null);
            await EnsureSuccessAsync(response);
            _fruitPriceReason.Text = string.Empty;
            _status.Text = L("Settings.Admin.Success");
            await LoadFruitPriceAsync();
        }
        catch (Exception error) { _status.Text = error.Message; }
        finally { _busy = false; }
    }

    private async Task<JsonDocument> GetAsync(string view, string extra = "")
    {
        using HttpResponseMessage response = await _api!.SendAsync(HttpMethod.Get,
            "../admin/index.php?view=" + view + extra, null, null);
        await EnsureSuccessAsync(response);
        return await JsonDocument.ParseAsync(await response.Content.ReadAsStreamAsync());
    }

    private static async Task EnsureSuccessAsync(HttpResponseMessage response)
    {
        if (response.IsSuccessStatusCode) return;
        string body = await response.Content.ReadAsStringAsync();
        throw new InvalidOperationException($"HTTP {(int)response.StatusCode}: {body}");
    }

    private async Task LoadUsersAsync()
    {
        if (_busy) return;
        _busy = true;
        try { await LoadUsersCoreAsync(); _status.Text = string.Empty; }
        catch (Exception error) { _status.Text = error.Message; }
        finally { _busy = false; }
    }

    private async Task LoadUsersCoreAsync()
    {
        using JsonDocument result = await GetAsync("users", "&q=" + Uri.EscapeDataString(_search.Text ?? string.Empty));
        _users.Children.Clear();
        if (result.RootElement.GetProperty("users").GetArrayLength() == 0)
        {
            _users.Children.Add(EmptyState("No users found"));
            return;
        }
        foreach (JsonElement user in result.RootElement.GetProperty("users").EnumerateArray())
        {
            string id = user.GetProperty("id").GetString()!;
            string name = user.GetProperty("username").GetString()!;
            string status = user.GetProperty("status").GetString()!;
            var button = new Button { Content = CreateUserRow(name, status, user.GetProperty("is_admin").GetInt32() == 1), HorizontalAlignment = HorizontalAlignment.Stretch, HorizontalContentAlignment = HorizontalAlignment.Stretch, CommandParameter = id };
            _users.Children.Add(button);
            button.Click += async (_, _) => await LoadDetailAsync(id);
        }
    }

    private async Task LoadDetailAsync(string id)
    {
        if (_busy) return;
        _busy = true;
        bool loaded = false;
        try
        {
            await LoadDetailCoreAsync(id);
            _status.Text = string.Empty;
            loaded = true;
        }
        catch (Exception error) { _status.Text = error.Message; }
        finally { _busy = false; }
        if (loaded) ShowDetailPanel();
    }

    private void ShowDetailPanel()
    {
        if (_targetId is null) return;
        _detailCard.Header = $"{L("Settings.Admin.User")}: {_targetName}";
        _detailCard.Visibility = Visibility.Visible;
    }

    private async Task LoadDetailCoreAsync(string id)
    {
        using JsonDocument result = await GetAsync("user", "&id=" + Uri.EscapeDataString(id));
        JsonElement value = result.RootElement;
        JsonElement user = value.GetProperty("user");
        _targetId = id;
        _targetName = user.GetProperty("username").GetString();
        bool isAdmin = user.GetProperty("is_admin").GetInt32() == 1;
        _detail.Children.Clear();
        _detail.RowDefinitions.Clear();
        _detail.ColumnDefinitions.Clear();
        _detail.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(1, GridUnitType.Star) });
        _detail.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(1, GridUnitType.Star) });
        var accountPanel = new StackPanel { Spacing = 8 };
        accountPanel.Children.Add(CreateSummaryRow(_targetName!, user.GetProperty("status").GetString()!, id));
        accountPanel.Children.Add(LabeledField("Settings.Admin.Reason", _reason.Parent is null ? _reason : Detach(_reason)));
        var accountActions = new StackPanel { Orientation = Orientation.Horizontal, Spacing = 8 };
        if (!isAdmin)
        {
            string status = user.GetProperty("status").GetString()!;
            accountActions.Children.Add(ActionButton(status == "active" ? "Settings.Admin.Disable" : "Settings.Admin.Enable",
                async () => await MutateAsync(new() { Action = status == "active" ? "disable" : "enable" })));
        }
        if (accountActions.Children.Count > 0) accountPanel.Children.Add(accountActions);
        var backups = new StackPanel { Spacing = 4 };
        backups.Children.Add(CreateTableHeader("Created", "Device", "Size", "Status", string.Empty));
        foreach (JsonElement backup in value.GetProperty("backups").EnumerateArray())
        {
            string snapshotId = backup.GetProperty("id").GetString()!;
            backups.Children.Add(CreateTableRow(backup.GetProperty("created_at").GetString(), backup.GetProperty("device_id").GetString(), $"{backup.GetProperty("total_bytes").GetInt64():N0} B", L("Settings.Admin.BackupReady"), ActionButton("Settings.Admin.DeleteBackup", async () => await MutateAsync(new() { Action = "delete_backup", SnapshotId = snapshotId }))));
        }
        foreach (JsonElement upload in value.GetProperty("backup_uploads").EnumerateArray())
            backups.Children.Add(CreateTableRow(upload.GetProperty("updated_at").GetString(), upload.GetProperty("device_id").GetString(), $"{upload.GetProperty("total_bytes").GetInt64():N0} B", L("Settings.Admin.BackupUploading"), null));
        if (backups.Children.Count == 1) backups.Children.Add(EmptyState("No backups"));
        SettingsCard backupsCard = CreateCard(L("Settings.Admin.Backups"), backups);
        JsonElement tree = value.GetProperty("tree").GetProperty("state");
        _treeVersion = tree.GetProperty("state_version").GetInt32();
        _treeDay = tree.GetProperty("day").GetString() ?? string.Empty;
        var treePanel = new StackPanel { Spacing = 10 };
        var treeGrid = new Grid { ColumnSpacing = 10, RowSpacing = 8 };
        for (int column = 0; column < 3; column++) treeGrid.ColumnDefinitions.Add(new ColumnDefinition());
        string[] fields = ["total_energy", "today_growth", "fruit_energy", "fruit_total", "harvested_total"];
        for (int index = 0; index < fields.Length; index++)
        {
            _treeInputs[index].Header = fields[index];
            _treeInputs[index].Text = tree.GetProperty(fields[index]).GetInt64().ToString(CultureInfo.InvariantCulture);
            Grid.SetRow(_treeInputs[index], index / 3); Grid.SetColumn(_treeInputs[index], index % 3);
            treeGrid.Children.Add(_treeInputs[index].Parent is null ? _treeInputs[index] : Detach(_treeInputs[index]));
        }
        treePanel.Children.Add(treeGrid);
        treePanel.Children.Add(new TextBlock { Text = $"{L("Settings.Admin.Stage")}: {tree.GetProperty("stage").GetInt32()} · version {_treeVersion} · {L("Settings.Admin.Participating")}: {value.GetProperty("tree").GetProperty("participating").GetBoolean()}" });
        var treeActions = new StackPanel { Orientation = Orientation.Horizontal, Spacing = 8 };
        treeActions.Children.Add(ActionButton("Settings.Admin.Correct", async () => await CorrectTreeAsync()));
        treeActions.Children.Add(ActionButton("Settings.Admin.Reset", async () => await MutateAsync(new() { Action = "tree_reset", BaseVersion = _treeVersion, Day = _treeDay })));
        treePanel.Children.Add(treeActions);
        SettingsCard treeCard = CreateCard(L("Settings.Admin.Tree"), treePanel);
        JsonElement aquarium = value.GetProperty("aquarium");
        var coinPanel = new StackPanel { Spacing = 8 };
        coinPanel.Children.Add(new TextBlock
        {
            Text = L("Settings.Admin.CoinBalance"),
            Opacity = .72
        });
        _coinInput.Text = aquarium.GetProperty("coins").GetInt64().ToString(CultureInfo.InvariantCulture);
        coinPanel.Children.Add(_coinInput.Parent is null ? _coinInput : Detach(_coinInput));
        coinPanel.Children.Add(ActionButton("Settings.Admin.SetCoins", async () => await SetAquariumCoinsAsync()));
        SettingsCard coinsCard = CreateCard(L("Settings.Admin.Coins"), coinPanel);
        SettingsCard accountCard = CreateCard(L("Settings.Admin.User"), accountPanel);
        int row = 0;
        AddDetailSection(_detail, accountCard, row, 0);
        AddDetailSection(_detail, backupsCard, row++, 1);
        AddDetailSection(_detail, treeCard, row, 0);
        AddDetailSection(_detail, coinsCard, row++, 1);
        if (!isAdmin)
        {
            var danger = new StackPanel { Spacing = 8 };
            danger.Children.Add(new TextBlock { Text = "This action permanently removes the account.", TextWrapping = TextWrapping.Wrap, Opacity = .78 });
            danger.Children.Add(LabeledField("Settings.Admin.ConfirmUsername", _confirm.Parent is null ? _confirm : Detach(_confirm)));
            danger.Children.Add(LabeledField("Settings.Admin.AdminPassword", _password.Parent is null ? _password : Detach(_password)));
            danger.Children.Add(ActionButton("Settings.Admin.DeleteUser", async () => await MutateAsync(new()
            {
                Action = "delete_user", ConfirmUsername = _confirm.Text, AdminPassword = _password.Password
            })));
            var dangerCard = CreateDangerCard(L("Settings.Admin.DeleteUser"), danger);
            Grid.SetColumnSpan(dangerCard, 2);
            AddDetailSection(_detail, dangerCard, row++, 0);
        }
    }

    private static T Detach<T>(T element) where T : FrameworkElement
    {
        if (element.Parent is Panel panel) panel.Children.Remove(element);
        else if (element.Parent is ContentControl content && ReferenceEquals(content.Content, element)) content.Content = null;
        return element;
    }

    private static void AddDetailSection(Grid grid, FrameworkElement content, int row, int column)
    {
        while (grid.RowDefinitions.Count <= row)
            grid.RowDefinitions.Add(new RowDefinition { Height = GridLength.Auto });
        Grid.SetRow(content, row);
        Grid.SetColumn(content, column);
        grid.Children.Add(content);
    }

    private async Task CorrectTreeAsync()
    {
        if (!_treeInputs.All(input => long.TryParse(input.Text, NumberStyles.None, CultureInfo.InvariantCulture, out _)))
        { _status.Text = L("Settings.Admin.InvalidNumber"); return; }
        long[] values = _treeInputs.Select(input => long.Parse(input.Text, CultureInfo.InvariantCulture)).ToArray();
        await MutateAsync(new() { Action = "tree_correct", BaseVersion = _treeVersion, Day = _treeDay,
            TotalEnergy = values[0], TodayGrowth = values[1], FruitEnergy = values[2],
            FruitTotal = values[3], HarvestedTotal = values[4] });
    }

    private async Task SetAquariumCoinsAsync()
    {
        if (!long.TryParse(_coinInput.Text, NumberStyles.None, CultureInfo.InvariantCulture, out long coins)
            || coins > int.MaxValue)
        {
            _status.Text = L("Settings.Admin.InvalidNumber");
            return;
        }
        await MutateAsync(new() { Action = "set_aquarium_coins", Coins = coins });
    }

    private async Task MutateAsync(AdminMutationRequest request)
    {
        if (_busy || _targetId is null || _api is null) return;
        if (string.IsNullOrWhiteSpace(_reason.Text)) { _status.Text = L("Settings.Admin.ReasonRequired"); return; }
        _busy = true;
        request.TargetId = _targetId;
        request.Reason = _reason.Text.Trim();
        try
        {
            byte[] content = WriteMutation(request);
            using HttpResponseMessage response = await _api.SendAsync(HttpMethod.Post, "../admin/index.php", content, null);
            await EnsureSuccessAsync(response);
            _status.Text = L("Settings.Admin.Success");
            if (request.Action == "delete_user")
            {
                _targetId = null;
                _targetName = null;
                _detail.Children.Clear();
                _detailCard.Visibility = Visibility.Collapsed;
            }
            else await LoadDetailCoreAsync(_targetId);
            await LoadUsersCoreAsync();
            await LoadAuditCoreAsync();
            _password.Password = string.Empty;
        }
        catch (Exception error) { _status.Text = error.Message; }
        finally { _busy = false; }
    }

    private async Task LoadAuditCoreAsync()
    {
        using JsonDocument result = await GetAsync("audit");
        _audit.Children.Clear();
        foreach (JsonElement entry in result.RootElement.GetProperty("audit").EnumerateArray())
            _audit.Children.Add(CreateTableRow(entry.GetProperty("created_at").GetString(), entry.GetProperty("actor_id").GetString(), entry.GetProperty("target_id").GetString(), entry.GetProperty("action").GetString(), entry.GetProperty("reason").GetString(), entry.GetProperty("result").GetString()));
        if (_audit.Children.Count == 0) _audit.Children.Add(EmptyState("No audit records"));
    }

    private TextBlock Heading(string key)
    {
        var heading = new TextBlock { Text = L(key), FontSize = 18,
            FontWeight = Microsoft.UI.Text.FontWeights.SemiBold };
        Localized.SetKey(heading, key);
        return heading;
    }

    private SettingsCard CreateCard(string title, UIElement content)
    {
        return new SettingsCard
        {
            Header = title,
            HorizontalContentAlignment = HorizontalAlignment.Stretch,
            Content = content
        };
    }

    private void ConfigureCard(SettingsCard card, string titleKey, UIElement content)
    {
        card.Header = L(titleKey);
        card.HorizontalContentAlignment = HorizontalAlignment.Stretch;
        card.Content = content;
    }

    private SettingsCard CreateDangerCard(string title, UIElement content)
    {
        var card = CreateCard(title, content);
        card.Background = new SolidColorBrush(Microsoft.UI.Colors.MistyRose);
        return card;
    }

    private static Grid CreateTableHeader(params string[] labels)
    {
        var grid = new Grid { Padding = new Thickness(8, 4, 8, 4), ColumnSpacing = 10, Opacity = .68 };
        for (int index = 0; index < labels.Length; index++)
        {
            grid.ColumnDefinitions.Add(new ColumnDefinition { Width = index == labels.Length - 1 ? GridLength.Auto : new GridLength(1, GridUnitType.Star) });
            var label = new TextBlock { Text = labels[index], FontWeight = Microsoft.UI.Text.FontWeights.SemiBold, TextTrimming = TextTrimming.CharacterEllipsis };
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

    private static Grid CreateUserRow(string name, string status, bool isAdmin)
    {
        return CreateTableRow(name, status, isAdmin ? "Admin" : string.Empty, new TextBlock { Text = "›", FontSize = 20, HorizontalAlignment = HorizontalAlignment.Right });
    }

    private static Grid CreateSummaryRow(string name, string status, string id)
    {
        return CreateTableRow(name, status, id);
    }

    private static Grid CreateKeyValue(string key, string? value)
    {
        return CreateTableRow(new TextBlock { Text = key, FontWeight = Microsoft.UI.Text.FontWeights.SemiBold }, value);
    }

    private static StackPanel LabeledField(string label, Control field)
    {
        return new StackPanel { Spacing = 4, Children = { new TextBlock { Text = label, Opacity = .72 }, field } };
    }

    private static TextBlock EmptyState(string text)
    {
        return new TextBlock { Text = text, Opacity = .62, Margin = new Thickness(8, 4, 8, 4) };
    }

    private void RenderOverviewMetrics(JsonElement metrics)
    {
        _overviewMetrics.Children.Clear();
        _overviewMetrics.ColumnDefinitions.Clear();
        _overviewMetrics.RowDefinitions.Clear();
        for (int column = 0; column < 4; column++)
            _overviewMetrics.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(1, GridUnitType.Star) });
        for (int row = 0; row < 2; row++)
            _overviewMetrics.RowDefinitions.Add(new RowDefinition { Height = GridLength.Auto });
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
        for (int index = 0; index < labels.Length; index++)
        {
            var metric = new Border
            {
                Background = new SolidColorBrush(Microsoft.UI.Colors.Transparent),
                BorderBrush = new SolidColorBrush(Microsoft.UI.Colors.LightGray),
                BorderThickness = new Thickness(1),
                CornerRadius = new CornerRadius(8),
                Padding = new Thickness(10, 8, 10, 8),
                Child = new StackPanel { Spacing = 2, Children = { new TextBlock { Text = labels[index], FontSize = 12, Opacity = .68 }, new TextBlock { Text = values[index], FontSize = 18, FontWeight = Microsoft.UI.Text.FontWeights.SemiBold } } }
            };
            Grid.SetColumn(metric, index % 4);
            Grid.SetRow(metric, index / 4);
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

    private TextBlock SectionHeading(string key) => new() { Text = L(key), FontSize = 18,
        FontWeight = Microsoft.UI.Text.FontWeights.SemiBold, Margin = new Thickness(0, 8, 0, 0) };

    private string L(string key) => _localization?.T(key) ?? key;

    private static byte[] WriteMutation(AdminMutationRequest request)
    {
        using var stream = new MemoryStream();
        using (var writer = new Utf8JsonWriter(stream))
        {
            writer.WriteStartObject();
            writer.WriteString("action", request.Action);
            writer.WriteString("target_id", request.TargetId);
            writer.WriteString("reason", request.Reason);
            if (request.SessionId is not null) writer.WriteString("session_id", request.SessionId);
            if (request.SnapshotId is not null) writer.WriteString("snapshot_id", request.SnapshotId);
            if (request.ConfirmUsername is not null) writer.WriteString("confirm_username", request.ConfirmUsername);
            if (request.AdminPassword is not null) writer.WriteString("admin_password", request.AdminPassword);
            if (request.Day is not null) writer.WriteString("day", request.Day);
            if (request.BaseVersion is int version) writer.WriteNumber("base_version", version);
            if (request.TotalEnergy is long energy) writer.WriteNumber("total_energy", energy);
            if (request.TodayGrowth is long growth) writer.WriteNumber("today_growth", growth);
            if (request.FruitEnergy is long fruitEnergy) writer.WriteNumber("fruit_energy", fruitEnergy);
            if (request.FruitTotal is long fruitTotal) writer.WriteNumber("fruit_total", fruitTotal);
            if (request.HarvestedTotal is long harvestedTotal) writer.WriteNumber("harvested_total", harvestedTotal);
            if (request.Coins is long coins) writer.WriteNumber("coins", coins);
            if (request.PricePerFruit is long price) writer.WriteNumber("price_per_fruit", price);
            writer.WriteEndObject();
        }
        return stream.ToArray();
    }
}
