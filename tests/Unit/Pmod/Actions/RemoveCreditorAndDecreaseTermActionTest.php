<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit\Pmod\Actions;

use Cmd\Reports\Pmod\Actions\RemoveCreditorAndDecreaseTermAction;
use Cmd\Reports\Pmod\Enums\PmodActionType;
use Cmd\Reports\Tests\Support\FakePmodExecutionGateway;
use Cmd\Reports\Tests\TestCase;

final class RemoveCreditorAndDecreaseTermActionTest extends TestCase
{
    private function makeDebt(string $id, string $creditorName): array
    {
        return [
            'id'       => $id,
            'creditor' => ['company_name' => $creditorName],
        ];
    }

    private function futureDrafts(): array
    {
        return [
            ['transaction_id' => 'd1', 'type' => 'D', 'active' => '1', 'process_date' => '2030-06-01', 'amount' => '150.00'],
            ['transaction_id' => 'd2', 'type' => 'D', 'active' => '1', 'process_date' => '2030-07-01', 'amount' => '150.00'],
            ['transaction_id' => 'd3', 'type' => 'D', 'active' => '1', 'process_date' => '2030-08-01', 'amount' => '150.00'],
            ['transaction_id' => 'd4', 'type' => 'D', 'active' => '1', 'process_date' => '2030-09-01', 'amount' => '150.00'],
            ['transaction_id' => 'd5', 'type' => 'DPG', 'active' => '1', 'process_date' => '2030-06-15', 'amount' => '10.95'],
        ];
    }

    // --- Validation / capture paths ---

    public function test_captures_when_creditor_info_missing(): void
    {
        $gateway = new FakePmodExecutionGateway();
        $action  = new RemoveCreditorAndDecreaseTermAction($gateway, true);

        $result = $action->handle($this->makeWorkItem([
            'actionType'        => PmodActionType::REMOVE_CREDITOR_AND_DECREASE_TERM,
            'creditorChange'    => [],
            'normalizedPayload' => ['months_to_decrease' => 3],
        ]));

        self::assertSame('captured_for_manual_review', $result->status);
        self::assertSame('missing_creditor_info', $result->metadata['reason']);
        self::assertCount(0, $gateway->cancelDebtCalls);
    }

    public function test_captures_when_months_to_decrease_is_zero(): void
    {
        $gateway = new FakePmodExecutionGateway();
        $action  = new RemoveCreditorAndDecreaseTermAction($gateway, true);

        $result = $action->handle($this->makeWorkItem([
            'actionType'        => PmodActionType::REMOVE_CREDITOR_AND_DECREASE_TERM,
            'creditorChange'    => ['creditor_name' => 'Chase Visa'],
            'normalizedPayload' => ['months_to_decrease' => 0],
        ]));

        self::assertSame('captured_for_manual_review', $result->status);
        self::assertSame('invalid_months', $result->metadata['reason']);
        self::assertCount(0, $gateway->cancelDebtCalls);
    }

    public function test_captures_when_months_exceeds_safety_cap_of_120(): void
    {
        $gateway = new FakePmodExecutionGateway();
        $action  = new RemoveCreditorAndDecreaseTermAction($gateway, true);

        $result = $action->handle($this->makeWorkItem([
            'actionType'        => PmodActionType::REMOVE_CREDITOR_AND_DECREASE_TERM,
            'creditorChange'    => ['creditor_name' => 'Chase Visa'],
            'normalizedPayload' => ['months_to_decrease' => 121],
        ]));

        self::assertSame('captured_for_manual_review', $result->status);
        self::assertSame('invalid_months', $result->metadata['reason']);
    }

    public function test_captures_when_live_updates_disabled(): void
    {
        $gateway = new FakePmodExecutionGateway();
        $action  = new RemoveCreditorAndDecreaseTermAction($gateway, false);

        $result = $action->handle($this->makeWorkItem([
            'actionType'        => PmodActionType::REMOVE_CREDITOR_AND_DECREASE_TERM,
            'creditorChange'    => ['creditor_name' => 'Chase Visa'],
            'normalizedPayload' => ['months_to_decrease' => 3],
        ]));

        self::assertSame('captured_for_manual_review', $result->status);
        self::assertSame('live_draft_updates_disabled', $result->metadata['reason']);
        self::assertCount(0, $gateway->cancelDebtCalls);
    }

    public function test_captures_when_dry_run(): void
    {
        $gateway = new FakePmodExecutionGateway();
        $action  = new RemoveCreditorAndDecreaseTermAction($gateway, true);

        $result = $action->handle($this->makeWorkItem([
            'actionType'        => PmodActionType::REMOVE_CREDITOR_AND_DECREASE_TERM,
            'creditorChange'    => ['creditor_name' => 'Chase Visa'],
            'normalizedPayload' => ['months_to_decrease' => 3],
            'dryRun'            => true,
        ]));

        self::assertSame('captured_for_manual_review', $result->status);
        self::assertSame('dry_run_only', $result->metadata['reason']);
        self::assertCount(0, $gateway->cancelDebtCalls);
    }

    public function test_captures_when_debt_not_found(): void
    {
        $gateway        = new FakePmodExecutionGateway();
        $gateway->debts = [$this->makeDebt('debt-99', 'Bank of America')];
        $action         = new RemoveCreditorAndDecreaseTermAction($gateway, true);

        $result = $action->handle($this->makeWorkItem([
            'actionType'        => PmodActionType::REMOVE_CREDITOR_AND_DECREASE_TERM,
            'creditorChange'    => ['creditor_name' => 'Chase Visa'],
            'normalizedPayload' => ['months_to_decrease' => 3],
        ]));

        self::assertSame('captured_for_manual_review', $result->status);
        self::assertSame('debt_not_found', $result->metadata['reason']);
        self::assertCount(0, $gateway->cancelDebtCalls);
        self::assertCount(0, $gateway->cancelDraftCalls);
    }

    // --- Happy path ---

    public function test_cancels_debt_then_cancels_last_n_type_d_future_drafts(): void
    {
        $gateway               = new FakePmodExecutionGateway();
        $gateway->debts        = [$this->makeDebt('debt-42', 'Chase Visa')];
        $gateway->transactions = $this->futureDrafts();
        $action                = new RemoveCreditorAndDecreaseTermAction($gateway, true);

        $result = $action->handle($this->makeWorkItem([
            'actionType'        => PmodActionType::REMOVE_CREDITOR_AND_DECREASE_TERM,
            'creditorChange'    => ['creditor_name' => 'Chase Visa'],
            'normalizedPayload' => ['months_to_decrease' => 2],
        ]));

        self::assertSame('updated', $result->status);
        // Debt cancelled
        self::assertCount(1, $gateway->cancelDebtCalls);
        self::assertSame('debt-42', $gateway->cancelDebtCalls[0]['debt_id']);
        // Last 2 type-D drafts cancelled (d3 and d4 — the latest ones)
        self::assertSame(2, $result->metadata['drafts_cancelled']);
        self::assertCount(2, $gateway->cancelDraftCalls);
        $cancelledIds = array_column($gateway->cancelDraftCalls, 'draft_id');
        self::assertContains('d3', $cancelledIds);
        self::assertContains('d4', $cancelledIds);
        // d1 and d2 (earlier drafts) NOT cancelled
        self::assertNotContains('d1', $cancelledIds);
        self::assertNotContains('d2', $cancelledIds);
        // DPG d5 not touched
        self::assertNotContains('d5', $cancelledIds);
        // CRM note
        self::assertCount(1, $gateway->notes);
        self::assertStringContainsString('Months Decreased: 2', $gateway->notes[0]['content']);
        self::assertStringContainsString('Drafts Cancelled: 2', $gateway->notes[0]['content']);
    }

    public function test_cancels_debt_then_cancels_last_1_draft(): void
    {
        $gateway               = new FakePmodExecutionGateway();
        $gateway->debts        = [$this->makeDebt('debt-42', 'Chase Visa')];
        $gateway->transactions = $this->futureDrafts();
        $action                = new RemoveCreditorAndDecreaseTermAction($gateway, true);

        $result = $action->handle($this->makeWorkItem([
            'actionType'        => PmodActionType::REMOVE_CREDITOR_AND_DECREASE_TERM,
            'creditorChange'    => ['creditor_name' => 'Chase Visa'],
            'normalizedPayload' => ['months_to_decrease' => 1],
        ]));

        self::assertSame('updated', $result->status);
        self::assertSame(1, $result->metadata['drafts_cancelled']);
        // Only d4 (last draft) cancelled
        self::assertSame('d4', $gateway->cancelDraftCalls[0]['draft_id']);
    }

    public function test_clamps_to_available_drafts_when_months_exceeds_draft_count(): void
    {
        $gateway               = new FakePmodExecutionGateway();
        $gateway->debts        = [$this->makeDebt('debt-42', 'Chase Visa')];
        $gateway->transactions = $this->futureDrafts();
        $action                = new RemoveCreditorAndDecreaseTermAction($gateway, true);

        // Only 4 type-D drafts, requesting 10 months
        $result = $action->handle($this->makeWorkItem([
            'actionType'        => PmodActionType::REMOVE_CREDITOR_AND_DECREASE_TERM,
            'creditorChange'    => ['creditor_name' => 'Chase Visa'],
            'normalizedPayload' => ['months_to_decrease' => 10],
        ]));

        self::assertSame('updated', $result->status);
        // Clamped to 4 (all available)
        self::assertSame(4, $result->metadata['drafts_cancelled']);
    }

    public function test_finds_debt_by_id(): void
    {
        $gateway        = new FakePmodExecutionGateway();
        $gateway->debts = [$this->makeDebt('debt-42', 'Chase Visa')];
        $action         = new RemoveCreditorAndDecreaseTermAction($gateway, true);

        $result = $action->handle($this->makeWorkItem([
            'actionType'        => PmodActionType::REMOVE_CREDITOR_AND_DECREASE_TERM,
            'creditorChange'    => ['creditor_id' => 'debt-42'],
            'normalizedPayload' => ['months_to_decrease' => 1],
        ]));

        self::assertSame('updated', $result->status);
        self::assertSame('debt-42', $gateway->cancelDebtCalls[0]['debt_id']);
    }

    public function test_name_match_is_case_insensitive(): void
    {
        $gateway        = new FakePmodExecutionGateway();
        $gateway->debts = [$this->makeDebt('debt-42', 'CHASE VISA')];
        $action         = new RemoveCreditorAndDecreaseTermAction($gateway, true);

        $result = $action->handle($this->makeWorkItem([
            'actionType'        => PmodActionType::REMOVE_CREDITOR_AND_DECREASE_TERM,
            'creditorChange'    => ['creditor_name' => 'chase visa'],
            'normalizedPayload' => ['months_to_decrease' => 1],
        ]));

        self::assertSame('updated', $result->status);
    }

    public function test_boundary_exactly_120_months_is_accepted(): void
    {
        $gateway               = new FakePmodExecutionGateway();
        $gateway->debts        = [$this->makeDebt('debt-42', 'Chase Visa')];
        $gateway->transactions = $this->futureDrafts();
        $action                = new RemoveCreditorAndDecreaseTermAction($gateway, true);

        $result = $action->handle($this->makeWorkItem([
            'actionType'        => PmodActionType::REMOVE_CREDITOR_AND_DECREASE_TERM,
            'creditorChange'    => ['creditor_name' => 'Chase Visa'],
            'normalizedPayload' => ['months_to_decrease' => 120],
        ]));

        // Clamped to available drafts but doesn't error
        self::assertSame('updated', $result->status);
        self::assertSame(120, $result->metadata['months_to_decrease']);
    }

    // --- Fail-safe / rollback ---

    public function test_debt_cancel_failure_propagates_before_any_draft_is_cancelled(): void
    {
        $gateway                    = new FakePmodExecutionGateway();
        $gateway->debts             = [$this->makeDebt('debt-42', 'Chase Visa')];
        $gateway->transactions      = $this->futureDrafts();
        $gateway->cancelDebtException = 'CRM debt cancel failed';
        $action                     = new RemoveCreditorAndDecreaseTermAction($gateway, true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('CRM debt cancel failed');

        $action->handle($this->makeWorkItem([
            'actionType'        => PmodActionType::REMOVE_CREDITOR_AND_DECREASE_TERM,
            'creditorChange'    => ['creditor_name' => 'Chase Visa'],
            'normalizedPayload' => ['months_to_decrease' => 2],
        ]));

        self::assertCount(0, $gateway->cancelDraftCalls);
    }

    public function test_partial_draft_cancel_failure_returns_captured_with_errors(): void
    {
        $gateway                       = new FakePmodExecutionGateway();
        $gateway->debts                = [$this->makeDebt('debt-42', 'Chase Visa')];
        $gateway->transactions         = $this->futureDrafts();
        $gateway->cancelDraftException = 'Draft cancel timeout';
        $action                        = new RemoveCreditorAndDecreaseTermAction($gateway, true);

        $result = $action->handle($this->makeWorkItem([
            'actionType'        => PmodActionType::REMOVE_CREDITOR_AND_DECREASE_TERM,
            'creditorChange'    => ['creditor_name' => 'Chase Visa'],
            'normalizedPayload' => ['months_to_decrease' => 2],
        ]));

        // Debt was still cancelled
        self::assertCount(1, $gateway->cancelDebtCalls);
        // Draft failures surface as captured
        self::assertSame('captured_for_manual_review', $result->status);
        self::assertNotEmpty($result->metadata['cancel_errors']);
    }

    public function test_action_type_is_remove_creditor_and_decrease_term(): void
    {
        $action = new RemoveCreditorAndDecreaseTermAction(new FakePmodExecutionGateway());
        self::assertSame(PmodActionType::REMOVE_CREDITOR_AND_DECREASE_TERM, $action->actionType());
    }
}
