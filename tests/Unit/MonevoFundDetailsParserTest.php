<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Console\Commands\ReferralCommissions\MonevoFundDetailsParser;
use Cmd\Reports\Tests\TestCase;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * The VBA reads Monevo's workbook by position (A, E, K, L, M, O, P) after deleting column B when
 * its header is "Processed Date". No real export is on file yet, so these workbooks are built to
 * that layout; the header names are placeholders until the first live file reports them.
 */
class MonevoFundDetailsParserTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        $this->tempFiles = [];

        parent::tearDown();
    }

    public function test_processed_date_column_is_removed_before_positions_are_read(): void
    {
        // Column B present: the funding date is A, then B shifts everything right by one.
        $path = $this->workbook(true, [
            [ExcelDate::PHPToExcel(new \DateTimeImmutable('2026-08-24')), ExcelDate::PHPToExcel(new \DateTimeImmutable('2026-08-25')), 'x', 'x', 'x', 58.8, 'x', 'x', 'x', 'x', 'x', 4200.0, 35.8, 60, 'x', 'Upstart', 1246896011.0],
        ]);

        $parsed = MonevoFundDetailsParser::parse($path);

        $this->assertTrue($parsed['processed_date_removed']);
        $this->assertCount(1, $parsed['rows']);
        $this->assertSame([
            'row' => 2,
            'client_id' => '1246896011',
            'funding_date' => '2026-08-24',
            'commission' => 58.8,
            'loan_amount' => 4200.0,
            'loan_amount_text' => '4200',
            'rate' => 35.8,
            'rate_text' => '35.8',
            'term' => 60,
            'term_text' => '60',
            'lender' => 'Upstart',
        ], $parsed['rows'][0]);
        $this->assertSame('Funded Date', $parsed['headers'][0]);
        $this->assertSame('Client Reference', $parsed['headers'][15]);
    }

    public function test_without_processed_date_the_columns_are_read_as_they_are(): void
    {
        $path = $this->workbook(false, [
            ['2026-08-21', 'x', 'x', 'x', 65.8, 'x', 'x', 'x', 'x', 'x', 4700, 35.8, 60, 'x', 'Upstart', '1246800345'],
            ['2026-08-21', 'x', 'x', 'x', '', 'x', 'x', 'x', 'x', 'x', '', '', '', 'x', 'Best Egg', ''],           // no client id: skipped, reported
            ['', '', '', '', '', '', '', '', '', '', '', '', '', '', '', ''],                                       // blank: ignored silently
        ]);

        $parsed = MonevoFundDetailsParser::parse($path);

        $this->assertFalse($parsed['processed_date_removed']);
        $this->assertCount(1, $parsed['rows']);
        $row = $parsed['rows'][0];
        $this->assertSame('1246800345', $row['client_id'], 'a text id stays as typed');
        $this->assertSame('2026-08-21', $row['funding_date'], 'a text date parses too');
        $this->assertSame(4700.0, $row['loan_amount']);
        $this->assertSame('4700', $row['loan_amount_text']);
        $this->assertSame(['row 3: no client id in column P'], $parsed['skipped']);
    }

    public function test_blank_numeric_cells_become_null_not_zero(): void
    {
        // VBA: IIf(L = "", "NULL", L) for rate/term; Val(E) for commission (0 when blank).
        $path = $this->workbook(false, [
            ['2026-08-20', '', '', '', '', '', '', '', '', '', 9000, '', '', '', 'SoFi', 42],
        ]);

        $row = MonevoFundDetailsParser::parse($path)['rows'][0];

        $this->assertSame(0.0, $row['commission']);
        $this->assertNull($row['rate']);
        $this->assertSame('', $row['rate_text']);
        $this->assertNull($row['term']);
        $this->assertSame('42', $row['client_id']);
    }

    /**
     * @param list<list<mixed>> $rows
     */
    private function workbook(bool $withProcessedDate, array $rows): string
    {
        $headers = ['Funded Date', 'B', 'C', 'D', 'E Commission', 'F', 'G', 'H', 'I', 'J', 'K Loan Amount', 'L APR', 'M Term', 'N', 'O Lender', 'Client Reference'];
        if ($withProcessedDate) {
            array_splice($headers, 1, 0, [MonevoFundDetailsParser::PROCESSED_DATE_HEADER]);
        }
        // Pad to the width of the widest data row so positions past the last header still exist.
        $width = max(count($headers), ...array_map('count', $rows));
        $headers = array_pad($headers, $width, '');

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray($headers, null, 'A1');

        foreach ($rows as $r => $values) {
            foreach ($values as $c => $value) {
                $cell = $sheet->getCell([$c + 1, $r + 2]);
                if ($value === '') {
                    continue;
                }
                if (is_string($value) && preg_match('/^\d+$/', $value)) {
                    $cell->setValueExplicit($value, DataType::TYPE_STRING);   // an id typed as text
                    continue;
                }
                $cell->setValue($value);
                if (is_float($value) && $value > 40000 && $value < 60000) {
                    $cell->getStyle()->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_DATE_YYYYMMDD);   // a date serial
                }
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'monevo') . '.xlsx';
        $this->tempFiles[] = $path;
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }
}
