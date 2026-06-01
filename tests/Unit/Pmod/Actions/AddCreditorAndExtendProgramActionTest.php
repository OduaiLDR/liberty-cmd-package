<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit\Pmod\Actions;

use Cmd\Reports\Pmod\Actions\AddCreditorAndExtendProgramAction;
use Cmd\Reports\Pmod\Enums\PmodActionType;
use Cmd\Reports\Tests\Support\FakePmodExecutionGateway;
use Cmd\Reports\Tests\TestCase;

final class AddCreditorAndExtendProgramActionTest extends TestCase
{
    private function validCreditor(): array
    {
        return [
            'creditor_id'    => '87654321',
            'creditor_name'  => 'Bank of America',
            'account_number' => 'ACC-456',
            'balance'        => '8000.00',
        ];
    }

    private function transactionsWithLastDraft(string $lastDate): array
    {
        return [
            ['transaction_id' => 'd1', 'type' => 'D', 'active' => '1', 'process_date' => '2030-06-01', 'amount' => '25.00'],
            ['transaction_id' => 'd2', 'type' => 'D', 'active' => '1', 'process_date' => $lastDate,    'amount' => '25.00'],
        ];
    }

    // --- Validation / capture paths ---

    public function test_captures_when_creditor_info_missing(): void
    {
        $gateway = new FakePmodExecutionGateway();
        $action  = new AddCreditorAndExtendProgramAction($gateway, true);

        $result = $action->handle($this->makeWorkItem([
            'actionType'        => PmodActionType::ADD_CREDITOR_AND_EXTEND_PROGRAM,
            'creditorChange'    => [],
            'amount'            => '25.00',
            'normalizedPayload' => ['months_to_extend' => 3],
        ]));

        self::assertSame('captured_for_manual_review', $result->status);
        self::assertSame('missing_creditor_info', $result->metadata['reason']);
        self::assertCount(0, $gateway->debtCalls);
    }

    public function test_captures_when_months_to_extend_is_zero(): void
    {
        $gateway = new FakePmodExecutionGateway();
        $action  = new AddCreditorAndExtendProgramAction($gateway, true);

        $result = $action->handle($this->makeWorkItem([
            'actionType'        => PmodActionType::ADD_CREDITOR_AND_EXTEND_PROGRAM,
            'creditorChange'    => $this->validCreditor(),
            'amount'            => '25.00',
            'normalizedPayload' => ['months_to_extend' => 0],
        ]));

        self::assertSame('captured_for_manual_review', $result->status);
        self::assertSame('invalid_months', $result->metadata['reason']);
        self::assertCount(0, $gateway->debtCalls);
    }

    public function test_captures_when_months_exceeds_safety_cap_of_60(): void
    {
        $gateway = new FakePmodExecutionGateway();
        $action  = new AddCreditorAndExtendProgramAction($gateway, true);

        $result = $action->handle($this->makeWorkItem([
            'actionType'        => PmodActionType::ADD_CREDITOR_AND_EXTEND_PROGRAM,
            'creditorChange'    => $this->validCreditor(),
            'amount'            => '25.00',
            'normalizedPayload' => ['months_to_extend' => 61],
        ]));

        self::assertSame('captured_for_manual_review', $result->status);
        self::assertSame('invalid_months', $result->metadata['reason']);
        self::assertCount(0, $gateway->debtCalls);
    }

    public function test_captures_when_amount_missing(): void
    {
        $gateway = new FakePmodExecutionGateway();
        $action  = new AddCreditorAndExtendProgramAction($gateway, true);

        $result = $action->handle($this->makeWorkItem([
            'actionType'        => PmodActionType::ADD_CREDITOR_AND_EXTEND_PROGRAM,
            'creditorChange'    => $this->validCreditor(),
            'amount'            => null,
            'normalizedPayload' => ['months_to_extend' => 3],
        ]));

        self::assertSame('captured_for_manual_review', $result->status);
        self::assertSame('missing_amount', $result->metadata['reason']);
        self::assertCount(0, $gateway->debtCalls);
    }

    public function test_captures_when_creditor_id_missing(): void
    {
        $gateway = new FakePmodExecutionGateway();
        $action  = new AddCreditorAndExtendProgramAction($gateway, true);

        $result = $action->handle($this->makeWorkItem([
            'actionType'        => PmodActionType::ADD_CREDITOR_AND_EXTEND_PROGRAM,
            'creditorChange'    => ['creditor_name' => 'Bank of America', 'balance' => '8000.00'],
            'amount'            => '25.00',
            'normalizedPayload' => ['months_to_extend' => 3],
        ]));

        self::assertSame('captured_for_manual_review', $result->status);
        self::assertSame('missing_creditor_id', $result->metadata['reason']);
        self::assertCount(0, $gateway->debtCalls);
    }

    public function test_captures_when_live_updates_disabled(): void
    {
        $gateway = new FakePmodExecutionGateway();
        $action  = new AddCreditorAndExtendProgramAction($gateway, false);

        $result = $action->handle($this->makeWorkItem([
            'actionType'        => PmodActionType::ADD_CREDITOR_AND_EXTEND_PROGRAM,
            'creditorChange'    => $this->validCreditor(),
            'amount'            => '25.00',
            'normalizedPayload' => ['months_to_extend' => 3],
        ]));

        self::assertSame('captured_for_manual_review', $result->status);
        self::assertSame('live_draft_updates_disabled', $result->metadata['reason']);
        self::assertCount(0, $gateway->debtCalls);
    }

    public function test_captures_when_dry_run(): void
    {
        $gateway = new FakePmodExecutionGateway();
        $action  = new AddCreditorAndExtendProgramAction($gateway, true);

        $result = $action->handle($this->makeWorkItem([
            'actionType'        => PmodActionType::ADD_CREDITOR_AND_EXTEND_PROGRAM,
            'creditorChange'    => $this->validCreditor(),
            'amount'            => '25.00',
            'normalizedPayload' => ['months_to_extend' => 3],
            'dryRun'            => true,
        ]));

        self::assertSame('captured_for_manual_review', $result->status);
        self::assertSame('dry_run_only', $result->metadata['reason']);
        self::assertCount(0, $gateway->debtCalls);
    }

    // --- Happy path ---

    public function test_creates_debt_then_generates_extension_drafts_after_last_draft(): void
    {
        $gateway               = new FakePmodExecutionGateway();
        $gateway->transactions = $this->transactionsWithLastDraft('2030-09-01');
        $action                = new AddCreditorAndExtendProgramAction($gateway, true);

        $result = $action->handle($this->makeWorkItem([
            'actionType'        => PmodActionType::ADD_CREDITOR_AND_EXTEND_PROGRAM,
            'creditorChange'    => $this->validCreditor(),
            'amount'            => '25.00',
            'normalizedPayload' => ['months_to_extend' => 3],
        ]));

        self::assertSame('updated', $result->status);
        // Debt created once
        self::assertCount(1, $gateway->debtCalls);
        self::assertSame('87654321', $gateway->debtCalls[0]['payload']['creditor']);
        // 3 extension drafts created after 2030-09-01
        self::assertSame(3, $result->metadata['drafts_created']);
        self::assertCount(3, $gateway->draftCreations);
        self::assertSame('2030-10-01', $gateway->draftCreations[0]['payload']['process_date']);
        self::assertSame('2030-11-01', $gateway->draftCreations[1]['payload']['process_date']);
        self::assertSame('2030-12-01', $gateway->draftCreations[2]['payload']['process_date']);
        self::assertSame('25.00', $gateway->draftCreations[0]['payload']['amount']);
        // CRM note
        self::assertCount(1, $gateway->notes);
        self::assertStringContainsString('Months Extended: 3', $gateway->notes[0]['content']);
        self::assertStringContainsString('Drafts Created: 3', $gateway->notes[0]['content']);
    }

    public function test_creates_zero_drafts_gracefully_when_no_transactions_exist(): void
    {
        $gateway               = new FakePmodExecutionGateway();
        $gateway->transactions = [];
        $action                = new AddCreditorAndExtendProgramAction($gateway, true);

        $result = $action->handle($this->makeWorkItem([
            'actionType'        => PmodActionType::ADD_CREDITOR_AND_EXTEND_PROGRAM,
            'creditorChange'    => $this->validCreditor(),
            'amount'            => '25.00',
            'normalizedPayload' => ['months_to_extend' => 3],
        ]));

        self::assertSame('updated', $result->status);
        self::assertCount(1, $gateway->debtCalls);
        self::assertSame(0, $result->metadata['drafts_created']);
    }

    // --- Rollback / fail-safe tests ---

    public function test_debt_creation_failure_propagates_before_any_draft_is_created(): void
    {
        $gateway                    = new FakePmodExecutionGateway();
        $gateway->createDebtException = 'Forth API 503';
        $gateway->transactions      = $this->transactionsWithLastDraft('2030-09-01');
        $action                     = new AddCreditorAndExtendProgramAction($gateway, true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Forth API 503');

        $action->handle($this->makeWorkItem([
            'actionType'        => PmodActionType::ADD_CREDITOR_AND_EXTEND_PROGRAM,
            'creditorChange'    => $this->validCreditor(),
            'amount'            => '25.00',
            'normalizedPayload' => ['months_to_extend' => 3],
        ]));

        // No drafts created — failed at debt step
        self::assertCount(0, $gateway->draftCreations);
    }

    public function test_partial_draft_creation_failure_returns_captured_with_errors(): void
    {
        $gateway                                = new FakePmodExecutionGateway();
        $gateway->transactions                  = $this->transactionsWithLastDraft('2030-09-01');
        $gateway->createDraftExceptionsByCall   = [2 => 'Draft creation timeout']; // 2nd call fails
        $action                                 = new AddCreditorAndExtendProgramAction($gateway, true);

        $result = $action->handle($this->makeWorkItem([
            'actionType'        => PmodActionType::ADD_CREDITOR_AND_EXTEND_PROGRAM,
            'creditorChange'    => $this->validCreditor(),
            'amount'            => '25.00',
            'normalizedPayload' => ['months_to_extend' => 3],
        ]));

        // Debt created
        self::assertCount(1, $gateway->debtCalls);
        // Partial failure surfaced
        self::assertSame('captured_for_manual_review', $result->status);
        self::assertCount(1, $result->metadata['extension_errors']);
        // 2 drafts succeeded, 1 failed
        self::assertSame(2, $result->metadata['drafts_created']);
    }

    public function test_boundary_exactly_60_months_is_accepted(): void
    {
        $gateway               = new FakePmodExecutionGateway();
        $gateway->transactions = $this->transactionsWithLastDraft('2030-09-01');
        $action                = new AddCreditorAndExtendProgramAction($gateway, true);

        $result = $action->handle($this->makeWorkItem([
            'actionType'        => PmodActionType::ADD_CREDITOR_AND_EXTEND_PROGRAM,
            'creditorChange'    => $this->validCreditor(),
            'amount'            => '25.00',
            'normalizedPayload' => ['months_to_extend' => 60],
        ]));

        self::assertSame('updated', $result->status);
        self::assertSame(60, $result->metadata['months_to_extend']);
    }

    public function test_action_type_is_add_creditor_and_extend_program(): void
    {
        $action = new AddCreditorAndExtendProgramAction(new FakePmodExecutionGateway());
        self::assertSame(PmodActionType::ADD_CREDITOR_AND_EXTEND_PROGRAM, $action->actionType());
    }
}
