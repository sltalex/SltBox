using DeskBox.Models;
using DeskBox.Services;

namespace DeskBox.Tests;

public sealed class DingTalkGroupDocsPresentationSettingsTests
{
    [Fact]
    public void MissingPageSize_UsesTheExistingFiveRowDefault()
    {
        Assert.Equal(5, DingTalkGroupDocsPresentationSettings.ReadPageSize(new WidgetConfig()));
    }

    [Theory]
    [InlineData("1", 1)]
    [InlineData("12", 12)]
    [InlineData("0", 1)]
    [InlineData("999", 50)]
    [InlineData("invalid", 5)]
    public void ReadPageSize_NormalizesPersistedValues(string value, int expected)
    {
        var widget = new WidgetConfig();
        widget.Metadata[DingTalkGroupDocsPresentationSettings.PageSizeMetadataKey] = value;

        Assert.Equal(expected, DingTalkGroupDocsPresentationSettings.ReadPageSize(widget));
    }

    [Fact]
    public void WritePageSize_PersistsAnInvariantInteger()
    {
        var widget = new WidgetConfig();

        DingTalkGroupDocsPresentationSettings.WritePageSize(widget, 12.6);

        Assert.Equal("13", widget.Metadata[DingTalkGroupDocsPresentationSettings.PageSizeMetadataKey]);
        Assert.Equal(13, DingTalkGroupDocsPresentationSettings.ReadPageSize(widget));
    }
}
