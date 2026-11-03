<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Services\PayrollCommissionResultSnapshot;
use Cmd\Reports\Tests\TestCase;

class PayrollCommissionResultSnapshotTest extends TestCase
{
    public function test_complete_frozen_components_merge_exact_persisted_amounts_and_explicit_zero_rows(): void
    {
        $sources = $this->sources();
        $snapshot = PayrollCommissionResultSnapshot::merge($sources, '2098-09-01');
        $this->assertSame([
            ['reportKind' => 'retention', 'source' => 'ldr', 'period' => '2098-09-01', 'agent' => 'Alice Smith', 'agentKey' => 'alice smith', 'commission' => 12.35, 'bonusCommission' => 3.0],
            ['reportKind' => 'retention', 'source' => 'ldr', 'period' => '2098-09-01', 'agent' => 'Zero Agent', 'agentKey' => 'zero agent', 'commission' => 0.0, 'bonusCommission' => 0.0],
            ['reportKind' => 'retention', 'source' => 'plaw', 'period' => '2098-09-01', 'agent' => 'Alice Smith', 'agentKey' => 'alice smith', 'commission' => 12.35, 'bonusCommission' => 3.0],
            ['reportKind' => 'retention', 'source' => 'plaw', 'period' => '2098-09-01', 'agent' => 'Zero Agent', 'agentKey' => 'zero agent', 'commission' => 0.0, 'bonusCommission' => 0.0],
            ['reportKind' => 'nsf', 'source' => 'ldr', 'period' => '2098-09-01', 'agent' => 'NSF Agent', 'agentKey' => 'nsf agent', 'commission' => 8.0, 'bonusCommission' => 0.0],
            ['reportKind' => 'nsf', 'source' => 'plaw', 'period' => '2098-09-01', 'agent' => 'NSF Agent', 'agentKey' => 'nsf agent', 'commission' => 8.0, 'bonusCommission' => 0.0],
        ], $snapshot['agentResults']);
        $this->assertCount(4, $snapshot['agentResultsCoverage']);
        foreach ($snapshot['agentResultsCoverage'] as $scope) $this->assertTrue($scope['complete']);
    }
    public function test_missing_or_wrong_source_component_cannot_be_treated_as_zero(): void
    {
        $sources = $this->sources();
        unset($sources['lookback_plaw']['context']['commissionResults']);
        $this->expectException(\RuntimeException::class);
        PayrollCommissionResultSnapshot::merge($sources, '2098-09-01');
    }
    public function test_duplicate_employee_keys_and_non_finite_amounts_cannot_be_captured(): void
    {
        foreach ([ [['agent' => 'Alice  Smith', 'amount' => 1], ['agent' => 'alice smith', 'amount' => 2]],
            [['agent' => 'Alice', 'amount' => INF]] ] as $rows) {
            try { PayrollCommissionResultSnapshot::component('retention', 'ldr', '2098-09-01', 'commission', $rows); $this->fail('Ambiguous values must fail.'); }
            catch (\InvalidArgumentException) { $this->addToAssertionCount(1); }
        }
    }
    public function test_verified_empty_components_publish_complete_zero_coverage(): void
    {
        $snapshot = PayrollCommissionResultSnapshot::merge($this->sources(true), '2098-09-01');
        $this->assertSame([], $snapshot['agentResults']);
        $this->assertCount(4, $snapshot['agentResultsCoverage']);
    }
    public function test_only_explicit_all_priced_nsf_capture_preserves_the_new_coverage_basis(): void
    {
        $sources = $this->sources();
        $legacy = PayrollCommissionResultSnapshot::merge($sources, '2098-09-01');
        foreach ($legacy['agentResultsCoverage'] as $scope) $this->assertArrayNotHasKey('coverageBasis', $scope);
        foreach (['ldr', 'plaw'] as $source) {
            $component = $sources['nsf_' . $source]['context']['commissionResults'];
            $sources['nsf_' . $source]['context']['commissionResults'] = PayrollCommissionResultSnapshot::component('nsf', $source,
                '2098-09-01', 'commission', $component['rows'], PayrollCommissionResultSnapshot::NSF_ALL_PRICED_AGENTS);
        }
        $current = PayrollCommissionResultSnapshot::merge($sources, '2098-09-01');
        $this->assertSame($legacy['agentResults'], $current['agentResults']);
        foreach ($current['agentResultsCoverage'] as $scope) {
            if ($scope['reportKind'] === 'nsf') $this->assertSame('all_priced_agents_v1', $scope['coverageBasis']);
            else $this->assertArrayNotHasKey('coverageBasis', $scope);
        }
    }

    public function test_an_unknown_coverage_claim_cannot_be_published(): void
    {
        $sources = $this->sources();
        $sources['nsf_ldr']['context']['commissionResults']['coverageBasis'] = 'roster_only';
        $this->expectException(\RuntimeException::class);
        PayrollCommissionResultSnapshot::merge($sources, '2098-09-01');
    }
    public function test_component_source_or_period_substitution_cannot_publish(): void
    {
        foreach (['source' => 'ldr', 'period' => '2098-08-01', 'complete' => false] as $field => $value) {
            $sources = $this->sources();
            $sources['lookback_plaw']['context']['commissionResults'][$field] = $value;
            try { PayrollCommissionResultSnapshot::merge($sources, '2098-09-01'); $this->fail('Uncorrelated component must not publish.'); }
            catch (\RuntimeException) { $this->addToAssertionCount(1); }
        }
    }
    private function sources(bool $empty = false): array
    {
        $sources = [];
        foreach (['ldr', 'plaw'] as $source) {
            foreach (['retention' => ['retention', 'commission', [['agent' => '  Alice   Smith ', 'amount' => 12.345], ['agent' => 'Zero Agent', 'amount' => 0]]],
                'lookback' => ['retention', 'bonusCommission', [['agent' => 'alice smith', 'amount' => 3]]],
                'nsf' => ['nsf', 'commission', [['agent' => 'NSF Agent', 'amount' => 8]]]] as $family => [$kind, $component, $rows]) {
                $sources[$family . '_' . $source] = ['context' => ['commissionResults' => PayrollCommissionResultSnapshot::component($kind, $source, '2098-09-01', $component, $empty ? [] : $rows)]];
            }
        }
        return $sources;
    }
}
