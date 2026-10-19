using System.Globalization;

namespace DeskBox.Services;

public sealed record CalendarHoliday(DateOnly Date, string NameKey);

/// <summary>Provides Chinese festival dates, not annual adjusted workday or vacation schedules.</summary>
public static class CalendarHolidayService
{
    private static readonly ChineseLunisolarCalendar ChineseCalendar = new();

    public static IReadOnlyList<CalendarHoliday> GetHolidays(int year)
    {
        if (year is < 1901 or > 2099)
        {
            return [];
        }

        var holidays = new List<CalendarHoliday>(16);
        Add(holidays, new DateOnly(year, 1, 1), "Calendar.Holiday.NewYear");
        Add(holidays, new DateOnly(year, 5, 1), "Calendar.Holiday.LaborDay");
        Add(holidays, new DateOnly(year, 10, 1), "Calendar.Holiday.NationalDay");
        Add(holidays, new DateOnly(year, 10, 2), "Calendar.Holiday.NationalDay");
        Add(holidays, new DateOnly(year, 10, 3), "Calendar.Holiday.NationalDay");

        double centuryConstant = year < 2000 ? 5.59 : 4.81;
        int qingmingDay = (int)Math.Floor((year % 100) * 0.2422 + centuryConstant) - (year % 100) / 4;
        Add(holidays, new DateOnly(year, 4, qingmingDay), "Calendar.Holiday.Qingming");

        foreach (int lunarYear in new[] { year - 1, year })
        {
            AddLunar(holidays, lunarYear, 1, 1, "Calendar.Holiday.SpringFestival");
            AddLunar(holidays, lunarYear, 1, 2, "Calendar.Holiday.SpringFestival");
            AddLunar(holidays, lunarYear, 1, 3, "Calendar.Holiday.SpringFestival");
            AddLunar(holidays, lunarYear, 5, 5, "Calendar.Holiday.DragonBoat");
            AddLunar(holidays, lunarYear, 8, 15, "Calendar.Holiday.MidAutumn");
        }

        return holidays
            .Where(item => item.Date.Year == year)
            .GroupBy(item => item.Date)
            .Select(group => group.First())
            .OrderBy(item => item.Date)
            .ToArray();
    }

    public static CalendarHoliday? GetNextHoliday(DateOnly from)
    {
        for (int year = from.Year; year <= from.Year + 2; year++)
        {
            CalendarHoliday? next = GetHolidays(year).FirstOrDefault(item => item.Date >= from);
            if (next is not null)
            {
                return next;
            }
        }

        return null;
    }

    private static void Add(List<CalendarHoliday> holidays, DateOnly date, string nameKey)
    {
        holidays.Add(new CalendarHoliday(date, nameKey));
    }

    private static void AddLunar(
        List<CalendarHoliday> holidays,
        int lunarYear,
        int month,
        int day,
        string nameKey)
    {
        try
        {
            int leapMonth = ChineseCalendar.GetLeapMonth(lunarYear);
            int calendarMonth = leapMonth > 0 && month >= leapMonth ? month + 1 : month;
            DateTime date = ChineseCalendar.ToDateTime(lunarYear, calendarMonth, day, 0, 0, 0, 0);
            if (date.Year >= 1901 && date.Year <= 2099)
            {
                Add(holidays, DateOnly.FromDateTime(date), nameKey);
            }
        }
        catch (ArgumentOutOfRangeException)
        {
            // The calendar's supported range is narrower than DateOnly's range.
        }
    }
}
