<?php

namespace Cmd\Reports\Invoices;

/**
 * Writes an invoice's files and record.json in the layout the Invoices page reads:
 * {company}/{type}/{YYYY}/{MM}/{number}/ holding the PDF, the backup and record.json.
 * Everything is create-only (never overwrites). A repeated attempt may find its own files already there,
 * but record.json existing means the invoice was already recorded, which is an error.
 */
final class InvoiceRecordWriter
{
    public const STATUSES = ['issued', 'sent', 'legacy'];

    public function __construct(private readonly InvoiceObjectStore $store)
    {
    }

    /**
     * @param  array<string, mixed>  $fields  amount (dollars), invoiceDate (Y-m-d), status, generator, plus optional period, generatedBy, notes, sentAt...
     * @param  list<array{name: string, kind: 'pdf'|'backup', bytes: string, contentType: string}>  $files
     * @return array<string, mixed> the record that was written
     */
    public function write(string $series, string $number, array $fields, array $files): array
    {
        $def = InvoiceSeries::get($series);

        return $this->writeAt(InvoiceSeries::folder($series, $number), $def['company'], $def['type'], $number, $fields, $files);
    }

    /**
     * Same as write() but for an explicit folder, used by the legacy backfill (old numbers do not fit a series).
     * A legacy record may have no amount (e.g. an advance request PDF); every other status needs one.
     */
    public function writeAt(string $folder, string $company, string $type, string $number, array $fields, array $files): array
    {
        $amount = $fields['amount'] ?? null;
        $status = (string) ($fields['status'] ?? '');
        if (! ($status === 'legacy' && $amount === null) && (! is_int($amount) && ! is_float($amount) || ! is_finite((float) $amount))) {
            throw new InvoiceRegistryException('Invoice amount must be a number.');
        }
        if (! in_array($status, self::STATUSES, true)) {
            throw new InvoiceRegistryException("Invoice status must be one of: " . implode(', ', self::STATUSES));
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($fields['invoiceDate'] ?? '')) !== 1) {
            throw new InvoiceRegistryException('invoiceDate must be Y-m-d.');
        }

        $listed = [];
        foreach ($files as $file) {
            $key = $folder . $file['name'];
            if (! $this->store->putIfAbsent($key, $file['bytes'], $file['contentType'])) {
                $this->assertSameStoredFile($folder, $key, $number, $file['bytes']);
            }
            $listed[] = ['name' => $file['name'], 'kind' => $file['kind'], 'key' => $key, 'sha256' => hash('sha256', $file['bytes']), 'bytes' => strlen($file['bytes'])];
        }

        $record = $fields + ['generator' => 'cmd-runner', 'source' => 'system'];
        $record = array_merge($record, [
            'invoiceNumber' => $number,
            'company' => $company,
            'type' => $type,
            'amount' => $amount === null ? null : (float) $amount,
            'currency' => 'USD',
            'status' => $status,
            'revision' => 1,
            'files' => $listed,
        ]);

        if (! $this->store->putIfAbsent($folder . 'record.json', (string) json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), 'application/json')) {
            throw new InvoiceRegistryException("{$number} already has a record.json; refusing to overwrite it.");
        }

        return $record;
    }

    /**
     * A file is already there (an earlier attempt uploaded it, then the record failed). Keep it only when it is byte-for-byte
     * the file we are about to describe; otherwise the record would carry a checksum for a file that is not the stored one.
     * Nothing is overwritten (create-only bucket): a mismatch fails loudly and the generator keeps its old number.
     */
    private function assertSameStoredFile(string $folder, string $key, string $number, string $bytes): void
    {
        $stored = $this->store->get($key);
        if ($stored !== null && hash_equals(hash('sha256', $stored), hash('sha256', $bytes))) {
            return;
        }
        if ($this->store->get($folder . 'record.json') !== null) {
            throw new InvoiceRegistryException("{$number} already has a record.json; refusing to overwrite it.");
        }
        throw new InvoiceRegistryException($stored === null
            ? "{$key} exists but could not be read back, so it cannot be verified; not recording {$number}."
            : "{$key} already holds a different file (sha256 " . hash('sha256', $stored) . ', ' . strlen($stored) . ' bytes) than this attempt (sha256 ' . hash('sha256', $bytes) . ', ' . strlen($bytes) . " bytes); not recording {$number}.");
    }
}
