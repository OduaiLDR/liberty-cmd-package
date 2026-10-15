<?php

namespace Cmd\Reports\Console\Commands;

use Cmd\Reports\Invoices\Backfill\LegacyBackfill;
use Cmd\Reports\Invoices\Backfill\LegacyInvoiceScanner;
use Cmd\Reports\Invoices\Backfill\LocalDirectorySource;
use Cmd\Reports\Invoices\InvoiceObjectStore;
use Cmd\Reports\Invoices\InvoiceRegistryConfig;
use Cmd\Reports\Invoices\S3InvoiceObjectStore;
use Illuminate\Console\Command;

/**
 * Imports the Runner's existing invoices into the invoice bucket as status "legacy", keeping their old numbers.
 * Reads local files only. Default is a DRY RUN that prints the plan; it writes only with --write.
 */
class BackfillLegacyInvoices extends Command
{
    protected $signature = 'invoices:backfill-legacy
                            {--write : Actually write to the invoice bucket (INVOICE_S3_BUCKET). Without it nothing is written}
                            {--root= : Runner storage/app folder to read (default storage_path("app"))}';

    protected $description = 'Import existing invoices (LT, Paramount, advance request, old Jordan files) as legacy records. Dry run unless --write.';

    /** Inject a store (tests); otherwise the real S3 store is built from INVOICE_S3_BUCKET / INVOICE_S3_REGION. */
    public ?InvoiceObjectStore $store = null;

    public ?LegacyInvoiceScanner $scanner = null;

    private function hostScanner(string $root): LegacyInvoiceScanner
    {
        return new LegacyInvoiceScanner(new LocalDirectorySource($root . '/private'), new LocalDirectorySource($root . '/advance-request-invoices'));
    }

    public function handle(): int
    {
        $scanner = $this->scanner ?? $this->hostScanner(rtrim((string) ($this->option('root') ?: storage_path('app')), '/'));
        $candidates = $scanner->scan();
        $plan = (new LegacyBackfill())->plan($candidates);

        foreach ($plan as $p) {
            $this->line(sprintf('%-26s %-18s %-14s %s  %s  files=%d  -> %s', $p['number'], $p['company'], $p['type'], $p['invoiceDate'], $p['amount'] === null ? '(amount on PDF)' : '$' . number_format($p['amount'], 2), $p['files'], $p['folder']));
        }
        foreach ($scanner->skipped as $why) {
            $this->warn("[SKIP] {$why}");
        }
        $this->info(count($plan) . ' invoice(s) found, ' . count($scanner->skipped) . ' skipped.');

        if (! $this->option('write')) {
            $this->info('DRY RUN: nothing was written. Re-run with --write to import these as legacy records.');

            return self::SUCCESS;
        }

        $config = InvoiceRegistryConfig::fromEnv();
        if ($this->store === null && $config->bucket === '') {
            $this->error('INVOICE_S3_BUCKET is not set; refusing to write.');

            return self::FAILURE;
        }
        $this->warn('WRITING to ' . ($this->store === null ? $config->bucket : 'the injected store') . '.');
        $result = (new LegacyBackfill())->apply($this->store ?? S3InvoiceObjectStore::fromConfig($config), $candidates);
        $this->info(sprintf('Imported %d, already imported %d, failed %d.', count($result['written']), count($result['existing']), count($result['failed'])));
        foreach ($result['failed'] as $number => $why) {
            $this->error("[FAILED] {$number}: {$why}");
        }

        return $result['failed'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
