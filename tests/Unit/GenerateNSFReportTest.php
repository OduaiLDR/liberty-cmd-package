<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Console\Commands\GenerateNSFReport\Formatter;
use Cmd\Reports\Console\Commands\GenerateNSFReport\GenerateNSFReport;
use Cmd\Reports\Services\DBConnector;
use Cmd\Reports\Tests\TestCase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use ReflectionMethod;

class GenerateNSFReportTest extends TestCase
{
    public function test_query_uses_eligible_contacts_latest_status_and_explicit_projection(): void
    {
        $connector = new NsfFakeConnector([
            'data' => [[
                'ID' => '123',
                'CONTACT' => 'Ada Lovelace',
                'ENROLLED_DATE' => '2026-01-02',
                'ENROLLED_DEBT' => '1000',
                'STATUS' => 'NSF Follow Up',
                'STATUS_DATE' => '2026-07-20',
                'DAYS' => 3,
                'PHONE_1' => '2125551212',
                'PHONE_2' => null,
                'PHONE_3' => null,
                'PHONE_4' => null,
            ]],
        ]);

        $rows = $this->invokePrivate(
            new GenerateNSFReport,
            'fetchNsfRows',
            [$connector, '2026-07-23']
        );

        $this->assertStringContainsString('WITH NSF_CONTACTS AS', $connector->sql);
        $this->assertStringContainsString('INNER JOIN NSF_CONTACTS', $connector->sql);
        $this->assertStringContainsString("TO_DATE('2026-07-23')", $connector->sql);
        $this->assertStringContainsString('ORDER BY STATUS_STAMP DESC, ID', $connector->sql);
        $this->assertStringNotContainsString('SELECT *', $connector->sql);
        $this->assertSame('NSF Follow Up', $rows[0]['STATUS']);
    }

    public function test_formatter_writes_the_vba_columns_and_formats(): void
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        $this->invokePrivate(new Formatter, 'fillSheet', [$sheet, [[
            'ID' => '123',
            'CONTACT' => 'Ada Lovelace',
            'ENROLLED_DATE' => '2026-01-02',
            'ENROLLED_DEBT' => 1000.5,
            'STATUS' => 'NSF Follow Up',
            'STATUS_DATE' => '2026-07-20',
            'DAYS' => 3,
            'PHONE_1' => '(212) 555-1212',
            'PHONE_2' => null,
            'PHONE_3' => null,
            'PHONE_4' => null,
        ]]]);

        $this->assertSame([
            'ID',
            'Contact',
            'Enrolled Date',
            'Enrolled Debt',
            'Status',
            'Status Date',
            'Days',
            'Phone 1',
            'Phone 2',
            'Phone 3',
            'Phone 4',
        ], $sheet->rangeToArray('A1:K1')[0]);
        $this->assertSame('Ada Lovelace', $sheet->getCell('B2')->getValue());
        $this->assertSame(1000.5, $sheet->getCell('D2')->getValue());
        $this->assertSame('NSF Follow Up', $sheet->getCell('E2')->getValue());
        $this->assertSame('(###) ###-####', $sheet->getStyle('H2')->getNumberFormat()->getFormatCode());
        $this->assertSame(34.0, $sheet->getColumnDimension('E')->getWidth());

        $spreadsheet->disconnectWorksheets();
    }

    public function test_invalid_connector_result_fails_instead_of_returning_empty_data(): void
    {
        $connector = new NsfFakeConnector(['unexpected' => true]);

        $this->expectException(\UnexpectedValueException::class);
        $this->invokePrivate(
            new GenerateNSFReport,
            'fetchNsfRows',
            [$connector, '2026-07-23']
        );
    }

    private function invokePrivate(object $target, string $method, array $arguments = []): mixed
    {
        return (new ReflectionMethod($target, $method))->invoke($target, ...$arguments);
    }
}

final class NsfFakeConnector extends DBConnector
{
    public string $sql = '';

    public function __construct(private readonly array $result) {}

    public function query(string $sql, array $bindings = []): array
    {
        $this->sql = $sql;

        return $this->result;
    }
}
