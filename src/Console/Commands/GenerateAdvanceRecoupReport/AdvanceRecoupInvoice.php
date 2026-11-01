<?php

declare(strict_types=1);

namespace Cmd\Reports\Console\Commands\GenerateAdvanceRecoupReport;

use DateTimeImmutable;
use InvalidArgumentException;

/** Builds the Progress Law internal account-transfer invoice for the EPF adjustment total. */
final class AdvanceRecoupInvoice
{
    public const SENDER = 'invoices@progresslaw.com';

    /** @param array{start:string,endExclusive:string,label:string} $window */
    public static function document(array $window, float $balanceDue, string $invoiceDate): array
    {
        if (!is_finite($balanceDue)) {
            throw new InvalidArgumentException('The EPF balance due must be a finite amount.');
        }

        try {
            $periodStart = new DateTimeImmutable($window['start']);
            $periodEnd = (new DateTimeImmutable($window['endExclusive']))->modify('-1 day');
            $issueDate = new DateTimeImmutable($invoiceDate);
        } catch (\Throwable $e) {
            throw new InvalidArgumentException('The EPF invoice dates are invalid.', previous: $e);
        }

        if ($balanceDue < 0) {
            $transferDirection = 'From Progress Law Operation Account to Progress Law Primary Account';
        } elseif ($balanceDue > 0) {
            $transferDirection = 'From Progress Law Primary Account to Progress Law Operation Account';
        } else {
            $transferDirection = 'No account transfer - the balance due is zero';
        }
        $amount = '$' . number_format(abs($balanceDue), 2);
        $number = 'PL-PL-' . $issueDate->format('Ymd');
        $logo = dirname(__DIR__, 4) . '/resources/images/advance-request/progress-law-logo.jpg';

        return [
            'number' => $number,
            'issue_date' => $issueDate->format('F j, Y'),
            'due_date' => 'Internal transfer',
            'terms' => 'Internal transfer only',
            'period' => $periodStart->format('F j') . '-' . $periodEnd->format('j, Y'),
            'from' => [
                'name' => 'Progress Law, LLC',
                'lines' => ['Internal accounting transfer'],
                'email' => self::SENDER,
            ],
            'bill_to' => [
                'name' => 'Progress Law, LLC - Internal Accounts',
                'lines' => ['Internal account transfer; no external payment required'],
            ],
            'logo_data_uri' => is_file($logo) ? 'data:image/jpeg;base64,' . base64_encode((string) file_get_contents($logo)) : null,
            'brand' => [
                'dark' => '#15313c',
                'accent' => '#15313c',
                'light' => '#e7eff2',
            ],
            'columns' => ['quantity' => 'Transfer amount', 'rate' => 'Type'],
            'lines' => [[
                'description' => 'Internal EPF account transfer',
                'detail' => $transferDirection . ' - ' . $window['label'],
                'quantity' => $amount,
                'rate' => 'Internal',
                'amount' => $amount,
            ]],
            'totals' => [],
            'total_label' => 'Internal transfer amount',
            'total_due' => $amount,
            'notes' => [
                'This invoice documents an internal transfer between Progress Law accounts; it is not a third-party payment request.',
                'Direction is based on the signed EPF balance due. A zero balance results in no transfer amount.',
            ],
        ];
    }
}
