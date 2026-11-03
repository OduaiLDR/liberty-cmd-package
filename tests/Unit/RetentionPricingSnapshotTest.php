<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Services\PayrollCommissionResultSnapshot;
use Cmd\Reports\Services\RetentionPricingSnapshot;
use Cmd\Reports\Tests\TestCase;

final class RetentionPricingSnapshotTest extends TestCase
{
    private const PERIOD = '2026-10-01';

    private function line(array $overrides = []): array
    {
        return array_replace(['detailRow' => 2, 'clientId' => '1001', 'agent' => 'Jane Doe', 'agentKey' => 'jane doe',
            'retentionDate' => '2026-09-10', 'paymentDate' => '2026-10-05', 'retainedMonth' => '2026-09-01',
            'currentTier' => 3, 'appliedTier' => 1, 'rates' => [10, 20, 30, 40], 'amount' => 10], $overrides);
    }
    private function results(array $rows = [['agent' => 'Jane Doe', 'amount' => 10]]): array
    {
        return PayrollCommissionResultSnapshot::component('retention', 'ldr', self::PERIOD, 'commission', $rows);
    }
    private function snapshot(array $lines = [], ?array $results = null, bool $policy = true): array
    {
        return RetentionPricingSnapshot::capture('ldr', self::PERIOD, $policy, $lines ?: [$this->line()], $results ?? $this->results());
    }

    public function test_historical_and_same_month_actual_selections_reconcile_without_repricing(): void
    {
        $lines = [$this->line(), $this->line(['detailRow' => 3, 'clientId' => '1002', 'retentionDate' => '2026-10-06',
            'retainedMonth' => self::PERIOD, 'appliedTier' => 3, 'amount' => 30])];
        $results = $this->results([['agent' => 'Jane Doe', 'amount' => 40], ['agent' => 'No Activity', 'amount' => 0]]);
        $snapshot = $this->snapshot($lines, $results);
        $this->assertSame(['version', 'source', 'period', 'policy', 'rows'], array_keys($snapshot));
        $this->assertSame('retained_month', $snapshot['policy']);
        $this->assertSame($lines, $snapshot['rows']);
        $this->assertSame($snapshot, RetentionPricingSnapshot::validate($snapshot, $results));
    }

    public function test_override_uses_captured_current_tier_for_historical_rows(): void
    {
        $snapshot = $this->snapshot([$this->line(['appliedTier' => 3, 'amount' => 30])], $this->results([['agent' => 'Jane Doe', 'amount' => 30]]), false);
        $this->assertSame('report_month_override', $snapshot['policy']);
        $this->assertSame(30, $snapshot['rows'][0]['amount']);
        $this->expectException(\InvalidArgumentException::class);
        $this->snapshot(policy: false);
    }

    public function test_zero_tier_and_explicit_zero_results_are_preserved(): void
    {
        $line = $this->line(['appliedTier' => 0, 'amount' => 0, 'rates' => [10, 20, 30, 0]]);
        $results = $this->results([['agent' => 'Jane Doe', 'amount' => 0], ['agent' => 'No Activity', 'amount' => 0]]);
        $snapshot = $this->snapshot([$line], $results);
        $this->assertSame([$line], $snapshot['rows']);
        $this->assertSame([], RetentionPricingSnapshot::capture('ldr', self::PERIOD, true, [], $results)['rows']);
    }

    public function test_verified_empty_components_need_no_fabricated_lines_but_nonzero_components_do(): void
    {
        $results = $this->results([]);
        $snapshot = RetentionPricingSnapshot::capture('ldr', self::PERIOD, true, [], $results);
        $this->assertSame([], $snapshot['rows']);
        $this->assertSame($snapshot, RetentionPricingSnapshot::validate($snapshot, $results));
        $this->expectException(\InvalidArgumentException::class);
        RetentionPricingSnapshot::capture('ldr', self::PERIOD, true, [], $this->results());
    }

    public function test_subcent_rates_are_accumulated_raw_and_rounded_once_per_agent(): void
    {
        $lines = [$this->line(['amount' => 0.105, 'rates' => [0.105, 20, 30, 40]]),
            $this->line(['detailRow' => 3, 'amount' => 0.105, 'rates' => [0.105, 20, 30, 40]])];
        $snapshot = $this->snapshot($lines, $this->results([['agent' => 'Jane Doe', 'amount' => 0.21]]));
        $this->assertSame([0.105, 0.105], array_column($snapshot['rows'], 'amount'));
        $this->expectException(\InvalidArgumentException::class);
        RetentionPricingSnapshot::validate($snapshot, $this->results([['agent' => 'Jane Doe', 'amount' => 0.22]]));
    }

    public function test_floating_addition_order_and_signed_offsetting_lines_are_not_changed(): void
    {
        $lines = [];
        foreach ([1e16, 1, -1e16] as $index => $amount) $lines[] = $this->line(['detailRow' => $index + 2, 'amount' => $amount, 'rates' => [$amount, 0, 0, 0]]);
        $snapshot = $this->snapshot($lines, $this->results([['agent' => 'Jane Doe', 'amount' => 0]]));
        $this->assertSame($lines, $snapshot['rows']);
        $this->assertSame([1e16, 1, -1e16], array_column($snapshot['rows'], 'amount'));
        $this->expectException(\InvalidArgumentException::class);
        $this->snapshot([$lines[0]], $this->results([['agent' => 'Jane Doe', 'amount' => 0]]));
    }

    public function test_repeated_client_payment_tranches_remain_distinct_by_worksheet_row(): void
    {
        $lines = [$this->line(), $this->line(['detailRow' => 5, 'paymentDate' => '2026-10-20'])];
        $this->assertSame($lines, $this->snapshot($lines, $this->results([['agent' => 'Jane Doe', 'amount' => 20]]))['rows']);
    }

    public function test_capture_normalizes_only_supported_agent_identity_and_has_no_input_side_effects(): void
    {
        $line = $this->line(['agent' => '  ANDREA   MENDOZE  ']); unset($line['agentKey']);
        $results = $this->results([['agent' => 'Andrea Mendoza', 'amount' => 10]]);
        $beforeLine = $line; $beforeResults = $results;
        $snapshot = $this->snapshot([$line], $results);
        $this->assertSame('Andrea Mendoza', $snapshot['rows'][0]['agent']);
        $this->assertSame('andrea mendoza', $snapshot['rows'][0]['agentKey']);
        $this->assertSame($beforeLine, $line); $this->assertSame($beforeResults, $results);
        $this->assertSame($snapshot, RetentionPricingSnapshot::validate($snapshot, $results));
    }

    public function test_nonzero_results_cannot_disappear_and_line_agents_cannot_be_inferred(): void
    {
        foreach ([$this->results([['agent' => 'Jane Doe', 'amount' => 10], ['agent' => 'Missing Earner', 'amount' => 999]]),
            $this->results([['agent' => 'Other Agent', 'amount' => 10]])] as $results) {
            try { $this->snapshot(results: $results); $this->fail('Uncovered commission identity must fail.'); }
            catch (\InvalidArgumentException) { $this->addToAssertionCount(1); }
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidLines')]
    public function test_invalid_or_tampered_line_evidence_is_rejected(array $change): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->snapshot([$this->line($change)]);
    }
    public static function invalidLines(): array
    {
        return [
            'header row' => [['detailRow' => 1]], 'string row' => [['detailRow' => '2']],
            'blank client' => [['clientId' => ' ']], 'numeric client' => [['clientId' => 1001]],
            'wrong agent key' => [['agentKey' => 'other agent']], 'null supplied agent key' => [['agentKey' => null]],
            'blank retention date' => [['retentionDate' => '']],
            'impossible date' => [['retentionDate' => '2026-02-30']], 'wrong retained month' => [['retainedMonth' => '2026-08-01']],
            'next month payment' => [['paymentDate' => '2026-11-01']], 'future retention' => [['retentionDate' => '2026-11-01', 'retainedMonth' => '2026-11-01']],
            'wrong selected rate' => [['amount' => 11]], 'nonfinite amount' => [['amount' => INF]],
            'numeric string amount' => [['amount' => '10']], 'string tier' => [['appliedTier' => '1']],
            'tier above four' => [['currentTier' => 5]], 'negative tier' => [['appliedTier' => -1]],
            'missing T4 entry' => [['rates' => [10, 20, 30]]], 'nonfinite rate' => [['rates' => [10, INF, 30, 40]]],
            'numeric string rate' => [['rates' => ['10', 20, 30, 40]]], 'unknown field' => [['guessedTier' => 1]],
            'current month cannot use earlier tier' => [['retentionDate' => '2026-10-02', 'retainedMonth' => self::PERIOD]],
        ];
    }

    public function test_duplicate_or_descending_detail_rows_are_not_reordered_or_deduplicated(): void
    {
        foreach ([[$this->line(), $this->line()], [$this->line(['detailRow' => 3]), $this->line()]] as $lines) {
            try { $this->snapshot($lines, $this->results([['agent' => 'Jane Doe', 'amount' => 20]])); $this->fail('Invalid worksheet identity order accepted.'); }
            catch (\InvalidArgumentException) { $this->addToAssertionCount(1); }
        }
    }

    public function test_wrong_component_scope_and_duplicate_result_identity_fail(): void
    {
        $original = $this->results();
        foreach ([['reportKind' => 'nsf'], ['source' => 'plaw'], ['period' => '2026-09-01'], ['component' => 'bonusCommission'],
            ['complete' => false], ['rows' => [...$original['rows'], ...$original['rows']]],
            ['rows' => [['agent' => 'Jane Doe', 'agentKey' => 'jane doe', 'amount' => NAN]]]] as $change) {
            try { $this->snapshot(results: array_replace($original, $change)); $this->fail('Invalid frozen component accepted.'); }
            catch (\InvalidArgumentException) { $this->addToAssertionCount(1); }
        }
    }

    public function test_validate_rejects_unknown_schema_and_missing_legacy_pricing_instead_of_synthesizing_it(): void
    {
        $snapshot = $this->snapshot();
        foreach ([[], ['version' => 2] + $snapshot, ['policy' => 'guessed'] + $snapshot, ['extra' => true] + $snapshot,
            ['source' => 'both'] + $snapshot, ['period' => '2026-10-02'] + $snapshot] as $invalid) {
            try { RetentionPricingSnapshot::validate($invalid, $this->results()); $this->fail('Unsupported snapshot accepted.'); }
            catch (\InvalidArgumentException) { $this->addToAssertionCount(1); }
        }
    }
}
