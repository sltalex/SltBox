using Microsoft.UI;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
using Microsoft.UI.Xaml.Media;

namespace DeskBox.Controls.WidgetContents;

internal static class TreeFruitBadge
{
    internal static Image Create(ImageSource source) => new()
    {
        Width = 24,
        Height = 28,
        Source = source,
        Stretch = Stretch.Uniform,
        IsHitTestVisible = false
    };

    internal static Grid CreateForVisit(ImageSource source, long quantity)
    {
        var visual = new Grid { Width = 24, Height = 36, IsHitTestVisible = false };
        visual.Children.Add(new Image
        {
            Width = 24, Height = 28, Source = source, Stretch = Stretch.Uniform,
            VerticalAlignment = VerticalAlignment.Top
        });
        visual.Children.Add(new Border
        {
            Background = new SolidColorBrush(ColorHelper.FromArgb(205, 35, 64, 35)),
            CornerRadius = new CornerRadius(3), Padding = new Thickness(1, 0, 1, 0),
            HorizontalAlignment = HorizontalAlignment.Center, VerticalAlignment = VerticalAlignment.Bottom,
            Margin = new Thickness(0, 0, 0, 2),
            Child = new TextBlock
            {
                Text = $"{quantity}", FontSize = 6, LineHeight = 7,
                Foreground = new SolidColorBrush(Colors.White), TextWrapping = TextWrapping.NoWrap
            }
        });
        return visual;
    }
}
