using DeskBox.Controls;

namespace DeskBox.Tests;

public sealed class VerticalTextConverterTests
{
    [Theory]
    [InlineData("随记", "随\n记")]
    [InlineData("第12阶段", "第\n12\n阶\n段")]
    [InlineData("今天123", "今\n天\n123")]
    [InlineData("123", "123")]
    [InlineData("待办１２项", "待\n办\n１２\n项")]
    [InlineData("12条34项", "12\n条\n34\n项")]
    [InlineData("", "")]
    public void Convert_PreservesConsecutiveDigits(string text, string expected)
    {
        var converter = new VerticalTextConverter();
        Assert.Equal(expected, converter.Convert(text, typeof(string), null!, "zh-CN"));
    }
}
