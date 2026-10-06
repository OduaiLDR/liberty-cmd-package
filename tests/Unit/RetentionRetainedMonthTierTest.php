<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Console\Commands\GenerateRetentionCommissionReport\GenerateRetentionCommissionReport;
use Cmd\Reports\Services\DBConnector;
use Cmd\Reports\Services\RetentionCommissionTierStore as Tiers;
use Cmd\Reports\Tests\TestCase;
use Illuminate\Console\Command;
use ReflectionMethod;

final class RetentionRetainedMonthTierTest extends TestCase
{
    private function summary(array $rows, array $map = [], bool $enabled = true): array
    {
        return (new ReflectionMethod(GenerateRetentionCommissionReport::class, 'buildSummary'))->invoke(
            new GenerateRetentionCommissionReport, $rows, ['Jane Doe'], '2098-02-01', '2098-02-28', [], true, $map, $enabled, false
        );
    }

    private function rows(): array
    {
        return [
            ['RETENTION_AGENT' => 'Jane Doe', 'CANCEL_REQUEST_DATE' => '2098-02-05', 'RETENTION_DATE' => '2098-02-06', 'RETAINED_DATE' => '2098-02-06'],
            ['RETENTION_AGENT' => ' Jane   DOE ', 'CANCEL_REQUEST_DATE' => '2098-01-05', 'RETENTION_DATE' => '2098-01-06', 'RETAINED_DATE' => '2098-01-06',
                'RETENTION_PAYMENT_DATE' => '2098-02-10', 'T1' => 10, 'T2' => 20, 'T3' => 30, 'T4' => 40],
        ];
    }

    public function test_delayed_payment_uses_saved_earned_month_tier_and_normalizes_aliases(): void
    {
        $map = [Tiers::tierMapKey('2098-01-01', '  JANE   Doe ') => 1];
        $summary = $this->summary($this->rows(), $map);
        $this->assertSame(3, $summary['Jane Doe']['tier']);
        $this->assertSame(10.0, $summary['Jane Doe']['commission']);
    }

    public function test_missing_historical_tier_fails_instead_of_pricing_at_current_tier(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Missing retained-month tier for Jane Doe (2098-01-01)');
        $this->summary($this->rows());
    }

    public function test_missing_custom_retention_date_uses_actual_enrolled_status_month_for_tiering(): void
    {
        $rows = $this->rows();
        $rows[1]['RETENTION_DATE'] = '';
        $map = [Tiers::tierMapKey('2098-01-01', 'Jane Doe') => 1];

        $this->assertSame(10.0, $this->summary($rows, $map)['Jane Doe']['commission']);
    }

    public function test_explicit_disabled_policy_preserves_current_tier_payment(): void
    {
        $this->assertSame(30.0, $this->summary($this->rows(), [], false)['Jane Doe']['commission']);
    }

    public function test_current_month_uses_calculated_tier_not_stale_current_month_history(): void
    {
        $rows = [$this->rows()[0] + ['RETENTION_PAYMENT_DATE' => '2098-02-10', 'T1' => 10, 'T3' => 30]];
        $this->assertSame(30.0, $this->summary($rows, [Tiers::tierMapKey('2098-02-01', 'Jane Doe') => 1])['Jane Doe']['commission']);
    }

    public function test_monthly_assignment_window_accepts_snowflake_epoch_datetime(): void
    {
        $inPeriod = new ReflectionMethod(GenerateRetentionCommissionReport::class, 'inExcelPeriod');
        $command = new GenerateRetentionCommissionReport;
        $duringMonth = (string) strtotime('2098-02-10 12:00:00') . '.000000000';
        $atEndMidnight = (string) strtotime('2098-02-28 00:00:00') . '.000000000';
        $afterEndMidnight = (string) strtotime('2098-02-28 00:00:01') . '.000000000';
        $nextMonth = (string) strtotime('2098-03-01 00:00:00') . '.000000000';
        $this->assertTrue($inPeriod->invoke($command, $duringMonth, '2098-02-01', '2098-02-28', true));
        $this->assertTrue($inPeriod->invoke($command, $atEndMidnight, '2098-02-01', '2098-02-28', true));
        $this->assertTrue($inPeriod->invoke($command, $afterEndMidnight, '2098-02-01', '2098-02-28', true));
        $this->assertFalse($inPeriod->invoke($command, $nextMonth, '2098-02-01', '2098-02-28', true));

        $sheet = (new \PhpOffice\PhpSpreadsheet\Spreadsheet)->getActiveSheet();
        (new ReflectionMethod(GenerateRetentionCommissionReport::class, 'setDateTime'))
            ->invoke($command, $sheet, 'A1', $duringMonth);
        $this->assertIsNumeric($sheet->getCell('A1')->getValue());
    }

    public function test_saved_zero_tier_is_valid_and_unpaid_history_requires_no_tier(): void
    {
        $this->assertSame(0.0, $this->summary($this->rows(), [Tiers::tierMapKey('2098-01-01', 'Jane Doe') => 0])['Jane Doe']['commission']);
        $rows = $this->rows();
        $rows[1]['RETENTION_PAYMENT_DATE'] = '2098-03-01';
        $this->assertSame(0.0, $this->summary($rows)['Jane Doe']['commission']);
    }

    public function test_required_history_read_distinguishes_missing_data_from_sql_failure(): void
    {
        $missing = new RetainedTierConnector(['success' => true, 'data' => []]);
        $this->assertSame([], Tiers::fetchMap($missing, 'ldr', ['2098-01-01'], true));
        $failed = new RetainedTierConnector(['success' => false, 'error' => 'synthetic SQL outage']);
        try {
            Tiers::fetchMap($failed, 'ldr', ['2098-01-01'], true);
            $this->fail('SQL failure must not be returned as empty history.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('unavailable or invalid', $error->getMessage());
            $this->assertStringContainsString('synthetic SQL outage', $error->getPrevious()->getMessage());
        }
        $this->assertSame(0, $failed->writes);
    }

    public function test_invalid_saved_tier_is_not_silently_clamped(): void
    {
        $this->expectException(\RuntimeException::class);
        Tiers::fetchMap(new RetainedTierConnector(['success' => true, 'data' => [
            ['Period_Start' => '2098-01-01', 'Agent' => 'Jane Doe', 'Tier' => 9],
        ]]), 'ldr', ['2098-01-01'], true);
    }

    public function test_source_query_failure_is_not_a_valid_empty_report(): void
    {
        $source = new RetainedTierConnector(['success' => false, 'error' => 'synthetic source outage']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Retention source query failed');
        (new ReflectionMethod(GenerateRetentionCommissionReport::class, 'fetchBase'))->invoke(
            new GenerateRetentionCommissionReport, $source, ['custom_agent' => 1, 'custom_date' => 2, 'custom_results' => 3, 'cancel_request_custom' => 4], '2098-02-01'
        );
    }

    public function test_snowflake_source_accepts_real_data_shape_without_success_flag(): void
    {
        $rows = [['ID' => '101', 'RETENTION_AGENT' => 'Jane Doe']];
        $fetch = new ReflectionMethod(GenerateRetentionCommissionReport::class, 'fetchBase');
        $cfg = ['custom_agent' => 1, 'custom_date' => 2, 'custom_results' => 3, 'cancel_request_custom' => 4];
        $this->assertSame($rows, $fetch->invoke(new GenerateRetentionCommissionReport,
            new RetainedTierConnector(['data' => $rows, 'rowCount' => 1, 'columns' => []]), $cfg, '2098-02-01'));
        $this->assertSame($rows, $fetch->invoke(new GenerateRetentionCommissionReport,
            new RetainedTierConnector(['success' => true, 'data' => $rows]), $cfg, '2098-02-01'));
    }

    public function test_base_query_scopes_candidates_to_three_months_without_truncating_history(): void
    {
        $source = new RetainedTierConnector(['data' => [['ID' => '101', 'RETENTION_AGENT' => 'Jane Doe']]]);
        (new ReflectionMethod(GenerateRetentionCommissionReport::class, 'fetchBase'))->invoke(
            new GenerateRetentionCommissionReport, $source,
            ['custom_agent' => 1, 'custom_date' => 2, 'custom_results' => 3, 'cancel_request_custom' => 4],
            '2026-09-01'
        );
        $this->assertStringContainsString("F_DATETIME >= '2026-07-01'::TIMESTAMP_NTZ", $source->lastSql);
        $this->assertStringContainsString("F_DATE >= '2026-07-01'::DATE", $source->lastSql);
        $this->assertStringContainsString("CLEARED_DATE < '2026-10-01'::DATE", $source->lastSql);
        $this->assertStringContainsString('JOIN relevant_contacts eligible', $source->lastSql);
        $this->assertSame(4, substr_count($source->lastSql, 'JOIN relevant_contacts relevant'));
        $this->assertSame(4, substr_count($source->lastSql, 'SELECT DISTINCT uf.CONTACT_ID'));
        $this->assertStringContainsString('WHERE uf.CUSTOM_ID = 1 AND (uf._FIVETRAN_DELETED = FALSE OR uf._FIVETRAN_DELETED IS NULL)', $source->lastSql);
        $this->assertStringContainsString('WHERE uf.CUSTOM_ID = 3 AND (uf._FIVETRAN_DELETED = FALSE OR uf._FIVETRAN_DELETED IS NULL)', $source->lastSql);
        $this->assertStringContainsString('WHERE uf.CUSTOM_ID = 4 AND (uf._FIVETRAN_DELETED = FALSE OR uf._FIVETRAN_DELETED IS NULL)', $source->lastSql);
    }

    public function test_retained_status_selection_is_as_of_the_report_end_date(): void
    {
        $source = new RetainedTierConnector(['data' => [
            ['CONTACT_ID' => '101', 'RETAINED_DATE' => '2026-09-30'],
            ['CONTACT_ID' => '101', 'RETAINED_DATE' => '2026-10-05'],
        ]]);
        $retained = (new ReflectionMethod(GenerateRetentionCommissionReport::class, 'fetchRetainedDates'))->invoke(
            new GenerateRetentionCommissionReport, $source, '101', '2026-09-30'
        );

        $this->assertSame(['2026-09-30'], $retained['101']);
        $this->assertStringContainsString("LEFT(cs.STAMP,10) <= '2026-09-30'", $source->lastSql);
    }

    public function test_base_query_deduplicates_identical_contacts_and_rejects_conflicts(): void
    {
        $row = ['ID' => '101', 'RETENTION_AGENT' => 'Jane Doe'];
        $fetch = new ReflectionMethod(GenerateRetentionCommissionReport::class, 'fetchBase');
        $cfg = ['custom_agent' => 1, 'custom_date' => 2, 'custom_results' => 3, 'cancel_request_custom' => 4];
        $this->assertSame([$row], $fetch->invoke(new GenerateRetentionCommissionReport,
            new RetainedTierConnector(['data' => [$row, $row]]), $cfg, '2026-09-01'));
        $this->expectException(\UnexpectedValueException::class);
        $fetch->invoke(new GenerateRetentionCommissionReport,
            new RetainedTierConnector(['data' => [$row, [...$row, 'RETENTION_AGENT' => 'Other Agent']]]),
            $cfg, '2026-09-01');
    }

    public function test_historical_tier_periods_follow_enrolled_status_date_not_custom_retention_date(): void
    {
        $periods = (new ReflectionMethod(GenerateRetentionCommissionReport::class, 'retentionPeriodStarts'))
            ->invoke(new GenerateRetentionCommissionReport, [[
                'RETENTION_DATE' => '',
                'RETAINED_DATE' => '2026-08-17',
                'RETENTION_PAYMENT_DATE' => '2026-09-05',
            ]]);

        $this->assertSame(['2026-08-01'], $periods);
    }

    public function test_missing_historical_snapshot_is_rebuilt_from_that_months_source_rows_without_writes(): void
    {
        $command = new GenerateRetentionCommissionReport;
        $source = new RetainedTierConnector(['success' => true, 'data' => [[
            'ID' => '123',
            'RETENTION_AGENT' => 'Gracia Rivera',
            'RETENTION_DATE' => '2026-04-12',
            'CANCEL_REQUEST_DATE' => '2026-04-02 09:00:00',
        ]]]);
        $sql = new RetainedTierConnector(['success' => true, 'data' => []]);
        $loader = new ReflectionMethod(GenerateRetentionCommissionReport::class, 'loadTierSnapshotMap');

        $map = $loader->invoke($command, $source, $sql, [
            'custom_agent' => 742096,
            'custom_date' => 742101,
            'custom_results' => 742105,
            'cancel_request_custom' => 742098,
            'has_t4' => true,
        ], 'ldr', [[
            'RETENTION_AGENT' => 'Gracia Rivera',
            'RETAINED_DATE' => '2026-04-12',
            'RETENTION_PAYMENT_DATE' => '2026-09-08',
        ]], '2026-09-01', [], true);

        $this->assertSame(3, $map[Tiers::tierMapKey('2026-04-01', 'Gracia Rivera')]);
        $this->assertSame(0, $source->writes);
        $this->assertSame(0, $sql->writes);
    }

    public function test_malformed_source_payload_fails_before_calculation(): void
    {
        $this->expectException(\RuntimeException::class);
        (new ReflectionMethod(GenerateRetentionCommissionReport::class, 'sourceRows'))->invoke(
            new GenerateRetentionCommissionReport, new RetainedTierConnector(['data' => 'invalid']), 'SELECT synthetic'
        );
    }

    public function test_successful_empty_base_response_is_incomplete_not_a_stale_success(): void
    {
        $source = new RetainedTierConnector(['success' => true, 'data' => []]);
        try {
            (new ReflectionMethod(GenerateRetentionCommissionReport::class, 'fetchBase'))->invoke(
                new GenerateRetentionCommissionReport, $source,
                ['custom_agent' => 1, 'custom_date' => 2, 'custom_results' => 3, 'cancel_request_custom' => 4], '2098-02-01'
            );
            $this->fail('Empty source must not advance to persistence or report delivery.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('Run is incomplete', $error->getMessage());
            $this->assertStringContainsString('prior commission results were preserved', $error->getMessage());
            $this->assertStringContainsString('no fresh report will be sent', $error->getMessage());
        }
        $this->assertSame(0, $source->writes);
    }

    public function test_any_failed_source_returns_failure_to_the_runner(): void
    {
        $command = new RetainedTierCommandStatus;
        $this->assertSame(Command::FAILURE, $command->handle());
        $this->assertSame(['ldr', 'plaw'], $command->sources);
    }
}

final class RetainedTierConnector extends DBConnector
{
    public int $writes = 0;
    public string $lastSql = '';
    public function __construct(private array $result) {}
    public function querySqlServer(string $sql, array $params = []): array
    {
        if (!str_starts_with(ltrim($sql), 'SELECT')) {
            $this->writes++;
            throw new \LogicException('Synthetic tier tests cannot write SQL.');
        }
        return $this->result;
    }
    public function query(string $sql, array $bindings = [], ?int $timeoutSeconds = null): array
    {
        $this->lastSql = $sql;
        return $this->result;
    }
}

final class RetainedTierCommandStatus extends GenerateRetentionCommissionReport
{
    public array $sources = [];
    public function argument($key = null) { return 'both'; }
    protected function runForSource(string $source): bool { $this->sources[] = $source; return $source !== 'ldr'; }
}
