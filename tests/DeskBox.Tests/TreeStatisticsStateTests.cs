using DeskBox.Services;
using System.Text.Json;

namespace DeskBox.Tests;

public sealed class TreeStatisticsStateTests
{
    [Theory]
    [InlineData(true, false, true, true, "Tree.Settings.StatisticsPaused")]
    [InlineData(true, true, true, false, "Tree.Settings.StatisticsPaused")]
    [InlineData(false, false, false, false, "Tree.Settings.StatisticsDisabled")]
    [InlineData(false, true, false, false, "Tree.Settings.StatisticsDisabled")]
    public void ToggleProjectsActualCountingWithoutInvertingItsMeaning(
        bool treeEnabled, bool paused, bool expectedEnabled, bool expectedOn, string expectedOffContent)
    {
        TreeStatisticsState state = TreeStatisticsState.FromProgress(treeEnabled, paused);
        Assert.Equal(expectedEnabled, state.ToggleEnabled);
        Assert.Equal(expectedOn, state.ToggleOn);
        Assert.Equal(expectedOffContent, state.OffContentKey);
    }

    [Theory]
    [InlineData(true, false, 1L)]
    [InlineData(false, true, 0L)]
    public void ToggleOnStartsCountingAndToggleOffPauses(bool isOn, bool expectedPaused, long expectedEnergy)
    {
        var progress = new TreeProgress { Paused = TreeStatisticsState.PausedFromToggle(isOn) };
        progress.AddEnergy(1, new DateTime(2026, 10, 12));

        Assert.Equal(expectedPaused, progress.Paused);
        Assert.Equal(expectedEnergy, progress.TotalEnergy);
        Assert.Equal(isOn, TreeStatisticsState.FromProgress(true, progress.Paused).ToggleOn);
    }

    [Theory]
    [InlineData(true)]
    [InlineData(false)]
    public void ReenablingTreeStartsCountingEvenWhenItWasPreviouslyPaused(bool paused)
    {
        var progress = new TreeProgress { Paused = paused };
        TreeStatisticsState disabled = TreeStatisticsState.FromProgress(false, progress.Paused);
        progress.Paused = false;
        TreeStatisticsState restored = TreeStatisticsState.FromProgress(true, progress.Paused);

        Assert.False(disabled.ToggleOn);
        Assert.False(progress.Paused);
        Assert.True(restored.ToggleOn);
    }

    [Fact]
    public void SettingsEventAndRefreshUseTheSameTestedMapping()
    {
        string source = File.ReadAllText(TestPaths.FromRepository(
            "src/DeskBox/Views/SettingsSections/TreeSettingsSection.cs"));
        Assert.Contains("TreeStatisticsState.PausedFromToggle(", source);
        Assert.Contains("if (_enable.IsOn && !wasEnabled)", source);
        Assert.Contains("_counter.SetPaused(false);", source);
        Assert.Contains("TreeStatisticsState.FromProgress(_enable.IsOn, snapshot.Paused)", source);
        Assert.Contains(".IsEnabled = statistics.ToggleEnabled;", source);
        Assert.Contains(".IsOn = statistics.ToggleOn;", source);
        Assert.Contains("T(statistics.OffContentKey)", source);
        Assert.Contains("_statisticsLabel.Text = _localization.T(\"Tree.Settings.EnableStatistics\")", source);
        Assert.Contains("_statistics.OnContent = _localization.T(\"Tree.Settings.StatisticsRunning\")", source);
        Assert.Contains("if (_updating || !_statistics.IsEnabled) return;", source);
    }

    [Fact]
    public void EveryLanguageHasDistinctCountingAndDisabledLabels()
    {
        string directory = TestPaths.FromRepository("src/DeskBox/Strings");
        foreach (string file in Directory.EnumerateFiles(directory, "*.json"))
        {
            using JsonDocument language = JsonDocument.Parse(File.ReadAllText(file));
            string[] labels = ["EnableStatistics", "StatisticsRunning", "StatisticsPaused", "StatisticsDisabled"];
            var values = labels.Select(label => language.RootElement.GetProperty("Tree.Settings." + label).GetString()).ToArray();
            Assert.All(values, value => Assert.False(string.IsNullOrWhiteSpace(value), file));
            Assert.Equal(labels.Length, values.Distinct(StringComparer.Ordinal).Count());
        }
    }

    [Fact]
    public void ChineseLabelsDescribeThePositiveSwitchAndActualStates()
    {
        using JsonDocument language = JsonDocument.Parse(File.ReadAllText(TestPaths.FromRepository(
            "src/DeskBox/Strings/zh-CN.json")));
        Assert.Equal("启用统计", language.RootElement.GetProperty("Tree.Settings.EnableStatistics").GetString());
        Assert.Equal("正在统计", language.RootElement.GetProperty("Tree.Settings.StatisticsRunning").GetString());
        Assert.Equal("已暂停", language.RootElement.GetProperty("Tree.Settings.StatisticsPaused").GetString());
        Assert.Equal("种树未启动", language.RootElement.GetProperty("Tree.Settings.StatisticsDisabled").GetString());
    }

    [Fact]
    public void TreeSettings_DoesNotRenderSeparateGrowthProgressCard()
    {
        string source = File.ReadAllText(TestPaths.FromRepository(
            "src/DeskBox/Views/SettingsSections/TreeSettingsSection.cs"));
        Assert.DoesNotContain("_progressCard", source, StringComparison.Ordinal);
        Assert.DoesNotContain("_nextStageBar", source, StringComparison.Ordinal);
        Assert.DoesNotContain("_totalGrowthBar", source, StringComparison.Ordinal);
        Assert.DoesNotContain("Tree.Settings.NextStageProgress", source, StringComparison.Ordinal);
        Assert.DoesNotContain("Tree.Settings.TotalGrowthProgress", source, StringComparison.Ordinal);
        Assert.Contains("_growthSummary", source, StringComparison.Ordinal);
    }
}
