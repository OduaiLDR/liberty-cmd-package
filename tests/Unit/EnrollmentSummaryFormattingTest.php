<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Console\Commands\GenerateEnrollmentSummaryReport\Formatter;
use Illuminate\Container\Container;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class EnrollmentSummaryFormattingTest extends TestCase
{
    private string $storage;
    private string $path = '';
    private ?Spreadsheet $book = null;

    protected function setUp(): void
    {
        $this->storage = sys_get_temp_dir() . '/enrollment-formatting-' . bin2hex(random_bytes(6));
        mkdir($this->storage . '/app', 0777, true);
        Container::setInstance(new class($this->storage) extends Container {
            public function __construct(private string $reportStorage) {}

            public function storagePath(string $path = ''): string
            {
                return $this->reportStorage . ($path === '' ? '' : '/' . ltrim($path, '/\\'));
            }
        });
    }

    protected function tearDown(): void
    {
        $this->book?->disconnectWorksheets();
        if ($this->path !== '' && is_file($this->path)) {
            unlink($this->path);
        }
        rmdir($this->storage . '/app');
        rmdir($this->storage);
        Container::setInstance(null);
    }

    public function test_hidden_detail_immediately_follows_summary_and_both_headers_match_existing_blue(): void
    {
        $book = $this->buildAndReload($this->detailRows());
        self::assertSame(
            ['Enrollment Summary', 'Sellable Debt Paying Detail', 'Peel Offs', 'Tranche Summary'],
            $book->getSheetNames()
        );
        self::assertSame(0, $book->getActiveSheetIndex());
        self::assertSame(Worksheet::SHEETSTATE_HIDDEN, $book->getSheet(1)->getSheetState());
        $existingBlue = $book->getSheetByName('Tranche Summary')->getStyle('A1')->getFill()->getStartColor()->getARGB();
        self::assertSame('FF8EA9DB', $existingBlue);

        foreach (['Enrollment Summary' => 'D', 'Sellable Debt Paying Detail' => 'E'] as $name => $lastColumn) {
            $sheet = $book->getSheetByName($name);
            foreach (range('A', $lastColumn) as $column) {
                $style = $sheet->getStyle($column . '3');
                self::assertSame(Fill::FILL_SOLID, $style->getFill()->getFillType());
                self::assertSame($existingBlue, $style->getFill()->getStartColor()->getARGB());
                self::assertTrue($style->getFont()->getBold());
            }
        }
    }

    public function test_name_sort_preserves_row_data_and_writes_numeric_excel_dates_with_blanks_kept_blank(): void
    {
        $sheet = $this->buildAndReload($this->detailRows())->getSheet(1);
        self::assertSame(['alpha Contact', 'Bravo Client', 'Zulu Client'], array_column($sheet->rangeToArray('B4:B6'), 0));
        self::assertSame(['000999', '000007', '000001'], array_column($sheet->rangeToArray('A4:A6'), 0));
        self::assertNull($sheet->getCell('D4')->getValue());
        self::assertNull($sheet->getCell('E4')->getValue());
        foreach (['D5' => '2026-10-02', 'E5' => '2026-10-05', 'D6' => '2026-09-07', 'E6' => '2026-09-09'] as $address => $date) {
            $cell = $sheet->getCell($address);
            self::assertSame(DataType::TYPE_NUMERIC, $cell->getDataType());
            self::assertEquals(ExcelDate::PHPToExcel(new \DateTimeImmutable($date)), $cell->getValue());
            self::assertSame('m/d/yyyy', $cell->getStyle()->getNumberFormat()->getFormatCode());
        }
        self::assertEquals(74.5, $sheet->getCell('C4')->getValue());
        self::assertEquals(10, $sheet->getCell('C5')->getValue());
        self::assertEquals(125.5, $sheet->getCell('C6')->getValue());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('detailStates')]
    public function test_saved_workbook_freezes_first_three_rows_and_selects_one_cell(bool $hasRows): void
    {
        $sheet = $this->buildAndReload($hasRows ? $this->detailRows() : [])->getSheet(1);
        self::assertSame(Worksheet::SHEETSTATE_HIDDEN, $sheet->getSheetState());
        self::assertSame('A4', $sheet->getFreezePane());

        $zip = new ZipArchive();
        self::assertTrue($zip->open($this->path));
        $xml = simplexml_load_string($zip->getFromName('xl/worksheets/sheet2.xml'));
        $zip->close();
        $xml->registerXPathNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $pane = $xml->xpath('//s:sheetView/s:pane')[0];
        self::assertSame('3', (string) $pane['ySplit']);
        self::assertSame('A4', (string) $pane['topLeftCell']);
        self::assertSame('frozen', (string) $pane['state']);
        $selections = $xml->xpath('//s:sheetView/s:selection');
        self::assertNotEmpty($selections);
        foreach ($selections as $selection) {
            self::assertSame('A4', (string) $selection['activeCell']);
            self::assertSame('A4', (string) $selection['sqref']);
        }
    }

    public static function detailStates(): array
    {
        return ['empty detail' => [false], 'populated detail' => [true]];
    }

    private function buildAndReload(array $details): Spreadsheet
    {
        $rows = [['label' => 'Gross Enrollments', 'values' => ['Total' => 3, 'LDR' => 2, 'Legal' => 1],
            'format' => 'count', 'bold' => false, 'blank' => false]];
        $workbook = (new Formatter())->buildWorkbook($rows, ['Total', 'LDR', 'Legal'], '2026-10-30', [], null, null,
            ['nsf' => [], 'cancel' => [], 'unprocessed' => []], $details, 'September');
        $this->path = $workbook['path'];
        return $this->book = IOFactory::load($this->path);
    }

    private function detailRows(): array
    {
        return [
            ['LLG_ID' => '000001', 'Name' => 'Zulu Client', 'Debt_Amount' => 125.5,
                'First_Payment_Date' => '2026-09-07', 'First_Payment_Cleared_Date' => '2026-09-09'],
            ['LLG_ID' => '000999', 'Name' => 'alpha Contact', 'Debt_Amount' => 74.5,
                'First_Payment_Date' => null, 'First_Payment_Cleared_Date' => ''],
            ['LLG_ID' => '000007', 'Name' => 'Bravo Client', 'Debt_Amount' => 10,
                'First_Payment_Date' => '2026-10-02', 'First_Payment_Cleared_Date' => '2026-10-05'],
        ];
    }
}
