<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit\Pmod\Actions;

use Cmd\Reports\Pmod\Actions\RemoveCreditorAndDecreasePaymentAction;
use Cmd\Reports\Pmod\Enums\PmodActionType;
use Cmd\Reports\Tests\Support\FakePmodExecutionGateway;
use Cmd\Reports\Tests\TestCase;

final class RemoveCreditorAndDecreasePaymentActionTest extends TestCase
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
            ['transaction_id' => 'd1', 'type' => 'D', 'active' => '1', 'process_date' => '2030-07-01', 'amount' => '150.00'],
            ['transaction_id' => 'd2', 'type' => 'D', 'active' => '1', 'process_date' => '2030-08-01', 'amount' => '150.00'],
            ['transaction_id' => 'd3', 'type' => 'DPG', 'active' => '1', 'process_date' => '2030-07-15', 'amount' => '10.95'],
        ];
    }

    // --- Validation / capture paths ---

    public function test_captures_when_creditor_info_missing(): void
    {
        $gateway = new FakePmodExecutionGateway();
        $action  = new RemoveCreditorAndDecreasePaymentAction($gateway, true);

        $result = $action->handle($this->makeWorkItem([
            'actionType'     => PmodActionType::REMOVE_CREDITOR_AND_DECREASE_PAYMENT,
            'creditorChange' => [],
            'amount'         => '100.00',
        ]));

        self::assertSame('captured_for_manual_review', $result->status);
        self::assertSame('missing_creditor_info', $result->metadata['reason']);
        self::assertCount(0, $gateway->cancelDebtCalls);
        self::assertCount(0, $gateway->draftUpdates);
    }

    public function test_captures_when_new_payment_amount_is_null(): void
    {
        $gateway = new FakePmodExecutionGateway();
        $action  = new RemoveCreditorAndDecreasePaymentAction($gateway, true);

        $result = $action->handle($this->makeWorkItem([
            'actionType'     => PmodActionType::REMOVE_CREDITOR_AND_DECREASE_PAYMENT,
            'creditorChange' => ['creditor_name' => 'Chase Visa'],
            'amount'         => null,
        ]));

        self::assertSame('captured_for_manual_review', $result->status);
        self::assertSame('invalid_payment_amount', $result->metadata['reason']);
        self::assertCount(0, $gateway->cancelDebtCalls);
    }

    public function test_captures_when_new_payment_amount_is_zero(): void
    {
        $gateway = new FakePmodExecutionGateway();
        $action  = new RemoveCreditorAndDecreasePaymentAction($gateway, true);

        $result = $action->handle($this->makeWorkItem([
            'actionType'     => PmodActionType::REMOVE_CREDITOR_AND_DECREASE_PAYMENT,
            'creditorChange' => ['creditor_name' => 'Chase Visa'],
            'amount'         => '0.00',
        ]));

        self::assertSame('captured_for_manual_review', $result->status);
        self::assertSame('invalid_payment_amount', $result->metadata['reason']);
    }

    public function test_captures_when_live_updates_disabled(): void
    {
        $gateway = new FakePmodExecutionGateway();
        $action  = new RemoveCreditorAndDecreasePaymentAction($gateway, false);

        $result = $action->handle($this->makeWorkItem([
            'actionType'     => PmodActionType::REMOVE_CREDITOR_AND_DECREASE_PAYMENT,
            'creditorChange' => ['creditor_name' => 'Chase Visa'],
            'amount'         => '100.00',
        ]));

        self::assertSame('captured_for_manual_review', $result->status);
        self::assertSame('live_draft_updates_disabled', $result->metadata['reason']);
        self::assertCount(0, $gateway->cancelDebtCalls);
    }

    public function test_captures_when_dry_run(): void
    {
        $gateway = new FakePmodExecutionGateway();
        $action  = new RemoveCreditorAndDecreasePaymentAction($gateway, true);

        $result = $action->handle($this->makeWorkItem([
            'actionType'     => PmodActionType::REMOVE_CREDITOR_AND_DECREASE_PAYMENT,
            'creditorChange' => ['creditor_name' => 'Chase Visa'],
            'amount'         => '100.00',
            'dryRun'         => true,
        ]));

        self::assertSame('captured_for_manual_review', $result->status);
        self::assertSame('dry_run_only', $result->metadata['reason']);
        self::assertCount(0, $gateway->cancelDebtCalls);
    }

    public function test_captures_when_debt_not_found_by_name(): void
    {
        $gateway        = new FakePmodExecutionGateway();
        $gateway->debts = [$this->makeDebt('debt-99', 'Bank of America')];
        $action         = new RemoveCreditorAndDecreasePaymentAction($gateway, true);

        $result = $action->handle($this->makeWorkItem([
            'actionType'     => PmodActionType::REMOVE_CREDITOR_AND_DECREASE_PAYMENT,
            'creditorChange' => ['creditor_name' => 'Chase Visa'],
            'amount'         => '100.00',
        ]));

        self::assertSame('captured_for_manual_review', $result->status);
        self::assertSame('debt_not_found', $result->metadata['reason']);
        self::assertCount(0, $gateway->cancelDebtCalls);
    }

    // --- Happy path ---

    public function test_finds_debt_by_name_cancels_it_and_updates_future_type_d_drafts(): void
    {
        $gateway               = new FakePmodExecutionGateway();
        $gateway->debts        = [$this->makeDebt('debt-42', 'Chase Visa')];
        $gateway->transactions = $this->futureDrafts();
        $action                = new RemoveCreditorAndDecreasePaymentAction($gateway, true);

        $result = $action->handle($this->makeWorkItem([
            'actionType'     => PmodActionType::REMOVE_CREDITOR_AND_DECREASE_PAYMENT,
            'creditorChange' => ['creditor_name' => 'Chase Visa'],
            'amount'         => '100.00',
        ]));

        self::assertSame('updated', $result->status);
        // Debt cancelled
        self::assertCount(1, $gateway->cancelDebtCalls);
        self::assertSame('debt-42', $gateway->cancelDebtCalls[0]['debt_id']);
        // Only type-D active drafts updated (d1, d2 — not DPG d3)
        self::assertSame(2, $result->metadata['drafts_updated']);
        self::assertCount(2, $gateway->draftUpdates);
        self::assertSame('100.00', $gateway->draftUpdates[0]['payload']['amount']);
        // CRM note
        self::assertCount(1, $gateway->notes);
        self::assertStringContainsString('Request Status: Successful', $gateway->notes[0]['content']);
        self::assertStringContainsString('Drafts Updated: 2', $gateway->notes[0]['content']);
    }

    public function test_finds_debt_by_id_when_creditor_id_provided(): void
    {
        $gateway        = new FakePmodExecutionGateway();
        $gateway->debts = [$this->makeDebt('debt-42', 'Chase Visa')];
        $action         = new RemoveCreditorAndDecreasePaymentAction($gateway, true);

        $result = $action->handle($this->makeWorkItem([
            'actionType'     => PmodActionType::REMOVE_CREDITOR_AND_DECREASE_PAYMENT,
            'creditorChange' => ['creditor_id' => 'debt-42'],
            'amount'         => '100.00',
        ]));

        self::assertSame('updated', $result->status);
        self::assertSame('debt-42', $gateway->cancelDebtCalls[0]['debt_id']);
    }

    public function test_name_match_is_case_insensitive(): void
    {
        $gateway        = new FakePmodExecutionGateway();
        $gateway->debts = [$this->makeDebt('debt-42', 'CHASE VISA')];
        $action         = new RemoveCreditorAndDecreasePaymentAction($gateway, true);

        $result = $action->handle($this->makeWorkItem([
            'actionType'     => PmodActionType::REMOVE_CREDITOR_AND_DECREASE_PAYMENT,
            'creditorChange' => ['creditor_name' => 'chase visa'],
            'amount'         => '100.00',
        ]));

        self::assertSame('updated', $result->status);
    }

    // --- Fail-safe / rollback ---

    public function test_debt_cancel_failure_propagates_before_any_draft_is_touched(): void
    {
        $gateway                    = new FakePmodExecutionGateway();
        $gateway->debts             = [$this->makeDebt('debt-42', 'Chase Visa')];
        $gateway->transactions      = $this->futureDrafts();
        $gateway->cancelDebtException = 'CRM 500';
        $action                     = new RemoveCreditorAndDecreasePaymentAction($gateway, true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('CRM 500');

        $action->handle($this->makeWorkItem([
            'actionType'     => PmodActionType::REMOVE_CREDITOR_AND_DECREASE_PAYMENT,
            'creditorChange' => ['creditor_name' => 'Chase Visa'],
            'amount'         => '100.00',
        ]));

        self::assertCount(0, $gateway->draftUpdates);
    }

    public function test_partial_draft_update_failure_returns_captured_with_errors(): void
    {
        $gateway                                  = new FakePmodExecutionGateway();
        $gateway->debts                           = [$this->makeDebt('debt-42', 'Chase Visa')];
        $gateway->transactions                    = $this->futureDrafts();
        $gateway->updateDraftExceptions['d2']     = 'Draft timeout';
        $action                                   = new RemoveCreditorAndDecreasePaymentAction($gateway, true);

        $result = $action->handle($this->makeWorkItem([
            'actionType'     => PmodActionType::REMOVE_CREDITOR_AND_DECREASE_PAYMENT,
            'creditorChange' => ['creditor_name' => 'Chase Visa'],
            'amount'         => '100.00',
        ]));

        // Debt was still cancelled
        self::assertCount(1, $gateway->cancelDebtCalls);
        // Partial failure
        self::assertSame('captured_for_manual_review', $result->status);
        self::assertCount(1, $result->metadata['update_errors']);
        self::assertSame(1, $result->metadata['drafts_updated']);
    }

    public function test_action_type_is_remove_creditor_and_decrease_payment(): void
    {
        $action = new RemoveCreditorAndDecreasePaymentAction(new FakePmodExecutionGateway());
        self::assertSame(PmodActionType::REMOVE_CREDITOR_AND_DECREASE_PAYMENT, $action->actionType());
    }
}
