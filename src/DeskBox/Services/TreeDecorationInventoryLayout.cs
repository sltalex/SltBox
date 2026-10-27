namespace DeskBox.Services;

internal static class TreeDecorationInventoryLayout
{
    internal const int ColumnCount = 4;
    internal const int CardWidth = 146;
    internal const int CardSpacing = 8;
    internal const int RowWidth = ColumnCount * CardWidth + (ColumnCount - 1) * CardSpacing;
    internal const int SettingsCardWidth = 120;
    internal const int SettingsCardSpacing = 4;
    internal const int SettingsRowWidth = ColumnCount * SettingsCardWidth + (ColumnCount - 1) * SettingsCardSpacing;

    internal static int SettingsColumnsForWidth(double availableWidth) =>
        ColumnsForWidth(availableWidth, SettingsCardWidth, SettingsCardSpacing);

    internal static int SettingsRowWidthForColumns(int columns) =>
        RowWidthForColumns(columns, SettingsCardWidth, SettingsCardSpacing);

    internal static int ColumnsForWidth(double availableWidth) =>
        ColumnsForWidth(availableWidth, CardWidth, CardSpacing);

    private static int ColumnsForWidth(double availableWidth, int cardWidth, int spacing)
    {
        if (!double.IsFinite(availableWidth)) return 1;
        if (availableWidth >= ColumnCount * cardWidth + (ColumnCount - 1) * spacing) return ColumnCount;
        return Math.Clamp((int)Math.Floor((availableWidth + spacing) / (cardWidth + spacing)), 1, ColumnCount);
    }

    internal static int RowWidthForColumns(int columns) =>
        RowWidthForColumns(columns, CardWidth, CardSpacing);

    private static int RowWidthForColumns(int columns, int cardWidth, int spacing)
    {
        ArgumentOutOfRangeException.ThrowIfLessThan(columns, 1);
        ArgumentOutOfRangeException.ThrowIfGreaterThan(columns, ColumnCount);
        return columns * cardWidth + (columns - 1) * spacing;
    }

    internal static (int Row, int Column) PositionOf(int index)
    {
        ArgumentOutOfRangeException.ThrowIfNegative(index);
        return (index / ColumnCount, index % ColumnCount);
    }

    internal static (int Row, int Column) PositionOf(int index, int columns)
    {
        ArgumentOutOfRangeException.ThrowIfNegative(index);
        ArgumentOutOfRangeException.ThrowIfLessThan(columns, 1);
        ArgumentOutOfRangeException.ThrowIfGreaterThan(columns, ColumnCount);
        return (index / columns, index % columns);
    }
}
