using Microsoft.UI.Xaml.Media.Imaging;

namespace DeskBox.Services;

internal static class TreeDecorationAssetCatalog
{
    private const string Root = "ms-appx:///Assets/Tree/Decorations";

    internal static SvgImageSource Source(string assetKey) =>
        new(new Uri($"{Root}/Source/{assetKey}.svg"));

    internal static SvgImageSource Silhouette(string assetKey) =>
        new(new Uri($"{Root}/Blur/{assetKey}.svg"));

    internal static double VisualSize(string category) => category switch
    {
        "large" => 80,
        "ground" => 56,
        _ => 50
    };
}
