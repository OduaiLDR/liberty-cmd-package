<?php

namespace Cmd\Reports\Tests\Unit\Invoices;

use Cmd\Reports\Console\Commands\BackfillLegacyInvoices;
use Cmd\Reports\Invoices\Backfill\LegacyBackfill;
use Cmd\Reports\Invoices\Backfill\LegacyInvoiceScanner;
use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use PHPUnit\Framework\TestCase;

final class LegacyBackfillTest extends TestCase
{
    private function scanner(): LegacyInvoiceScanner
    {
        return new LegacyInvoiceScanner(new FakeLegacySource(LegacyInvoiceScannerTest::hostFiles()), new FakeLegacySource([
            'LDR-ADV-20260901-53.pdf' => ['%PDF-a1', strtotime('2026-10-01 17:35:00 UTC')],
        ]));
    }

    private function run_(array $options, FakeInvoiceStore $store, ?string &$output = null): int
    {
        $cmd = new BackfillLegacyInvoices();
        $cmd->store = $store;
        $cmd->scanner = $this->scanner();
        $cmd->setInput(new ArrayInput($options, $cmd->getDefinition()));
        $cmd->setOutput(new OutputStyle(new ArrayInput([]), $buffer = new BufferedOutput()));
        $code = $cmd->handle();
        $output = $buffer->fetch();

        return $code;
    }

    public function test_default_is_a_dry_run_that_writes_nothing(): void
    {
        $store = new FakeInvoiceStore();
        self::assertSame(0, $this->run_([], $store, $out));
        self::assertSame(0, $store->puts, 'dry run never touches the bucket');
        self::assertStringContainsString('DRY RUN: nothing was written', $out);
        self::assertStringContainsString('LT-PL-2026-09', $out);
        self::assertStringContainsString('lending-tower/progress-law/2026/10/LT-PL-2026-09/', $out);
        self::assertStringContainsString('[SKIP] LT-PL-2026-10: already registered', $out);
        self::assertSame(0, $this->run_(['--write' => false], $store), 'an explicit false is still a dry run');
        self::assertSame(0, $store->puts);
    }

    public function test_write_imports_every_invoice_as_legacy_with_its_old_number_and_files(): void
    {
        $store = new FakeInvoiceStore();
        self::assertSame(0, $this->run_(['--write' => true], $store, $out));
        $record = json_decode($store->objects['lending-tower/progress-law/2026/10/LT-PL-2026-09/record.json'], true);
        self::assertSame(['LT-PL-2026-09', 'legacy', 5500, '2026-10-01', 'legacy-import', 'September 2026'], [
            $record['invoiceNumber'], $record['status'], $record['amount'], $record['invoiceDate'], $record['source'], $record['period'],
        ]);
        self::assertSame(['LT-PL-2026-09.pdf', 'LT-PL-2026-09-backup.xlsx'], array_column($record['files'], 'name'));
        self::assertSame('%PDF-pl', $store->objects['lending-tower/progress-law/2026/10/LT-PL-2026-09/LT-PL-2026-09.pdf']);
        $adv = json_decode($store->objects['advance-request/ldr/2026/10/LDR-ADV-20260901-53/record.json'], true);
        self::assertNull($adv['amount'], 'amount is on the PDF, so the legacy record has none');
        self::assertArrayHasKey('paramount/paramount/2026/10/LDR-PAR-2026-09/record.json', $store->objects);
        self::assertArrayHasKey('expenses-jordan/ldr/2026/08/2026-07-LDR-D1/record.json', $store->objects);
        self::assertStringContainsString('Imported 5, already imported 0, failed 0.', $out);
        self::assertSame([], array_filter(array_keys($store->objects), static fn ($k) => str_starts_with($k, '_')), 'no reservations are created');
    }

    public function test_running_twice_imports_nothing_twice_and_never_overwrites(): void
    {
        $store = new FakeInvoiceStore();
        $this->run_(['--write' => true], $store);
        $before = $store->objects;
        self::assertSame(0, $this->run_(['--write' => true], $store, $out));
        self::assertSame($before, $store->objects);
        self::assertStringContainsString('Imported 0, already imported 5, failed 0.', $out);
    }

    public function test_s3_failure_is_reported_per_invoice_and_exits_non_zero(): void
    {
        $store = new FakeInvoiceStore();
        $store->failAlways = true;
        self::assertSame(1, $this->run_(['--write' => true], $store, $out));
        self::assertStringContainsString('[FAILED] LT-PL-2026-09', $out);
        self::assertStringContainsString('Imported 0, already imported 0, failed 5.', $out);
    }

    public function test_writing_without_a_bucket_is_refused_and_plan_has_no_side_effects(): void
    {
        $cmd = new BackfillLegacyInvoices();
        $cmd->scanner = $this->scanner();
        $cmd->setInput(new ArrayInput(['--write' => true], $cmd->getDefinition()));
        $cmd->setOutput(new OutputStyle(new ArrayInput([]), $buffer = new BufferedOutput()));
        self::assertSame(1, $cmd->handle());
        self::assertStringContainsString('INVOICE_S3_BUCKET is not set', $buffer->fetch());
        self::assertCount(5, (new LegacyBackfill())->plan($this->scanner()->scan()));
    }
}
