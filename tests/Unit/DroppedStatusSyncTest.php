<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Console\Commands\SyncDroppedStatus\Formatter;
use Cmd\Reports\Console\Commands\SyncDroppedStatus\SyncDroppedStatus;
use Cmd\Reports\Console\Commands\SyncDroppedStatus\SyncDroppedStatusPreview;
use Cmd\Reports\Pmod\Services\DppDataClient;
use Cmd\Reports\Services\DBConnector;
use Cmd\Reports\Services\DroppedStatusSyncService;
use Cmd\Reports\Tests\TestCase;
use PhpOffice\PhpSpreadsheet\IOFactory;

final class DroppedStatusSyncTest extends TestCase
{
    public function test_portals_support_all_and_each_source(): void
    {
        $service = new DroppedStatusSyncService;

        $this->assertSame(['LDR', 'PLAW'], array_column($service->portals(), 'source'));
        $this->assertSame(['LDR'], array_column($service->portals('ldr'), 'source'));
        $this->assertSame(['PLAW'], array_column($service->portals('PLAW'), 'source'));

        $this->expectException(\InvalidArgumentException::class);
        $service->portals('other');
    }

    public function test_selection_is_case_insensitive_and_deduplicated(): void
    {
        $connector = new DroppedStatusFakeConnector([
            'data' => [
                $this->row('1', 'dRoPpEd / Cancelled'),
                $this->row('2', 'SYSTEM CANCEL - NSF'),
                $this->row('3', null),
                $this->row('4', 'Active'),
                $this->row('4', 'Active'),
            ],
        ]);

        $report = (new DroppedStatusSyncService)->buildReport($connector);

        $this->assertSame(5, $report['scanned_count']);
        $this->assertSame(1, $report['candidate_count']);
        $this->assertSame(1, $report['selected_count']);
        $this->assertSame(1, $report['skipped_dropped_count']);
        $this->assertSame(1, $report['skipped_system_cancel_count']);
        $this->assertSame(1, $report['skipped_missing_status_count']);
        $this->assertSame(1, $report['duplicate_count']);
        $this->assertSame('4', $report['candidates'][0]['contact_id']);
        $this->assertSame(
            ['already_dropped_status', 'already_system_cancel_status', 'missing_current_status'],
            array_column($report['skipped'], 'skip_reason')
        );
    }

    public function test_limit_applies_to_selected_candidates(): void
    {
        $connector = new DroppedStatusFakeConnector([
            'data' => [
                $this->row('1', 'Active'),
                $this->row('2', 'Active'),
                $this->row('3', 'Active'),
            ],
        ]);

        $report = (new DroppedStatusSyncService)->buildReport($connector, 2);

        $this->assertSame(3, $report['candidate_count']);
        $this->assertSame(2, $report['selected_count']);
        $this->assertSame(2, $report['limit']);
        $this->assertSame(['1', '2'], array_column($report['candidates'], 'contact_id'));
    }

    public function test_query_uses_only_contact_and_status_tables(): void
    {
        $connector = new DroppedStatusFakeConnector(['data' => []]);

        (new DroppedStatusSyncService)->buildReport($connector);

        $this->assertStringContainsString('FROM CONTACTS AS c', $connector->sql);
        $this->assertStringContainsString('CONTACTS_LEAD_STATUS', $connector->sql);
        $this->assertStringNotContainsString('ENROLLMENT_PLAN', $connector->sql);
        $this->assertStringNotContainsString('DEBTS', $connector->sql);
        $this->assertStringNotContainsString('CANCELLATION_REASONS', $connector->sql);
    }

    public function test_invalid_snowflake_result_fails_closed(): void
    {
        $this->expectException(\UnexpectedValueException::class);

        (new DroppedStatusSyncService)->buildReport(new DroppedStatusFakeConnector(['error' => true]));
    }

    public function test_snowflake_query_failure_is_not_treated_as_an_empty_report(): void
    {
        $this->expectException(\RuntimeException::class);

        (new DroppedStatusSyncService)->buildReport(new DroppedStatusFakeConnector([
            'success' => false,
            'error' => 'connection failed',
        ]));
    }

    public function test_dpp_preflight_validates_the_selected_tenant_without_http(): void
    {
        $client = new DppDataClient('https://example.test', ['ldr' => 'ldr-key', 'plaw' => '']);
        $client->assertConfigured('LDR');

        $this->expectException(\RuntimeException::class);
        $client->assertConfigured('PLAW');
    }

    public function test_preview_workbook_has_expected_sheets_headers_and_summary(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sync-dropped-status-'.bin2hex(random_bytes(4));
        mkdir($directory, 0775, true);
        $path = null;

        try {
            $result = (new Formatter)->buildWorkbook([
                'scanned_count' => 5,
                'candidate_count' => 2,
                'selected_count' => 1,
                'skipped_dropped_count' => 1,
                'skipped_system_cancel_count' => 1,
                'skipped_missing_status_count' => 1,
                'skipped_invalid_id_count' => 0,
                'duplicate_count' => 1,
                'limit' => 1,
                'candidates' => [[
                    'contact_id' => '10',
                    'client' => 'Ada Lovelace',
                    'enrolled_date' => '2026-07-01',
                    'dropped_date' => '2026-07-10',
                    'current_status' => 'Active',
                    'target_status' => 'Dropped / Cancelled',
                    'action' => 'update_client_status',
                ]],
                'skipped' => [[
                    'contact_id' => '11',
                    'client' => 'Grace Hopper',
                    'enrolled_date' => '2026-07-01',
                    'dropped_date' => '2026-07-10',
                    'current_status' => 'Dropped',
                    'skip_reason' => 'already_dropped_status',
                ]],
            ], 'LDR', $directory);
            $path = $result['path'];

            $workbook = IOFactory::load($result['path']);

            $this->assertSame(['Summary', 'Candidates', 'Skipped'], $workbook->getSheetNames());
            $this->assertSame('No CRM updates were sent', $workbook->getSheetByName('Summary')->getCell('A2')->getValue());
            $this->assertSame(5, $workbook->getSheetByName('Summary')->getCell('B5')->getValue());
            $this->assertSame(2, $workbook->getSheetByName('Summary')->getCell('B6')->getValue());
            $this->assertSame(0, $workbook->getSheetByName('Summary')->getCell('B11')->getValue());
            $this->assertSame(
                ['Contact ID', 'Client', 'Enrolled Date', 'Dropped Date', 'Current Status', 'Target Status', 'Action'],
                $workbook->getSheetByName('Candidates')->rangeToArray('A1:G1')[0]
            );
            $this->assertSame(
                ['Contact ID', 'Client', 'Enrolled Date', 'Dropped Date', 'Current Status', 'Skip Reason'],
                $workbook->getSheetByName('Skipped')->rangeToArray('A1:F1')[0]
            );

            $workbook->disconnectWorksheets();
        } finally {
            if ($path !== null && is_file($path)) {
                unlink($path);
            }
            rmdir($directory);
        }
    }

    public function test_command_names_are_registered_by_their_signatures(): void
    {
        $this->assertSame('Sync:dropped-status-preview', (new SyncDroppedStatusPreview)->getName());
        $this->assertSame('Sync:dropped-status', (new SyncDroppedStatus)->getName());
    }

    /** @return array<string,string|null> */
    private function row(string $id, ?string $status): array
    {
        return [
            'ID' => $id,
            'CLIENT' => 'Test Client '.$id,
            'ENROLLED_DATE' => '2026-07-01',
            'DROPPED_DATE' => '2026-07-10',
            'STATUS' => $status,
        ];
    }
}

final class DroppedStatusFakeConnector extends DBConnector
{
    public string $sql = '';

    public function __construct(private readonly array $result) {}

    public function query(string $sql, array $bindings = []): array
    {
        $this->sql = $sql;

        return $this->result;
    }
}
