using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Media;
using Windows.UI;

namespace DeskBox.Helpers;

/// <summary>
/// Keeps text legible inside a widget's locally light material surfaces.
/// A widget can use a light custom foreground, while a search field, note or
/// card still needs a dark text palette when its own background is light.
/// </summary>
public static class WidgetSurfaceForegroundScope
{
    private static readonly Color DarkText = Color.FromArgb(255, 48, 48, 46);

    /// <summary>
    /// The hosting widget publishes its resolved foreground here so nested
    /// note/todo cards can preserve an explicit per-widget color. A fully
    /// transparent value means that no host palette has been published yet.
    /// </summary>
    public static readonly DependencyProperty PreferredForegroundColorProperty =
        DependencyProperty.RegisterAttached(
            "PreferredForegroundColor",
            typeof(Color),
            typeof(WidgetSurfaceForegroundScope),
            new PropertyMetadata(default(Color)));

    public static void SetPreferredForegroundColor(DependencyObject element, Color value) =>
        element.SetValue(PreferredForegroundColorProperty, value);

    public static void ClearPreferredForegroundColor(DependencyObject element) =>
        element.ClearValue(PreferredForegroundColorProperty);

    public static void ApplyForBackground(
        FrameworkElement surface,
        Brush? background,
        bool respectPreferredForeground = false)
    {
        ArgumentNullException.ThrowIfNull(surface);
        if (background is SolidColorBrush solid && IsLight(solid.Color))
        {
            ForegroundResourceScope.Apply(
                surface,
                respectPreferredForeground
                    ? FindPreferredForegroundColor(surface) ?? DarkText
                    : DarkText);
            return;
        }
        ForegroundResourceScope.Clear(surface);
    }

    private static Color? FindPreferredForegroundColor(FrameworkElement surface)
    {
        DependencyObject? current = surface;
        while (current is not null)
        {
            Color value = (Color)current.GetValue(PreferredForegroundColorProperty);
            if (value.A != 0)
            {
                return value;
            }

            current = (current as FrameworkElement)?.Parent;
        }

        return null;
    }

    private static bool IsLight(Color color)
    {
        // Alpha-blended cards still read as light when their RGB value is
        // light; this keeps paper/sticky-note materials legible as well.
        double channel(byte value) => value / 255.0 <= .04045
            ? value / 255.0 / 12.92
            : Math.Pow((value / 255.0 + .055) / 1.055, 2.4);
        return .2126 * channel(color.R) + .7152 * channel(color.G) +
               .0722 * channel(color.B) > .42;
    }
}
