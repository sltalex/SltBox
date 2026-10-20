using System.Globalization;
using Microsoft.UI.Xaml.Data;

namespace DeskBox.Controls;

/// <summary>
/// Stacks Chinese text one grapheme per line for vertical tab labels while
/// keeping consecutive digits together. This keeps labels such as "第12阶段"
/// compact instead of stretching the side strip one digit at a time.
/// </summary>
public sealed partial class VerticalTextConverter : IValueConverter
{
    public object Convert(object value, Type targetType, object parameter, string language)
    {
        string text = value?.ToString()?.Trim() ?? string.Empty;
        if (text.Length == 0) return string.Empty;

        var elements = new List<string>();
        TextElementEnumerator enumerator = StringInfo.GetTextElementEnumerator(text);
        string? pendingDigits = null;
        while (enumerator.MoveNext())
        {
            string element = enumerator.GetTextElement();
            if (string.IsNullOrWhiteSpace(element))
            {
                continue;
            }

            // Keep an uninterrupted run of ASCII/full-width digits on one
            // line. Other text remains one grapheme per line.
            if (element.Length == 1 && char.IsDigit(element[0]))
            {
                pendingDigits += element;
                continue;
            }

            if (pendingDigits is not null)
            {
                elements.Add(pendingDigits);
                pendingDigits = null;
            }

            elements.Add(element);
        }

        if (pendingDigits is not null)
        {
            elements.Add(pendingDigits);
        }

        return string.Join("\n", elements);
    }

    public object ConvertBack(object value, Type targetType, object parameter, string language) =>
        throw new NotSupportedException();
}
