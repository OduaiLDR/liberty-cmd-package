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
    public function __construct(private readonly LegacySource $private, private readonly LegacySource $advance, private readonly string $jordanPrefix = 'cmd/jordan-expenses', private readonly string $guatemalaPrefix = 'cmd/guatemala-expenses')
    {
    }

    /** @return list<Candidate> */
    public function scan(): array
    {
        $this->skipped = [];

        $first = array_merge($this->lendingTower(), $this->paramount(), $this->advance());
        $jordan = $this->expenses($this->jordanPrefix, 'expenses-jordan', 'jordan-expenses', 'Jordan', []);
        $taken = array_column($jordan, 'number');

        return array_merge($first, $jordan, $this->expenses($this->guatemalaPrefix, 'expenses-guatemala', 'guatemala-expenses', 'Guatemala', $taken));
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

    /** Pacific Y-m-d for an instant given as unix time or a date string; null when it cannot be read (never "now"). */
    private function ptDate(mixed $when): ?string
    {
        try {
            if (is_int($when)) {
                return PacificTime::date((new \DateTimeImmutable())->setTimestamp($when));
            }
            if (is_string($when) && trim($when) !== '') {
                return PacificTime::date(new \DateTimeImmutable($when));
            }
        } catch (\Throwable) {
        }

        return null;
    }

    /** First readable date of: the record's own value, then the file's modified time. */
    private function dateOf(mixed $recorded, LegacySource $source, string $path): ?string
    {
        return $this->ptDate($recorded) ?? $this->ptDate($source->modifiedAt($path));
    }

    /** A number from a JSON value, null for anything else (strings like "$1,234.50" are cleaned; arrays and junk are not numbers). */
    private function dollars(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return is_finite((float) $value) ? (float) $value : null;
        }
        if (! is_string($value)) {
            return null;
        }
        $clean = preg_replace('/[^0-9.\-]/', '', $value);

        return $clean === '' || ! is_numeric($clean) ? null : (float) $clean;
    }

    private function text(mixed $value): ?string
    {
        $text = is_string($value) || is_int($value) ? trim((string) $value) : '';

        return $text === '' ? null : $text;
    }

    /** "2026-07" -> "July 2026"; null when it is not a real year-month. */
    private function monthLabel(string $yearMonth): ?string
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m', $yearMonth);

        return $d !== false && $d->format('Y-m') === $yearMonth ? $d->format('F Y') : null;
    }

    /** The decoded JSON object at a path, or null (with the reason recorded) when it is missing, malformed or not an object/array. */
    private function readJson(LegacySource $source, string $path, string $label): ?array
    {
        $raw = $source->read($path);
        $decoded = $raw === null || trim($raw) === '' ? null : json_decode($raw, true);
        if (! is_array($decoded)) {
            $this->skipped[] = "{$label}: " . basename($path) . ' is empty, not valid JSON or not a JSON object; not imported.';

            return null;
        }

        return $decoded;
    }

    /** @return list<array<string, mixed>> */
    private function lendingTower(): array
    {
        $out = [];
        foreach ($this->listed($this->private, 'cmd/lending-tower-invoices') as $path) {
            if (preg_match('#^cmd/lending-tower-invoices/(\d{4}-\d{2})/(LT-PL|LT-LDR)-\d{4}-\d{2}\.sent\.json$#', $path, $m) !== 1) {
                continue;
            }
            $number = basename($path, '.sent.json');
            $marker = $this->readJson($this->private, $path, $number);
            if ($marker === null) {
                continue;
            }
            if (isset($marker['invoice_number'])) {
                $this->skipped[] = "{$number}: already registered as " . (is_scalar($marker['invoice_number']) ? $marker['invoice_number'] : 'a registry number');
                continue;
            }
            $period = $this->monthLabel($m[1]);
            $date = $this->dateOf($marker['sent_at'] ?? null, $this->private, $path);
            if ($period === null || $date === null) {
                $this->skipped[] = "{$number}: " . ($period === null ? "'{$m[1]}' is not a real month" : 'no usable sent_at and no file time to date it') . '; not imported.';
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
            $out[] = $this->candidate($number, 'lending-tower', $type, $date, $this->dollars($marker['total_due'] ?? null), $period, 'lending-tower-invoices', $files, ['sentAt' => $this->text($marker['sent_at'] ?? null)]);
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
            $marker = $this->readJson($this->private, $path, $m[1]);
            if ($marker === null) {
                continue;
            }
            $invoice = $this->text($marker['invoice'] ?? null) ?? '';
            if (preg_match('/^LDR-PAR-\d{8}-\d{2}\.pdf$/', $invoice) === 1) {
                $this->skipped[] = "{$m[1]}: already registered as " . basename($invoice, '.pdf');
                continue;
            }
            $period = $this->monthLabel($m[1]);
            $date = $this->dateOf($marker['sent_at'] ?? null, $this->private, $path);
            if ($period === null || $date === null) {
                $this->skipped[] = "{$m[1]}: " . ($period === null ? 'not a real month' : 'no usable sent_at and no file time to date it') . '; not imported.';
                continue;
            }
            // No PDF is kept on the host for Paramount, so only the record is imported.
            $out[] = $this->candidate('LDR-PAR-' . $m[1], 'paramount', 'paramount', $date, $this->dollars($marker['payment'] ?? null), $period, 'paramount-invoices', [], ['sentAt' => $this->text($marker['sent_at'] ?? null), 'notes' => 'Legacy import: no PDF was kept on the Runner host.']);
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
            $period = checkdate((int) $m[3], (int) $m[4], (int) $m[2]) ? $this->monthLabel("{$m[2]}-{$m[3]}") : null;
            if ($period === null) {
                $this->skipped[] = "{$number}: the date in the number ({$m[2]}{$m[3]}{$m[4]}) is not a real date; not imported.";
                continue;
            }
            // The file time when there is one, else the date printed in the number itself.
            $date = $this->ptDate($this->advance->modifiedAt($path)) ?? "{$m[2]}-{$m[3]}-{$m[4]}";
            $bytes = (string) $this->advance->read($path);
            $out[] = $this->candidate($number, 'advance-request', $m[1] === 'LDR' ? 'ldr' : 'progress-law', $date, null, $period, 'advance-request', [
                ['name' => "{$number}.pdf", 'kind' => 'pdf', 'bytes' => $bytes, 'contentType' => 'application/pdf'],
            ], ['notes' => "Legacy import: amount is on the PDF; tranche {$m[5]}."]);
        }

        return $out;
    }

    private const COMPANY_KEYS = ['ldr' => 'ldr', 'lt' => 'lt', 'plaw' => 'plaw', 'combined' => 'combined'];

    /**
     * Old expense invoices (Jordan or Guatemala). Both locations used the same numbers (2026-07-LDR-D1) and the page opens a
     * number by name only, so a number already taken by an earlier location is skipped and reported instead of importing a
     * second record that would shadow (or be shadowed by) the first. Numbers are never renamed.
     *
     * Every manifest record is untrusted: only invoiceNumber is required. Missing companyKey, month and invoiceSet are inferred
     * from the number (2026-07-LDR-D1 = month 2026-07, company ldr, set D1) only when that is unambiguous; a missing
     * storageKey, an undatable record or an uninferable company is skipped with the reason, never fatal.
     *
     * @param  list<string>  $taken  numbers already claimed by another location
     * @return list<array<string, mixed>>
     */
    private function expenses(string $prefix, string $company, string $source, string $label, array $taken): array
    {
        $latest = [];
        $paths = [];
        foreach ($this->listed($this->private, $prefix) as $path) {
            if (preg_match('#/invoices/manifests/[^/]+\.json$#', $path) !== 1) {
                continue;
            }
            $manifest = $this->readJson($this->private, $path, $label);
            if ($manifest === null) {
                continue;
            }
            if (! is_array($manifest['invoices'] ?? null)) {
                $this->skipped[] = "{$label}: " . basename($path) . ' has no "invoices" list; not imported.';
                continue;
            }
            foreach ($manifest['invoices'] as $index => $record) {
                $number = is_array($record) ? $this->text($record['invoiceNumber'] ?? null) : null;
                if ($number === null) {
                    $this->skipped[] = "{$label}: record #{$index} in " . basename($path) . ' has no usable invoiceNumber; not imported.';
                    continue;
                }
                $at = is_string($record['generatedAt'] ?? null) ? $record['generatedAt'] : '';
                if (! isset($latest[$number]) || ($latest[$number]['generatedAt'] ?? '') <= $at) {
                    $latest[$number] = $record;
                    $paths[$number] = $path;
                }
            }
        }
        $out = [];
        ksort($latest);
        foreach ($latest as $number => $record) {
            $number = (string) $number;
            if (in_array($number, $taken, true)) {
                $this->skipped[] = "{$number}: {$label} has the same historical number as Jordan; not imported (numbers are never renamed). Needs a decision.";
                continue;
            }
            $storageKey = $this->text($record['storageKey'] ?? null);
            if ($storageKey === null) {
                $this->skipped[] = "{$number}: manifest record has no storageKey, so there is no file to import.";
                continue;
            }
            $bytes = $this->private->read($storageKey);
            if ($bytes === null) {
                $this->skipped[] = "{$number}: manifest lists it but the file is not on this host";
                continue;
            }
            $tokens = explode('-', $number);
            $key = $this->text($record['companyKey'] ?? null) ?? ($tokens[2] ?? '');
            $key = self::COMPANY_KEYS[strtolower($key)] ?? null;
            if ($key === null) {
                $this->skipped[] = "{$number}: cannot tell which company this is (no companyKey, and the number does not name LDR, LT, PLAW or Combined); not imported.";
                continue;
            }
            $month = $this->text($record['month'] ?? null);
            $month = $month !== null && $this->monthLabel($month) !== null ? $month : (preg_match('/^(\d{4}-\d{2})-/', $number, $mm) === 1 && $this->monthLabel($mm[1]) !== null ? $mm[1] : (preg_match('#/(\d{4}-\d{2})/invoices/#', $paths[$number], $pm) === 1 && $this->monthLabel($pm[1]) !== null ? $pm[1] : null));
            if ($month === null) {
                $this->skipped[] = "{$number}: no usable month in the record, the number or the manifest path; not imported.";
                continue;
            }
            $date = $this->dateOf($record['generatedAt'] ?? null, $this->private, $storageKey);
            if ($date === null) {
                $this->skipped[] = "{$number}: no usable generatedAt and no file time to date it; not imported.";
                continue;
            }
            // The set (D1, D2, S1, S2) only labels the note: from the record, else the number's own suffix, else left out.
            $set = $this->text($record['invoiceSet'] ?? null);
            $set ??= preg_match('/-([DS][12])$/i', $number, $sm) === 1 ? strtoupper($sm[1]) : null;
            $out[] = $this->candidate($number, $company, $key, $date, $this->dollars($record['totalUsd'] ?? null), $month, $source, [
                ['name' => "{$number}.xls", 'kind' => 'backup', 'bytes' => $bytes, 'contentType' => 'application/vnd.ms-excel'],
            ], ['generatedBy' => $this->text($record['generatedBy'] ?? null), 'notes' => 'Legacy import: expense invoice' . ($set !== null ? " {$set}" : '') . '.']);
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function candidate(string $number, string $company, string $type, string $date, ?float $amount, string $period, string $source, array $files, array $fields): array
    {
        return ['number' => $number, 'company' => $company, 'type' => $type, 'invoiceDate' => $date, 'amount' => $amount, 'period' => $period, 'source' => $source, 'files' => $files, 'fields' => $fields];
    }
}
