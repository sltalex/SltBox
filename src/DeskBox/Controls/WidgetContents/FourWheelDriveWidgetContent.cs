using System.Globalization;
using DeskBox.Models;
using DeskBox.Services;
using Microsoft.UI.Text;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;

namespace DeskBox.Controls.WidgetContents;

/// <summary>
/// The first local-only four-wheel-drive progression surface. State is kept
/// in the widget metadata so the prototype does not require a server schema.
/// </summary>
public sealed class FourWheelDriveWidgetContent : UserControl
{
    private const string LevelKey = "FourWheelDrive.Level";
    private const string ExperienceKey = "FourWheelDrive.Experience";
    private const string CoinsKey = "FourWheelDrive.Coins";
    private const string EngineKey = "FourWheelDrive.Engine";
    private const string SuspensionKey = "FourWheelDrive.Suspension";
    private const string TiresKey = "FourWheelDrive.Tires";
    private const string DurabilityKey = "FourWheelDrive.Durability";
    private const string LastRaceKey = "FourWheelDrive.LastRace";

    private readonly WidgetConfig _config;
    private readonly LocalizationService _localization;
    private readonly SettingsService? _settings;
    private readonly TextBlock _level = new();
    private readonly TextBlock _power = new();
    private readonly TextBlock _coins = new();
    private readonly TextBlock _experience = new();
    private readonly TextBlock _engine = new();
    private readonly TextBlock _suspension = new();
    private readonly TextBlock _tires = new();
    private readonly TextBlock _durability = new();
    private readonly ProgressBar _durabilityBar = new() { Minimum = 0, Maximum = 100, Height = 8 };
    private readonly TextBlock _raceResult = new() { TextWrapping = TextWrapping.Wrap };
    private readonly Button _raceButton = new();
    private readonly Button _upgradeButton = new();
    private readonly Button _repairButton = new();

    internal FourWheelDriveWidgetContent(
        WidgetConfig config,
        LocalizationService localization,
        SettingsService? settings)
    {
        _config = config;
        _localization = localization;
        _settings = settings;

        EnsureDefaults();
        Content = BuildView();
        Refresh();
    }

    internal void Refresh()
    {
        EnsureDefaults();
        int level = Read(LevelKey, 1, 1, 99);
        int experience = Read(ExperienceKey, 0, 0, int.MaxValue);
        int coins = Read(CoinsKey, 100, 0, int.MaxValue);
        int engine = Read(EngineKey, 1, 1, 99);
        int suspension = Read(SuspensionKey, 1, 1, 99);
        int tires = Read(TiresKey, 1, 1, 99);
        int durability = Read(DurabilityKey, 100, 0, 100);
        int power = CalculatePower(level, engine, suspension, tires);
        int nextLevelExperience = ExperienceForNextLevel(level);

        _level.Text = _localization.Format("FourWheelDrive.LevelValue", level);
        _power.Text = _localization.Format("FourWheelDrive.PowerValue", power);
        _coins.Text = _localization.Format("FourWheelDrive.CoinsValue", coins);
        _experience.Text = _localization.Format("FourWheelDrive.ExperienceValue", experience, nextLevelExperience);
        _engine.Text = _localization.Format("FourWheelDrive.EngineValue", engine);
        _suspension.Text = _localization.Format("FourWheelDrive.SuspensionValue", suspension);
        _tires.Text = _localization.Format("FourWheelDrive.TiresValue", tires);
        _durability.Text = _localization.Format("FourWheelDrive.DurabilityValue", durability);
        _durabilityBar.Value = durability;
        _raceButton.Content = _localization.T("FourWheelDrive.Race");
        _upgradeButton.Content = _localization.Format("FourWheelDrive.UpgradeValue", UpgradeCost(engine));
        _repairButton.Content = _localization.Format("FourWheelDrive.RepairValue", RepairCost(durability));
        _raceButton.IsEnabled = durability >= 10;
        _upgradeButton.IsEnabled = coins >= UpgradeCost(engine);
        _repairButton.IsEnabled = durability < 100 && coins >= RepairCost(durability);

        string lastRace = _config.Metadata.TryGetValue(LastRaceKey, out string? result)
            ? result
            : _localization.T("FourWheelDrive.NoRace");
        _raceResult.Text = $"{_localization.T("FourWheelDrive.LastRace")}: {lastRace}";
    }

    private FrameworkElement BuildView()
    {
        var root = new StackPanel { Spacing = 10, Padding = new Thickness(14) };

        var header = new Grid { ColumnSpacing = 12 };
        header.ColumnDefinitions.Add(new ColumnDefinition { Width = GridLength.Auto });
        header.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(1, GridUnitType.Star) });
        var car = new TextBlock
        {
            Text = "🚙",
            FontSize = 42,
            VerticalAlignment = VerticalAlignment.Center
        };
        Grid.SetColumn(car, 0);
        header.Children.Add(car);

        var title = new StackPanel { VerticalAlignment = VerticalAlignment.Center };
        title.Children.Add(new TextBlock
        {
            Text = _localization.T("FourWheelDrive.Title"),
            FontSize = 20,
            FontWeight = FontWeights.SemiBold
        });
        title.Children.Add(_level);
        Grid.SetColumn(title, 1);
        header.Children.Add(title);
        root.Children.Add(header);

        var summary = new Grid { ColumnSpacing = 14 };
        summary.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(1, GridUnitType.Star) });
        summary.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(1, GridUnitType.Star) });
        Grid.SetColumn(_power, 0);
        Grid.SetColumn(_coins, 1);
        summary.Children.Add(_power);
        summary.Children.Add(_coins);
        root.Children.Add(summary);
        root.Children.Add(_experience);
        root.Children.Add(_durability);
        root.Children.Add(_durabilityBar);

        var stats = new Grid { ColumnSpacing = 12, RowSpacing = 4 };
        stats.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(1, GridUnitType.Star) });
        stats.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(1, GridUnitType.Star) });
        AddStat(stats, _engine, 0, 0);
        AddStat(stats, _suspension, 1, 0);
        AddStat(stats, _tires, 0, 1);
        var localSave = new TextBlock
        {
            Text = _localization.T("FourWheelDrive.LocalSave"),
            Opacity = 0.7,
            TextWrapping = TextWrapping.Wrap
        };
        Grid.SetColumn(localSave, 1);
        Grid.SetRow(localSave, 1);
        stats.Children.Add(localSave);
        root.Children.Add(stats);

        var actions = new StackPanel { Orientation = Orientation.Horizontal, Spacing = 8 };
        _raceButton.Click += (_, _) => Race();
        _upgradeButton.Click += (_, _) => UpgradeEngine();
        _repairButton.Click += (_, _) => Repair();
        actions.Children.Add(_raceButton);
        actions.Children.Add(_upgradeButton);
        actions.Children.Add(_repairButton);
        root.Children.Add(actions);
        root.Children.Add(_raceResult);
        return root;
    }

    private static void AddStat(Grid grid, TextBlock text, int column, int row)
    {
        Grid.SetColumn(text, column);
        Grid.SetRow(text, row);
        grid.Children.Add(text);
    }

    private void Race()
    {
        int durability = Read(DurabilityKey, 100, 0, 100);
        if (durability < 10) return;

        int level = Read(LevelKey, 1, 1, 99);
        int engine = Read(EngineKey, 1, 1, 99);
        int suspension = Read(SuspensionKey, 1, 1, 99);
        int tires = Read(TiresKey, 1, 1, 99);
        int power = CalculatePower(level, engine, suspension, tires);
        int opponent = Random.Shared.Next(28, 76);
        bool won = power + Random.Shared.Next(-8, 9) >= opponent;
        int reward = won ? 30 + level * 5 : 8;
        int experience = won ? 28 : 12;
        int coins = Read(CoinsKey, 100, 0, int.MaxValue);

        Set(DurabilityKey, durability - 10);
        Set(CoinsKey, coins + reward);
        AddExperience(experience);
        _config.Metadata[LastRaceKey] = won
            ? _localization.Format("FourWheelDrive.RaceWin", reward, experience)
            : _localization.Format("FourWheelDrive.RaceLoss", reward, experience);
        Save();
        Refresh();
    }

    private void UpgradeEngine()
    {
        int engine = Read(EngineKey, 1, 1, 99);
        int cost = UpgradeCost(engine);
        int coins = Read(CoinsKey, 100, 0, int.MaxValue);
        if (coins < cost) return;

        Set(CoinsKey, coins - cost);
        Set(EngineKey, engine + 1);
        _config.Metadata[LastRaceKey] = _localization.Format("FourWheelDrive.EngineUpgraded", engine + 1);
        Save();
        Refresh();
    }

    private void Repair()
    {
        int durability = Read(DurabilityKey, 100, 0, 100);
        int cost = RepairCost(durability);
        int coins = Read(CoinsKey, 100, 0, int.MaxValue);
        if (durability >= 100 || coins < cost) return;

        Set(CoinsKey, coins - cost);
        Set(DurabilityKey, 100);
        _config.Metadata[LastRaceKey] = _localization.T("FourWheelDrive.Repaired");
        Save();
        Refresh();
    }

    private void AddExperience(int amount)
    {
        int level = Read(LevelKey, 1, 1, 99);
        int experience = Read(ExperienceKey, 0, 0, int.MaxValue) + amount;
        while (level < 99 && experience >= ExperienceForNextLevel(level))
        {
            experience -= ExperienceForNextLevel(level);
            level++;
        }

        Set(LevelKey, level);
        Set(ExperienceKey, experience);
    }

    private void EnsureDefaults()
    {
        SetIfMissing(LevelKey, 1);
        SetIfMissing(ExperienceKey, 0);
        SetIfMissing(CoinsKey, 100);
        SetIfMissing(EngineKey, 1);
        SetIfMissing(SuspensionKey, 1);
        SetIfMissing(TiresKey, 1);
        SetIfMissing(DurabilityKey, 100);
    }

    private void SetIfMissing(string key, int value)
    {
        if (!_config.Metadata.ContainsKey(key)) Set(key, value);
    }

    private int Read(string key, int fallback, int minimum, int maximum)
    {
        return _config.Metadata.TryGetValue(key, out string? raw) &&
               int.TryParse(raw, NumberStyles.Integer, CultureInfo.InvariantCulture, out int value)
            ? Math.Clamp(value, minimum, maximum)
            : fallback;
    }

    private void Set(string key, int value)
    {
        _config.Metadata[key] = value.ToString(CultureInfo.InvariantCulture);
    }

    private void Save() => _settings?.SaveDebounced();

    private static int CalculatePower(int level, int engine, int suspension, int tires)
        => level * 5 + engine * 12 + suspension * 8 + tires * 7;

    private static int ExperienceForNextLevel(int level) => 80 + level * 35;

    private static int UpgradeCost(int engine) => 45 + engine * 30;

    private static int RepairCost(int durability)
        => durability >= 100 ? 0 : Math.Max(8, (100 - durability) / 2);
}
