<?php

namespace Cmd\Reports\Console\Commands\GenerateEnrollmentBonusReport;

use Cmd\Reports\Services\DBConnector;
use Illuminate\Support\Facades\Log;

/**
 * Business days for the Enrollment Status Report's EOM projection (Jacob, 2026-09-14).
 *
 * Two sources, in order:
 *
 *  1. **`dbo.TblCompanyHolidays`** — the company's own closure calendar, which CT maintain several
 *     years out (Jacob, 2026-09-15). Loaded once per run with `loadFromDatabase()` and applied as an
 *     exact date match. This is the authority when it covers the year being counted.
 *  2. **The built-in four** — Thanksgiving, Labor Day, July 4 and January 1, the list Jacob gave on
 *     2026-09-14 before the calendar existed. Used for any year the table does not cover.
 *
 * **The fallback is per YEAR, deliberately.** A table that stops at 2030 must not make every weekday
 * in 2031 a business day — that would silently inflate the month's divisor and depress the EOM
 * projection. A year with no rows falls back to the built-in rule and the report says so.
 *
 * No observed-day shifting: a Saturday July 4 is already not a business day, and nothing is moved to
 * the Friday. Thanksgiving is the FOURTH Thursday of November (the federal holiday, and what the
 * CMD's own IsHoliday uses); Jacob wrote "last Thursday", which differs only when November has five
 * (next: 2028, the 4th is Nov 23 and the last is Nov 30).
 */
class BusinessDayCalendar
{
    public const TABLE = 'dbo.TblCompanyHolidays';

    /** @var array<string, true> Y-m-d => true, from the table. */
    private static array $holidayDates = [];

    /** @var array<int, true> Years the table actually covers; only these bypass the built-in rule. */
    private static array $loadedYears = [];

    /**
     * Reads the company holiday calendar for the run. Safe to call when the table does not exist or
     * is empty: it reports that and leaves the built-in rule in place, rather than treating "no rows"
     * as "no holidays".
     *
     * @return array{loaded: int, years: list<int>, error: ?string}
     */
    public static function loadFromDatabase(DBConnector $sql): array
    {
        self::reset();

        $result = $sql->querySqlServer(
            'SELECT CONVERT(varchar(10), Holiday_Date, 23) AS Holiday_Date FROM ' . self::TABLE . ' ORDER BY Holiday_Date'
        );

        if (($result['success'] ?? false) !== true) {
            $error = $result['error'] ?? 'unknown error';
            Log::warning('BusinessDayCalendar: company holiday calendar unavailable; using the built-in holidays.', ['error' => $error]);

            return ['loaded' => 0, 'years' => [], 'error' => $error];
        }

        foreach ($result['data'] ?? [] as $row) {
            $date = trim((string) ($row['Holiday_Date'] ?? ''));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                continue;
            }
            self::$holidayDates[$date] = true;
            self::$loadedYears[(int) substr($date, 0, 4)] = true;
        }

        $years = array_keys(self::$loadedYears);
        sort($years);

        return ['loaded' => count(self::$holidayDates), 'years' => $years, 'error' => null];
    }

    /** Forgets anything loaded from the table (tests, and the start of every load). */
    public static function reset(): void
    {
        self::$holidayDates = [];
        self::$loadedYears = [];
    }

    /** @return list<int> Years the loaded calendar covers. */
    public static function loadedYears(): array
    {
        $years = array_keys(self::$loadedYears);
        sort($years);

        return $years;
    }

    public static function isBusinessDay(\DateTimeImmutable $date): bool
    {
        return (int) $date->format('N') <= 5 && !self::isHoliday($date);
    }

    public static function isHoliday(\DateTimeImmutable $date): bool
    {
        if (isset(self::$loadedYears[(int) $date->format('Y')])) {
            return isset(self::$holidayDates[$date->format('Y-m-d')]);
        }

        return self::isBuiltInHoliday($date);
    }

    /** The pre-calendar rule (Jacob, 2026-09-14), still used for any year the table does not cover. */
    public static function isBuiltInHoliday(\DateTimeImmutable $date): bool
    {
        $month = (int) $date->format('n');
        $day = (int) $date->format('j');

        return match (true) {
            $month === 1 && $day === 1 => true,                                           // New Year's Day
            $month === 7 && $day === 4 => true,                                           // Independence Day
            $month === 9 && $day === self::nthWeekdayOfMonth($date, 1, 1) => true,        // Labor Day: 1st Monday
            $month === 11 && $day === self::nthWeekdayOfMonth($date, 4, 4) => true,       // Thanksgiving: 4th Thursday
            default => false,
        };
    }

    /**
     * Business days in [$from, $to], both inclusive (Y-m-d). Zero when $to < $from.
     */
    public static function countBetween(string $from, string $to): int
    {
        $cursor = new \DateTimeImmutable($from);
        $end = new \DateTimeImmutable($to);
        $count = 0;
        while ($cursor <= $end) {
            if (self::isBusinessDay($cursor)) {
                $count++;
            }
            $cursor = $cursor->modify('+1 day');
        }

        return $count;
    }

    /**
     * Business days in the calendar month containing $date (Y-m-d).
     */
    public static function countInMonth(string $date): int
    {
        $start = (new \DateTimeImmutable($date))->modify('first day of this month');

        return self::countBetween($start->format('Y-m-d'), $start->modify('last day of this month')->format('Y-m-d'));
    }

    /**
     * Day-of-month of the Nth given ISO weekday (1 = Monday … 7 = Sunday) in $date's month.
     */
    private static function nthWeekdayOfMonth(\DateTimeImmutable $date, int $isoWeekday, int $nth): int
    {
        $first = $date->modify('first day of this month');
        $offset = ($isoWeekday - (int) $first->format('N') + 7) % 7;

        return 1 + $offset + ($nth - 1) * 7;
    }
}
