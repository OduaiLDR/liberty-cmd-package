<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Console\Commands\GenerateReconsiderationReport\Formatter;
use Cmd\Reports\Console\Commands\GenerateReconsiderationReport\GenerateReconsiderationReport;
use Cmd\Reports\Services\DBConnector;
use Cmd\Reports\Tests\TestCase;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use ReflectionClass;
use ReflectionMethod;

final class GenerateReconsiderationReportTest extends TestCase
{
    public function test_email_subject_identifies_each_company(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 7, 24, 12, 0, 0, 'America/Los_Angeles'));
        $formatter = new Formatter;

        $this->assertSame(
            'Reconsideration Report - LDR - 07/24/2026',
            $this->invokePrivate($formatter, 'emailSubject', ['LDR'])
        );
        $this->assertSame(
            'Reconsideration Report - Progress Law - 07/24/2026',
            $this->invokePrivate($formatter, 'emailSubject', ['PLAW'])
        );
    }

    public function test_status_bundle_is_one_query_and_keeps_pending_and_status_filters(): void
    {
        $connector = new ReconsiderationFakeConnector([
            'KIND' => ['data' => [
                [
                    'KIND' => 'pending',
                    'CONTACT_ID' => '10',
                    'ENROLLED_BY' => 'Sys User',
                    'TITLE' => 'Enrolled (Reconsideration Pending)',
                    'STATUS_DATE' => '2026-08-01',
                ],
                [
                    'KIND' => 'status1',
                    'CONTACT_ID' => '10',
                    'ENROLLED_BY' => 'Sys User',
                    'TITLE' => 'Enrolled (Reconsideration Pending)',
                    'STATUS_DATE' => '2026-08-01',
                ],
                [
                    'KIND' => 'status2',
                    'CONTACT_ID' => '10',
                    'ENROLLED_BY' => 'Ada Lovelace',
                    'TITLE' => 'Active',
                    'STATUS_DATE' => '2026-07-15',
                ],
            ]],
        ]);

        $bundle = $this->invokePrivate(
            new GenerateReconsiderationReport,
            'fetchStatusBundle',
            [$connector, 377650]
        );

        $this->assertCount(1, $connector->sqls);
        $sql = $connector->sqls[0];
        $this->assertStringContainsString('STATUS_ID = 377650', $sql);
        $this->assertStringContainsString("UNION ALL", $sql);
        $this->assertStringContainsString('N_PENDING', $sql);
        $this->assertStringContainsString('N_STATUS1', $sql);
        $this->assertStringContainsString('N_STATUS2', $sql);
        $this->assertStringContainsString('USER_ID NOT IN (3121141, 7803971)', $sql);
        $this->assertStringNotContainsString('CONTACT_ID IN (10', $sql);
        $this->assertSame([
            'CONTACT_ID' => '10',
            'STATUS' => 'Enrolled (Reconsideration Pending)',
            'STATUS_DATE' => '2026-08-01',
        ], $bundle['pending'][0]);
        $this->assertSame('Sys User', $bundle['status1']['10']['ENROLLED_BY']);
        $this->assertSame('Ada Lovelace', $bundle['status2']['10']['ENROLLED_BY']);
    }

    public function test_assemble_joins_status_only_for_active_clients_and_hides_extra_status_ids(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 13, 12, 0, 0, 'America/Los_Angeles'));

        $data = $this->invokePrivate(new GenerateReconsiderationReport, 'assembleReportData', [
            [[
                'ID' => '10',
                'CLIENT' => 'Ada Lovelace',
                'ENROLLED_DATE' => '2026-01-02',
                'DROPPED_DATE' => '',
                'DROPPED_BY' => 'Pat Drop',
                'DROPPED_REASON' => "Can't Afford Program",
                'ENROLLED_DEBT' => 1500,
            ]],
            [
                [
                    'ID' => '10',
                    'CLIENT' => 'Ada Lovelace',
                    'ENROLLED_DATE' => '2026-01-02',
                    'DROPPED_DATE' => '',
                    'ENROLLED_DEBT' => 1500,
                    'ACTIVE_STATUS' => 'Active',
                    'RETENTION_AGENT' => 'R1',
                    'REASON_FOR_REQUEST' => 'Other',
                    'RETENTION_IMMEDIATE_RESULTS' => 'Kept',
                    'ASSIGNED_TO' => 'A1',
                    'CANCEL_REQUEST_DATE' => '2026-08-01 09:00:00',
                ],
                [
                    'ID' => '11',
                    'CLIENT' => 'Grace Hopper',
                    'ENROLLED_DATE' => '2026-02-02',
                    'DROPPED_DATE' => '2026-07-04',
                    'ENROLLED_DEBT' => 800,
                    'ACTIVE_STATUS' => 'Dropped',
                    'RETENTION_AGENT' => '',
                    'REASON_FOR_REQUEST' => '',
                    'RETENTION_IMMEDIATE_RESULTS' => '',
                    'ASSIGNED_TO' => '',
                    'CANCEL_REQUEST_DATE' => '',
                ],
            ],
            [
                'pending' => [
                    ['CONTACT_ID' => '10', 'STATUS' => 'Active', 'STATUS_DATE' => '2026-08-01'],
                    ['CONTACT_ID' => '99', 'STATUS' => 'Other', 'STATUS_DATE' => '2026-08-02'],
                ],
                'status1' => [
                    '10' => [
                        'CONTACT_ID' => '10',
                        'ENROLLED_BY' => 'Sys',
                        'TITLE' => 'Enrolled',
                        'STATUS_DATE' => '2026-08-01',
                    ],
                    '11' => [
                        'CONTACT_ID' => '11',
                        'ENROLLED_BY' => 'Sys',
                        'TITLE' => 'Dropped',
                        'STATUS_DATE' => '2026-07-04',
                    ],
                    '99' => [
                        'CONTACT_ID' => '99',
                        'ENROLLED_BY' => 'Sys',
                        'TITLE' => 'Test',
                        'STATUS_DATE' => '2026-08-02',
                    ],
                ],
                'status2' => [
                    '10' => [
                        'CONTACT_ID' => '10',
                        'ENROLLED_BY' => 'Ada Agent',
                        'TITLE' => 'Enrolled',
                        'STATUS_DATE' => '2026-07-15',
                    ],
                    '99' => [
                        'CONTACT_ID' => '99',
                        'ENROLLED_BY' => 'Skip Me',
                        'TITLE' => 'Test',
                        'STATUS_DATE' => '2026-08-02',
                    ],
                ],
            ],
        ]);

        $this->assertSame('Enrolled', $data['reconsideration_clients'][0]['current_status']);
        $this->assertSame('2026-08-01', $data['reconsideration_clients'][0]['status_date']);
        $this->assertSame('Ada Agent', $data['reconsideration_clients'][0]['last_status_by']);
        $this->assertSame('Pat Drop', $data['reconsideration_clients'][0]['dropped_by']);
        $this->assertSame('', $data['reconsideration_clients'][1]['current_status']);
        $this->assertSame('', $data['reconsideration_clients'][1]['last_status_by']);
        $this->assertSame(['10', '99'], array_column($data['reconsideration_pending'], 'contact_id'));
        $this->assertSame(['10', '11'], array_column($data['current_status_1'], 'CONTACT_ID'));
        $this->assertSame(['10'], array_column($data['current_status_2'], 'CONTACT_ID'));
        $this->assertSame(['2026-05-01', '2026-06-01', '2026-07-01', '2026-08-01'], $data['months']);
    }

    public function test_build_report_data_runs_three_snowflake_queries(): void
    {
        $portal = (new ReflectionClass(GenerateReconsiderationReport::class))->getConstant('PORTALS')[0];
        $connector = new ReconsiderationFakeConnector([
            'DROPPED = 1' => ['data' => []],
            'CASE WHEN c.DROPPED = 0' => ['data' => []],
            'KIND' => ['data' => []],
        ]);

        $this->invokePrivate(new GenerateReconsiderationReport, 'buildReportData', [$connector, $portal, 'LDR']);

        $this->assertCount(3, $connector->sqls);
        $this->assertStringContainsString('DROPPED = 1', $connector->sqls[0]);
        $this->assertStringContainsString('STATUS_ID = 377650', $connector->sqls[1]);
        $this->assertStringContainsString("'pending' AS KIND", $connector->sqls[2]);
    }

    public function test_dropped_sheet_keeps_id_as_string_and_excel_dates(): void
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        $this->invokePrivate(new Formatter, 'fillDroppedClients', [$sheet, [[
            'id' => '770711975',
            'client' => 'Ada Lovelace',
            'enrolled_date' => '2026-01-02',
            'dropped_date' => '2026-07-04',
            'dropped_by' => 'Pat Drop',
            'dropped_reason' => "Can't Afford Program",
            'enrolled_debt' => 1500.0,
        ]]]);

        $this->assertSame('770711975', $sheet->getCell('A2')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('A2')->getDataType());
        $this->assertSame('Ada Lovelace', $sheet->getCell('B2')->getValue());
        $this->assertSame(
            ExcelDate::PHPToExcel(new \DateTimeImmutable('2026-01-02')),
            $sheet->getCell('C2')->getValue()
        );
        $this->assertSame(1500.0, $sheet->getCell('G2')->getValue());

        $spreadsheet->disconnectWorksheets();
    }

    public function test_summary_tallies_match_previous_countifs_rules(): void
    {
        $months = ['2026-05-01', '2026-06-01', '2026-07-01', '2026-08-01'];
        $formatter = new Formatter;

        $dropped = [
            [
                'dropped_by' => 'Pat Drop',
                'dropped_reason' => "Can't Afford Program",
                'dropped_date' => '2026-07-10',
                'enrolled_debt' => 100,
            ],
            [
                'dropped_by' => ' Pat Drop',
                'dropped_reason' => "Can't Afford Program",
                'dropped_date' => '2026-07-11',
                'enrolled_debt' => 50,
            ],
            [
                'dropped_by' => 'Other Agent',
                'dropped_reason' => 'Unknown',
                'dropped_date' => '2026-08-02',
                'enrolled_debt' => 25,
            ],
        ];

        $reasonCounts = $this->invokePrivate($formatter, 'tallyDroppedByReason', [$dropped, $months]);
        $this->assertSame(2, $reasonCounts["Can't Afford Program"][2]);
        $this->assertSame(1, $reasonCounts['Unknown'][3]);
        $this->assertSame(0, $reasonCounts['Other'][2]);

        [$agents, $agentStats] = $this->invokePrivate($formatter, 'tallyDroppedByAgent', [$dropped, $months]);
        $this->assertSame(['Other Agent', 'Pat Drop'], $agents);
        $this->assertSame(1, $agentStats['Pat Drop'][2][0]);
        $this->assertSame(100.0, $agentStats['Pat Drop'][2][1]);
        $this->assertSame(0, $agentStats['Pat Drop'][3][0]);

        $recon = [
            [
                'last_status_by' => 'Ada Agent',
                'active_status' => 'Active',
                'current_status' => 'Enrolled',
                'status_date' => '2026-08-05',
                'enrolled_debt' => 200,
                'dropped_by' => 'Pat Drop',
                'dropped_reason' => "Can't Afford Program",
                'enrolled_date' => '2026-05-15',
            ],
            [
                'last_status_by' => 'Ada Agent',
                'active_status' => 'Active',
                'current_status' => 'Enrolled (Reconsideration Pending)',
                'status_date' => '2026-08-06',
                'enrolled_debt' => 999,
                'dropped_by' => 'Pat Drop',
                'dropped_reason' => "Can't Afford Program",
                'enrolled_date' => '2026-05-16',
            ],
            [
                'last_status_by' => 'Ada Agent',
                'active_status' => 'Dropped',
                'current_status' => 'Dropped',
                'status_date' => '2026-08-07',
                'enrolled_debt' => 50,
                'dropped_by' => 'Pat Drop',
                'dropped_reason' => "Can't Afford Program",
                'enrolled_date' => '2026-08-01',
            ],
        ];

        [$reconAgents, $reconStats] = $this->invokePrivate($formatter, 'tallyReconSummary', [$recon, $months]);
        $this->assertSame(['Ada Agent'], $reconAgents);
        $this->assertSame(1, $reconStats['Ada Agent'][3][0]);
        $this->assertSame(200.0, $reconStats['Ada Agent'][3][1]);

        $detail = $this->invokePrivate($formatter, 'tallyDroppedDetail', [$recon, $months]);
        $this->assertCount(1, $detail);
        $this->assertSame('Pat Drop', $detail[0]['agent']);
        $this->assertSame(2, $detail[0]['counts'][0]);
        $this->assertSame(1, $detail[0]['counts'][3]);
    }

    private function invokePrivate(object $target, string $method, array $arguments = []): mixed
    {
        return (new ReflectionMethod($target, $method))->invoke($target, ...$arguments);
    }
}

final class ReconsiderationFakeConnector extends DBConnector
{
    /** @var list<string> */
    public array $sqls = [];

    /**
     * @param  array<string, array{data:list<array<string,mixed>>}>  $results
     */
    public function __construct(private array $results) {}

    public function query(string $sql, array $bindings = []): array
    {
        $this->sqls[] = $sql;
        foreach ($this->results as $needle => $result) {
            if (str_contains($sql, $needle)) {
                return $result;
            }
        }

        return ['data' => []];
    }
}
