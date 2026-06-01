<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit\Pmod\Actions;

use Cmd\Reports\Pmod\Actions\AddCreditorAndIncreasePaymentAction;
use Cmd\Reports\Pmod\Enums\PmodActionType;
use Cmd\Reports\Tests\Support\FakePmodExecutionGateway;
use Cmd\Reports\Tests\TestCase;

final class AddCreditorAndIncreasePaymentActionTest extends TestCase
{
    private function validCreditor(): array
    {
        return [
            'creditor_id'    => '12345678',
            'creditor_name'  => 'Chase Visa',
            'account_number' => 'ACC-123',
            'balance'        => '5000.00',
        ];
    }

    private function futureDrafts(): array
    {
        return [
            ['transaction_id' => 'draft-1', 'type' => 'D', 'active' => '1', 'process_date' => '2030-07-01', 'amount' => '25.00'],
            ['transaction_id' => 'draft-2', 'type' => 'D', 'active' => '1', 'process_date' => '2030-08-01', 'amount' => '25.00'],
            ['transaction_id' => 'draft-3', 'type' => 'DPG', 'active' => '1', 'process_date' => '2030-07-15', 'amount' => '10.95'], // not type D — skip
            ['transaction_id' => 'draft-4', 'type' => 'D', 'active' => '0', 'process_date' => '2030-09-01', 'amount' => '25.00'],  // inactive — skip
        ];
    }

    // --- Validation / capture paths ---

    public function test_captures_when_creditor_info_missing(): void
    {
        $gateway = new FakePmodExecutionGateway();
        $action  = new AddCreditorAndIncreasePaymentAction($gateway, true);

        $result = $action->handle($this->makeWorkItem([
            'actionType'     => PmodActionType::ADD_CREDITOR_AND_INCREASE_PAYMENT,
            'creditorChange' => [],
            'amount'         => '30.00',
        ]));

        self::assertSame('captured_for_manual_review', $result->status);
        self::assertSame('missing_creditor_info', $result->metadata['reason']);
        self::assertCount(0, $gateway->debtCalls);
        self::assertCount(0, $gateway->draftUpdates);
    }

    public function test_captures_when_amount_is_null(): void
    {
        $gateway = new FakePmodExecutionGateway();
        $action  = new AddCreditorAndIncreasePaymentAction($gateway, true);

        $result = $action->handle($this->makeWorkItem([
            'actionType'     => PmodActionType::ADD_CREDITOR_AND_INCREASE_PAYMENT,
            'creditorChange' => $this->validCreditor(),
            'amount'         => null,
        ]));

        self::assertSame('captured_for_manual_review', $result->status);
        self::assertSame('invalid_payment_amount', $result->metadata['reason']);
        self::assertCount(0, $gateway->debtCalls);
    }

    public function test_captures_when_amount_is_zero(): void
    {
        $gateway = new FakePmodExecutionGateway();
        $action  = new AddCreditorAndIncreasePaymentAction($gateway, true);

        $result = $action->handle($this->makeWorkItem([
            'actionType'     => PmodActionType::ADD_CREDITOR_AND_INCREASE_PAYMENT,
            'creditorChange' => $this->validCreditor(),
            'amount'         => '0.00',
        ]));

        self::assertSame('captured_for_manual_review', $result->status);
        self::assertSame('invalid_payment_amount', $result->metadata['reason']);
    }

    public function test_captures_when_creditor_id_missing(): void
    {
        $gateway = new FakePmodExecutionGateway();
        $action  = new AddCreditorAndIncreasePaymentAction($gateway, true);

        $result = $action->handle($this->makeWorkItem([
            'actionType'     => PmodActionType::ADD_CREDITOR_AND_INCREASE_PAYMENT,
            'creditorChange' => ['creditor_name' => 'Chase Visa', 'balance' => '5000.00'],
            'amount'         => '30.00',
        ]));

        self::assertSame('captured_for_manual_review', $result->status);
        self::assertSame('missing_creditor_id', $result->metadata['reason']);
        self::assertCount(0, $gateway->debtCalls);
    }

    public function test_captures_when_live_updates_disabled(): void
    {
        $gateway = new FakePmodExecutionGateway();
        $action  = new AddCreditorAndIncreasePaymentAction($gateway, false);

        $result = $action->handle($this->makeWorkItem([
            'actionType'     => PmodActionType::ADD_CREDITOR_AND_INCREASE_PAYMENT,
            'creditorChange' => $this->validCreditor(),
            'amount'         => '30.00',
        ]));

        self::assertSame('captured_for_manual_review', $result->status);
        self::assertSame('live_draft_updates_disabled', $result->metadata['reason']);
        self::assertCount(0, $gateway->debtCalls);
    }

    public function test_captures_when_dry_run(): void
    {
        $gateway = new FakePmodExecutionGateway();
        $action  = new AddCreditorAndIncreasePaymentAction($gateway, true);

        $result = $action->handle($this->makeWorkItem([
            'actionType'     => PmodActionType::ADD_CREDITOR_AND_INCREASE_PAYMENT,
            'creditorChange' => $this->validCreditor(),
            'amount'         => '30.00',
            'dryRun'         => true,
        ]));

        self::assertSame('captured_for_manual_review', $result->status);
        self::assertSame('dry_run_only', $result->metadata['reason']);
        self::assertCount(0, $gateway->debtCalls);
    }

    // --- Happy path ---

    public function test_creates_debt_then_updates_future_type_d_active_drafts(): void
    {
        $gateway               = new FakePmodExecutionGateway();
        $gateway->transactions = $this->futureDrafts();
        $action                = new AddCreditorAndIncreasePaymentAction($gateway, true);

        $result = $action->handle($this->makeWorkItem([
            'actionType'     => PmodActionType::ADD_CREDITOR_AND_INCREASE_PAYMENT,
            'creditorChange' => $this->validCreditor(),
            'amount'         => '30.00',
        ]));

        self::assertSame('updated', $result->status);
        // Debt created once
        self::assertCount(1, $gateway->debtCalls);
        self::assertSame('12345678', $gateway->debtCalls[0]['payload']['creditor']);
        self::assertSame('5000.00', $gateway->debtCalls[0]['payload']['balance']);
        // Only active type-D drafts updated (draft-1 and draft-2, not DPG or inactive)
        self::assertSame(2, $result->metadata['drafts_updated']);
        self::assertCount(2, $gateway->draftUpdates);
        self::assertSame('30.00', $gateway->draftUpdates[0]['payload']['amount']);
        self::assertSame('30.00', $gateway->draftUpdates[1]['payload']['amount']);
        // debt_id in metadata
        self::assertSame('debt-1', $result->metadata['debt_id']);
        // CRM note created
        self::assertCount(1, $gateway->notes);
        self::assertStringContainsString('Drafts Updated: 2', $gateway->notes[0]['content']);
    }

    public function test_skips_non_d_type_transactions(): void
    {
        $gateway               = new FakePmodExecutionGateway();
        $gateway->transactions = [
            ['transaction_id' => 'dpg-1', 'type' => 'DPG', 'active' => '1', 'process_date' => '2030-07-15', 'amount' => '10.95'],
        ];
        $action = new AddCreditorAndIncreasePaymentAction($gateway, true);

        $result = $action->handle($this->makeWorkItem([
            'actionType'     => PmodActionType::ADD_CREDITOR_AND_INCREASE_PAYMENT,
            'creditorChange' => $this->validCreditor(),
            'amount'         => '30.00',
        ]));

        self::assertSame('updated', $result->status);
        self::assertSame(0, $result->metadata['drafts_updated']);
        self::assertCount(0, $gateway->draftUpdates);
    }

    // --- Rollback / fail-safe tests ---

    public function test_debt_creation_failure_propagates_before_any_draft_is_touched(): void
    {
        $gateway                    = new FakePmodExecutionGateway();
        $gateway->createDebtException = 'CRM 500 debt';
        $gateway->transactions      = $this->futureDrafts();
        $action                     = new AddCreditorAndIncreasePaymentAction($gateway, true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('CRM 500 debt');

        $action->handle($this->makeWorkItem([
            'actionType'     => PmodActionType::ADD_CREDITOR_AND_INCREASE_PAYMENT,
            'creditorChange' => $this->validCreditor(),
            'amount'         => '30.00',
        ]));

        // No drafts touched — debt failed before we got there
        self::assertCount(0, $gateway->draftUpdates);
    }

    public function test_partial_draft_update_failure_returns_captured_with_errors(): void
    {
        $gateway                                  = new FakePmodExecutionGateway();
        $gateway->transactions                    = $this->futureDrafts();
        $gateway->updateDraftExceptions['draft-2'] = 'CRM timeout';
        $action                                   = new AddCreditorAndIncreasePaymentAction($gateway, true);

        $result = $action->handle($this->makeWorkItem([
            'actionType'     => PmodActionType::ADD_CREDITOR_AND_INCREASE_PAYMENT,
            'creditorChange' => $this->validCreditor(),
            'amount'         => '30.00',
        ]));

        // Debt was still created
        self::assertCount(1, $gateway->debtCalls);
        // Partial failure — captured for manual review
        self::assertSame('captured_for_manual_review', $result->status);
        self::assertCount(1, $result->metadata['update_errors']);
        self::assertSame('draft-2', $result->metadata['update_errors'][0]['draft_id']);
        // One draft succeeded, one failed
        self::assertSame(1, $result->metadata['drafts_updated']);
    }

    public function test_action_type_is_add_creditor_and_increase_payment(): void
    {
        $action = new AddCreditorAndIncreasePaymentAction(new FakePmodExecutionGateway());
        self::assertSame(PmodActionType::ADD_CREDITOR_AND_INCREASE_PAYMENT, $action->actionType());
    }
}
