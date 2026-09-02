<?php

declare(strict_types=1);

namespace Cmd\Reports\Pmod\Support;

final class PmodBusinessDateResolver
{
    public static function nextBusinessDay(string $date): string
    {
        $cursor = new \DateTimeImmutable($date);

        while (self::isWeekend($cursor)) {
            $cursor = $cursor->modify('+1 day');
        }

        return $cursor->format('Y-m-d');
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
