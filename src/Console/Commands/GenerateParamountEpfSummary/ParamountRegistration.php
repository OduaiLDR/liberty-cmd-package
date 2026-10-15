<?php

namespace Cmd\Reports\Console\Commands\GenerateParamountEpfSummary;

use Cmd\Reports\Invoices\InvoiceRegistryGate;

/**
 * Paramount's side of the invoice registry (TASK-006). Own switch INVOICE_PARAMOUNT_ENABLED, default off.
 * Every method is a no-op returning null/false when the switch is off or the registry fails, so the
 * command carries on exactly as before.
 */
final class ParamountRegistration
{
    public const SERIES = 'LDR-PAR';
    public const SWITCH = 'INVOICE_PARAMOUNT_ENABLED';

    public function __construct(private readonly ?InvoiceRegistryGate $gate)
    {
    }

    public static function fromEnv(callable $alert): self
    {
        return new self(InvoiceRegistryGate::forGenerator(self::SWITCH, alert: $alert));
    }

    /** New-style number dated like the invoice (the day after the billing month ends, Pacific); null = keep the old number. */
    public function reserve(array $window): ?string
    {
        return $this->gate?->reserve(self::SERIES, $window['endExclusive'], ['generator' => 'paramount-epf-summary', 'period' => $window['label']]);
    }

    /** @param  'sent'|'issued'  $status */
    public function record(string $number, array $window, float $payment, string $status, string $pdf, string $workbook, ?string $note = null): bool
    {
        return $this->gate?->record(self::SERIES, $number, [
            'amount' => $payment,
            'invoiceDate' => $window['endExclusive'],
            'status' => $status,
            'generator' => 'paramount-epf-summary',
            'period' => $window['label'],
        ] + ($note === null ? [] : ['notes' => $note]), [
            ['name' => "{$number}.pdf", 'kind' => 'pdf', 'bytes' => $pdf, 'contentType' => 'application/pdf'],
            ['name' => "{$number}-epf-summary.xlsx", 'kind' => 'backup', 'bytes' => $workbook, 'contentType' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        ]) ?? false;
    }
}
