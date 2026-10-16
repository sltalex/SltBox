using System.Globalization;
using System.Net.Http;
using System.Text.Json;
using DeskBox.Contracts;
using DeskBox.Services;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;

namespace DeskBox.Views.SettingsSections;

public sealed class AdminSettingsSection : UserControl
{
    private readonly StackPanel _root = new() { Spacing = 12 };
    private readonly TextBlock _status = new() { TextWrapping = TextWrapping.Wrap };
    private readonly TextBlock _overview = new() { TextWrapping = TextWrapping.Wrap };
    private readonly TextBox _search = new() { Width = 220 };
    private readonly StackPanel _users = new() { Spacing = 5 };
    private readonly StackPanel _detail = new() { Spacing = 8 };
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

    public AdminSettingsSection()
    {
        Content = _root;
        _root.Children.Add(_status);
        _root.Children.Add(_overview);
        var searchRow = new StackPanel { Orientation = Orientation.Horizontal, Spacing = 8 };
        searchRow.Children.Add(_search);
        searchRow.Children.Add(ActionButton("Settings.Admin.Search", async () => await LoadUsersAsync()));
        _root.Children.Add(searchRow);
        _root.Children.Add(_users);
        _root.Children.Add(_detail);
        _root.Children.Add(Heading("Settings.Admin.Audit"));
        _root.Children.Add(ActionButton("Settings.Admin.RefreshAudit", async () => await LoadAuditAsync()));
        _root.Children.Add(_audit);
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
        foreach (JsonElement user in result.RootElement.GetProperty("users").EnumerateArray())
        {
            string id = user.GetProperty("id").GetString()!;
            string name = user.GetProperty("username").GetString()!;
            string status = user.GetProperty("status").GetString()!;
            _users.Children.Add(new Button
            {
                Content = $"{name} · {status}" + (user.GetProperty("is_admin").GetInt32() == 1 ? " · Admin" : ""),
                HorizontalAlignment = HorizontalAlignment.Stretch,
                HorizontalContentAlignment = HorizontalAlignment.Left,
                CommandParameter = id
            });
            if (_users.Children[^1] is Button button)
                button.Click += async (_, _) => await LoadDetailAsync(id);
        }
    }

    private async Task LoadDetailAsync(string id)
    {
        if (_busy) return;
        _busy = true;
        try { await LoadDetailCoreAsync(id); _status.Text = string.Empty; }
        catch (Exception error) { _status.Text = error.Message; }
        finally { _busy = false; }
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
        _detail.Children.Add(Heading("Settings.Admin.User"));
        _detail.Children.Add(new TextBlock { Text = $"{_targetName} · {user.GetProperty("status").GetString()} · {id}", TextWrapping = TextWrapping.Wrap });
        _detail.Children.Add(_reason.Parent is null ? _reason : Detach(_reason));
        if (!isAdmin)
        {
            string status = user.GetProperty("status").GetString()!;
            _detail.Children.Add(ActionButton(status == "active" ? "Settings.Admin.Disable" : "Settings.Admin.Enable",
                async () => await MutateAsync(new() { Action = status == "active" ? "disable" : "enable" })));
            _detail.Children.Add(ActionButton("Settings.Admin.RevokeAll", async () => await MutateAsync(new() { Action = "revoke_all_sessions" })));
        }
        _detail.Children.Add(Heading("Settings.Admin.Sessions"));
        foreach (JsonElement session in value.GetProperty("sessions").EnumerateArray())
        {
            string sessionId = session.GetProperty("id").GetString()!;
            var row = new StackPanel { Orientation = Orientation.Horizontal, Spacing = 8 };
            row.Children.Add(new TextBlock { Text = session.GetProperty("device_id").GetString() + " · " + session.GetProperty("created_at").GetString(), VerticalAlignment = VerticalAlignment.Center });
            if (session.GetProperty("revoked_at").ValueKind == JsonValueKind.Null)
                row.Children.Add(ActionButton("Settings.Admin.Revoke", async () => await MutateAsync(new() { Action = "revoke_session", SessionId = sessionId })));
            _detail.Children.Add(row);
        }
        _detail.Children.Add(Heading("Settings.Admin.Backups"));
        foreach (JsonElement backup in value.GetProperty("backups").EnumerateArray())
        {
            string snapshotId = backup.GetProperty("id").GetString()!;
            var row = new StackPanel { Orientation = Orientation.Horizontal, Spacing = 8 };
            row.Children.Add(new TextBlock { Text = $"{backup.GetProperty("created_at").GetString()} · {backup.GetProperty("device_id").GetString()} · {backup.GetProperty("total_bytes").GetInt64():N0} B · {L("Settings.Admin.BackupReady")}", VerticalAlignment = VerticalAlignment.Center });
            row.Children.Add(ActionButton("Settings.Admin.DeleteBackup", async () => await MutateAsync(new() { Action = "delete_backup", SnapshotId = snapshotId })));
            _detail.Children.Add(row);
        }
        foreach (JsonElement upload in value.GetProperty("backup_uploads").EnumerateArray())
            _detail.Children.Add(new TextBlock { Text = $"{upload.GetProperty("updated_at").GetString()} · {upload.GetProperty("device_id").GetString()} · {upload.GetProperty("total_bytes").GetInt64():N0} B · {L("Settings.Admin.BackupUploading")}", TextWrapping = TextWrapping.Wrap });
        JsonElement tree = value.GetProperty("tree").GetProperty("state");
        _treeVersion = tree.GetProperty("state_version").GetInt32();
        _treeDay = tree.GetProperty("day").GetString() ?? string.Empty;
        _detail.Children.Add(Heading("Settings.Admin.Tree"));
        string[] fields = ["total_energy", "today_growth", "fruit_energy", "fruit_total", "harvested_total"];
        for (int index = 0; index < fields.Length; index++)
        {
            _treeInputs[index].Header = fields[index];
            _treeInputs[index].Text = tree.GetProperty(fields[index]).GetInt64().ToString(CultureInfo.InvariantCulture);
            _detail.Children.Add(_treeInputs[index].Parent is null ? _treeInputs[index] : Detach(_treeInputs[index]));
        }
        _detail.Children.Add(new TextBlock { Text = $"{L("Settings.Admin.Stage")}: {tree.GetProperty("stage").GetInt32()} · version {_treeVersion} · {L("Settings.Admin.Participating")}: {value.GetProperty("tree").GetProperty("participating").GetBoolean()}" });
        _detail.Children.Add(ActionButton("Settings.Admin.Correct", async () => await CorrectTreeAsync()));
        _detail.Children.Add(ActionButton("Settings.Admin.Reset", async () => await MutateAsync(new() { Action = "tree_reset", BaseVersion = _treeVersion, Day = _treeDay })));
        JsonElement identity = value.GetProperty("identity");
        _detail.Children.Add(Heading("Settings.Admin.DingTalk"));
        if (identity.ValueKind != JsonValueKind.Null)
        {
            _detail.Children.Add(new TextBlock { Text = $"{identity.GetProperty("display_name").GetString()} · {identity.GetProperty("organization_name").GetString()} · {identity.GetProperty("department_name").GetString()} · {identity.GetProperty("corp_id").GetString()} / {identity.GetProperty("dingtalk_user_id").GetString()}", TextWrapping = TextWrapping.Wrap });
            _detail.Children.Add(ActionButton("Settings.Admin.Unlink", async () => await MutateAsync(new() { Action = "unlink_dingtalk" })));
        }
        if (!isAdmin)
        {
            _detail.Children.Add(Heading("Settings.Admin.DeleteUser"));
            _detail.Children.Add(_confirm.Parent is null ? _confirm : Detach(_confirm));
            _detail.Children.Add(_password.Parent is null ? _password : Detach(_password));
            _detail.Children.Add(ActionButton("Settings.Admin.DeleteUser", async () => await MutateAsync(new()
            {
                Action = "delete_user", ConfirmUsername = _confirm.Text, AdminPassword = _password.Password
            })));
        }
    }

    private static T Detach<T>(T element) where T : FrameworkElement
    {
        if (element.Parent is Panel panel) panel.Children.Remove(element);
        return element;
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
            if (request.Action == "delete_user") { _targetId = null; _detail.Children.Clear(); }
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
            _audit.Children.Add(new TextBlock
            {
                Text = $"{entry.GetProperty("created_at").GetString()} · {entry.GetProperty("actor_id").GetString()} → {entry.GetProperty("target_id").GetString()} · {entry.GetProperty("action").GetString()} · {entry.GetProperty("reason").GetString()} · {entry.GetProperty("result").GetString()}",
                TextWrapping = TextWrapping.Wrap
            });
    }

    private TextBlock Heading(string key)
    {
        var heading = new TextBlock { Text = L(key), FontSize = 18,
            FontWeight = Microsoft.UI.Text.FontWeights.SemiBold };
        Localized.SetKey(heading, key);
        return heading;
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
