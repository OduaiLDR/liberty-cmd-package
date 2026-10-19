<?php

namespace Cmd\Reports\Tests\Unit\Invoices;

use Cmd\Reports\Invoices\Backfill\LegacyInvoiceScanner;
use Cmd\Reports\Invoices\Backfill\LegacySource;
use Cmd\Reports\Invoices\Backfill\LocalDirectorySource;
use PHPUnit\Framework\TestCase;

final class LegacyInvoiceScannerTest extends TestCase
{
    /** Fake files shaped like the Runner host listing (Sep 2026 LT/Paramount/advance, old Jordan files). */
    public static function hostFiles(): array
    {
        $d = 'cmd/lending-tower-invoices/2026-09/';
        $manifest = ['invoices' => [
            ['invoiceNumber' => '2026-07-LDR-D1', 'companyKey' => 'ldr', 'month' => '2026-07', 'invoiceSet' => 'D1', 'totalUsd' => 1234.5, 'generatedAt' => '2026-08-27T10:00:00+00:00', 'generatedBy' => 'a@example.com', 'storageKey' => 'cmd/jordan-expenses/2026-07/invoices/files/2026-07-ldr-d1.xls'],
            ['invoiceNumber' => '2026-07-LT-D1', 'companyKey' => 'lt', 'month' => '2026-07', 'invoiceSet' => 'D1', 'totalUsd' => 10, 'generatedAt' => '2026-08-27T10:00:00+00:00', 'storageKey' => 'cmd/jordan-expenses/2026-07/invoices/files/missing.xls'],
        ]];
        $older = ['invoices' => [['invoiceNumber' => '2026-07-LDR-D1', 'companyKey' => 'ldr', 'month' => '2026-07', 'invoiceSet' => 'D1', 'totalUsd' => 999, 'generatedAt' => '2026-08-20T10:00:00+00:00', 'storageKey' => 'cmd/jordan-expenses/2026-07/invoices/files/2026-07-ldr-d1.xls']]];

        return [
            $d . 'LT-PL-2026-09.pdf' => ['%PDF-pl'],
            $d . 'LT-PL-2026-09 backup.xlsx' => ['PKpl'],
            $d . 'LT-PL-2026-09.sent.json' => [json_encode(['number' => 'LT-PL-2026-09', 'sent_at' => '2026-10-01T05:00:00-07:00', 'total_due' => '$5,500.00'])],
            $d . 'LT-LDR-2026-09.pdf' => ['%PDF-ldr'],
            $d . 'LT-LDR-2026-09.sent.json' => [json_encode(['number' => 'LT-LDR-2026-09', 'sent_at' => '2026-10-01T05:00:00-07:00', 'total_due' => '$1,234.56'])],
            // already registered by the new system: must not be imported again
            'cmd/lending-tower-invoices/2026-10/LT-PL-2026-10.sent.json' => [json_encode(['sent_at' => '2026-11-01T05:00:00-07:00', 'total_due' => '$1.00', 'invoice_number' => 'LT-PL-20261101-01'])],
            'cmd/paramount-invoices/2026-09.sent.json' => [json_encode(['sent_at' => '2026-10-01T07:30:00+00:00', 'invoice' => 'LDR-PAR-2026-09.pdf', 'payment' => 2500])],
            'cmd/paramount-invoices/2026-10.sent.json' => [json_encode(['sent_at' => '2026-11-01T07:30:00+00:00', 'invoice' => 'LDR-PAR-20261031-01.pdf', 'payment' => 1])],
            'cmd/jordan-expenses/2026-07/invoices/manifests/set-1.json' => [json_encode($manifest)],
            'cmd/jordan-expenses/2026-07/invoices/manifests/set-0.json' => [json_encode($older)],
            'cmd/jordan-expenses/2026-07/invoices/files/2026-07-ldr-d1.xls' => ['<xml/>'],
            'cmd/jordan-expenses/2026-07/lock.json' => ['{}'],
        ];
    }

    private function advanceFiles(): array
    {
        return [
            'LDR-ADV-20260901-53.pdf' => ['%PDF-a1', strtotime('2026-10-01 17:35:00 UTC')],
            'PLAW-ADV-20260901-53.pdf' => ['%PDF-a2', strtotime('2026-10-01 17:35:00 UTC')],
            'notes.txt' => ['ignore me'],
        ];
    }

    public function test_finds_every_legacy_invoice_with_old_numbers_pacific_dates_and_amounts(): void
    {
        $scanner = new LegacyInvoiceScanner(new FakeLegacySource(self::hostFiles()), new FakeLegacySource($this->advanceFiles()));
        $found = [];
        foreach ($scanner->scan() as $c) {
            $found[$c['number']] = $c;
        }
        ksort($found);
        self::assertSame(['2026-07-LDR-D1', 'LDR-ADV-20260901-53', 'LDR-PAR-2026-09', 'LT-LDR-2026-09', 'LT-PL-2026-09', 'PLAW-ADV-20260901-53'], array_keys($found));

        $pl = $found['LT-PL-2026-09'];
        self::assertSame(['lending-tower', 'progress-law', '2026-10-01', 5500.0, 'September 2026'], [$pl['company'], $pl['type'], $pl['invoiceDate'], $pl['amount'], $pl['period']]);
        self::assertSame(['LT-PL-2026-09.pdf', 'LT-PL-2026-09-backup.xlsx'], array_column($pl['files'], 'name'));
        self::assertSame(1234.56, $found['LT-LDR-2026-09']['amount']);
        self::assertSame(['LT-LDR-2026-09.pdf'], array_column($found['LT-LDR-2026-09']['files'], 'name'));

        $par = $found['LDR-PAR-2026-09'];
        self::assertSame(['paramount', 'paramount', '2026-10-01', 2500.0, []], [$par['company'], $par['type'], $par['invoiceDate'], $par['amount'], $par['files']], '07:30Z on Oct 1 is Oct 1 PT; no PDF is kept for Paramount');

        $adv = $found['PLAW-ADV-20260901-53'];
        self::assertSame(['advance-request', 'progress-law', '2026-10-01', null], [$adv['company'], $adv['type'], $adv['invoiceDate'], $adv['amount']]);

        $jo = $found['2026-07-LDR-D1'];
        self::assertSame(['expenses-jordan', 'ldr', 1234.5, '2026-08-27', '2026-07'], [$jo['company'], $jo['type'], $jo['amount'], $jo['invoiceDate'], $jo['period']], 'the newest manifest wins');
        self::assertSame(['2026-07-LDR-D1.xls'], array_column($jo['files'], 'name'));
    }

    public function test_registered_and_missing_files_are_skipped_with_a_reason(): void
    {
        $scanner = new LegacyInvoiceScanner(new FakeLegacySource(self::hostFiles()), new FakeLegacySource($this->advanceFiles()));
        $scanner->scan();
        self::assertSame([
            'LT-PL-2026-10: already registered as LT-PL-20261101-01',
            '2026-10: already registered as LDR-PAR-20261031-01',
            '2026-07-LT-D1: manifest lists it but the file is not on this host',
        ], $scanner->skipped);
    }

    public function test_guatemala_manifests_are_imported_verbatim_but_a_number_that_Jordan_already_uses_is_skipped_not_shadowed(): void
    {
        $gt = ['invoices' => [
            ['invoiceNumber' => '2026-07-LDR-D1', 'companyKey' => 'ldr', 'month' => '2026-07', 'invoiceSet' => 'D1', 'totalUsd' => 55.0, 'generatedAt' => '2026-08-28T10:00:00+00:00', 'storageKey' => 'cmd/guatemala-expenses/2026-07/invoices/files/a.xls'],
            ['invoiceNumber' => '2026-08-PLAW-S2', 'companyKey' => 'plaw', 'month' => '2026-08', 'invoiceSet' => 'S2', 'totalUsd' => 66.0, 'generatedAt' => '2026-09-28T10:00:00+00:00', 'storageKey' => 'cmd/guatemala-expenses/2026-08/invoices/files/b.xls'],
        ]];
        $files = self::hostFiles() + [
            'cmd/guatemala-expenses/2026-07/invoices/manifests/set-1.json' => [json_encode($gt)],
            'cmd/guatemala-expenses/2026-07/invoices/files/a.xls' => ['<gt-a/>'],
            'cmd/guatemala-expenses/2026-08/invoices/files/b.xls' => ['<gt-b/>'],
        ];
        $scanner = new LegacyInvoiceScanner(new FakeLegacySource($files), new FakeLegacySource([]));
        $found = [];
        foreach ($scanner->scan() as $c) {
            $found[$c['company'] . '|' . $c['number']] = $c;
        }
        self::assertArrayHasKey('expenses-guatemala|2026-08-PLAW-S2', $found);
        self::assertSame(['expenses-guatemala', 'plaw', 66.0, '2026-09-28'], [$found['expenses-guatemala|2026-08-PLAW-S2']['company'], $found['expenses-guatemala|2026-08-PLAW-S2']['type'], $found['expenses-guatemala|2026-08-PLAW-S2']['amount'], $found['expenses-guatemala|2026-08-PLAW-S2']['invoiceDate']]);
        self::assertSame(['<gt-b/>'], array_column($found['expenses-guatemala|2026-08-PLAW-S2']['files'], 'bytes'));
        self::assertArrayHasKey('expenses-jordan|2026-07-LDR-D1', $found, 'Jordan keeps its number');
        self::assertArrayNotHasKey('expenses-guatemala|2026-07-LDR-D1', $found, 'the page opens a number by name only, so a duplicate would hide one of the two files');
        self::assertContains('2026-07-LDR-D1: Guatemala has the same historical number as Jordan; not imported (numbers are never renamed). Needs a decision.', $scanner->skipped);
    }

    /** A host whose directory listing uses backslashes (Windows), like LocalDirectorySource there. */
    private function windowsView(FakeLegacySource $inner): LegacySource
    {
        return new class($inner) implements LegacySource {
            public function __construct(private readonly FakeLegacySource $inner)
            {
            }

            public function files(string $dir): array
            {
                return array_map(static fn (string $p) => str_replace('/', '\\', $p), $this->inner->files($dir));
            }

            public function read(string $path): ?string
            {
                return $this->inner->read(str_replace('\\', '/', $path));
            }

            public function modifiedAt(string $path): ?int
            {
                return $this->inner->modifiedAt(str_replace('\\', '/', $path));
            }
        };
    }

    public function test_backslash_paths_from_a_windows_host_are_found_exactly_like_unix_ones(): void
    {
        $unix = new LegacyInvoiceScanner(new FakeLegacySource(self::hostFiles()), new FakeLegacySource($this->advanceFiles()));
        $win = new LegacyInvoiceScanner($this->windowsView(new FakeLegacySource(self::hostFiles())), $this->windowsView(new FakeLegacySource($this->advanceFiles())));
        $numbers = static fn (array $c): array => array_map(static fn (array $x) => $x['number'], $c);
        $found = $win->scan();
        self::assertSame($numbers($unix->scan()), $numbers($found));
        self::assertCount(6, $found, 'LT, Paramount, advance and Jordan invoices are all found');
        self::assertSame($unix->scan(), $found, 'same candidates (files, dates, amounts) as on Unix');
        self::assertSame($unix->skipped, $win->skipped);
    }

    public function test_local_directory_source_lists_forward_slash_paths_for_nested_folders(): void
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'legacy-nested-' . bin2hex(random_bytes(3));
        mkdir($root . DIRECTORY_SEPARATOR . 'a' . DIRECTORY_SEPARATOR . 'b', 0777, true);
        file_put_contents($root . DIRECTORY_SEPARATOR . 'a' . DIRECTORY_SEPARATOR . 'b' . DIRECTORY_SEPARATOR . 'x.txt', 'hi');
        try {
            $src = new LocalDirectorySource($root . '/');
            self::assertSame(['a/b/x.txt'], $src->files('a'), 'never a backslash in a listed path');
            self::assertSame('hi', $src->read('a/b/x.txt'));
            self::assertSame('hi', $src->read('a\\b\\x.txt'), 'a backslash path reads the same file');
        } finally {
            TestFiles::removeTree($root);
        }
    }

    public function test_local_directory_source_is_read_only_and_cannot_leave_its_folder(): void
    {
        $root = sys_get_temp_dir() . '/legacy-src-' . bin2hex(random_bytes(3));
        mkdir($root . '/a', 0777, true);
        file_put_contents($root . '/a/x.txt', 'hi');
        $src = new LocalDirectorySource($root);
        self::assertSame(['a/x.txt'], $src->files('a'));
        self::assertSame('hi', $src->read('a/x.txt'));
        self::assertNull($src->read('../../etc/passwd'));
        self::assertSame([], $src->files('../..'));
        self::assertSame([], $src->files('nope'));
        self::assertFalse(in_array('write', get_class_methods($src), true) || in_array('delete', get_class_methods($src), true));
        unlink($root . '/a/x.txt');
        rmdir($root . '/a');
        rmdir($root);
    }
}
