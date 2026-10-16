<?php

namespace Cmd\Reports\Invoices\Backfill;

use Cmd\Reports\Invoices\PacificTime;

/**
 * Finds the invoices already issued under the old numbers and describes each as an import candidate
 * (status legacy, old number kept). Pure reading: it never writes anywhere.
 *
 * Sources (all on the Runner host): Lending Tower and Paramount send markers, the advance-request PDFs,
 * and the old local Jordan expense invoice sets. Invoices recorded by the registry are skipped.
 *
 * @phpstan-type Candidate array{number: string, company: string, type: string, invoiceDate: string, amount: float|null, period: string, source: string, files: list<array{name: string, kind: string, bytes: string, contentType: string}>, fields: array<string, mixed>}
 */
final class LegacyInvoiceScanner
{
    private const XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    /** @var list<string> */
    public array $skipped = [];

    /**
     * @param  LegacySource  $private  storage/app/private (cmd/lending-tower-invoices, cmd/paramount-invoices, cmd/jordan-expenses)
     * @param  LegacySource  $advance  storage/app/advance-request-invoices
     */
    public function __construct(private readonly LegacySource $private, private readonly LegacySource $advance, private readonly string $jordanPrefix = 'cmd/jordan-expenses')
    {
    }

    /** @return list<Candidate> */
    public function scan(): array
    {
        $this->skipped = [];

        return array_merge($this->lendingTower(), $this->paramount(), $this->advance(), $this->jordan());
    }

    /**
     * File paths from a source, always with forward slashes: a Windows host lists backslash paths, and every pattern
     * below (and the paths we read back) uses '/'.
     *
     * @return list<string>
     */
    private function listed(LegacySource $source, string $dir): array
    {
        return array_map(static fn (string $path): string => str_replace('\\', '/', $path), $source->files($dir));
    }

    private function ptDate(int|string|null $when): string
    {
        $instant = is_int($when) ? (new \DateTimeImmutable())->setTimestamp($when) : new \DateTimeImmutable((string) $when);

        return PacificTime::date($instant);
    }

    private function dollars(mixed $text): ?float
    {
        $clean = preg_replace('/[^0-9.\-]/', '', (string) $text);

        return $clean === '' || ! is_numeric($clean) ? null : (float) $clean;
    }

    /** @return list<array<string, mixed>> */
    private function lendingTower(): array
    {
        $out = [];
        foreach ($this->listed($this->private, 'cmd/lending-tower-invoices') as $path) {
            if (preg_match('#^cmd/lending-tower-invoices/(\d{4}-\d{2})/(LT-PL|LT-LDR)-\d{4}-\d{2}\.sent\.json$#', $path, $m) !== 1) {
                continue;
            }
            $marker = json_decode((string) $this->private->read($path), true) ?: [];
            $number = basename($path, '.sent.json');
            if (isset($marker['invoice_number'])) {
                $this->skipped[] = "{$number}: already registered as {$marker['invoice_number']}";
                continue;
            }
            $dir = dirname($path);
            $files = [];
            foreach ([["{$number}.pdf", "{$number}.pdf", 'pdf', 'application/pdf'], ["{$number} backup.xlsx", "{$number}-backup.xlsx", 'backup', self::XLSX]] as [$on, $as, $kind, $type]) {
                $bytes = $this->private->read("{$dir}/{$on}");
                if ($bytes !== null) {
                    $files[] = ['name' => $as, 'kind' => $kind, 'bytes' => $bytes, 'contentType' => $type];
                }
            }
            $type = $m[2] === 'LT-PL' ? 'progress-law' : 'ldr';
            $out[] = $this->candidate($number, 'lending-tower', $type, $this->ptDate($marker['sent_at'] ?? $this->private->modifiedAt($path)), $this->dollars($marker['total_due'] ?? null), (new \DateTimeImmutable($m[1] . '-01'))->format('F Y'), 'lending-tower-invoices', $files, ['sentAt' => $marker['sent_at'] ?? null]);
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function paramount(): array
    {
        $out = [];
        foreach ($this->listed($this->private, 'cmd/paramount-invoices') as $path) {
            if (preg_match('#^cmd/paramount-invoices/(\d{4}-\d{2})\.sent\.json$#', $path, $m) !== 1) {
                continue;
            }
            $marker = json_decode((string) $this->private->read($path), true) ?: [];
            $invoice = (string) ($marker['invoice'] ?? '');
            if (preg_match('/^LDR-PAR-\d{8}-\d{2}\.pdf$/', $invoice) === 1) {
                $this->skipped[] = "{$m[1]}: already registered as " . basename($invoice, '.pdf');
                continue;
            }
            // No PDF is kept on the host for Paramount, so only the record is imported.
            $out[] = $this->candidate('LDR-PAR-' . $m[1], 'paramount', 'paramount', $this->ptDate($marker['sent_at'] ?? $this->private->modifiedAt($path)), isset($marker['payment']) ? (float) $marker['payment'] : null, (new \DateTimeImmutable($m[1] . '-01'))->format('F Y'), 'paramount-invoices', [], ['sentAt' => $marker['sent_at'] ?? null, 'notes' => 'Legacy import: no PDF was kept on the Runner host.']);
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function advance(): array
    {
        $out = [];
        foreach ($this->listed($this->advance, '') as $path) {
            if (preg_match('/^(LDR|PLAW)-ADV-(\d{4})(\d{2})(\d{2})-([A-Za-z0-9-]+)\.pdf$/', basename($path), $m) !== 1) {
                continue;
            }
            $number = basename($path, '.pdf');
            $bytes = (string) $this->advance->read($path);
            $out[] = $this->candidate($number, 'advance-request', $m[1] === 'LDR' ? 'ldr' : 'progress-law', $this->ptDate($this->advance->modifiedAt($path)), null, (new \DateTimeImmutable("{$m[2]}-{$m[3]}-01"))->format('F Y'), 'advance-request', [
                ['name' => "{$number}.pdf", 'kind' => 'pdf', 'bytes' => $bytes, 'contentType' => 'application/pdf'],
            ], ['notes' => "Legacy import: amount is on the PDF; tranche {$m[5]}."]);
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function jordan(): array
    {
        $latest = [];
        foreach ($this->listed($this->private, $this->jordanPrefix) as $path) {
            if (preg_match('#/invoices/manifests/[^/]+\.json$#', $path) !== 1) {
                continue;
            }
            $manifest = json_decode((string) $this->private->read($path), true) ?: [];
            foreach ($manifest['invoices'] ?? [] as $record) {
                $key = $record['invoiceNumber'] ?? null;
                if ($key !== null && ($latest[$key]['generatedAt'] ?? '') <= ($record['generatedAt'] ?? '')) {
                    $latest[$key] = $record;
                }
            }
        }
        $out = [];
        ksort($latest);
        foreach ($latest as $number => $record) {
            $bytes = $this->private->read((string) ($record['storageKey'] ?? ''));
            if ($bytes === null) {
                $this->skipped[] = "{$number}: manifest lists it but the file is not on this host";
                continue;
            }
            $out[] = $this->candidate($number, 'expenses-jordan', (string) $record['companyKey'], $this->ptDate($record['generatedAt'] ?? null), (float) ($record['totalUsd'] ?? 0), (string) $record['month'], 'jordan-expenses', [
                ['name' => "{$number}.xls", 'kind' => 'backup', 'bytes' => $bytes, 'contentType' => 'application/vnd.ms-excel'],
            ], ['generatedBy' => $record['generatedBy'] ?? null, 'notes' => "Legacy import: expense invoice {$record['invoiceSet']}."]);
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function candidate(string $number, string $company, string $type, string $date, ?float $amount, string $period, string $source, array $files, array $fields): array
    {
        return ['number' => $number, 'company' => $company, 'type' => $type, 'invoiceDate' => $date, 'amount' => $amount, 'period' => $period, 'source' => $source, 'files' => $files, 'fields' => $fields];
    }
}
