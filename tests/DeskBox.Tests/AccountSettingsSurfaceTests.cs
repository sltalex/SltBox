using System.Text.Json;

namespace DeskBox.Tests;

public sealed class AccountSettingsSurfaceTests
{
    [Fact]
    public void GeneralSettings_ContainsOneUsernameAndOnePasswordInputWithAccountActions()
    {
        string root = FindRepositoryRoot();
        string xaml = File.ReadAllText(Path.Combine(root, "src", "DeskBox", "Views", "SettingsWindow.xaml"));
        string general = Slice(xaml, "x:Name=\"GeneralSection\"", "</NavigationView>");

        Assert.Equal(1, Count(general, "x:Name=\"AccountUsernameTextBox\""));
        Assert.Equal(1, Count(general, "x:Name=\"AccountPasswordBox\""));
        Assert.Contains("AccountSignInButton_Click", general, StringComparison.Ordinal);
        Assert.Contains("AccountRegisterButton_Click", general, StringComparison.Ordinal);
        Assert.Contains("AccountSignOutButton_Click", general, StringComparison.Ordinal);
        Assert.Contains("Settings.Account.SyncBenefit", general, StringComparison.Ordinal);
    }

    [Fact]
    public void EveryLanguage_DefinesAccountCopyAndChineseBenefitIsExact()
    {
        string root = FindRepositoryRoot();
        string strings = Path.Combine(root, "src", "DeskBox", "Strings");
        string[] required =
        [
            "Settings.Account.Title", "Settings.Account.SyncBenefit", "Settings.Account.Username",
            "Settings.Account.Password", "Settings.Account.SignIn", "Settings.Account.Register",
            "Settings.Account.SignOut", "Settings.Account.SignedInAs"
        ];

        foreach (string file in Directory.EnumerateFiles(strings, "*.json"))
        {
            using JsonDocument document = JsonDocument.Parse(File.ReadAllText(file));
            foreach (string key in required)
            {
                Assert.True(document.RootElement.TryGetProperty(key, out _), $"{Path.GetFileName(file)} misses {key}");
            }
        }

        using JsonDocument chinese = JsonDocument.Parse(File.ReadAllText(Path.Combine(strings, "zh-CN.json")));
        Assert.Equal(
            "登录账号可享资料自动云同步，跨平台保留资料，资料不丢失。",
            chinese.RootElement.GetProperty("Settings.Account.SyncBenefit").GetString());
    }

    [Fact]
    public void AccountOperations_ClearPasswordInFinallyAndRestoreAtStartup()
    {
        string root = FindRepositoryRoot();
        string handlers = File.ReadAllText(Path.Combine(
            root, "src", "DeskBox", "Views", "SettingsWindow.Account.cs"));
        string startup = File.ReadAllText(Path.Combine(root, "src", "DeskBox", "App.xaml.cs"));

        Assert.Equal(2, Count(handlers, "finally"));
        Assert.Equal(3, Count(handlers, "AccountPasswordBox.Password = string.Empty;"));
        Assert.Contains("auth-session-restore", startup, StringComparison.Ordinal);
        Assert.Contains("GetRequiredService<IAuthSession>().RestoreAsync()", startup, StringComparison.Ordinal);
    }

    private static int Count(string value, string needle) =>
        value.Split(needle, StringSplitOptions.None).Length - 1;

    private static string Slice(string value, string start, string end)
    {
        int startIndex = value.IndexOf(start, StringComparison.Ordinal);
        Assert.True(startIndex >= 0);
        int endIndex = value.IndexOf(end, startIndex, StringComparison.Ordinal);
        Assert.True(endIndex > startIndex);
        return value[startIndex..endIndex];
    }

    private static string FindRepositoryRoot()
    {
        DirectoryInfo? current = new(AppContext.BaseDirectory);
        while (current is not null && !File.Exists(Path.Combine(current.FullName, "DeskBox.sln")))
        {
            current = current.Parent;
        }
        return current?.FullName ?? throw new DirectoryNotFoundException("DeskBox.sln not found.");
    }
}
