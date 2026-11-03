<?php

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Services\LegacyRetentionWorkbook;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LegacyRetentionWorkbookTest extends TestCase
{
    private function workbook(array $rows): string
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Commission Summary');
        $sheet->fromArray([['Retention Agent', 'Assigned', 'Retained', '% Retained', 'Tier', 'Commission', 'Location', 'Company'], ...$rows], null, 'A1');
        $path = tempnam(sys_get_temp_dir(), 'legacy-retention-');
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();
        return $path;
    }

    public function test_reads_assigned_and_unassigned_without_claiming_pricing_or_receipt(): void
    {
        $path = $this->workbook([['Jane', 1, 1, 1, 3, 10.25], ['Unassigned Agents'], ['Sam', 1, 1, 1, 1, 2]]);
        try {
            $out = LegacyRetentionWorkbook::read($path, 'ldr', hash_file('sha256', $path));
            self::assertSame([1025, 200], array_column($out['entries'], 'commission_cents'));
            self::assertSame(['assigned', 'unassigned'], array_column($out['entries'], 'assignment'));
            self::assertSame('unverified', $out['historical_line_pricing']);
            self::assertSame('unverified', $out['execution_receipt']);
        } finally { unlink($path); }
    }

    public function test_duplicate_names_fail_closed(): void
    {
        $path = $this->workbook([['Jane', 1, 1, 1, 3, 10], ['Unassigned Agents'], ['jane', 1, 1, 1, 1, 2]]);
        try {
            $this->expectExceptionMessage('Duplicate agent');
            LegacyRetentionWorkbook::read($path, 'ldr', hash_file('sha256', $path));
        } finally { unlink($path); }
    }

    public function test_mismatched_hash_fails_closed(): void
    {
        $path = $this->workbook([['Jane', 1, 1, 1, 3, 10]]);
        try {
            $this->expectExceptionMessage('recorded hash');
            LegacyRetentionWorkbook::read($path, 'plaw', str_repeat('0', 64));
        } finally { unlink($path); }
    }

    public function test_formula_amount_fails_closed(): void
    {
        $path = $this->workbook([['Jane', 1, 1, 1, 3, '=1+2']]);
        try {
            $this->expectExceptionMessage('Invalid saved legacy commission');
            LegacyRetentionWorkbook::read($path, 'ldr', hash_file('sha256', $path));
        } finally { unlink($path); }
    }

    #[DataProvider('invalidRows')]
    public function test_invalid_rows_fail_closed(array $rows): void
    {
        $path = $this->workbook($rows);
        try {
            $this->expectException(\RuntimeException::class);
            LegacyRetentionWorkbook::read($path, 'ldr', hash_file('sha256', $path));
        } finally { unlink($path); }
    }

    public static function invalidRows(): array
    {
        return [
            'fractional cent' => [[['Jane', 1, 1, 1, 3, 10.001]]],
            'negative' => [[['Jane', 1, 1, 1, 3, -1]]],
            'blank amount' => [[['Jane', 1, 1, 1, 3, null]]],
            'missing agent' => [[['', 1, 1, 1, 3, 10]]],
            'repeated section' => [[['Jane', 1, 1, 1, 3, 10], ['Unassigned Agents'], ['Unassigned Agents']]],
        ];
    }
}
