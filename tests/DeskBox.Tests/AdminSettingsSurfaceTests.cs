namespace DeskBox.Tests;

public sealed class AdminSettingsSurfaceTests
{
    [Fact]
    public void AdminSurface_UsesOverviewUserTableAndFruitRecycleOnly()
    {
        string source = File.ReadAllText(TestPaths.FromRepository(
            "src/DeskBox/Views/SettingsSections/AdminSettingsSection.cs"));

        Assert.Contains("SettingsCard _overviewCard", source, StringComparison.Ordinal);
        Assert.Contains("SettingsCard _recycleCard", source, StringComparison.Ordinal);
        Assert.Contains("CreateTableHeader", source, StringComparison.Ordinal);
        Assert.Contains("Content = _shell", source, StringComparison.Ordinal);
        Assert.Contains("Settings.Admin.AccountManagement", source, StringComparison.Ordinal);
        Assert.Contains("Settings.Admin.RecycleFruit", source, StringComparison.Ordinal);
        Assert.Contains("page_size={PageSize}", source, StringComparison.Ordinal);
        Assert.Contains("ShowEditDialogAsync", source, StringComparison.Ordinal);
        Assert.Contains("Action = \"update_user\"", source, StringComparison.Ordinal);
        Assert.Contains("ContentDialog", source, StringComparison.Ordinal);
        Assert.DoesNotContain("Settings.Admin.TreeManagement", source, StringComparison.Ordinal);
        Assert.DoesNotContain("Settings.Admin.StoreManagement", source, StringComparison.Ordinal);
        Assert.DoesNotContain("Settings.Admin.AquariumManagement", source, StringComparison.Ordinal);
        Assert.DoesNotContain("LoadAudit", source, StringComparison.Ordinal);
        Assert.DoesNotContain("Settings.Admin.Reason", source, StringComparison.Ordinal);
    }

    [Fact]
    public void FruitRecyclePrice_OnlyValidatesItsNumericInput()
    {
        string source = File.ReadAllText(TestPaths.FromRepository(
            "src/DeskBox/Views/SettingsSections/AdminSettingsSection.cs"));
        int start = source.IndexOf("private async Task SaveFruitPriceAsync", StringComparison.Ordinal);
        int end = source.IndexOf("private async Task LoadUsersAsync", start, StringComparison.Ordinal);
        Assert.True(start >= 0 && end > start);
        string method = source[start..end];

        Assert.Contains("int.TryParse", method, StringComparison.Ordinal);
        Assert.DoesNotContain("Reason", method, StringComparison.Ordinal);
        Assert.DoesNotContain("reason", method, StringComparison.Ordinal);
    }
}
