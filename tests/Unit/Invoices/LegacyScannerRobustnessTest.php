<?php

namespace Cmd\Reports\Tests\Unit\Invoices;

use Cmd\Reports\Invoices\Backfill\LegacyBackfill;
use Cmd\Reports\Invoices\Backfill\LegacyInvoiceScanner;
use PHPUnit\Framework\TestCase;

/** The scanner must never crash a whole run on one odd record: default what is safe, skip and say why otherwise. */
final class LegacyScannerRobustnessTest extends TestCase
{
    private const GOOD = ['invoiceNumber' => '2026-07-LDR-D1', 'companyKey' => 'ldr', 'month' => '2026-07', 'invoiceSet' => 'D1', 'totalUsd' => 12.5, 'generatedAt' => '2026-08-27T10:00:00+00:00', 'generatedBy' => 'a@example.com', 'storageKey' => 'cmd/jordan-expenses/2026-07/invoices/files/good.xls'];

    private function scan(array $records, array $extra = [], string $manifest = null): LegacyInvoiceScanner
    {
        $files = $extra + ['cmd/jordan-expenses/2026-07/invoices/manifests/m.json' => [$manifest ?? json_encode(['invoices' => $records])]];
        foreach ($records as $r) {
            if (is_array($r) && is_string($r['storageKey'] ?? null)) {
                $files[$r['storageKey']] = ['<xls/>'];
            }
        }

        return new LegacyInvoiceScanner(new FakeLegacySource($files), new FakeLegacySource([]));
    }

    private function numbers(LegacyInvoiceScanner $s): array
    {
        return array_column($s->scan(), 'number');
    }

    private function good(string $n, array $override = []): array
    {
        return array_merge(self::GOOD, ['invoiceNumber' => $n, 'storageKey' => "cmd/jordan-expenses/2026-07/invoices/files/{$n}.xls"], $override);
    }

    public function test_each_key_missing_on_its_own_never_crashes_and_valid_records_still_import(): void
    {
        $records = [$this->good('2026-07-LDR-D1')];
        foreach (['invoiceSet' => '2026-07-LT-D1', 'companyKey' => '2026-07-PLAW-S2', 'month' => '2026-07-Combined-D2', 'totalUsd' => '2026-07-LDR-S1', 'generatedAt' => '2026-07-LT-S2', 'generatedBy' => '2026-07-PLAW-D1', 'storageKey' => '2026-07-LDR-D2'] as $key => $n) {
            $r = $this->good($n);
            unset($r[$key]);
            $records[] = $r;
        }
        $scanner = $this->scan($records, ['cmd/jordan-expenses/2026-07/invoices/files/2026-07-LT-D1.xls' => ['<xls/>']]);
        $found = [];
        foreach ($scanner->scan() as $c) {
            $found[$c['number']] = $c;
        }
        // invoiceSet inferred from the -D1 suffix, companyKey from the LT/PLAW/Combined token, month from the number or path:
        self::assertSame('Legacy import: expense invoice D1.', $found['2026-07-LT-D1']['fields']['notes']);
        self::assertSame('plaw', $found['2026-07-PLAW-S2']['type']);
        self::assertSame('ldr', $found['2026-07-Combined-D2']['type'], "the record's own companyKey wins over the number");
        self::assertSame('2026-07', $found['2026-07-Combined-D2']['period']);
        self::assertNull($found['2026-07-LDR-S1']['amount'], 'a missing total is "amount unknown", not $0.00');
        self::assertNull($found['2026-07-PLAW-D1']['fields']['generatedBy']);
        self::assertArrayHasKey('2026-07-LDR-D1', $found, 'valid record imported');
        // generatedAt missing: falls back to the file time (none in the fake) so it cannot be dated: skipped with a reason
        self::assertArrayNotHasKey('2026-07-LT-S2', $found);
        self::assertArrayNotHasKey('2026-07-LDR-D2', $found, 'no storageKey: nothing to import');
        $text = implode("\n", $scanner->skipped);
        self::assertStringContainsString('2026-07-LT-S2: no usable generatedAt', $text);
        self::assertStringContainsString('2026-07-LDR-D2: manifest record has no storageKey', $text);
    }

    public function test_several_keys_missing_and_a_record_that_cannot_be_inferred_is_skipped_with_a_reason(): void
    {
        $records = [
            $this->good('2026-07-LDR-D1'),
            ['invoiceNumber' => 'ODDNAME-1', 'storageKey' => 'cmd/jordan-expenses/2026-07/invoices/files/odd.xls', 'generatedAt' => '2026-08-27T10:00:00+00:00'],
            ['invoiceNumber' => '2026-07-LT-D2', 'generatedAt' => '2026-08-27T10:00:00+00:00'],
        ];
        $scanner = $this->scan($records);
        self::assertSame(['2026-07-LDR-D1'], $this->numbers($scanner));
        $text = implode("\n", $scanner->skipped);
        self::assertStringContainsString('ODDNAME-1: cannot tell which company', $text);
        self::assertStringContainsString('2026-07-LT-D2: manifest record has no storageKey', $text);
    }

    public function test_bad_shapes_and_types_are_skipped_or_defaulted_never_fatal(): void
    {
        $records = [
            $this->good('2026-07-LDR-D1'),
            'just a string', 42, null, [], ['invoiceNumber' => ''], ['invoiceNumber' => ['x']], ['invoiceNumber' => 7],
            $this->good('2026-07-LT-D1', ['totalUsd' => 'lots', 'invoiceSet' => ['D1'], 'companyKey' => ['lt'], 'month' => 202607, 'generatedBy' => ['x'], 'storageKey' => 'cmd/jordan-expenses/2026-07/invoices/files/2026-07-LT-D1.xls']),
            $this->good('2026-07-PLAW-D1', ['generatedAt' => 'not a date']),
            $this->good('2026-07-LDR-S1', ['storageKey' => ['x']]),
        ];
        $scanner = $this->scan($records);
        $found = [];
        foreach ($scanner->scan() as $c) {
            $found[$c['number']] = $c;
        }
        self::assertSame(['2026-07-LDR-D1', '2026-07-LT-D1'], array_keys($found));
        self::assertNull($found['2026-07-LT-D1']['amount']);
        self::assertSame('lt', $found['2026-07-LT-D1']['type']);
        self::assertSame('2026-07', $found['2026-07-LT-D1']['period']);
        self::assertGreaterThanOrEqual(7, count($scanner->skipped));
        foreach ($scanner->skipped as $why) {
            self::assertIsString($why);
        }
        self::assertStringContainsString('has no usable invoiceNumber', implode("\n", $scanner->skipped));
    }

    public function test_malformed_and_non_array_manifests_are_reported_and_the_others_still_import(): void
    {
        $extra = [
            'cmd/jordan-expenses/2026-06/invoices/manifests/bad.json' => ['{not json'],
            'cmd/jordan-expenses/2026-06/invoices/manifests/scalar.json' => ['"hello"'],
            'cmd/jordan-expenses/2026-06/invoices/manifests/null.json' => ['null'],
            'cmd/jordan-expenses/2026-06/invoices/manifests/noinvoices.json' => ['{"invoices": "nope"}'],
            'cmd/jordan-expenses/2026-06/invoices/manifests/empty.json' => [''],
        ];
        $scanner = $this->scan([$this->good('2026-07-LDR-D1')], $extra);
        self::assertSame(['2026-07-LDR-D1'], $this->numbers($scanner));
        $text = implode("\n", $scanner->skipped);
        foreach (['bad.json', 'scalar.json', 'null.json', 'noinvoices.json', 'empty.json'] as $name) {
            self::assertStringContainsString($name, $text, "{$name} is reported");
        }
    }

    public function test_lt_paramount_and_advance_markers_survive_bad_json_and_missing_fields(): void
    {
        $d = 'cmd/lending-tower-invoices/2026-09/';
        $files = [
            $d . 'LT-PL-2026-09.sent.json' => ['"scalar"'],
            $d . 'LT-LDR-2026-09.sent.json' => ['{broken'],
            'cmd/lending-tower-invoices/2026-08/LT-PL-2026-08.sent.json' => [json_encode(['sent_at' => 'garbage', 'total_due' => ['x']])],
            'cmd/lending-tower-invoices/2026-07/LT-PL-2026-07.sent.json' => [json_encode(['sent_at' => '2026-08-01T05:00:00-07:00'])],
            'cmd/paramount-invoices/2026-09.sent.json' => ['[1,2]'],
            'cmd/paramount-invoices/2026-08.sent.json' => [json_encode(['sent_at' => '2026-09-01T07:00:00+00:00', 'payment' => 'n/a'])],
            'cmd/paramount-invoices/2026-13.sent.json' => [json_encode(['sent_at' => '2026-09-01T07:00:00+00:00'])],
            'cmd/paramount-invoices/2026-07.sent.json' => ['null'],
        ];
        $advance = ['LDR-ADV-20260901-53.pdf' => ['%PDF'], 'PLAW-ADV-20261345-53.pdf' => ['%PDF'], 'LDR-ADV-20260801-T1.pdf' => ['%PDF', strtotime('2026-09-01 17:00 UTC')]];
        $scanner = new LegacyInvoiceScanner(new FakeLegacySource($files), new FakeLegacySource($advance));
        $found = [];
        foreach ($scanner->scan() as $c) {
            $found[$c['number']] = $c;
        }
        self::assertSame('2026-08-01', $found['LT-PL-2026-07']['invoiceDate']);
        self::assertNull($found['LT-PL-2026-07']['amount']);
        self::assertSame('2026-09-01', $found['LDR-PAR-2026-08']['invoiceDate']);
        self::assertNull($found['LDR-PAR-2026-08']['amount'], 'a non-numeric payment is "unknown"');
        self::assertSame('2026-09-01', $found['LDR-ADV-20260901-53']['invoiceDate'], 'no file time: the date in the number is used');
        self::assertSame('2026-09-01', $found['LDR-ADV-20260801-T1']['invoiceDate'], 'file time wins when there is one');
        foreach (['LT-PL-2026-09', 'LT-LDR-2026-09', 'LT-PL-2026-08', 'LDR-PAR-2026-09', 'LDR-PAR-2026-13', 'LDR-PAR-2026-07', 'PLAW-ADV-20261345-53'] as $n) {
            self::assertArrayNotHasKey($n, $found, "{$n} cannot be imported");
        }
        $text = implode("\n", $scanner->skipped);
        foreach (['LT-PL-2026-09', 'LT-LDR-2026-09', 'LT-PL-2026-08', '2026-09:', '2026-13', '2026-07:', 'PLAW-ADV-20261345-53'] as $needle) {
            self::assertStringContainsString($needle, $text);
        }
    }

    public function test_skip_reasons_are_the_same_in_a_dry_run_and_a_real_run_and_resolve_still_works(): void
    {
        $scanner = $this->scan([$this->good('2026-07-LDR-D1'), ['invoiceNumber' => 'ODDNAME-1']], ['cmd/jordan-expenses/2026-06/invoices/manifests/bad.json' => ['{x']]);
        $first = $scanner->scan();
        $skippedFirst = $scanner->skipped;
        $second = $scanner->scan();
        self::assertSame($skippedFirst, $scanner->skipped, 'a second scan reports the same reasons, nothing accumulates');
        self::assertSame($first, $second);
        $resolved = (new LegacyBackfill())->resolve($first, new FakeInvoiceStore());
        self::assertSame(['2026-07-LDR-D1'], array_column($resolved['candidates'], 'number'));
    }
}
