<?php

namespace Cmd\Reports\Console\Commands\GenerateEnrollmentBonusReport;

/**
 * Business days for the Enrollment Status Report's EOM projection (Jacob, 2026-09-14):
 * weekdays minus four holidays — Thanksgiving, Labor Day, and July 4 / January 1 when they fall
 * on a weekday. No observed-day shifting: a Saturday July 4 simply is not a business day already.
 *
 * Thanksgiving is the FOURTH Thursday of November (the federal holiday, and what the CMD's own
 * IsHoliday uses). Jacob wrote "last Thursday"; the two coincide except in a November with five
 * Thursdays (next: 2028, when the 4th is Nov 23 and the last is Nov 30).
 *
 * This is deliberately NOT the PMOD holiday calendar (11 federal holidays): Jacob listed exactly
 * these four for this calculation.
 */
class BusinessDayCalendar
{
    public static function isBusinessDay(\DateTimeImmutable $date): bool
    {
        return (int) $date->format('N') <= 5 && !self::isHoliday($date);
    }

    public static function isHoliday(\DateTimeImmutable $date): bool
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
