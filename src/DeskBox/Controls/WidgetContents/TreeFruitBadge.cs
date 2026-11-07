using Microsoft.UI;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
using Microsoft.UI.Xaml.Media;

namespace DeskBox.Controls.WidgetContents;

internal static class TreeFruitBadge
{
    internal static Grid Create(ImageSource source, long quantity)
    {
        var visual = new Grid { Width = 24, Height = 28, IsHitTestVisible = false };
        visual.Children.Add(new Image { Source = source, Stretch = Stretch.Uniform });
        visual.Children.Add(new Border
        {
            Background = new SolidColorBrush(ColorHelper.FromArgb(210, 35, 64, 35)),
            CornerRadius = new CornerRadius(4), Padding = new Thickness(2, 0, 2, 0),
            HorizontalAlignment = HorizontalAlignment.Center, VerticalAlignment = VerticalAlignment.Bottom,
            Margin = new Thickness(-16, 0, -16, -7),
            Child = new TextBlock { Text = $"×{quantity}", FontSize = 12,
                Foreground = new SolidColorBrush(Colors.White), TextWrapping = TextWrapping.NoWrap }
        });
        return visual;
    }
}
