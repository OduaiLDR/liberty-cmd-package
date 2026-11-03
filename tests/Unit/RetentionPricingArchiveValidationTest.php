<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Services\PayrollCommissionResultSnapshot;
use Cmd\Reports\Services\RetentionPricingSnapshot;
use PHPUnit\Framework\TestCase;

/** Six synthetic archived components; no workbook rewrite, database, jobs or mail. */
final class RetentionPricingArchiveValidationTest extends TestCase
{
    private const PERIOD = '2026-10-01';

    private function sources(): array
    {
        $sources = [];
        foreach (['ldr', 'plaw'] as $source) {
            foreach (['retention' => ['retention', 'commission', [['agent' => 'Earned Agent', 'amount' => 42],
                ['agent' => 'Unassigned Agent', 'amount' => 10], ['agent' => 'Zero Agent', 'amount' => 0]]],
                'lookback' => ['retention', 'bonusCommission', [['agent' => 'Earned Agent', 'amount' => 3], ['agent' => 'Zero Agent', 'amount' => 0]]],
                'nsf' => ['nsf', 'commission', [['agent' => 'NSF Agent', 'amount' => 8]]]] as $family => [$kind, $component, $rows]) {
                $sources[$family . '_' . $source] = ['context' => ['commissionResults' => PayrollCommissionResultSnapshot::component(
                    $kind, $source, self::PERIOD, $component, $rows,
                    $kind === 'nsf' ? PayrollCommissionResultSnapshot::NSF_ALL_PRICED_AGENTS : null)]];
            }
        }
        return $sources;
    }

    private function legacyResult(): array
    {
        $agents = [];
        foreach (['ldr', 'plaw'] as $source) foreach ([['Earned Agent', 42.0, 3.0], ['Unassigned Agent', 10.0, 0.0], ['Zero Agent', 0.0, 0.0]] as [$agent, $commission, $bonus]) {
            $agents[] = ['reportKind' => 'retention', 'source' => $source, 'period' => self::PERIOD, 'agent' => $agent,
                'agentKey' => strtolower($agent), 'commission' => $commission, 'bonusCommission' => $bonus];
        }
        foreach (['ldr', 'plaw'] as $source) $agents[] = ['reportKind' => 'nsf', 'source' => $source, 'period' => self::PERIOD,
            'agent' => 'NSF Agent', 'agentKey' => 'nsf agent', 'commission' => 8.0, 'bonusCommission' => 0.0];
        $coverage = [];
        foreach (['retention', 'nsf'] as $kind) foreach (['ldr', 'plaw'] as $source) $coverage[] = ['reportKind' => $kind,
            'source' => $source, 'period' => self::PERIOD, 'complete' => true]
            + ($kind === 'nsf' ? ['coverageBasis' => PayrollCommissionResultSnapshot::NSF_ALL_PRICED_AGENTS] : []);
        return ['agentResults' => $agents, 'agentResultsCoverage' => $coverage];
    }

    private function proof(string $source): array
    {
        $line = ['detailRow' => 2, 'clientId' => '1001', 'agent' => 'Earned Agent', 'agentKey' => 'earned agent',
            'retentionDate' => '2026-10-01', 'paymentDate' => '2026-10-10', 'retainedMonth' => self::PERIOD,
            'currentTier' => 1, 'appliedTier' => 1, 'rates' => [2, 5, 10, 40], 'amount' => 2];
        $rows = [$line,
            array_replace($line, ['detailRow' => 3, 'clientId' => '1002', 'retentionDate' => '2026-09-10',
                'retainedMonth' => '2026-09-01', 'appliedTier' => 4, 'amount' => 40]),
            array_replace($line, ['detailRow' => 4, 'clientId' => '1003', 'agent' => 'Unassigned Agent', 'agentKey' => 'unassigned agent',
                'retentionDate' => '2026-09-11', 'retainedMonth' => '2026-09-01', 'currentTier' => 0, 'appliedTier' => 3, 'amount' => 10]),
            array_replace($line, ['detailRow' => 5, 'clientId' => '1004', 'agent' => 'Zero Agent', 'agentKey' => 'zero agent',
                'currentTier' => 0, 'appliedTier' => 0, 'amount' => 0])];
        return RetentionPricingSnapshot::capture($source, self::PERIOD, true, $rows,
            $this->sources()['retention_' . $source]['context']['commissionResults']);
    }

    private function withProofs(): array
    {
        $sources = $this->sources();
        foreach (['ldr', 'plaw'] as $source) $sources['retention_' . $source]['context']['regularPricing'] = $this->proof($source);
        return $sources;
    }

    public function test_absent_pricing_keeps_legacy_results_and_coverage_exactly_unchanged(): void
    {
        $sources = $this->sources(); $before = serialize($sources);
        $this->assertSame($this->legacyResult(), PayrollCommissionResultSnapshot::merge($sources, self::PERIOD));
        $this->assertSame($before, serialize($sources));
    }

    public function test_a_present_nonarray_proof_is_not_treated_as_missing_legacy_evidence(): void
    {
        foreach ([null, false, true, 0, 'legacy', new \stdClass] as $proof) {
            $sources = $this->sources(); $sources['retention_ldr']['context']['regularPricing'] = $proof;
            try { PayrollCommissionResultSnapshot::merge($sources, self::PERIOD); $this->fail('A present malformed pricing proof must fail.'); }
            catch (\RuntimeException $error) { $this->assertStringContainsString('pricing evidence', $error->getMessage()); }
        }
    }

    public function test_other_source_families_do_not_reinterpret_the_regular_retention_proof_key(): void
    {
        $sources = $this->sources();
        foreach (['lookback_ldr', 'lookback_plaw', 'nsf_ldr', 'nsf_plaw'] as $key) $sources[$key]['context']['regularPricing'] = ['version' => 999];
        $this->assertSame($this->legacyResult(), PayrollCommissionResultSnapshot::merge($sources, self::PERIOD));
    }

    public function test_actual_valid_pricing_for_both_brands_matches_legacy_totals_without_exposing_client_lines(): void
    {
        $sources = $this->withProofs(); $before = serialize($sources);
        $merged = PayrollCommissionResultSnapshot::merge($sources, self::PERIOD);
        $this->assertSame($this->legacyResult(), $merged);
        $this->assertSame(['agentResults', 'agentResultsCoverage'], array_keys($merged));
        $this->assertSame($before, serialize($sources));
        foreach ($merged['agentResults'] as $row) {
            $this->assertArrayNotHasKey('clientId', $row); $this->assertArrayNotHasKey('regularPricing', $row);
        }
    }

    public function test_legacy_and_new_pricing_can_coexist_without_inventing_proof_for_the_older_brand(): void
    {
        $sources = $this->sources(); $sources['retention_ldr']['context']['regularPricing'] = $this->proof('ldr');
        $this->assertSame($this->legacyResult(), PayrollCommissionResultSnapshot::merge($sources, self::PERIOD));
        $this->assertArrayNotHasKey('regularPricing', $sources['retention_plaw']['context']);
    }

    public function test_unknown_or_incomplete_present_proof_is_rejected_by_the_real_validator(): void
    {
        foreach ([static function (&$proof) { $proof = []; }, static function (&$proof) { $proof['version'] = 2; },
            static function (&$proof) { $proof['policy'] = 'today'; }, static function (&$proof) { unset($proof['rows']); },
            static function (&$proof) { $proof['extra'] = true; }, static function (&$proof) { $proof['rows'] = []; }] as $change) {
            $sources = $this->withProofs(); $change($sources['retention_ldr']['context']['regularPricing']);
            try { PayrollCommissionResultSnapshot::merge($sources, self::PERIOD); $this->fail('Present unsupported or incomplete proof cannot become legacy output.'); }
            catch (\InvalidArgumentException $error) { $this->assertStringContainsString('Frozen regular Retention pricing:', $error->getMessage()); }
        }
    }

    public function test_a_pricing_source_or_period_cannot_be_substituted_under_another_archived_component(): void
    {
        foreach ([['source' => 'plaw'], ['source' => 'both'], ['period' => '2026-09-01']] as $change) {
            $sources = $this->withProofs();
            $sources['retention_ldr']['context']['regularPricing'] = $change + $sources['retention_ldr']['context']['regularPricing'];
            try { PayrollCommissionResultSnapshot::merge($sources, self::PERIOD); $this->fail('Pricing must match its exact archived source and month.'); }
            catch (\InvalidArgumentException) { $this->addToAssertionCount(1); }
        }
    }

    public function test_line_identity_tiers_dates_and_selected_amount_corruptions_stop_the_merge(): void
    {
        $changes = [static function (&$row) { $row['detailRow'] = 1; }, static function (&$row) { $row['clientId'] = ''; },
            static function (&$row) { $row['agentKey'] = 'other agent'; },
            static function (&$row) { $row['agent'] = 'Other Agent'; $row['agentKey'] = 'other agent'; },
            static function (&$row) { $row['currentTier'] = '1'; }, static function (&$row) { $row['appliedTier'] = 5; },
            static function (&$row) { $row['appliedTier'] = 2; $row['amount'] = 5; },
            static function (&$row) { $row['retainedMonth'] = '2026-08-01'; },
            static function (&$row) { $row['retentionDate'] = '2026-11-01'; $row['retainedMonth'] = '2026-11-01'; },
            static function (&$row) { $row['paymentDate'] = '2026-11-01'; },
            static function (&$row) { $row['rates'] = [2, 5, 10]; }, static function (&$row) { $row['amount'] = 2.01; },
            static function (&$row) { $row['amount'] = INF; $row['rates'][0] = INF; }];
        foreach ($changes as $change) {
            $sources = $this->withProofs(); $change($sources['retention_plaw']['context']['regularPricing']['rows'][0]);
            try { PayrollCommissionResultSnapshot::merge($sources, self::PERIOD); $this->fail('Invalid selected pricing cannot be published.'); }
            catch (\InvalidArgumentException) { $this->addToAssertionCount(1); }
        }
        $sources = $this->withProofs(); $sources['retention_plaw']['context']['regularPricing']['rows'][1]['detailRow'] = 2;
        $this->expectException(\InvalidArgumentException::class); PayrollCommissionResultSnapshot::merge($sources, self::PERIOD);
    }

    public function test_consistent_line_rate_changes_still_fail_when_the_agent_total_drifts_from_the_saved_component(): void
    {
        $sources = $this->withProofs();
        $sources['retention_ldr']['context']['regularPricing']['rows'][1]['rates'][3] = 41;
        $sources['retention_ldr']['context']['regularPricing']['rows'][1]['amount'] = 41;
        $this->expectException(\InvalidArgumentException::class); PayrollCommissionResultSnapshot::merge($sources, self::PERIOD);
    }

    public function test_worksheet_order_preserves_raw_accumulation_and_rounds_only_once_per_agent(): void
    {
        $sources = $this->sources();
        $component = PayrollCommissionResultSnapshot::component('retention', 'ldr', self::PERIOD, 'commission', [['agent' => 'Earned Agent', 'amount' => .21]]);
        $line = $this->proof('ldr')['rows'][0]; $line['rates'][0] = .105; $line['amount'] = .105;
        $sources['retention_ldr']['context'] = ['commissionResults' => $component,
            'regularPricing' => RetentionPricingSnapshot::capture('ldr', self::PERIOD, true,
                [$line, array_replace($line, ['detailRow' => 3, 'paymentDate' => '2026-10-20'])], $component)];
        $merged = PayrollCommissionResultSnapshot::merge($sources, self::PERIOD);
        $earned = array_values(array_filter($merged['agentResults'], static fn ($row) => $row['reportKind'] === 'retention'
            && $row['source'] === 'ldr' && $row['agentKey'] === 'earned agent'))[0];
        $this->assertSame(.21, $earned['commission']); $this->assertSame(3.0, $earned['bonusCommission']);
    }

    public function test_explicit_zero_results_allow_an_empty_new_pricing_ledger_without_erasing_lookback(): void
    {
        $sources = $this->sources();
        $component = PayrollCommissionResultSnapshot::component('retention', 'ldr', self::PERIOD, 'commission', [['agent' => 'Earned Agent', 'amount' => 0], ['agent' => 'Zero Agent', 'amount' => 0]]);
        $sources['retention_ldr']['context'] = ['commissionResults' => $component,
            'regularPricing' => RetentionPricingSnapshot::capture('ldr', self::PERIOD, true, [], $component)];
        $merged = PayrollCommissionResultSnapshot::merge($sources, self::PERIOD);
        $earned = array_values(array_filter($merged['agentResults'], static fn ($row) => $row['reportKind'] === 'retention'
            && $row['source'] === 'ldr' && $row['agentKey'] === 'earned agent'))[0];
        $this->assertSame(0.0, $earned['commission']); $this->assertSame(3.0, $earned['bonusCommission']);
    }
}
