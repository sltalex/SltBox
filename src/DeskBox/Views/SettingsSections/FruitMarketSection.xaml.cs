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
                card.Children.Add(new Image { Source = FruitAssetCatalog.GetImageSource(fruit.Id), Width = 62, Height = 62, Stretch = Stretch.Uniform });
                card.Children.Add(new TextBlock { Text = fruit.Name, HorizontalAlignment = Microsoft.UI.Xaml.HorizontalAlignment.Center, TextTrimming = Microsoft.UI.Xaml.TextTrimming.CharacterEllipsis });
                card.Children.Add(new TextBlock { Text = $"今日 {fruit.CurrentPrice} 金币 · 数量 {owned.Quantity}", FontSize = 11, Opacity = .75, TextWrapping = Microsoft.UI.Xaml.TextWrapping.Wrap });
                var history = Service.PriceHistory.TryGetValue(fruit.Id, out var points) ? string.Join("  ", points.TakeLast(7).Select(x => x.Price)) : "暂无历史";
                card.Children.Add(new TextBlock { Text = $"7日：{history}", FontSize = 10, Opacity = .65, TextWrapping = Microsoft.UI.Xaml.TextWrapping.Wrap });
                var buttons = new StackPanel { Orientation = Orientation.Horizontal, Spacing = 4 };
                var one = new Button { Content = "卖 1", IsEnabled = Service.IsAvailable };
                var all = new Button { Content = "全部出售", IsEnabled = Service.IsAvailable };
                async Task SellAsync(int quantity)
                {
                    one.IsEnabled = all.IsEnabled = false;
                    try
                    {
                        if (!await Service.SellAsync(fruit.Id, quantity)) Status.Text = "出售失败，请稍后重试。";
                    }
                    catch (Exception ex) when (ex is HttpRequestException or TaskCanceledException)
                    {
                        Status.Text = "网络暂不可用，请重试。";
                    }
                    finally { one.IsEnabled = all.IsEnabled = Service.IsAvailable; }
                }
                one.Click += async (_, _) => await SellAsync(1);
                all.Click += async (_, _) => await SellAsync(owned.Quantity);
                buttons.Children.Add(one); buttons.Children.Add(all); card.Children.Add(buttons);
                FruitCards.Children.Add(new Border { Width = 156, MinHeight = 182, Padding = new Thickness(8), CornerRadius = new CornerRadius(10), Background = Application.Current.Resources["CardBackgroundFillColorDefaultBrush"] as Brush, Child = card });
            }
            Status.Text = FruitCards.Children.Count == 0 ? "背包中暂无可出售的分类果实。" : "";
        });
    }
}
