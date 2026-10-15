<?php

namespace Cmd\Reports\Invoices;

/**
 * Settings, all off unless switched on.
 *
 *   INVOICE_ENABLED=false         master switch (default off)
 *   INVOICE_S3_BUCKET             invoice bucket (ff-production-invoices; dev: ff-development-invoices)
 *   INVOICE_S3_REGION=us-east-2   bucket region
 *   INVOICE_<GENERATOR>_ENABLED   one switch per generator, each default off (see forGenerator())
 *
 * A generator uses the registry only when the master switch, its own switch AND a bucket are set.
 */
final class InvoiceRegistryConfig
{
    public const DEFAULT_REGION = 'us-east-2';

    public function __construct(
        public readonly bool $enabled = false,
        public readonly string $bucket = '',
        public readonly string $region = self::DEFAULT_REGION,
        private readonly array $generatorSwitches = [],
    ) {
    }

    /** @param  callable(string): mixed  $read  env reader, env() by default */
    public static function fromReader(callable $read): self
    {
        $flag = static fn (string $name): bool => filter_var($read($name), FILTER_VALIDATE_BOOLEAN);
        $switches = [];
        foreach (self::GENERATOR_SWITCHES as $name) {
            $switches[$name] = $flag($name);
        }

        return new self(
            $flag('INVOICE_ENABLED'),
            trim((string) ($read('INVOICE_S3_BUCKET') ?? '')),
            trim((string) ($read('INVOICE_S3_REGION') ?? '')) ?: self::DEFAULT_REGION,
            $switches
        );
    }

    public static function fromEnv(): self
    {
        return self::fromReader(static fn (string $name): mixed => env($name));
    }

    public const GENERATOR_SWITCHES = [
        'INVOICE_LT_PL_ENABLED',
        'INVOICE_LT_LDR_ENABLED',
        'INVOICE_PARAMOUNT_ENABLED',
        'INVOICE_EXPENSES_JORDAN_ENABLED',
        'INVOICE_EXPENSES_GUATEMALA_ENABLED',
        'INVOICE_ADVANCE_ENABLED',
    ];

    public function forGenerator(string $switch): bool
    {
        return $this->enabled && $this->bucket !== '' && ($this->generatorSwitches[$switch] ?? false);
    }
}
