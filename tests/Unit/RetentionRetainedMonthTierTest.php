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
            ['RETENTION_AGENT' => 'Jane Doe', 'CANCEL_REQUEST_DATE' => '2098-02-05', 'RETENTION_DATE' => '2098-02-06'],
            ['RETENTION_AGENT' => ' Jane   DOE ', 'CANCEL_REQUEST_DATE' => '2098-01-05', 'RETENTION_DATE' => '2098-01-06',
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

    public function test_explicit_disabled_policy_preserves_current_tier_payment(): void
    {
        $this->assertSame(30.0, $this->summary($this->rows(), [], false)['Jane Doe']['commission']);
    }

    public function test_current_month_uses_calculated_tier_not_stale_current_month_history(): void
    {
        $rows = [$this->rows()[0] + ['RETENTION_PAYMENT_DATE' => '2098-02-10', 'T1' => 10, 'T3' => 30]];
        $this->assertSame(30.0, $this->summary($rows, [Tiers::tierMapKey('2098-02-01', 'Jane Doe') => 1])['Jane Doe']['commission']);
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
            new GenerateRetentionCommissionReport, $source, ['custom_agent' => 1, 'custom_date' => 2, 'custom_results' => 3, 'cancel_request_custom' => 4]
        );
    }

    public function test_snowflake_source_accepts_real_data_shape_without_success_flag(): void
    {
        $rows = [['ID' => '101', 'RETENTION_AGENT' => 'Jane Doe']];
        $fetch = new ReflectionMethod(GenerateRetentionCommissionReport::class, 'fetchBase');
        $cfg = ['custom_agent' => 1, 'custom_date' => 2, 'custom_results' => 3, 'cancel_request_custom' => 4];
        $this->assertSame($rows, $fetch->invoke(new GenerateRetentionCommissionReport,
            new RetainedTierConnector(['data' => $rows, 'rowCount' => 1, 'columns' => []]), $cfg));
        $this->assertSame($rows, $fetch->invoke(new GenerateRetentionCommissionReport,
            new RetainedTierConnector(['success' => true, 'data' => $rows]), $cfg));
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
                ['custom_agent' => 1, 'custom_date' => 2, 'custom_results' => 3, 'cancel_request_custom' => 4]
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
    public function __construct(private array $result) {}
    public function querySqlServer(string $sql, array $params = []): array
    {
        if (!str_starts_with(ltrim($sql), 'SELECT')) {
            $this->writes++;
            throw new \LogicException('Synthetic tier tests cannot write SQL.');
        }
        return $this->result;
    }
    public function query(string $sql, array $bindings = [], ?int $timeoutSeconds = null): array { return $this->result; }
}

final class RetainedTierCommandStatus extends GenerateRetentionCommissionReport
{
    public array $sources = [];
    public function argument($key = null) { return 'both'; }
    protected function runForSource(string $source): bool { $this->sources[] = $source; return $source !== 'ldr'; }
}
