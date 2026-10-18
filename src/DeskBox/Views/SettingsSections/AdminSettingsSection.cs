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

public sealed class AdminSettingsSection : UserControl
{
    private readonly StackPanel _root = new() { Spacing = 12, Margin = new Thickness(24) };
    private readonly TextBlock _status = new() { TextWrapping = TextWrapping.Wrap };
    private readonly TextBlock _overview = new() { TextWrapping = TextWrapping.Wrap, Opacity = .72 };
    private readonly Grid _overviewMetrics = new() { ColumnSpacing = 10, RowSpacing = 10 };
    private readonly SettingsCard _overviewCard = new();
    private readonly SettingsCard _usersCard = new();
    private readonly SettingsCard _auditCard = new();
    private readonly TextBox _search = new() { Width = 220 };
    private readonly StackPanel _users = new() { Spacing = 5 };
    private readonly Grid _detail = new() { ColumnSpacing = 12, RowSpacing = 12 };
    private readonly StackPanel _audit = new() { Spacing = 5 };
    private readonly TextBox _reason = new() { MaxLength = 250 };
    private readonly TextBox _confirm = new();
    private readonly PasswordBox _password = new();
    private readonly TextBox[] _treeInputs = [new(), new(), new(), new(), new()];
    private IAuthSession? _auth;
    private IAuthenticatedAccountApi? _api;
    private LocalizationService? _localization;
    private string? _targetId;
    private string? _targetName;
    private int _treeVersion;
    private string _treeDay = string.Empty;
    private bool _busy;
    private ContentDialog? _detailDialog;

    public AdminSettingsSection()
    {
        Content = _root;
        ConfigureCard(_overviewCard, "Settings.Admin.Overview", new StackPanel { Spacing = 12, Children = { _status, _overview, _overviewMetrics } });
        _root.Children.Add(_overviewCard);

        var searchRow = new StackPanel { Orientation = Orientation.Horizontal, Spacing = 8 };
        searchRow.Children.Add(_search);
        searchRow.Children.Add(ActionButton("Settings.Admin.Search", async () => await LoadUsersAsync()));
        ConfigureCard(_usersCard, "Settings.Admin.User", new StackPanel { Spacing = 10, Children = { searchRow, CreateTableHeader(L("Settings.Admin.User"), L("Settings.Admin.Stage"), L("Settings.Admin.DingTalk"), string.Empty), _users } });
        _root.Children.Add(_usersCard);
        var auditActions = new StackPanel { Orientation = Orientation.Horizontal, Spacing = 8 };
        auditActions.Children.Add(ActionButton("Settings.Admin.RefreshAudit", async () => await LoadAuditAsync()));
        ConfigureCard(_auditCard, "Settings.Admin.Audit", new StackPanel { Spacing = 10, Children = { auditActions, CreateTableHeader("Time", "Actor", "Target", "Action", "Reason", "Result"), _audit } });
        _root.Children.Add(_auditCard);
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
        _auditCard.Header = L("Settings.Admin.Audit");
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
            await LoadUsersCoreAsync();
            await LoadAuditCoreAsync();
            if (_targetId is not null) await LoadDetailCoreAsync(_targetId);
            _status.Text = string.Empty;
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
        if (loaded) await ShowDetailDialogAsync();
    }

    private async Task ShowDetailDialogAsync()
    {
        if (XamlRoot is null || _targetId is null) return;
        _detailDialog?.Hide();
        Detach(_detail);
        var dialog = new ContentDialog
        {
            XamlRoot = XamlRoot,
            Title = $"{L("Settings.Admin.User")}: {_targetName}",
            CloseButtonText = L("Common.Cancel"),
            DefaultButton = ContentDialogButton.Close,
            Content = new ScrollViewer
            {
                MaxWidth = 920,
                MaxHeight = 680,
                HorizontalScrollBarVisibility = ScrollBarVisibility.Disabled,
                VerticalScrollBarVisibility = ScrollBarVisibility.Auto,
                Content = _detail
            }
        };
        _detailDialog = dialog;
        try { await dialog.ShowAsync(); }
        finally
        {
            if (ReferenceEquals(_detailDialog, dialog)) _detailDialog = null;
        }
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
            accountActions.Children.Add(ActionButton("Settings.Admin.RevokeAll", async () => await MutateAsync(new() { Action = "revoke_all_sessions" })));
        }
        if (accountActions.Children.Count > 0) accountPanel.Children.Add(accountActions);
        var sessions = new StackPanel { Spacing = 4 };
        sessions.Children.Add(CreateTableHeader("Device", "Created", "Status", string.Empty));
        foreach (JsonElement session in value.GetProperty("sessions").EnumerateArray())
        {
            string sessionId = session.GetProperty("id").GetString()!;
            var action = new StackPanel { Orientation = Orientation.Horizontal, HorizontalAlignment = HorizontalAlignment.Right };
            if (session.GetProperty("revoked_at").ValueKind == JsonValueKind.Null)
                action.Children.Add(ActionButton("Settings.Admin.Revoke", async () => await MutateAsync(new() { Action = "revoke_session", SessionId = sessionId })));
            sessions.Children.Add(CreateTableRow(session.GetProperty("device_id").GetString(), session.GetProperty("created_at").GetString(), session.GetProperty("revoked_at").ValueKind == JsonValueKind.Null ? "Active" : "Revoked", action));
        }
        if (sessions.Children.Count == 1) sessions.Children.Add(EmptyState("No sessions"));
        SettingsCard sessionsCard = CreateCard(L("Settings.Admin.Sessions"), sessions);
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
        JsonElement identity = value.GetProperty("identity");
        var dingPanel = new StackPanel { Spacing = 8 };
        if (identity.ValueKind != JsonValueKind.Null)
        {
            dingPanel.Children.Add(CreateKeyValue("Name", identity.GetProperty("display_name").GetString()));
            dingPanel.Children.Add(CreateKeyValue("Organization", identity.GetProperty("organization_name").GetString()));
            dingPanel.Children.Add(CreateKeyValue("Department", identity.GetProperty("department_name").GetString()));
            dingPanel.Children.Add(CreateKeyValue("Corp / User", $"{identity.GetProperty("corp_id").GetString()} / {identity.GetProperty("dingtalk_user_id").GetString()}"));
            dingPanel.Children.Add(ActionButton("Settings.Admin.Unlink", async () => await MutateAsync(new() { Action = "unlink_dingtalk" })));
        }
        else dingPanel.Children.Add(EmptyState("No DingTalk binding"));
        SettingsCard dingCard = CreateCard(L("Settings.Admin.DingTalk"), dingPanel);
        int row = 0;
        AddDetailSection(_detail, accountPanel, row++, 0, 2);
        AddDetailSection(_detail, sessionsCard, row, 0);
        AddDetailSection(_detail, backupsCard, row++, 1);
        AddDetailSection(_detail, treeCard, row, 0);
        AddDetailSection(_detail, dingCard, row++, 1);
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
            AddDetailSection(_detail, CreateDangerCard(L("Settings.Admin.DeleteUser"), danger), row++, 0, 2);
        }
    }

    private static T Detach<T>(T element) where T : FrameworkElement
    {
        if (element.Parent is Panel panel) panel.Children.Remove(element);
        else if (element.Parent is ContentControl content && ReferenceEquals(content.Content, element)) content.Content = null;
        return element;
    }

    private static void AddDetailSection(Grid grid, FrameworkElement content, int row, int column, int columnSpan = 1)
    {
        while (grid.RowDefinitions.Count <= row)
            grid.RowDefinitions.Add(new RowDefinition { Height = GridLength.Auto });
        Grid.SetRow(content, row);
        Grid.SetColumn(content, column);
        if (columnSpan > 1) Grid.SetColumnSpan(content, columnSpan);
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
            if (request.Action == "delete_user") { _targetId = null; _detail.Children.Clear(); _detailDialog?.Hide(); }
            else await LoadDetailCoreAsync(_targetId);
            await LoadUsersCoreAsync();
            await LoadAuditCoreAsync();
            _password.Password = string.Empty;
        }
        catch (Exception error) { _status.Text = error.Message; }
        finally { _busy = false; }
    }

    private async Task LoadAuditAsync()
    {
        if (_busy) return;
        _busy = true;
        try { await LoadAuditCoreAsync(); _status.Text = string.Empty; }
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
            writer.WriteEndObject();
        }
        return stream.ToArray();
    }
}
