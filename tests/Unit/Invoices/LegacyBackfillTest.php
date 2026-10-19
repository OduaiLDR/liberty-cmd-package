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
    /** Mirror of the Invoices page allowlist (invoices-core isInvoiceNumber): ASCII letters, digits, single dashes, case kept. */
    private function viewerAccepts(string $number): bool
    {
        return strlen($number) <= 48 && preg_match('/^[A-Za-z0-9]+(?:-[A-Za-z0-9]+){1,7}$/', $number) === 1;
    }

    public function test_mixed_case_expense_numbers_are_imported_verbatim_and_the_page_can_open_every_imported_number(): void
    {
        $manifest = ['invoices' => array_map(static fn (string $n, string $key) => [
            'invoiceNumber' => $n, 'companyKey' => $key, 'month' => '2026-07', 'invoiceSet' => substr($n, -2), 'totalUsd' => 10.0,
            'generatedAt' => '2026-08-27T10:00:00+00:00', 'storageKey' => "cmd/jordan-expenses/2026-07/invoices/files/{$n}.xls",
        ], ['2026-07-Combined-S2', '2026-07-PLAW-S1', '2026-07-LT-D2'], ['combined', 'plaw', 'lt'])];
        $files = LegacyInvoiceScannerTest::hostFiles() + ['cmd/jordan-expenses/2026-07/invoices/manifests/set-9.json' => [json_encode($manifest)]];
        foreach (['2026-07-Combined-S2', '2026-07-PLAW-S1', '2026-07-LT-D2'] as $n) {
            $files["cmd/jordan-expenses/2026-07/invoices/files/{$n}.xls"] = ['<xml/>'];
        }
        $scanner = new LegacyInvoiceScanner(new FakeLegacySource($files), new FakeLegacySource(['LDR-ADV-20260901-53.pdf' => ['%PDF', strtotime('2026-10-01 17:35:00 UTC')]]));
        $candidates = $scanner->scan();
        $store = new FakeInvoiceStore();
        $result = (new LegacyBackfill())->apply($store, $candidates);
        self::assertSame([], $result['failed']);
        self::assertContains('2026-07-Combined-S2', $result['written'], 'the printed number is kept exactly, not upper-cased or renamed');
        $folder = 'expenses-jordan/combined/2026/08/2026-07-Combined-S2/';
        self::assertSame('2026-07-Combined-S2', json_decode($store->objects[$folder . 'record.json'], true)['invoiceNumber']);
        self::assertArrayHasKey($folder . '2026-07-Combined-S2.xls', $store->objects);
        foreach ($result['written'] as $number) {
            self::assertTrue($this->viewerAccepts($number), "the Invoices page must accept the imported number {$number}");
        }
        foreach (array_keys($store->objects) as $key) {
            self::assertStringStartsNotWith('_', $key, 'the backfill never reserves a number');
        }
    }
    /** @return array{0: LegacyInvoiceScanner, 1: string} */
    private function dupScanner(array $jordan, array $guatemala, array $extra = []): LegacyInvoiceScanner
    {
        $entry = static fn (string $n, string $key, string $name) => ['invoiceNumber' => $n, 'companyKey' => $key, 'month' => '2026-07', 'invoiceSet' => 'D1', 'totalUsd' => 5.0, 'generatedAt' => '2026-08-27T10:00:00+00:00', 'storageKey' => $name];
        $files = $extra;
        foreach (['jordan' => $jordan, 'guatemala' => $guatemala] as $site => $numbers) {
            $list = [];
            foreach ($numbers as $n) {
                $list[] = $entry($n, 'ldr', "cmd/{$site}-expenses/2026-07/invoices/files/{$n}.xls");
                $files["cmd/{$site}-expenses/2026-07/invoices/files/{$n}.xls"] = ["<{$site}-{$n}/>"];
            }
            if ($list !== []) {
                $files["cmd/{$site}-expenses/2026-07/invoices/manifests/m.json"] = [json_encode(['invoices' => $list])];
            }
        }

        return new LegacyInvoiceScanner(new FakeLegacySource($files), new FakeLegacySource([]));
    }

    private function runBackfill(LegacyInvoiceScanner $scanner, FakeInvoiceStore $store, bool $write): array
    {
        $backfill = new LegacyBackfill();
        $resolved = $backfill->resolve($scanner->scan(), $store);
        $written = $write ? $backfill->apply($store, $resolved['candidates']) : null;

        return [$resolved, $written];
    }

    public function test_a_number_already_in_the_bucket_from_another_location_is_skipped_and_never_duplicated(): void
    {
        $store = new FakeInvoiceStore();
        [, $first] = $this->runBackfill($this->dupScanner(['2026-07-LDR-D1', '2026-07-LDR-D2'], []), $store, true);
        self::assertSame(['2026-07-LDR-D1', '2026-07-LDR-D2'], $first['written']);

        // Later run: Guatemala now has the same number, and Jordan's files are gone from the host.
        [$resolved, $second] = $this->runBackfill($this->dupScanner([], ['2026-07-LDR-D1', '2026-07-LDR-D3']), $store, true);
        self::assertSame(['2026-07-LDR-D3'], $second['written'], 'the non-colliding Guatemala number still imports');
        self::assertSame([], $second['existing'] + $second['failed']);
        self::assertCount(1, $resolved['skipped']);
        self::assertStringContainsString('2026-07-LDR-D1', $resolved['skipped'][0]);
        self::assertStringContainsString('already in the invoice bucket', $resolved['skipped'][0]);
        self::assertArrayNotHasKey('expenses-guatemala/ldr/2026/08/2026-07-LDR-D1/record.json', $store->objects);
        self::assertSame(1, count(array_filter(array_keys($store->objects), static fn (string $k) => str_ends_with($k, '/2026-07-LDR-D1/record.json'))), 'exactly one record per number');
    }

    public function test_reversed_order_guatemala_first_then_jordan_is_also_skipped(): void
    {
        $store = new FakeInvoiceStore();
        $this->runBackfill($this->dupScanner([], ['2026-07-LDR-D1']), $store, true);
        [$resolved, $result] = $this->runBackfill($this->dupScanner(['2026-07-LDR-D1', '2026-07-LDR-D9'], []), $store, true);
        self::assertSame(['2026-07-LDR-D9'], $result['written']);
        self::assertStringContainsString('2026-07-LDR-D1', $resolved['skipped'][0]);
        self::assertArrayHasKey('expenses-guatemala/ldr/2026/08/2026-07-LDR-D1/record.json', $store->objects);
    }

    public function test_the_same_number_from_two_sources_in_one_scan_imports_once_whatever_the_sources(): void
    {
        // A Paramount record and an advance-request file claiming the same number, plus the Jordan/Guatemala pair.
        $candidate = static fn (string $n, string $company) => ['number' => $n, 'company' => $company, 'type' => 'ldr', 'invoiceDate' => '2026-09-01', 'amount' => 1.0, 'period' => 'x', 'source' => $company, 'files' => [], 'fields' => []];
        $store = new FakeInvoiceStore();
        $resolved = (new LegacyBackfill())->resolve([$candidate('LDR-X-1', 'paramount'), $candidate('LDR-X-1', 'advance-request'), $candidate('LDR-X-2', 'advance-request')], $store);
        self::assertSame(['paramount', 'advance-request'], array_column($resolved['candidates'], 'company'));
        self::assertSame(['LDR-X-1', 'LDR-X-2'], array_column($resolved['candidates'], 'number'));
        self::assertCount(1, $resolved['skipped']);
        self::assertStringContainsString('LDR-X-1', $resolved['skipped'][0]);
        self::assertStringContainsString('already used by paramount', $resolved['skipped'][0]);
    }

    public function test_numbers_that_differ_only_by_case_count_as_taken_but_the_stored_case_is_never_changed(): void
    {
        $store = new FakeInvoiceStore();
        $this->runBackfill($this->dupScanner(['2026-07-Combined-S2'], []), $store, true);
        $candidate = ['number' => '2026-07-COMBINED-S2', 'company' => 'expenses-guatemala', 'type' => 'combined', 'invoiceDate' => '2026-08-27', 'amount' => 1.0, 'period' => 'x', 'source' => 'g', 'files' => [], 'fields' => []];
        $resolved = (new LegacyBackfill())->resolve([$candidate], $store);
        self::assertSame([], $resolved['candidates']);
        self::assertStringContainsString('differs only by letter case', $resolved['skipped'][0]);
        self::assertArrayHasKey('expenses-jordan/ldr/2026/08/2026-07-Combined-S2/record.json', $store->objects, 'the first number keeps its exact case');
    }

    public function test_dry_run_and_real_run_make_the_same_skip_decisions_and_the_dry_run_writes_nothing(): void
    {
        $store = new FakeInvoiceStore();
        $this->runBackfill($this->dupScanner(['2026-07-LDR-D1'], []), $store, true);
        $before = $store->objects;
        $scanner = $this->dupScanner([], ['2026-07-LDR-D1', '2026-07-LDR-D3']);
        [$dry] = $this->runBackfill($scanner, $store, false);
        self::assertSame($before, $store->objects, 'a dry run never writes');
        [$real] = $this->runBackfill($scanner, $store, true);
        self::assertSame($dry['skipped'], $real['skipped']);
        self::assertSame(array_column($dry['candidates'], 'number'), array_column($real['candidates'], 'number'));
    }

    public function test_rerun_of_the_same_record_is_existing_not_a_collision(): void
    {
        $store = new FakeInvoiceStore();
        $scanner = $this->dupScanner(['2026-07-LDR-D1'], []);
        $this->runBackfill($scanner, $store, true);
        [$resolved, $again] = $this->runBackfill($scanner, $store, true);
        self::assertSame([], $resolved['skipped'], 'the same location/folder is not "another location"');
        self::assertSame(['2026-07-LDR-D1'], $again['existing']);
        self::assertSame([], $again['written'] + $again['failed']);
    }

    public function test_when_the_bucket_cannot_be_listed_nothing_is_decided_and_the_error_is_loud(): void
    {
        $store = new FakeInvoiceStore();
        $store->listDenied = true;
        $this->expectException(\Cmd\Reports\Invoices\InvoiceRegistryException::class);
        $this->expectExceptionMessage('AccessDenied');
        (new LegacyBackfill())->resolve($this->dupScanner(['2026-07-LDR-D1'], [])->scan(), $store);
    }

    public function test_bucket_index_is_built_once_per_run_and_ignores_reservations_and_audit_keys(): void
    {
        $store = new FakeInvoiceStore();
        $store->objects['_reservations/LT-PL/20261015/01.json'] = '{}';
        $store->objects['_audit/downloads/2026/10/15/x-LT-PL-20261015-01-0.json'] = '{}';
        $store->objects['lending-tower/progress-law/2026/10/LT-PL-20261015-01/record.json'] = '{}';
        $store->objects['lending-tower/progress-law/2026/10/LT-PL-20261015-01/LT-PL-20261015-01.pdf'] = 'x';
        $scanner = $this->dupScanner(['2026-07-LDR-D1', '2026-07-LDR-D2', '2026-07-LDR-D3'], []);
        $resolved = (new LegacyBackfill())->resolve($scanner->scan(), $store);
        self::assertSame(1, $store->lists, 'one listing for the whole run, not one per invoice');
        self::assertCount(3, $resolved['candidates']);
        $taken = (new LegacyBackfill())->resolve([['number' => 'LT-PL-20261015-01', 'company' => 'x', 'type' => 'y', 'invoiceDate' => '2026-10-15', 'amount' => 1.0, 'period' => 'p', 'source' => 's', 'files' => [], 'fields' => []]], $store);
        self::assertSame([], $taken['candidates'], 'a number the registry already issued is taken too');
    }
    private function command(LegacyInvoiceScanner $scanner, FakeInvoiceStore $store, bool $write, ?string &$output): int
    {
        $cmd = new BackfillLegacyInvoices();
        $cmd->store = $store;
        $cmd->scanner = $scanner;
        $cmd->setInput(new ArrayInput($write ? ['--write' => true] : [], $cmd->getDefinition()));
        $cmd->setOutput(new OutputStyle(new ArrayInput([]), $buffer = new BufferedOutput()));
        $code = $cmd->handle();
        $output = $buffer->fetch();

        return $code;
    }

    public function test_command_dry_run_and_write_print_the_same_skip_lines_and_a_failed_listing_blocks_writing(): void
    {
        $store = new FakeInvoiceStore();
        $this->runBackfill($this->dupScanner(['2026-07-LDR-D1'], []), $store, true);
        $scanner = $this->dupScanner([], ['2026-07-LDR-D1', '2026-07-LDR-D3']);
        $skips = static fn (string $out): array => array_values(array_filter(explode("\n", $out), static fn (string $l) => str_starts_with($l, '[SKIP]') || str_contains($l, '[SKIP]')));
        $puts = $store->puts;
        self::assertSame(0, $this->command($scanner, $store, false, $dry));
        self::assertSame($puts, $store->puts, 'dry run wrote nothing');
        self::assertSame(0, $this->command($scanner, $store, true, $real));
        self::assertNotSame([], $skips($dry));
        self::assertSame($skips($dry), $skips($real));
        self::assertStringContainsString('already in the invoice bucket', $dry);

        $denied = new FakeInvoiceStore();
        $denied->listDenied = true;
        $puts = $denied->puts;
        self::assertSame(1, $this->command($this->dupScanner(['2026-07-LDR-D1'], []), $denied, true, $out));
        self::assertSame($puts, $denied->puts, 'nothing is written when the collision check cannot run');
        self::assertStringContainsString('Refusing to write without that check', $out);
        self::assertSame(0, $this->command($this->dupScanner(['2026-07-LDR-D1'], []), $denied, false, $out), 'a dry run still shows the plan');
        self::assertStringContainsString('did NOT check the bucket', $out);
    }
}
