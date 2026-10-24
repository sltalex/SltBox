using DeskBox.Services;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
using Microsoft.UI.Xaml.Media;

namespace DeskBox.Views.SettingsSections;

public sealed partial class FruitMarketSection : UserControl
{
    private FruitService Service => App.Current.FruitService;
    public FruitMarketSection()
    {
        InitializeComponent();
        Loaded += (_, _) => { Service.Changed += Refresh; _ = Service.RefreshAsync(); Refresh(); };
        Unloaded += (_, _) => Service.Changed -= Refresh;
    }

    private void Refresh()
    {
        DispatcherQueue.TryEnqueue(() =>
        {
            FruitCards.Children.Clear();
            var catalog = Service.Catalog.ToDictionary(x => x.Id, StringComparer.Ordinal);
            foreach (var owned in Service.Inventory.Where(x => x.Quantity > 0))
            {
                if (!catalog.TryGetValue(owned.FruitId, out FruitCatalogItem? fruit)) continue;
                var card = new StackPanel { Spacing = 4 };
                var imageAndActions = new StackPanel { Orientation = Orientation.Horizontal, Spacing = 8 };
                imageAndActions.Children.Add(new Image
                {
                    Source = FruitAssetCatalog.GetImageSource(fruit.Id), Width = 62, Height = 62,
                    Stretch = Stretch.Uniform
                });
                var actions = new StackPanel { Spacing = 6, VerticalAlignment = VerticalAlignment.Center };
                var customRow = new StackPanel { Orientation = Orientation.Horizontal, Spacing = 4 };
                var amount = new NumberBox
                {
                    Minimum = 1, Maximum = owned.Quantity, Value = 1, SmallChange = 1,
                    SpinButtonPlacementMode = NumberBoxSpinButtonPlacementMode.Hidden,
                    PlaceholderText = "数量", Width = 58, MinWidth = 0
                };
                Microsoft.UI.Xaml.Automation.AutomationProperties.SetName(amount, $"{fruit.Name}出售数量");
                var custom = new Button { Content = "卖", Width = 48, MinWidth = 0, IsEnabled = Service.IsAvailable };
                var all = new Button { Content = "全卖", Width = 110, MinWidth = 0, IsEnabled = Service.IsAvailable };
                async Task SellAsync(int quantity)
                {
                    all.IsEnabled = custom.IsEnabled = amount.IsEnabled = false;
                    try
                    {
                        if (!await Service.SellAsync(fruit.Id, quantity)) Status.Text = "出售失败，请稍后重试。";
                    }
                    catch (Exception ex) when (ex is HttpRequestException or TaskCanceledException)
                    {
                        Status.Text = "网络暂不可用，请重试。";
                    }
                    finally { all.IsEnabled = custom.IsEnabled = amount.IsEnabled = Service.IsAvailable; }
                }
                all.Click += async (_, _) => await SellAsync(owned.Quantity);
                custom.Click += async (_, _) =>
                {
                    double value = amount.Value;
                    if (!double.IsFinite(value) || value != Math.Truncate(value) ||
                        value < 1 || value > owned.Quantity)
                    {
                        Status.Text = $"请输入 1 到 {owned.Quantity} 的整数数量。";
                        return;
                    }
                    await SellAsync((int)value);
                };
                customRow.Children.Add(amount); customRow.Children.Add(custom);
                actions.Children.Add(customRow); actions.Children.Add(all);
                imageAndActions.Children.Add(actions); card.Children.Add(imageAndActions);
                card.Children.Add(new TextBlock { Text = fruit.Name, HorizontalAlignment = HorizontalAlignment.Center, TextTrimming = TextTrimming.CharacterEllipsis });
                card.Children.Add(new TextBlock { Text = $"今日 {fruit.CurrentPrice} 金币 · 数量 {owned.Quantity}", FontSize = 11, Opacity = .75, TextWrapping = TextWrapping.Wrap });
                var history = Service.PriceHistory.TryGetValue(fruit.Id, out var points) ? string.Join("  ", points.TakeLast(7).Select(x => x.Price)) : "暂无历史";
                card.Children.Add(new TextBlock { Text = $"7日：{history}", FontSize = 10, Opacity = .65, TextWrapping = TextWrapping.Wrap });
                FruitCards.Children.Add(new Border { Width = 210, MinHeight = 160, Padding = new Thickness(8), CornerRadius = new CornerRadius(10), Background = Application.Current.Resources["CardBackgroundFillColorDefaultBrush"] as Brush, Child = card });
            }
            Status.Text = FruitCards.Children.Count == 0 ? "背包中暂无可出售的分类果实。" : "";
        });
    }
}
