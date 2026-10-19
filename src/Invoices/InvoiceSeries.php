<?php

namespace Cmd\Reports\Invoices;

/**
 * Number series and where each lands in the bucket: {company}/{type}/{YYYY}/{MM}/{number}/.
 * A number is {series}-{YYYYMMDD}-{NN}, e.g. LT-PL-20261015-01 or LDR-EXP-JO-20261015-01.
 * Folder names are lowercase slugs and must match what the Invoices page reads (Debt-Relief-App invoices-core).
 */
final class InvoiceSeries
{
    /** @var array<string, array{company: string, type: string}> */
    public const ALL = [
        'LT-PL' => ['company' => 'lending-tower', 'type' => 'progress-law'],
        'LT-LDR' => ['company' => 'lending-tower', 'type' => 'ldr'],
        'LDR-PAR' => ['company' => 'paramount', 'type' => 'paramount'],
        'LDR-ADV' => ['company' => 'advance-request', 'type' => 'ldr'],
        'PLAW-ADV' => ['company' => 'advance-request', 'type' => 'progress-law'],
        'COMB-EXP-JO' => ['company' => 'expenses-jordan', 'type' => 'combined'],
        'LDR-EXP-JO' => ['company' => 'expenses-jordan', 'type' => 'ldr'],
        'LT-EXP-JO' => ['company' => 'expenses-jordan', 'type' => 'lt'],
        'PLAW-EXP-JO' => ['company' => 'expenses-jordan', 'type' => 'plaw'],
        'COMB-EXP-GT' => ['company' => 'expenses-guatemala', 'type' => 'combined'],
        'LDR-EXP-GT' => ['company' => 'expenses-guatemala', 'type' => 'ldr'],
        'LT-EXP-GT' => ['company' => 'expenses-guatemala', 'type' => 'lt'],
        'PLAW-EXP-GT' => ['company' => 'expenses-guatemala', 'type' => 'plaw'],
    ];

    /** @return array{company: string, type: string} */
    public static function get(string $series): array
    {
        return self::ALL[$series] ?? throw new InvoiceRegistryException("Unknown invoice series '{$series}'.");
    }

    /** {company}/{type}/{YYYY}/{MM}/{number}/ for a number reserved in this series. */
    public static function folder(string $series, string $number): string
    {
        $def = self::get($series);
        if (preg_match('/^' . preg_quote($series, '/') . '-(\d{4})(\d{2})\d{2}-\d{2}$/', $number, $m) !== 1) {
            throw new InvoiceRegistryException("'{$number}' is not a {$series} invoice number.");
        }

        return "{$def['company']}/{$def['type']}/{$m[1]}/{$m[2]}/{$number}/";
    }
}
