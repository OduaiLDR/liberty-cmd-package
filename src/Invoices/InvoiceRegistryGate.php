<?php

namespace Cmd\Reports\Invoices;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * What a generator talks to. It never throws: if the registry or S3 fails, it logs loudly and returns null/false,
 * and the generator carries on exactly as it did before (invoicing must not stop because the log is down).
 */
final class InvoiceRegistryGate
{
    /** @param  callable(string, array): void|null  $alert */
    public function __construct(private readonly InvoiceRegistry $registry, private $alert = null)
    {
    }

    /** Null when the generator's switch is off (generator behaves as before) or the registry cannot start. */
    public static function forGenerator(string $switch, ?InvoiceRegistryConfig $config = null, ?callable $storeFactory = null, ?callable $alert = null): ?self
    {
        $config ??= InvoiceRegistryConfig::fromEnv();
        if (! $config->forGenerator($switch)) {
            return null;
        }
        try {
            $store = $storeFactory ? $storeFactory($config) : S3InvoiceObjectStore::fromConfig($config);

            return new self(new InvoiceRegistry($store), $alert);
        } catch (Throwable $e) {
            self::shout($alert, "Invoice registry could not start ({$switch} is on); continuing WITHOUT it: " . $e->getMessage(), ['switch' => $switch]);

            return null;
        }
    }

    /** Pacific-time date for an invoice dated now. */
    public function today(): string
    {
        return $this->registry->today();
    }

    /** The new-style number, or null (alerted) when it could not be reserved. */
    public function reserve(string $series, string $invoiceDate, array $meta = []): ?string
    {
        try {
            return $this->registry->reserve($series, $invoiceDate, $meta);
        } catch (Throwable $e) {
            self::shout($this->alert, "Invoice number for {$series} could not be reserved; using the OLD number: " . $e->getMessage(), ['series' => $series]);

            return null;
        }
    }

    /** True when the files and record.json were written; false (alerted) otherwise. */
    public function record(string $series, string $number, array $fields, array $files): bool
    {
        try {
            $this->registry->record($series, $number, $fields, $files);

            return true;
        } catch (Throwable $e) {
            self::shout($this->alert, "Invoice {$number} was NOT recorded in the invoice bucket (the invoice itself is unaffected): " . $e->getMessage(), ['number' => $number]);

            return false;
        }
    }

    private static function shout(?callable $alert, string $message, array $context): void
    {
        if ($alert !== null) {
            $alert($message, $context);

            return;
        }
        try {
            Log::error('[INVOICE REGISTRY] ' . $message, $context);
        } catch (Throwable) {
            error_log('[INVOICE REGISTRY] ' . $message);
        }
    }
}
