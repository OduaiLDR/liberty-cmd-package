<?php

declare(strict_types=1);

namespace Cmd\Reports\Console\Commands\GenerateLendingTowerInvoices;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** LDR bills on the fifth using data through the fourth, with a four-month cohort lookback. */
final class LdrInvoiceWindow
{
    public const FIRST_BILLING_MONTH = '2026-10';

    public static function forMonth(string $month): array
    {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $month)) {
            throw new InvalidArgumentException('LDR billing month must be YYYY-MM.');
        }
        $start = new DateTimeImmutable($month . '-01', new DateTimeZone('America/Los_Angeles'));
        $end = $start->modify('+1 month');
        $cohort = $start->modify('-3 months');

        return [
            'start' => $start->format('Y-m-d'),
            'end' => $end->format('Y-m-d'),
            'invoice_date' => $end->modify('+4 days')->format('Y-m-d'),
            'cleared_before' => $end->modify('+4 days')->format('Y-m-d'),
            'cancelled_before' => $end->modify('+4 days')->format('Y-m-d'),
            'lookback_enabled' => $cohort->format('Y-m') >= self::FIRST_BILLING_MONTH,
            'lookback_month' => $cohort->format('Y-m'),
            'lookback_start' => $cohort->format('Y-m-d'),
            'lookback_end' => $cohort->modify('+1 month')->format('Y-m-d'),
        ];
    }

    /** SQL DATEADD(MONTH, 3, date) semantics: clamp a missing target day to month end. */
    public static function paymentCutoff(string $firstCleared): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $firstCleared, new DateTimeZone('America/Los_Angeles'));
        if ($date === false || $date->format('Y-m-d') !== $firstCleared) {
            throw new InvalidArgumentException('First cleared payment must be a valid YYYY-MM-DD date.');
        }
        $target = $date->modify('first day of this month')->modify('+3 months');
        return $target->setDate((int) $target->format('Y'), (int) $target->format('m'),
            min((int) $date->format('d'), (int) $target->format('t')))->format('Y-m-d');
    }
}
