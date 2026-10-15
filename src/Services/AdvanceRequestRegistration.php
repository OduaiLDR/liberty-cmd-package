<?php

namespace Cmd\Reports\Services;

use Cmd\Reports\Invoices\InvoiceRegistryGate;

/**
 * Advance request in the invoice registry (TASK-006). Own switch INVOICE_ADVANCE_ENABLED, default off.
 * Only the invoice number changes (it prints on the PDF and names the file); the PDF layout, email and
 * recipients are untouched. No-ops (null/false) when the switch is off or the registry fails.
 */
final class AdvanceRequestRegistration
{
    public const SWITCH = 'INVOICE_ADVANCE_ENABLED';
    private const SERIES = ['LDR' => 'LDR-ADV', 'PLAW' => 'PLAW-ADV'];

    public function __construct(private readonly ?InvoiceRegistryGate $gate)
    {
    }

    public static function fromEnv(callable $alert): self
    {
        return new self(InvoiceRegistryGate::forGenerator(self::SWITCH, alert: $alert));
    }

    /** New-style number dated today in Pacific time; null keeps the old LDR-ADV-YYYYMMDD-tranche number. */
    public function reserve(string $company, string $monthLabel, string $tranche): ?string
    {
        return $this->gate?->reserve(self::SERIES[$company], $this->gate->today(), ['generator' => 'advance-request', 'period' => $monthLabel, 'tranche' => $tranche]);
    }

    public function record(string $company, string $number, float $amount, string $status, string $pdf, string $monthLabel, string $tranche): bool
    {
        return $this->gate?->record(self::SERIES[$company], $number, [
            'amount' => $amount,
            'invoiceDate' => $this->gate->today(),
            'status' => $status,
            'generator' => 'advance-request',
            'period' => $monthLabel,
            'notes' => "Advance request, tranche {$tranche}.",
        ], [['name' => "{$number}.pdf", 'kind' => 'pdf', 'bytes' => $pdf, 'contentType' => 'application/pdf']]) ?? false;
    }
}
