<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Console\Commands\GenerateUnclearedSettlementPaymentsReport\Formatter;
use Cmd\Reports\Console\Commands\GenerateUnclearedSettlementPaymentsReport\GenerateUnclearedSettlementPaymentsReport;
use Cmd\Reports\Services\DBConnector;
use Cmd\Reports\Tests\TestCase;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use ReflectionMethod;

class GenerateUnclearedSettlementPaymentsReportTest extends TestCase
{
    public function test_date_window_matches_the_vba_offsets(): void
    {
        Carbon::setTestNow('2026-07-21 12:00:00');

        $window = $this->invokePrivate(
            new GenerateUnclearedSettlementPaymentsReport,
            'resolveDateWindow'
        );

        $this->assertSame([
            'start' => '2026-06-30',
            'end' => '2026-07-14',
        ], $window);
    }

    public function test_query_uses_both_inclusive_calendar_day_boundaries_and_maps_rows(): void
    {
        $connector = new UnclearedSettlementFakeConnector([
            'data' => [[
                'CONTACT_ID' => '123456789',
                'PROCESS_DATE' => '2026-07-14',
                'AMOUNT' => '125.50',
                'MEMO' => 'Settlement Payment - Example Bank',
            ]],
        ]);

        $rows = $this->invokePrivate(
            new GenerateUnclearedSettlementPaymentsReport,
            'fetchUnclearedSettlements',
            [$connector, '2026-06-30', '2026-07-14']
        );

        $this->assertStringContainsString("t.PROCESS_DATE >= '2026-06-30'", $connector->sql);
        $this->assertStringContainsString("t.PROCESS_DATE < '2026-07-15'", $connector->sql);
        $this->assertStringContainsString("t.TRANS_TYPE = 'S'", $connector->sql);
        $this->assertStringContainsString('t.CLEARED_DATE IS NULL', $connector->sql);
        $this->assertStringContainsString('t.RETURNED_DATE IS NULL', $connector->sql);
        $this->assertStringContainsString('t.CANCELLED = 0', $connector->sql);
        $this->assertSame([[
            'contact_id' => '123456789',
            'process_date' => '2026-07-14',
            'amount' => 125.5,
            'creditor' => 'Settlement Payment - Example Bank',
        ]], $rows);
    }

    public function test_formatter_writes_the_vba_columns_and_formats(): void
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        $this->invokePrivate(new Formatter, 'fillSheet', [$sheet, [[
            'contact_id' => '001234567',
            'process_date' => '2026-07-14',
            'amount' => 125.5,
            'creditor' => 'Settlement Payment - Example Bank',
        ]]]);

        $this->assertSame(['LLG ID', 'Process Date', 'Amount', 'Creditor'], $sheet->rangeToArray('A1:D1')[0]);
        $this->assertSame('001234567', $sheet->getCell('A2')->getValue());
        $this->assertSame(ExcelDate::PHPToExcel(new \DateTimeImmutable('2026-07-14')), $sheet->getCell('B2')->getValue());
        $this->assertSame(125.5, $sheet->getCell('C2')->getValue());
        $this->assertSame('Settlement Payment - Example Bank', $sheet->getCell('D2')->getValue());
        $this->assertSame('mm-dd-yy', $sheet->getStyle('B2')->getNumberFormat()->getFormatCode());
        $this->assertSame('$#,##0.00', $sheet->getStyle('C2')->getNumberFormat()->getFormatCode());

        $spreadsheet->disconnectWorksheets();
    }

    public function test_invalid_connector_result_fails_instead_of_sending_an_empty_report(): void
    {
        $connector = new UnclearedSettlementFakeConnector(['unexpected' => true]);

        $this->expectException(\UnexpectedValueException::class);
        $this->invokePrivate(
            new GenerateUnclearedSettlementPaymentsReport,
            'fetchUnclearedSettlements',
            [$connector, '2026-06-30', '2026-07-14']
        );
    }

    private function invokePrivate(object $target, string $method, array $arguments = []): mixed
    {
        return (new ReflectionMethod($target, $method))->invoke($target, ...$arguments);
    }
}

final class UnclearedSettlementFakeConnector extends DBConnector
{
    public string $sql = '';

    public function __construct(private readonly array $result) {}

    public function query(string $sql, array $bindings = []): array
    {
        $this->sql = $sql;

        return $this->result;
    }
}
