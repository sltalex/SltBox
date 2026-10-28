using DeskBox.Models;
using System.Globalization;

namespace DeskBox.Services;

/// <summary>Persisted presentation settings for the DingTalk group-document widget.</summary>
internal static class DingTalkGroupDocsPresentationSettings
{
    internal const string PageSizeMetadataKey = "DingTalkGroupDocs.PageSize";
    internal const int DefaultPageSize = 5;
    internal const int MinPageSize = 1;
    internal const int MaxPageSize = 50;

    internal static int ReadPageSize(WidgetConfig? widget)
    {
        return widget?.Metadata is { } metadata &&
            metadata.TryGetValue(PageSizeMetadataKey, out string? value) &&
            int.TryParse(value, NumberStyles.Integer, CultureInfo.InvariantCulture, out int pageSize)
            ? NormalizePageSize(pageSize)
            : DefaultPageSize;
    }

    internal static int NormalizePageSize(double value) =>
        NormalizePageSize((int)Math.Round(value, MidpointRounding.AwayFromZero));

    internal static int NormalizePageSize(int value) =>
        Math.Clamp(value, MinPageSize, MaxPageSize);

    internal static void WritePageSize(WidgetConfig widget, double value)
    {
        widget.Metadata ??= [];
        widget.Metadata[PageSizeMetadataKey] =
            NormalizePageSize(value).ToString(CultureInfo.InvariantCulture);
    }
}
