<?php

namespace Cmd\Reports\Console\Commands\GenerateParamountEpfSummary;

use DateTimeImmutable;
use InvalidArgumentException;

/** Present the existing EPF tier payment as an LDR invoice; never recalculate that payment. */
final class ParamountInvoice
{
    public const SENDER = 'invoices@libertydebtrelief.com';
    public const BILL_TO_ADDRESS = '1504 Brookhollow Drive Suite 112|Santa Ana, CA 92705|emcmurtrey@Higbee.law';

    public static function document(array $window, float $debt, int $tier, float $payment, array $billTo): array
    {
        if (!is_finite($debt) || !is_finite($payment) || $debt < 0 || $payment < 0 || $tier < 0) {
            throw new InvalidArgumentException('Invalid EPF invoice figures.');
        }
        $first = new DateTimeImmutable($window['start']);
        $issue = new DateTimeImmutable($window['endExclusive']);
        $number = 'LDR-PAR-' . $first->format('Y-m');
        $money = static fn (float $amount): string => '$' . number_format($amount, 2);
        $notes = [
            'Payment is based on the EPF tier shown in the attached EPF Summary workbook.',
            "Please include invoice number {$number} with your payment. Questions: " . self::SENDER,
        ];
        $logo = dirname(__DIR__, 4) . '/resources/images/advance-request/ldr-logo.jpg';
        return [
            'number' => $number,
            'issue_date' => $issue->format('F j, Y'),
            'due_date' => 'Upon Receipt',
            'terms' => 'Upon Receipt',
            'period' => $first->format('F j') . '-' . $issue->modify('-1 day')->format('j, Y'),
            'from' => ['name' => 'Liberty Debt Relief, LLC',
                'lines' => ['333 City Blvd W, 17th Fl', 'Orange, CA 92868'], 'email' => self::SENDER],
            'bill_to' => $billTo,
            'logo_data_uri' => is_file($logo) ? 'data:image/jpeg;base64,' . base64_encode(file_get_contents($logo)) : null,
            // Match the existing LDR Advance Request invoice's blue and pale-blue palette.
            'brand' => ['accent' => '#1f82b8', 'light' => '#ebf7fc'],
            'columns' => ['quantity' => 'EPF tier debt', 'rate' => 'Tier'],
            'lines' => [[
                'description' => 'Monthly EPF tier payment',
                'detail' => $window['label'],
                'quantity' => $money($debt), 'rate' => 'T' . $tier, 'amount' => $money($payment),
            ]],
            'totals' => [], 'total_due' => $money($payment), 'notes' => $notes,
        ];
    }
}
