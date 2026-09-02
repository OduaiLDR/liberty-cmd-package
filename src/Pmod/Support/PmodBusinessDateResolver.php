<?php

declare(strict_types=1);

namespace Cmd\Reports\Pmod\Support;

final class PmodBusinessDateResolver
{
    /**
     * Slide a date forward off weekends AND US federal holidays.
     *
     * Holidays were missing: this skipped Saturday and Sunday only, while the VBA
     * looped `Do While Weekday(d, vbMonday) > 5 Or IsHoliday(d)` on every date it
     * set (working reference §4.2, §7.5). So a draft could be written to Christmas
     * Day or Thanksgiving. `looksLikeDateRejection()` catches Forth complaining
     * about it afterwards, but that is a retry, not a calendar.
     */
    public static function nextBusinessDay(string $date): string
    {
        $cursor = new \DateTimeImmutable($date);

        while (self::isWeekend($cursor) || self::isHolidayOn($cursor)) {
            $cursor = $cursor->modify('+1 day');
        }

        return $cursor->format('Y-m-d');
    }

    /**
     * Add months without the end-of-month overflow that PHP's `+N month` has:
     * 31 January + 1 month is 3 March, because February has no 31st and PHP rolls
     * over. Every action that extends a schedule was doing exactly that (§7.7), so
     * a client drafting on the 31st got dates drifting into the following month.
     *
     * Clamped to the last day instead, so 31 Jan + 1 month is 28 Feb (29 in a leap
     * year), and the day of month is preserved wherever it exists.
     */
    public static function addMonths(string $date, int $months): string
    {
        $start = new \DateTimeImmutable($date);
        $day = (int) $start->format('j');

        $firstOfTarget = $start->modify('first day of this month')->modify(sprintf('%+d months', $months));
        $daysInTarget = (int) $firstOfTarget->format('t');

        return $firstOfTarget->setDate(
            (int) $firstOfTarget->format('Y'),
            (int) $firstOfTarget->format('n'),
            min($day, $daysInTarget),
        )->format('Y-m-d');
    }

    /** Is this a US federal holiday, by the calendar the legacy bots used? */
    public static function isHoliday(string $date): bool
    {
        return self::isHolidayOn(new \DateTimeImmutable($date));
    }

    /**
     * The eleven holidays `IsHoliday()` covered (`pmodLdr.md` 3904-3968), ported
     * as-is.
     *
     * Deliberately NOT observed-day aware: the VBA did not move a Saturday July 4
     * to the Friday, apart from one hardcoded 2025 case for July 5, which is kept
     * so behaviour matches the bots exactly. Adding observed days would be an
     * improvement on the legacy, not a port, and belongs in its own decision.
     */
    private static function isHolidayOn(\DateTimeImmutable $date): bool
    {
        $year = (int) $date->format('Y');
        $month = (int) $date->format('n');
        $day = (int) $date->format('j');
        $ymd = $date->format('Y-m-d');

        return match ($month) {
            1 => $day === 1 || $ymd === self::nthWeekdayOfMonth($year, 1, 'Monday', 3),
            2 => $ymd === self::nthWeekdayOfMonth($year, 2, 'Monday', 3),
            // "5th Monday" is the VBA's idiom for the LAST Monday - GetDate falls
            // through to the last match when the month has fewer. Memorial Day.
            5 => $ymd === self::nthWeekdayOfMonth($year, 5, 'Monday', 5),
            6 => $day === 19,
            7 => $day === 4 || ($day === 5 && $year === 2025),
            9 => $ymd === self::nthWeekdayOfMonth($year, 9, 'Monday', 1),
            10 => $ymd === self::nthWeekdayOfMonth($year, 10, 'Monday', 2),
            11 => $day === 11 || $ymd === self::nthWeekdayOfMonth($year, 11, 'Thursday', 4),
            12 => $day === 25,
            default => false,
        };
    }

    /**
     * The nth given weekday of a month, or the LAST one when the month holds
     * fewer than n.
     *
     * That fall-through is the whole point, not a quirk to tidy away: the VBA's
     * `GetDate` assigns on every matching weekday and only breaks once it reaches
     * the requested occurrence, so `GetDate(d, "Monday", 5)` reliably means "last
     * Monday" - which is how Memorial Day is expressed.
     */
    private static function nthWeekdayOfMonth(int $year, int $month, string $weekday, int $nth): string
    {
        $cursor = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $daysInMonth = (int) $cursor->format('t');
        $matches = [];

        for ($day = 1; $day <= $daysInMonth; $day++) {
            $candidate = $cursor->setDate($year, $month, $day);

            if ($candidate->format('l') === $weekday) {
                $matches[] = $candidate->format('Y-m-d');
            }
        }

        if ($matches === []) {
            return '';
        }

        return $matches[min($nth, count($matches)) - 1];
    }

    public static function nextDay(string $date): string
    {
        return (new \DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d');
    }

    /**
     * The last process date whose draft must NOT be modified.
     *
     * An ACH debit inside two days is already submitted to the bank, so changing
     * its amount either fails or leaves the bank taking the old figure while our
     * records claim the new one. The legacy VBA refused to touch them: every sub
     * that adjusts a draft amount selects `CDate(col4) > Date + 2`
     * (`pmodLdr.md` 1016, 1718, 1953). Only Reschedule All used a bare
     * `> Date` (820), because it moves dates rather than amounts.
     *
     * Calendar days, matching the VBA exactly — it did not skip weekends here.
     * Callers keep drafts with `process_date > cutoff`.
     */
    public static function draftModificationCutoff(?string $today = null): string
    {
        return (new \DateTimeImmutable($today ?? date('Y-m-d')))->modify('+2 days')->format('Y-m-d');
    }

    public static function looksLikeDateRejection(string $message): bool
    {
        $normalized = strtolower($message);

        foreach (['holiday', 'weekend', 'business day', 'process date', 'draft date', 'invalid date'] as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    private static function isWeekend(\DateTimeImmutable $date): bool
    {
        return in_array((int) $date->format('N'), [6, 7], true);
    }
}
