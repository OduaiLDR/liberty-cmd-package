<?php

namespace Cmd\Reports\Invoices;

/**
 * Hands out invoice numbers. The ONLY uniqueness mechanism is an S3 conditional put of
 * _reservations/{series}/{YYYYMMDD}/{NN}.json (If-None-Match: *): whoever creates the key owns the number.
 * NN starts at 01 for each day and rises only when that number is already taken. Nothing is ever deleted,
 * so a number whose invoice failed stays retired.
 */
final class InvoiceRegistry
{
    public const MAX_SEQUENCE = 99;
    public const TRANSIENT_ATTEMPTS = 3;

    /** @param  callable(): \DateTimeInterface|null  $clock */
    public function __construct(
        private readonly InvoiceObjectStore $store,
        private $clock = null,
    ) {
    }

    /** Today's date in Pacific time (not UTC). */
    public function today(): string
    {
        return PacificTime::date($this->clock ? ($this->clock)() : null);
    }

    /**
     * @param  string  $invoiceDate  Y-m-d, the Pacific-time invoice date (today() unless the invoice has its own date)
     * @param  array<string, mixed>  $meta  recorded in the reservation (generator, requestedBy, period...)
     */
    public function reserve(string $series, string $invoiceDate, array $meta = []): string
    {
        InvoiceSeries::get($series);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $invoiceDate, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new InvoiceRegistryException("Invalid invoice date '{$invoiceDate}' (expected Y-m-d).");
        }
        $stamp = $m[1] . $m[2] . $m[3];

        for ($sequence = 1; $sequence <= self::MAX_SEQUENCE; $sequence++) {
            $nn = str_pad((string) $sequence, 2, '0', STR_PAD_LEFT);
            $key = "_reservations/{$series}/{$stamp}/{$nn}.json";
            $body = (string) json_encode($meta + [
                'status' => 'reserved',
                'series' => $series,
                'invoiceDate' => $invoiceDate,
                'reservedAt' => gmdate('c'),
            ], JSON_UNESCAPED_SLASHES);

            if ($this->createOnce($key, $body)) {
                return "{$series}-{$stamp}-{$nn}";
            }
        }

        throw new InvoiceRegistryException("No free {$series} invoice number for {$invoiceDate} (01-99 are all taken).");
    }

    /** One conditional put, retried only for transient S3 conditions; false means the number is taken (412). */
    private function createOnce(string $key, string $body): bool
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $this->store->putIfAbsent($key, $body, 'application/json');
            } catch (InvoiceStoreTransientException $e) {
                if ($attempt >= self::TRANSIENT_ATTEMPTS) {
                    throw new InvoiceRegistryException("S3 kept asking to retry reserving {$key}: " . $e->getMessage(), 0, $e);
                }
            }
        }
    }
}
