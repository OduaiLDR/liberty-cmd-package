<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Services\SalesFinalRecaptureEligibility;
use PHPUnit\Framework\TestCase;

final class SalesFinalRecaptureEligibilityTest extends TestCase
{
    private function fixture(): object
    {
        $state = (object) ['queries' => [], 'enrollments' => [['LLG_ID' => 'LLG-1', 'Agent' => 'Verified Agent', 'First_Payment_Cleared_Date' => '2026-07-15', 'Cancel_Date' => null]],
            'employees' => [['PK' => 501, 'Employee_Name' => 'Verified Agent']], 'contacts' => ['ldr' => [['ID' => '1', 'DROPPED_DATE' => null]], 'plaw' => []],
            'payments' => ['ldr' => [], 'plaw' => []], 'failed' => null];
        foreach (['2026-07-15', '2026-08-15', '2026-09-15'] as $date) $state->payments['ldr'][] = $this->payment($date);
        $state->service = new SalesFinalRecaptureEligibility(
            function ($query, $bindings) use ($state) {
                $this->assertStringStartsWith('SELECT ', $query); $this->assertDoesNotMatchRegularExpression('/\b(?:INSERT|UPDATE|DELETE|MERGE|EXEC|CREATE|ALTER)\b/i', $query);
                $state->queries[] = ['sql' => $query, 'bindings' => $bindings];
                if (str_contains($query, 'TblEmployees') && ($state->directoryUnavailable ?? false)) throw new \RuntimeException('Synthetic current directory failure');
                return ['success' => true, 'data' => str_contains($query, 'TblEmployees') ? $state->employees : $state->enrollments];
            },
            function ($source, $query) use ($state) {
                $this->assertStringStartsWith('SELECT ', $query); $this->assertDoesNotMatchRegularExpression('/\b(?:INSERT|UPDATE|DELETE|MERGE|EXEC|CREATE|ALTER)\b/i', $query);
                $state->queries[] = ['source' => $source, 'sql' => $query];
                if ($state->failed === $source) throw new \RuntimeException('Synthetic source failure');
                if (str_contains($query, 'FROM TRANSACTIONS')) {
                    $this->assertStringContainsString("TRANS_TYPE = 'D'", $query); $this->assertStringContainsString('RETURNED_DATE IS NULL', $query);
                    $this->assertStringContainsString('RETURN_CODE', $query); $this->assertStringContainsString('_FIVETRAN_DELETED = FALSE', $query);
                    $this->assertMatchesRegularExpression("/CLEARED_DATE\) AS DATE\) >= '\\d{4}-\\d{2}-01'/", $query);
                    $this->assertMatchesRegularExpression("/CLEARED_DATE\) AS DATE\) < '\\d{4}-\\d{2}-01'/", $query);
                    return ['data' => $state->payments[$source]];
                }
                return ['data' => $state->contacts[$source]];
            }, static fn () => '2026-11-05T12:00:00Z');
        return $state;
    }
    private function payment(string $date, array $overrides = []): array { return $overrides + ['CONTACT_ID' => '1', 'CLEARED_DATE' => $date, 'TRANS_TYPE' => 'D', 'RETURNED_DATE' => null, 'RETURN_CODE' => null, '_FIVETRAN_DELETED' => false]; }
    private function row(object $state, string $period = '2026-10-01'): array { return $state->service->inspect($period, ['LLG-1'])['rows'][0]; }

    public function test_three_cleared_payments_pass_and_two_trigger_full_takeback_eligibility_without_amounts(): void
    {
        $state = $this->fixture(); $row = $this->row($state);
        $this->assertSame('verified', $row['status']); $this->assertSame(3, $row['clearedPaymentCount']); $this->assertFalse($row['eligibleForTakeback']);
        $this->assertSame('2026-10-15', $row['cutoffDate']); $this->assertSame(501, $row['employeePk']);
        array_pop($state->payments['ldr']); $row = $this->row($state);
        $this->assertTrue($row['eligibleForTakeback']); $this->assertContains('under_three_cleared_payments', $row['reasonCodes']);
        $this->assertArrayNotHasKey('amount', $row); $this->assertArrayNotHasKey('commission', $row);
    }
    public function test_cancellation_on_or_before_cutoff_fails_and_after_cutoff_alone_does_not(): void
    {
        foreach (['2026-10-14' => true, '2026-10-15' => true, '2026-10-16' => false] as $cancel => $expected) {
            $state = $this->fixture(); $state->contacts['ldr'][0]['DROPPED_DATE'] = $cancel;
            $this->assertSame($expected, $this->row($state)['eligibleForTakeback']);
        }
    }
    public function test_mirrored_histories_use_maximum_not_sum_and_later_payments_do_not_count(): void
    {
        $state = $this->fixture(); $state->payments['ldr'] = [$this->payment('2026-07-15'), $this->payment('2026-08-15'), $this->payment('2026-10-16')];
        $state->payments['plaw'] = [$this->payment('2026-07-15'), $this->payment('2026-08-15')];
        $this->assertSame(2, $this->row($state)['clearedPaymentCount']);
        $this->assertTrue($this->row($state)['eligibleForTakeback']);
    }
    public function test_returned_deleted_or_non_deposit_transactions_never_count(): void
    {
        $state = $this->fixture(); $state->payments['ldr'] = [$this->payment('2026-07-15'), $this->payment('2026-08-15'),
            $this->payment('2026-09-15', ['RETURNED_DATE' => '2026-09-20']), $this->payment('2026-09-15', ['RETURN_CODE' => 'R01']),
            $this->payment('2026-09-15', ['_FIVETRAN_DELETED' => true]), $this->payment('2026-09-15', ['TRANS_TYPE' => 'F'])];
        $this->assertSame(2, $this->row($state)['clearedPaymentCount']);
    }
    public function test_failed_or_missing_or_ambiguous_source_is_pending_never_zero(): void
    {
        foreach (['missing_enrollment', 'ambiguous_enrollment', 'missing_portal', 'failed_portal', 'invalid_first', 'invalid_payment'] as $scenario) {
            $state = $this->fixture();
            if ($scenario === 'missing_enrollment') $state->enrollments = [];
            if ($scenario === 'ambiguous_enrollment') $state->enrollments[] = $state->enrollments[0];
            if ($scenario === 'missing_portal') $state->contacts['ldr'] = [];
            if ($scenario === 'failed_portal') $state->failed = 'plaw';
            if ($scenario === 'invalid_first') $state->enrollments[0]['First_Payment_Cleared_Date'] = '2026-02-30';
            if ($scenario === 'invalid_payment') $state->payments['ldr'][] = $this->payment('invalid');
            $row = $this->row($state); $this->assertSame('pending', $row['status'], $scenario); $this->assertNull($row['clearedPaymentCount'], $scenario); $this->assertNull($row['eligibleForTakeback'], $scenario);
        }
    }
    public function test_current_employee_identity_is_context_only_and_never_blocks_client_eligibility(): void
    {
        foreach (['missing', 'duplicate', 'renamed', 'blank_agent', 'unavailable'] as $scenario) {
            $state = $this->fixture();
            if ($scenario === 'missing') $state->employees = [];
            if ($scenario === 'duplicate') $state->employees[] = $state->employees[0];
            if ($scenario === 'renamed') $state->employees[0]['Employee_Name'] = 'New Current Name';
            if ($scenario === 'blank_agent') $state->enrollments[0]['Agent'] = '';
            if ($scenario === 'unavailable') $state->directoryUnavailable = true;
            $row = $this->row($state);
            $this->assertSame('verified', $row['status'], $scenario);
            $this->assertSame(3, $row['clearedPaymentCount'], $scenario);
            $this->assertFalse($row['eligibleForTakeback'], $scenario);
            $this->assertNull($row['employeePk'], $scenario);
            $this->assertTrue($row['currentIdentityUnverified'], $scenario);
            $this->assertContains('unverified_current_employee_identity', $row['informationalReasonCodes'], $scenario);
            $this->assertNotContains('unverified_current_employee_identity', $row['reasonCodes'], $scenario);
            array_pop($state->payments['ldr']);
            $this->assertTrue($this->row($state)['eligibleForTakeback'], $scenario);
        }
    }
    public function test_conflicting_cancellation_dates_that_change_eligibility_remain_pending(): void
    {
        $state = $this->fixture(); $state->enrollments[0]['Cancel_Date'] = '2026-10-14'; $state->contacts['ldr'][0]['DROPPED_DATE'] = '2026-10-16';
        $row = $this->row($state); $this->assertSame('pending', $row['status']); $this->assertContains('conflicting_cancellation_eligibility', $row['reasonCodes']);
        $state->contacts['ldr'][0]['DROPPED_DATE'] = '2026-10-13'; $row = $this->row($state);
        $this->assertSame('verified', $row['status']); $this->assertSame('2026-10-13', $row['cancellationDate']); $this->assertTrue($row['eligibleForTakeback']);
    }
    public function test_month_end_cutoff_clamps_and_wrong_reporting_month_is_not_due(): void
    {
        $state = $this->fixture(); $state->enrollments[0]['First_Payment_Cleared_Date'] = '2026-08-31';
        $row = $this->row($state, '2026-11-01'); $this->assertSame('2026-11-30', $row['cutoffDate']);
        $row = $this->row($state); $this->assertFalse($row['eligibleForTakeback']); $this->assertContains('not_due', $row['reasonCodes']);
    }
    public function test_optional_cohort_uses_bounded_first_cleared_month_and_produces_integrity_proof(): void
    {
        $state = $this->fixture(); $response = $state->service->inspect('2026-10-01');
        $this->assertSame(['2026-07-01', '2026-08-01'], $state->queries[0]['bindings']); $this->assertStringContainsString('TOP (2001)', $state->queries[0]['sql']);
        $this->assertTrue($response['readOnly']); $this->assertSame('reporting_month_cohort', $response['scope']); $this->assertSame('2026-11-05T12:00:00.000000Z', $response['capturedAt']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $response['sourceSha256']); $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $response['rows'][0]['sha256']);
        $state->enrollments = array_fill(0, 2001, $state->enrollments[0]);
        $this->expectException(\RuntimeException::class); $this->expectExceptionMessage('no clients were silently truncated'); $state->service->inspect('2026-10-01');
    }
    public function test_invalid_request_is_rejected_before_any_source_query(): void
    {
        foreach ([['2026-10-02', null], ['2026-13-01', null], ['2026-10-01', []], ['2026-10-01', ['LLG-1', 'LLG-1']], ['2026-10-01', ['1']], ['2026-10-01', array_fill(0, 201, 'LLG-1')]] as [$period, $ids]) {
            $state = $this->fixture();
            try { $state->service->inspect($period, $ids); $this->fail('Invalid input accepted'); } catch (\InvalidArgumentException) { $this->assertSame([], $state->queries); }
        }
    }
}
