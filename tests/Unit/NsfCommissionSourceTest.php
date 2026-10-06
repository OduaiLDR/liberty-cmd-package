<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Services\DBConnector;
use Cmd\Reports\Services\NsfCommissionSource;
use Cmd\Reports\Tests\TestCase;

final class NsfCommissionSourceTest extends TestCase
{
    private const CONFIG = ['custom_agent' => 742134, 'custom_nsf_return' => 742148, 'custom_nsf_action' => 742136, 'custom_nsf_recoup' => 742146];

    private function row(array $changes = []): array
    {
        return array_replace([
            'ID' => '123', 'AGENT' => 'Synthetic Agent', 'NSF_RETURNED_DATE' => '2098-01-02',
            'NSF_ACTION' => 'Recoup', 'NSF_RECOUP_DATE' => '2098-01-03', 'CLEARED_DATE' => '2098-01-04',
            'AGENT_VARIANTS' => '1', 'RETURN_VARIANTS' => '1', 'ACTION_VARIANTS' => '1',
            'RECOUP_VARIANTS' => '1', 'CLEAR_VARIANTS' => '1', 'PAYMENT_PRESENT' => '1',
        ], $changes);
    }

    private function fetch(array $rows, ?string $agent = null): array
    {
        return NsfCommissionSource::fetch(new NsfSourceFixture($rows), self::CONFIG, '2098-01-01', '2098-01-31', $agent);
    }

    public function test_query_reduces_active_fields_before_join_and_keeps_all_date_variants(): void
    {
        $connector = new NsfSourceFixture([$this->row()]);
        $result = NsfCommissionSource::fetch($connector, self::CONFIG, '2098-01-01', '2098-01-31');
        $this->assertCount(1, $result);
        $query = $connector->queryText;
        $this->assertStringContainsString('WHERE (_FIVETRAN_DELETED = FALSE OR _FIVETRAN_DELETED IS NULL)', $query);
        $this->assertStringContainsString('(t._FIVETRAN_DELETED = FALSE OR t._FIVETRAN_DELETED IS NULL)', $query);
        $this->assertSame(5, substr_count($query, 'GROUP BY CONTACT_ID'));
        // The return-date aggregation is NOT restricted to the selected month:
        // an in-month + out-of-month pair must still produce RETURN_VARIANTS=2.
        $this->assertStringContainsString("MAX(IFF(F_DATE >= '2098-01-01' AND F_DATE <= '2098-01-31', 1, 0)) AS IN_PERIOD", $query);
        $this->assertStringContainsString('FROM active_fields WHERE CUSTOM_ID = 742148 GROUP BY CONTACT_ID', $query);
        $this->assertStringContainsString('WHERE r.IN_PERIOD = 1 AND EXISTS', $query);
        $this->assertStringContainsString('DENSE_RANK() OVER (PARTITION BY CONTACT_ID ORDER BY PROCESS_DATE DESC)', $query);
        $this->assertStringContainsString('COUNT(DISTINCT CLEARED_DATE) AS CLEAR_VARIANTS', $query);
        $this->assertStringContainsString('LEFT JOIN payments', $query);
        $this->assertStringContainsString("(t.RETURN_CODE IS NULL OR t.RETURN_CODE = '')", $query);
        $this->assertStringNotContainsString('ROW_NUMBER()', $query);
    }

    public function test_identical_contact_rows_collapse_but_conflicting_values_cannot_overwrite(): void
    {
        $this->assertCount(1, $this->fetch([$this->row(), $this->row()]));
        $this->expectExceptionMessage('conflicting rows for contact 123');
        $this->fetch([$this->row(), $this->row(['CLEARED_DATE' => '2098-01-05'])]);
    }

    public function test_payment_selection_is_bounded_before_ranking_without_changing_presence(): void
    {
        $connector = new NsfSourceFixture([$this->row(['CLEARED_DATE' => '2098-02-05'])]);
        $rows = NsfCommissionSource::fetch($connector, self::CONFIG, '2098-01-01', '2098-01-31');
        // Even if a later deposit exists, the ranked input can only include
        // Pacific clearing dates through the fifth. Presence remains unbounded.
        $this->assertStringContainsString("FROM active_payments WHERE CLEARED_DATE <= '2098-02-05'", $connector->queryText);
        $this->assertStringContainsString('SELECT DISTINCT CONTACT_ID, 1 AS PAYMENT_PRESENT FROM active_payments', $connector->queryText);
        $this->assertStringContainsString('LEFT JOIN payment_presence pp', $connector->queryText);
        $this->assertTrue(NsfCommissionSource::validCommission($rows[0]));
    }

    public function test_later_only_payment_stays_in_assignments_without_a_qualifying_clear(): void
    {
        $rows = $this->fetch([$this->row(['PAYMENT_PRESENT' => '1', 'CLEAR_VARIANTS' => '0', 'CLEARED_DATE' => null])]);
        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]['CLEARED_DATE']);
        $this->assertFalse(NsfCommissionSource::validCommission($rows[0]));
        $this->assertSame([], $this->fetch([$this->row(['PAYMENT_PRESENT' => '0', 'CLEAR_VARIANTS' => '0', 'CLEARED_DATE' => null])]));
    }

    public function test_every_field_and_tied_payment_conflict_fails_before_agent_filtering(): void
    {
        foreach (['AGENT_VARIANTS', 'RETURN_VARIANTS', 'ACTION_VARIANTS', 'RECOUP_VARIANTS', 'CLEAR_VARIANTS'] as $field) {
            try {
                $this->fetch([$this->row([$field => '2'])], 'Other Agent');
                $this->fail('A conflicting contact must stop the report.');
            } catch (\RuntimeException $error) {
                $this->assertStringContainsString($field, $error->getMessage());
                $this->assertStringContainsString('123', $error->getMessage());
            }
        }
    }

    public function test_nonpaying_candidates_preserve_denominator_but_cannot_hide_field_conflicts(): void
    {
        $noPayment = $this->row(['PAYMENT_PRESENT' => '0', 'CLEAR_VARIANTS' => '0', 'CLEARED_DATE' => null]);
        $this->assertSame([], $this->fetch([$noPayment]));
        $this->expectExceptionMessage('RETURN_VARIANTS');
        $this->fetch([array_replace($noPayment, ['RETURN_VARIANTS' => '2'])]);
    }

    public function test_requested_agent_uses_normalized_identity_without_losing_same_person(): void
    {
        $rows = [$this->row(['AGENT' => ' Synthetic  Agent ']), $this->row(['ID' => '124', 'AGENT' => 'Other Agent'])];
        $this->assertCount(1, $this->fetch($rows, 'synthetic agent'));
        $this->assertCount(2, $this->fetch($rows));
    }

    public function test_missing_uniqueness_or_payment_evidence_is_not_accepted(): void
    {
        foreach ([['RETURN_VARIANTS' => null], ['CLEAR_VARIANTS' => '0'], ['PAYMENT_PRESENT' => 'unknown']] as $changes) {
            try {
                $this->fetch([$this->row($changes)]);
                $this->fail('Invalid evidence must stop calculation.');
            } catch (\RuntimeException $error) {
                $this->assertStringContainsString('evidence', $error->getMessage());
            }
        }
    }

    public function test_qualifying_payment_without_agent_cannot_silently_disappear(): void
    {
        foreach ([null, '', " \t "] as $agent) {
            try {
                $this->fetch([$this->row(['AGENT' => $agent])]);
                $this->fail('A qualifying payment requires an agent identity.');
            } catch (\RuntimeException $error) {
                $this->assertStringContainsString('contact 123', $error->getMessage());
                $this->assertStringContainsString('no assigned agent', $error->getMessage());
            }
        }
    }

    public function test_unpayable_row_without_agent_remains_visible(): void
    {
        $rows = $this->fetch([$this->row(['AGENT' => null, 'AGENT_VARIANTS' => '0', 'CLEARED_DATE' => '2098-02-06'])]);
        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]['AGENT']);
        $this->assertFalse(NsfCommissionSource::validCommission($rows[0]));
    }

    public function test_invalid_date_range_is_rejected_before_querying(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        NsfCommissionSource::fetch(new NsfSourceFixture([]), self::CONFIG, '2098-02-30', '2098-02-31');
    }

    public function test_shared_validity_rejects_prior_year_and_respects_exact_cutoff(): void
    {
        $this->assertTrue(NsfCommissionSource::validCommission($this->row(['CLEARED_DATE' => '2098-02-05'])));
        $this->assertTrue(NsfCommissionSource::validCommission(array_change_key_case($this->row(), CASE_LOWER)));
        $this->assertFalse(NsfCommissionSource::validCommission($this->row(['CLEARED_DATE' => '2098-02-06'])));
        $this->assertFalse(NsfCommissionSource::validCommission($this->row(['NSF_RECOUP_DATE' => '2097-01-03'])));
        $this->assertFalse(NsfCommissionSource::validCommission($this->row(['NSF_RECOUP_DATE' => '2098-02-30'])));
    }
}

final class NsfSourceFixture extends DBConnector
{
    public string $queryText = '';
    public function __construct(private array $rows) {}
    public function query(string $sql, array $bindings = [], ?int $timeoutSeconds = null): array
    {
        $this->queryText = $sql;
        return ['data' => $this->rows, 'rowCount' => count($this->rows), 'columns' => []];
    }
}
