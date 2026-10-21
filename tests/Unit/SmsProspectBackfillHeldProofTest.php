<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Services\SmsProspectBackfill;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use SmsProspectBackfillPlan;

require_once dirname(__DIR__, 2).'/src/Services/SmsProspectBackfill.php';

/** Pure format validation only: no credentials, filesystem artifacts or SQL connections. */
final class SmsProspectBackfillHeldProofTest extends TestCase
{
    private function proof(): array
    {
        return ['format' => 2, 'coverage_manifest_sha256' => str_repeat('a',64), 'coverage_state_sha256' => str_repeat('b',64),
            'source_guard' => str_repeat('c',64), 'source_watermark_pk' => 100, 'prospect_watermark_pk' => 30,
            'candidate_count' => 8, 'candidate_max_id' => 90,
            'eligibility_policy' => SmsProspectBackfillPlan::HOLD_REPEATS_POLICY,
            'legacy_tool_sha256' => SmsProspectBackfillPlan::LEGACY_CONFLICT_TOOL_SHA256,
            'conflict_counts_verified' => false, 'audited_source_rows' => 30, 'missing_people' => 10,
            'excluded_repeated_people' => 2, 'conflict_counter_evidence' => [
                'recorded_total' => 4, 'recorded_missing' => 2, 'stored_total' => 2, 'stored_missing' => 1,
                'repeated_observations' => 6, 'missing_repeated_observations' => 3],
            'artifact_sha256' => str_repeat('d',64), 'writer_tool_sha256' => str_repeat('e',64),
            'history_policy' => 'confirmed-no-prior-sends-v1'];
    }

    private function validate(array $proof): void
    {
        (new ReflectionMethod(SmsProspectBackfill::class, 'proof'))->invoke(null, $proof);
    }

    public function test_writer_accepts_a_distinct_conservative_frozen_proof(): void
    {
        $proof = $this->proof();
        $this->validate($proof);
        self::assertSame(8, $proof['candidate_count']);
        self::assertFalse($proof['conflict_counts_verified']);
    }

    public static function invalidFields(): array
    {
        return [['format', '2'], ['eligibility_policy', 'all'], ['legacy_tool_sha256', str_repeat('f',64)],
            ['conflict_counts_verified', true], ['conflict_counts_verified', 0], ['missing_people', 11],
            ['audited_source_rows', '30'], ['audited_source_rows', -1], ['audited_source_rows', 9],
            ['excluded_repeated_people', 1], ['candidate_count', 7], ['candidate_count', '8'],
            ['candidate_max_id', 0], ['candidate_max_id', 7], ['candidate_max_id', 101],
            ['source_watermark_pk', 2147483648], ['prospect_watermark_pk', 2147483648],
            ['artifact_sha256', null], ['writer_tool_sha256', str_repeat('x',64)],
            ['coverage_manifest_sha256', null], ['coverage_state_sha256', str_repeat('0',63)],
            ['source_guard', false], ['history_policy', 'unknown'],
            ['excluded_conflicts', 1], ['excluded_repeated_nonconflicting', 1],
            ['conflict_counter_evidence', []], ['conflict_counter_evidence', null]];
    }

    #[DataProvider('invalidFields')]
    public function test_writer_rejects_malformed_or_misleading_format_two(string $field, mixed $value): void
    {
        $proof = $this->proof(); $proof[$field] = $value;
        $this->expectException(RuntimeException::class);
        $this->validate($proof);
    }

    public static function requiredFields(): array
    {
        return array_map(fn ($key) => [$key], ['eligibility_policy','legacy_tool_sha256','conflict_counts_verified',
            'audited_source_rows','missing_people','excluded_repeated_people','conflict_counter_evidence',
            'artifact_sha256','writer_tool_sha256','history_policy']);
    }

    #[DataProvider('requiredFields')]
    public function test_writer_refuses_missing_explicit_proof_fields(string $field): void
    {
        $proof = $this->proof(); unset($proof[$field]);
        $this->expectException(RuntimeException::class);
        $this->validate($proof);
    }

    public static function impossibleEvidence(): array
    {
        return [['recorded_total', 0], ['recorded_missing', 0], ['stored_total', 5], ['stored_missing', 3],
            ['repeated_observations', 5], ['missing_repeated_observations', 2],
            ['recorded_total', '4'], ['stored_total', -1], ['recorded_missing', 31], ['extra', 0]];
    }

    #[DataProvider('impossibleEvidence')]
    public function test_both_populations_require_enough_observations_for_flags_and_resets(string $field, mixed $value): void
    {
        $proof = $this->proof(); $proof['conflict_counter_evidence'][$field] = $value;
        $this->expectException(RuntimeException::class);
        $this->validate($proof);
    }

    public function test_repeated_counter_increments_are_not_falsely_bounded_by_unique_repeated_people(): void
    {
        $proof = $this->proof();
        $proof['excluded_repeated_people'] = 1; $proof['missing_people'] = 9;
        // A-B-A-B: one repeated person, two increments, one remaining flag, three repeat observations.
        $this->validate($proof);
        self::assertSame(2, $proof['conflict_counter_evidence']['recorded_missing']);
    }
}
