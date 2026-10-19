using DeskBox.Services;

namespace DeskBox.Tests;

public sealed class CalendarHolidayServiceTests
{
    [Theory]
    [InlineData(2023, 6, 22, "Calendar.Holiday.DragonBoat")]
    [InlineData(2023, 9, 29, "Calendar.Holiday.MidAutumn")]
    [InlineData(2025, 10, 6, "Calendar.Holiday.MidAutumn")]
    [InlineData(1901, 4, 5, "Calendar.Holiday.Qingming")]
    public void GetHolidays_UsesCivilLunarMonthsAndOnlyRequestedYear(int year, int month, int day, string key)
    {
        var holidays = CalendarHolidayService.GetHolidays(year);
        Assert.Contains(holidays, item => item.Date == new DateOnly(year, month, day) && item.NameKey == key);
        Assert.All(holidays, item => Assert.Equal(year, item.Date.Year));
    }

    [Fact]
    public void GetHolidays_ContainsFixedAndLunarHolidayDatesInOrder()
    {
        IReadOnlyList<CalendarHoliday> holidays = CalendarHolidayService.GetHolidays(2026);

        Assert.Contains(holidays, item => item.Date == new DateOnly(2026, 1, 1));
        Assert.Contains(holidays, item => item.Date == new DateOnly(2026, 4, 5));
        Assert.Contains(holidays, item => item.Date == new DateOnly(2026, 5, 1));
        Assert.Contains(holidays, item => item.Date == new DateOnly(2026, 2, 17));
        Assert.Equal(holidays.OrderBy(item => item.Date).Select(item => item.Date), holidays.Select(item => item.Date));
        Assert.Equal(holidays.Select(item => item.Date).Distinct().Count(), holidays.Count);
    }

    [Fact]
    public void GetNextHoliday_ReturnsTheNextDateAndSupportsYearBoundary()
    {
        CalendarHoliday? next = CalendarHolidayService.GetNextHoliday(new DateOnly(2026, 9, 30));
        Assert.NotNull(next);
        Assert.Equal(new DateOnly(2026, 10, 1), next.Date);

        CalendarHoliday? nextYear = CalendarHolidayService.GetNextHoliday(new DateOnly(2026, 10, 4));
        Assert.NotNull(nextYear);
        Assert.Equal(new DateOnly(2027, 1, 1), nextYear.Date);
    }
}
