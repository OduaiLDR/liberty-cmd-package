<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Console\Commands\GenerateEnrollmentSummaryReport\Formatter;
use Cmd\Reports\Tests\TestCase;
use Illuminate\Container\Container;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * The "Peel Offs" sheet (PRD 2026-09-10 §3–§6) as written into the workbook, read back with the
 * XLSX reader so what is asserted is what Excel will open.
 */
class EnrollmentSummaryPeelOffsSheetTest extends TestCase
{
    private string $path = '';

    protected function setUp(): void
    {
        parent::setUp();

        // Formatter::buildWorkbook() saves under storage_path('app/...'). Under a full Laravel
        // autoloader that helper asks the container for storagePath(); give it one.
        $app = new class extends Container {
            public function storagePath(string $path = ''): string
            {
                $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'liberty-cmd-package-tests';
                if (!is_dir($base . DIRECTORY_SEPARATOR . 'app')) {
                    mkdir($base . DIRECTORY_SEPARATOR . 'app', 0777, true);
                }

                return $path === '' ? $base : $base . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
            }
        };
        Container::setInstance($app);
    }

    protected function tearDown(): void
    {
        if ($this->path !== '' && is_file($this->path)) {
            @unlink($this->path);
        }
        Container::setInstance(null);
        parent::tearDown();
    }

    public function test_sheet_sits_after_enrollment_summary_and_holds_three_tables_plus_a_summary(): void
    {
        $sheet = $this->buildAndReload();

        // Gridlines: asserted on the XML — PhpSpreadsheet 1.30's reader turns showGridlines back on
        // when it meets the writer's default <printOptions gridLinesSet="1">.
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($this->path));
        $this->assertMatchesRegularExpression('/<sheetView[^>]*\sshowGridLines="false"/', (string) $zip->getFromName('xl/worksheets/sheet2.xml'));
        $zip->close();

        $this->assertSame('Peel Offs For 9/10/2026', $sheet->getCell('A1')->getValue());

        $titles = $this->cellsInColumnA($sheet, ['NSF Peel Offs', 'Cancel Peel Offs', 'Unprocessed Peel Offs', 'Peel Offs Summary']);
        $this->assertSame(['NSF Peel Offs', 'Cancel Peel Offs', 'Unprocessed Peel Offs', 'Peel Offs Summary'], array_keys($titles));

        // PRD §4 field order on every table header row.
        $expectedHeaders = ['LLG_ID', 'Client', 'Agent', 'Debt_Amount', 'First_Payment_Date', 'Cancel_Date', 'NSF_Date', 'Company', 'Paying In'];
        foreach (['NSF Peel Offs', 'Cancel Peel Offs', 'Unprocessed Peel Offs'] as $title) {
            $headerRow = $titles[$title] + 1;
            $this->assertSame($expectedHeaders, $this->rowValues($sheet, $headerRow, 9), $title);
        }
    }

    public function test_rows_are_grouped_progress_law_first_with_subtotals_and_a_grand_total(): void
    {
        $sheet = $this->buildAndReload();
        $start = $this->cellsInColumnA($sheet, ['Unprocessed Peel Offs'])['Unprocessed Peel Offs'] + 2;

        // Data rows arrive pre-sorted from the builder; the sheet groups them by company.
        $this->assertSame(['LLG-U3', 'Ursula', 'Agent U', 1000.0, null, null, null, 'Progress Law', 'August'], $this->rowValues($sheet, $start, 9));
        $this->assertSame('Progress Law Subtotal (1)', $sheet->getCell('A' . ($start + 1))->getValue());
        $this->assertSame(1000.0, $sheet->getCell('D' . ($start + 1))->getValue());

        $this->assertSame('LLG-U1', $sheet->getCell('A' . ($start + 2))->getValue());
        $this->assertSame('LLG-U2', $sheet->getCell('A' . ($start + 3))->getValue());
        $this->assertSame('LDR Subtotal (2)', $sheet->getCell('A' . ($start + 4))->getValue());
        $this->assertSame(500.0, $sheet->getCell('D' . ($start + 4))->getValue());

        $this->assertSame('Grand Total (3)', $sheet->getCell('A' . ($start + 5))->getValue());
        $this->assertSame(1500.0, $sheet->getCell('D' . ($start + 5))->getValue());
        $this->assertSame('$#,##0.00', $sheet->getCell('D' . ($start + 5))->getStyle()->getNumberFormat()->getFormatCode());
    }

    public function test_an_empty_company_still_gets_a_zero_subtotal_so_the_layout_is_stable(): void
    {
        $sheet = $this->buildAndReload();
        $start = $this->cellsInColumnA($sheet, ['Cancel Peel Offs'])['Cancel Peel Offs'] + 2;

        // The only cancel is LDR: Progress Law subtotal row comes first, with nothing above it.
        $this->assertSame('Progress Law Subtotal (0)', $sheet->getCell("A{$start}")->getValue());
        $this->assertSame(0.0, (float) $sheet->getCell("D{$start}")->getValue());
        $this->assertSame('LLG-C1', $sheet->getCell('A' . ($start + 1))->getValue());
        $this->assertSame('LDR Subtotal (1)', $sheet->getCell('A' . ($start + 2))->getValue());
        $this->assertSame('Grand Total (1)', $sheet->getCell('A' . ($start + 3))->getValue());
    }

    public function test_dates_are_real_excel_dates(): void
    {
        $sheet = $this->buildAndReload();
        $start = $this->cellsInColumnA($sheet, ['NSF Peel Offs'])['NSF Peel Offs'] + 2;

        $nsfDateCell = $sheet->getCell("G{$start}");
        $this->assertIsNumeric($nsfDateCell->getValue());
        $this->assertSame('2026-09-10', ExcelDate::excelToDateTimeObject((float) $nsfDateCell->getValue())->format('Y-m-d'));
        $this->assertSame('m/d/yyyy', $nsfDateCell->getStyle()->getNumberFormat()->getFormatCode());
        $this->assertNull($sheet->getCell("F{$start}")->getValue(), 'a NULL Cancel_Date stays blank');
    }

    public function test_summary_totals_each_category_by_company_with_an_all_row(): void
    {
        $sheet = $this->buildAndReload();
        $top = $this->cellsInColumnA($sheet, ['Peel Offs Summary'])['Peel Offs Summary'] + 1;

        $this->assertSame(['Category', 'Progress Law', 'LDR', 'Grand Total'], $this->rowValues($sheet, $top, 4));
        $this->assertSame(['NSF Peel Offs', 500.0, 300.0, 800.0], $this->rowValues($sheet, $top + 1, 4));
        $this->assertSame(['Cancel Peel Offs', 0.0, 200.0, 200.0], $this->rowValues($sheet, $top + 2, 4));
        $this->assertSame(['Unprocessed Peel Offs', 1000.0, 500.0, 1500.0], $this->rowValues($sheet, $top + 3, 4));
        $this->assertSame(['All Peel Offs', 1500.0, 1000.0, 2500.0], $this->rowValues($sheet, $top + 4, 4));
        $this->assertTrue($sheet->getCell('A' . ($top + 4))->getStyle()->getFont()->getBold());
    }

    public function test_workbook_without_peel_offs_keeps_the_old_sheet_list(): void
    {
        $workbook = (new Formatter())->buildWorkbook($this->summaryRows(), ['Total', 'LDR', 'Legal'], '2026-09-10');
        $this->path = $workbook['path'];

        $this->assertSame(['Enrollment Summary'], IOFactory::load($this->path)->getSheetNames());
    }

    private function buildAndReload(): Worksheet
    {
        $row = static fn (string $llg, string $client, float $debt, string $company, array $extra = []): array => array_merge([
            'LLG_ID' => $llg, 'Client' => $client, 'Agent' => 'Agent ' . substr($llg, 4, 1), 'Debt_Amount' => $debt,
            'First_Payment_Date' => '2026-08-05', 'Cancel_Date' => null, 'NSF_Date' => null,
            'Enrollment_Plan' => $company === 'Progress Law' ? 'Progress Law 29%' : 'LDR 29%',
            'Payment_Date' => '2026-08-05', 'Unprocessed_Date' => '2026-08-09', 'Company' => $company, 'Paying_In' => '2026-08', 'Paying_In_Label' => 'August',
        ], $extra);

        $peelOffs = [
            'nsf' => [
                $row('LLG-N1', 'Nadia', 500.0, 'Progress Law', ['NSF_Date' => '2026-09-10']),
                $row('LLG-N2', 'Ned', 300.0, 'LDR', ['NSF_Date' => '2026-09-10']),
            ],
            'cancel' => [
                $row('LLG-C1', 'Cara', 200.0, 'LDR', ['Cancel_Date' => '2026-09-10']),
            ],
            'unprocessed' => [
                $row('LLG-U3', 'Ursula', 1000.0, 'Progress Law', ['First_Payment_Date' => null]),
                $row('LLG-U1', 'Uma', 400.0, 'LDR'),
                $row('LLG-U2', 'Uri', 100.0, 'LDR'),
            ],
        ];

        $workbook = (new Formatter())->buildWorkbook($this->summaryRows(), ['Total', 'LDR', 'Legal'], '2026-09-10', null, null, null, $peelOffs);
        $this->path = $workbook['path'];

        $spreadsheet = IOFactory::load($this->path);
        $this->assertSame(['Enrollment Summary', 'Peel Offs'], $spreadsheet->getSheetNames(), 'Peel Offs directly after Enrollment Summary');

        return $spreadsheet->getSheetByName('Peel Offs');
    }

    /** @return array<int, array<string, mixed>> */
    private function summaryRows(): array
    {
        return [[
            'label' => 'Gross Enrollments',
            'values' => ['Total' => 1, 'LDR' => 1, 'Legal' => 0],
            'format' => 'count',
            'bold' => false,
            'blank' => false,
            'totalOnly' => false,
        ]];
    }

    /**
     * First row whose column A holds each wanted label (the summary table at the bottom reuses the
     * three table titles as its category labels, so only the first occurrence is a table title).
     *
     * @param string[] $wanted
     * @return array<string, int> label => row number, in sheet order
     */
    private function cellsInColumnA(Worksheet $sheet, array $wanted): array
    {
        $found = [];
        for ($r = 1; $r <= $sheet->getHighestRow(); $r++) {
            $value = $sheet->getCell("A{$r}")->getValue();
            if (in_array($value, $wanted, true) && !isset($found[$value])) {
                $found[$value] = $r;
            }
        }

        return $found;
    }

    /** @return array<int, mixed> */
    private function rowValues(Worksheet $sheet, int $row, int $columns): array
    {
        $values = [];
        for ($c = 1; $c <= $columns; $c++) {
            $values[] = $sheet->getCellByColumnAndRow($c, $row)->getValue();
        }

        return $values;
    }
}
