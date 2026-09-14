<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Console\Commands\GenerateEnrollmentBonusReport\Formatter;
use Cmd\Reports\Tests\TestCase;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Jacob 2026-09-14: External ID after the CID on Enrollment Data; gridlines off on sheets 2 and 3.
 */
class EnrollmentBonusReportFormatterTest extends TestCase
{
    private string $path = '';

    protected function tearDown(): void
    {
        if ($this->path !== '' && is_file($this->path)) {
            @unlink($this->path);
        }
        parent::tearDown();
    }

    public function test_enrollment_data_has_external_id_after_cid_and_keeps_the_other_columns_in_order(): void
    {
        $spreadsheet = $this->build();
        $sheet = $spreadsheet->getSheetByName('Enrollment Data');

        $headers = [];
        for ($c = 1; $c <= 8; $c++) {
            $headers[] = $sheet->getCellByColumnAndRow($c, 1)->getValue();
        }
        $this->assertSame(
            ['CID', 'External ID', 'Client', 'Submitted Date', 'Debt Amount', 'Current Status', 'Cut Off Status', 'Relevant Status'],
            $headers
        );

        // Enrolled row.
        $this->assertSame('123', (string) $sheet->getCell('A2')->getValue());
        $this->assertSame('0012345678901234567', $sheet->getCell('B2')->getValue(), 'leading zeros and 19 digits survive');
        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('B2')->getDataType());
        $this->assertSame('Ada Lovelace', $sheet->getCell('C2')->getValue());
        $this->assertSame('mm/dd/yyyy', $sheet->getCell('D2')->getStyle()->getNumberFormat()->getFormatCode(), 'date format moved with its column');
        $this->assertSame('$#,##0.00', $sheet->getCell('E2')->getStyle()->getNumberFormat()->getFormatCode(), 'currency format moved with its column');
        $this->assertSame(1500.0, (float) $sheet->getCell('E2')->getValue());

        // Pending (LT) row carries its Forth TP_ID.
        $this->assertSame('456', (string) $sheet->getCell('A3')->getValue());
        $this->assertSame('987654321', $sheet->getCell('B3')->getValue());
        $this->assertSame('Pending', $sheet->getCell('F3')->getValue());
    }

    public function test_gridlines_are_off_on_all_three_sheets(): void
    {
        $spreadsheet = $this->build();
        $this->assertSame(['Summary', 'Enrollment Data', 'Status Data'], $spreadsheet->getSheetNames());

        // Asserted on the written XML: PhpSpreadsheet 1.30's reader turns showGridlines back on
        // when it meets the default <printOptions gridLinesSet="1">, so a reloaded Worksheet lies.
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($this->path));
        foreach ([1 => 'Summary', 2 => 'Enrollment Data', 3 => 'Status Data'] as $index => $name) {
            $xml = (string) $zip->getFromName("xl/worksheets/sheet{$index}.xml");
            $this->assertMatchesRegularExpression('/<sheetView[^>]*\sshowGridLines="false"/', $xml, $name);
        }
        $zip->close();
    }

    private function build(): \PhpOffice\PhpSpreadsheet\Spreadsheet
    {
        $this->path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'enrollment-bonus-test-' . uniqid() . '.xlsx';

        $rows = [[
            'LLG_ID' => 'LLG-123',
            'EXTERNAL_ID' => '0012345678901234567',
            'CLIENT' => 'Ada Lovelace',
            'DEBT_AMOUNT' => 1500.0,
            'AZURE_STATUS' => 'LDR Enrolled',
            'ENROLLMENT_PLAN' => 'LDR 29%',
            'SUBMITTED_DATE' => '2026-09-02',
            'SNOWFLAKE_SOURCE' => 'LDR',
            'SNOWFLAKE_CONTACT_ID' => '123',
            'STATUS_TITLE' => 'LDR Enrolled',
            'STATUS_STAMP_PT' => '2026-09-03 10:00:00',
            'ASOF_TITLE' => 'LDR Enrolled',
            'ENROLLED' => true,
            'SNOWFLAKE_MATCH' => 'Yes',
        ]];
        $pending = [[
            'CONTACT_ID' => '456',
            'EXTERNAL_ID' => '987654321',
            'CLIENT' => 'Bob Babbage',
            'STATUS_TITLE' => 'Submitted',
            'STATUS_STAMP_PT' => '2026-09-05 09:00:00',
            'ENROLLMENT_PLAN' => 'LDR 29%',
            'SOURCE' => 'LDR',
            'ENROLLED_DEBT' => 900.0,
        ]];
        $summary = [
            'LDR' => ['All Enrollments' => 1500.0, 'Enrolled' => 1500.0, 'Pending' => 900.0],
            'Progress Law' => ['All Enrollments' => 0.0, 'Enrolled' => 0.0, 'Pending' => 0.0],
            'Combined' => ['All Enrollments' => 1500.0, 'Enrolled' => 1500.0, 'Pending' => 900.0],
        ];

        (new Formatter())->buildWorkbook($rows, $pending, $summary, $this->path);

        return IOFactory::load($this->path);
    }
}
