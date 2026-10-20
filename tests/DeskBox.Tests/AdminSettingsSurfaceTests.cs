namespace DeskBox.Tests;

public sealed class AdminSettingsSurfaceTests
{
    [Fact]
    public void AdminSurface_IsInlineCardAndTableLayoutWithoutRemovedManagers()
    {
        string source = File.ReadAllText(TestPaths.FromRepository(
            "src/DeskBox/Views/SettingsSections/AdminSettingsSection.cs"));

        Assert.Contains("SettingsCard _overviewCard", source, StringComparison.Ordinal);
        Assert.Contains("SettingsCard _usersCard", source, StringComparison.Ordinal);
        Assert.Contains("SettingsCard _detailCard", source, StringComparison.Ordinal);
        Assert.Contains("CreateTableHeader", source, StringComparison.Ordinal);
        Assert.Contains("HorizontalAlignment = HorizontalAlignment.Stretch", source, StringComparison.Ordinal);
        Assert.DoesNotContain("ContentDialog", source, StringComparison.Ordinal);
        Assert.DoesNotContain("Popup", source, StringComparison.Ordinal);
        Assert.DoesNotContain("Flyout", source, StringComparison.Ordinal);
        Assert.DoesNotContain("revoke_all_sessions", source, StringComparison.Ordinal);
        Assert.DoesNotContain("revoke_session", source, StringComparison.Ordinal);
        Assert.DoesNotContain("Settings.Admin.Sessions", source, StringComparison.Ordinal);
        Assert.DoesNotContain("unlink_dingtalk", source, StringComparison.Ordinal);
        Assert.DoesNotContain("Settings.Admin.DingTalk", source, StringComparison.Ordinal);
        Assert.DoesNotContain("Settings.Admin.RefreshAudit", source, StringComparison.Ordinal);
        Assert.DoesNotContain("LoadAuditAsync", source, StringComparison.Ordinal);
    }

    [Fact]
    public void AdminEditor_UsesOneBoundedColumnAndCompactTables()
    {
        string source = File.ReadAllText(TestPaths.FromRepository(
            "src/DeskBox/Views/SettingsSections/AdminSettingsSection.cs"));
        int start = source.IndexOf("private async Task LoadDetailCoreAsync", StringComparison.Ordinal);
        int end = source.IndexOf("private static T Detach", start, StringComparison.Ordinal);
        Assert.True(start >= 0 && end > start);
        string editor = source[start..end];

        Assert.Contains("AddDetailSection(_detail, accountCard", editor, StringComparison.Ordinal);
        Assert.Contains("AddDetailSection(_detail, treeCard", editor, StringComparison.Ordinal);
        Assert.Contains("AddDetailSection(_detail, coinsCard", editor, StringComparison.Ordinal);
        Assert.DoesNotContain("Grid.SetColumn(content", source, StringComparison.Ordinal);
        Assert.DoesNotContain("new GridLength(1, GridUnitType.Star) });\n        _detail.ColumnDefinitions.Add", editor, StringComparison.Ordinal);
    }
}
