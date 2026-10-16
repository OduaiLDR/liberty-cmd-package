<?php

namespace Cmd\Reports\Tests\Unit\Invoices;

use Cmd\Reports\Invoices\Backfill\AmountParser;
use Cmd\Reports\Invoices\Backfill\LegacyInvoiceScanner;
use PHPUnit\Framework\TestCase;

/** Amounts are validated as a whole (never "cleaned"), and markers/manifests must be JSON objects, with file times present. */
final class LegacyScannerStrictnessTest extends TestCase
{
    private const MTIME = 1788000000; // a file time exists for every fake file, as on a real host

    public static function amounts(): array
    {
        return [
            'plain' => ['1000', 1000.0], 'decimal' => ['1234.5', 1234.5], 'cents' => ['12.50', 12.5], 'zero' => ['0', 0.0], 'leading dot' => ['.5', 0.5],
            'exponent' => ['1e3', 1000.0], 'exponent caps' => ['1E3', 1000.0], 'signed exponent' => ['1.5e+3', 1500.0], 'neg exponent' => ['25e-1', 2.5],
            'commas' => ['1,234,567.89', 1234567.89], 'dollar' => ['$1,234.50', 1234.5], 'padded' => ["  \$5,500.00 \n", 5500.0],
            'negative' => ['-12.50', -12.5], 'negative dollar' => ['-$12.50', -12.5], 'accounting' => ['($12.50)', -12.5], 'accounting plain' => ['(12.50)', -12.5],
            'json int' => [7, 7.0], 'json float' => [7.25, 7.25],
            // everything below must be "unknown" (null), never a repaired number
            'text before' => ['unavailable-123', null], 'text after' => ['123 USD', null], 'embedded' => ['1a2', null], 'empty' => ['', null], 'blank' => ['   ', null],
            'only dollar' => ['$', null], 'only sign' => ['-', null], 'double sign' => ['--5', null], 'plus minus' => ['+-5', null], 'plus' => ['+5', null],
            'trailing minus' => ['5-', null], 'two dots' => ['1.2.3', null], 'trailing dot' => ['5.', null], 'bad commas' => ['1,23,456', null], 'comma only' => [',5', null],
            'comma with exponent' => ['1,000e3', null], 'exponent only' => ['e3', null], 'dangling exponent' => ['1e', null], 'double exponent' => ['1e3e3', null],
            'two dollars' => ['$$5', null], 'dollar after' => ['5$', null], 'space inside' => ['1 000', null], 'open paren' => ['(5', null], 'both signs' => ['-(5)', null],
            'nan' => ['NAN', null], 'inf' => ['INF', null], 'infinity' => ['Infinity', null], 'huge' => ['1e999', null], 'hex' => ['0x10', null], 'array' => [[5], null],
            'null' => [null, null], 'bool' => [true, null], 'json inf' => [INF, null], 'json nan' => [NAN, null], 'nul byte' => ["5\0", null],
        ];
    }

    /** @dataProvider amounts */
    #[\PHPUnit\Framework\Attributes\DataProvider('amounts')]
    public function test_amount_is_validated_as_a_whole(mixed $input, ?float $expected): void
    {
        $got = AmountParser::dollars($input);
        $expected === null ? self::assertNull($got, var_export($input, true)) : self::assertEqualsWithDelta($expected, $got, 1e-9, var_export($input, true));
    }

    private function scan(array $files, array $advance = []): LegacyInvoiceScanner
    {
        return new LegacyInvoiceScanner(new FakeLegacySource($files), new FakeLegacySource($advance));
    }

    private function jordan(array $totals): array
    {
        $records = [];
        $files = [];
        foreach ($totals as $n => $total) {
            $r = ['invoiceNumber' => $n, 'companyKey' => 'ldr', 'month' => '2026-07', 'invoiceSet' => 'D1', 'generatedAt' => '2026-08-27T10:00:00+00:00', 'storageKey' => "cmd/jordan-expenses/2026-07/invoices/files/{$n}.xls"];
            if ($total !== '__missing__') {
                $r['totalUsd'] = $total;
            }
            $records[] = $r;
            $files[$r['storageKey']] = ['<xls/>', self::MTIME];
        }
        $files['cmd/jordan-expenses/2026-07/invoices/manifests/m.json'] = [json_encode(['invoices' => $records]), self::MTIME];

        return $files;
    }

    public function test_the_two_reported_cases_for_expense_paramount_and_lt(): void
    {
        $found = [];
        $files = $this->jordan(['2026-07-LDR-D1' => '1e3', '2026-07-LDR-S1' => 'unavailable-123', '2026-07-LDR-D2' => '$2,500.00', '2026-07-LDR-S2' => '($12.50)']);
        $files += [
            'cmd/paramount-invoices/2026-08.sent.json' => [json_encode(['sent_at' => '2026-09-01T07:00:00+00:00', 'payment' => '1e3']), self::MTIME],
            'cmd/paramount-invoices/2026-09.sent.json' => [json_encode(['sent_at' => '2026-10-01T07:00:00+00:00', 'payment' => 'unavailable-123']), self::MTIME],
            'cmd/lending-tower-invoices/2026-08/LT-PL-2026-08.sent.json' => [json_encode(['sent_at' => '2026-09-01T07:00:00+00:00', 'total_due' => '1e3']), self::MTIME],
            'cmd/lending-tower-invoices/2026-09/LT-PL-2026-09.sent.json' => [json_encode(['sent_at' => '2026-10-01T07:00:00+00:00', 'total_due' => 'unavailable-123']), self::MTIME],
        ];
        foreach ($this->scan($files)->scan() as $c) {
            $found[$c['number']] = $c['amount'];
        }
        self::assertSame(1000.0, $found['2026-07-LDR-D1']);
        self::assertNull($found['2026-07-LDR-S1']);
        self::assertSame(2500.0, $found['2026-07-LDR-D2']);
        self::assertSame(-12.5, $found['2026-07-LDR-S2']);
        self::assertSame(1000.0, $found['LDR-PAR-2026-08']);
        self::assertNull($found['LDR-PAR-2026-09']);
        self::assertSame(1000.0, $found['LT-PL-2026-08']);
        self::assertNull($found['LT-PL-2026-09']);
    }

    public function test_markers_and_manifests_must_be_json_objects_even_when_file_times_exist(): void
    {
        $lt = static fn (string $n, string $body) => ["cmd/lending-tower-invoices/2026-0{$n}/LT-PL-2026-0{$n}.sent.json" => [$body, self::MTIME]];
        $files = $lt('1', '[]') + $lt('2', '[1,2]') + $lt('3', '{}') + $lt('4', '"text"') + $lt('5', '5') + $lt('6', 'null') + $lt('7', 'true') + $lt('8', '[{"sent_at":"2026-09-01T07:00:00+00:00"}]');
        $files += [
            'cmd/paramount-invoices/2026-01.sent.json' => ['[]', self::MTIME], 'cmd/paramount-invoices/2026-02.sent.json' => ['[1,2]', self::MTIME],
            'cmd/paramount-invoices/2026-03.sent.json' => ['{}', self::MTIME], 'cmd/paramount-invoices/2026-04.sent.json' => ['7', self::MTIME],
            'cmd/jordan-expenses/2026-07/invoices/manifests/a.json' => ['[]', self::MTIME], 'cmd/jordan-expenses/2026-07/invoices/manifests/b.json' => ['[1,2]', self::MTIME],
            'cmd/jordan-expenses/2026-07/invoices/manifests/c.json' => ['{}', self::MTIME], 'cmd/jordan-expenses/2026-07/invoices/manifests/d.json' => ['{"invoices":{"x":{"invoiceNumber":"2026-07-LDR-D1"}}}', self::MTIME],
            'cmd/guatemala-expenses/2026-07/invoices/manifests/e.json' => ['[[{"invoiceNumber":"2026-07-LDR-D1"}]]', self::MTIME],
        ];
        $scanner = $this->scan($files);
        self::assertSame([], $scanner->scan(), 'nothing that is not a JSON object may import, even with file times');
        $text = implode("\n", $scanner->skipped);
        foreach (['LT-PL-2026-01', 'LT-PL-2026-02', 'LT-PL-2026-03', 'LT-PL-2026-04', 'LT-PL-2026-05', 'LT-PL-2026-06', 'LT-PL-2026-07', 'LT-PL-2026-08', '2026-01:', '2026-02:', '2026-03:', '2026-04:', 'a.json', 'b.json', 'c.json', 'd.json', 'e.json'] as $needle) {
            self::assertStringContainsString($needle, $text, $needle);
        }
        self::assertStringContainsString('an empty object', $text);
        self::assertStringContainsString('a JSON list', $text);
    }

    public function test_a_valid_object_in_the_same_run_still_imports_and_records_must_be_objects_too(): void
    {
        $files = $this->jordan(['2026-07-LDR-D1' => 5]);
        $manifest = json_decode($files['cmd/jordan-expenses/2026-07/invoices/manifests/m.json'][0], true);
        $manifest['invoices'][] = [1, 2];
        $manifest['invoices'][] = ['x' => 'y'];
        $files['cmd/jordan-expenses/2026-07/invoices/manifests/m.json'] = [json_encode($manifest), self::MTIME];
        $files['cmd/lending-tower-invoices/2026-08/LT-PL-2026-08.sent.json'] = ['[]', self::MTIME];
        $scanner = $this->scan($files);
        self::assertSame(['2026-07-LDR-D1'], array_column($scanner->scan(), 'number'));
        self::assertGreaterThanOrEqual(3, count($scanner->skipped));
    }
}
