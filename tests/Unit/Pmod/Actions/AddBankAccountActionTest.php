<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit\Pmod\Actions;

use Cmd\Reports\Pmod\Actions\AddBankAccountAction;
use Cmd\Reports\Pmod\Enums\PmodActionType;
use Cmd\Reports\Tests\Support\FakePmodExecutionGateway;
use Cmd\Reports\Tests\TestCase;

final class AddBankAccountActionTest extends TestCase
{
    private function validBanking(): array
    {
        return [
            'account_number' => '123456789',
            'routing_number' => '021000021',
            'account_type'   => 'checking',
            'bank_name'      => 'Test Bank',
        ];
    }

    // --- Unit: validation / capture paths ---

    public function test_captures_when_banking_update_is_empty(): void
    {
        $gateway = new FakePmodExecutionGateway();
        $action  = new AddBankAccountAction($gateway, true);

        $result = $action->handle($this->makeWorkItem([
            'actionType'    => PmodActionType::ADD_BANK_ACCOUNT,
            'bankingUpdate' => [],
        ]));

        self::assertSame('captured_for_manual_review', $result->status);
        self::assertSame('missing_banking_info', $result->metadata['reason']);
        self::assertCount(1, $gateway->notes);
        self::assertCount(0, $gateway->bankAccountCalls);
    }

    public function test_captures_when_account_number_missing(): void
    {
        $gateway = new FakePmodExecutionGateway();
        $action  = new AddBankAccountAction($gateway, true);
        $banking = $this->validBanking();
        unset($banking['account_number']);

        $result = $action->handle($this->makeWorkItem([
            'actionType'    => PmodActionType::ADD_BANK_ACCOUNT,
            'bankingUpdate' => $banking,
        ]));

        self::assertSame('captured_for_manual_review', $result->status);
        self::assertSame('missing_required_fields', $result->metadata['reason']);
        self::assertContains('account_number', $result->metadata['missing_fields']);
        self::assertCount(0, $gateway->bankAccountCalls);
    }

    public function test_captures_when_routing_number_missing(): void
    {
        $gateway = new FakePmodExecutionGateway();
        $action  = new AddBankAccountAction($gateway, true);
        $banking = $this->validBanking();
        unset($banking['routing_number']);

        $result = $action->handle($this->makeWorkItem([
            'actionType'    => PmodActionType::ADD_BANK_ACCOUNT,
            'bankingUpdate' => $banking,
        ]));

        self::assertSame('captured_for_manual_review', $result->status);
        self::assertContains('routing_number', $result->metadata['missing_fields']);
        self::assertCount(0, $gateway->bankAccountCalls);
    }

    public function test_captures_when_account_type_missing(): void
    {
        $gateway = new FakePmodExecutionGateway();
        $action  = new AddBankAccountAction($gateway, true);
        $banking = $this->validBanking();
        unset($banking['account_type']);

        $result = $action->handle($this->makeWorkItem([
            'actionType'    => PmodActionType::ADD_BANK_ACCOUNT,
            'bankingUpdate' => $banking,
        ]));

        self::assertSame('captured_for_manual_review', $result->status);
        self::assertContains('account_type', $result->metadata['missing_fields']);
        self::assertCount(0, $gateway->bankAccountCalls);
    }

    public function test_captures_when_live_updates_disabled(): void
    {
        $gateway = new FakePmodExecutionGateway();
        $action  = new AddBankAccountAction($gateway, false);

        $result = $action->handle($this->makeWorkItem([
            'actionType'    => PmodActionType::ADD_BANK_ACCOUNT,
            'bankingUpdate' => $this->validBanking(),
        ]));

        self::assertSame('captured_for_manual_review', $result->status);
        self::assertSame('live_draft_updates_disabled', $result->metadata['reason']);
        self::assertCount(0, $gateway->bankAccountCalls);
    }

    public function test_captures_when_dry_run(): void
    {
        $gateway = new FakePmodExecutionGateway();
        $action  = new AddBankAccountAction($gateway, true);

        $result = $action->handle($this->makeWorkItem([
            'actionType'    => PmodActionType::ADD_BANK_ACCOUNT,
            'bankingUpdate' => $this->validBanking(),
            'dryRun'        => true,
        ]));

        self::assertSame('captured_for_manual_review', $result->status);
        self::assertSame('dry_run_only', $result->metadata['reason']);
        self::assertCount(0, $gateway->bankAccountCalls);
    }

    // --- Unit: happy path ---

    public function test_calls_gateway_and_returns_updated_on_success(): void
    {
        $gateway = new FakePmodExecutionGateway();
        $action  = new AddBankAccountAction($gateway, true);

        $result = $action->handle($this->makeWorkItem([
            'actionType'    => PmodActionType::ADD_BANK_ACCOUNT,
            'bankingUpdate' => $this->validBanking(),
        ]));

        self::assertSame('updated', $result->status);
        self::assertCount(1, $gateway->bankAccountCalls);
        self::assertSame('123456789', $gateway->bankAccountCalls[0]['payload']['account_number']);
        self::assertSame('021000021', $gateway->bankAccountCalls[0]['payload']['routing_number']);
        self::assertSame('checking', $gateway->bankAccountCalls[0]['payload']['account_type']);
        // Note created
        self::assertCount(1, $gateway->notes);
        self::assertStringContainsString('Request Status: Successful', $gateway->notes[0]['content']);
        self::assertStringContainsString('***6789', $gateway->notes[0]['content']);
        self::assertStringContainsString('***0021', $gateway->notes[0]['content']);
    }

    public function test_passes_optional_bank_name_and_account_holder(): void
    {
        $gateway = new FakePmodExecutionGateway();
        $action  = new AddBankAccountAction($gateway, true);
        $banking = array_merge($this->validBanking(), [
            'bank_name'           => 'Chase',
            'account_holder_name' => 'John Doe',
        ]);

        $action->handle($this->makeWorkItem([
            'actionType'    => PmodActionType::ADD_BANK_ACCOUNT,
            'bankingUpdate' => $banking,
        ]));

        self::assertSame('Chase', $gateway->bankAccountCalls[0]['payload']['bank_name']);
        self::assertSame('John Doe', $gateway->bankAccountCalls[0]['payload']['account_holder_name']);
    }

    public function test_falls_back_to_name_on_account_when_account_holder_name_absent(): void
    {
        $gateway = new FakePmodExecutionGateway();
        $action  = new AddBankAccountAction($gateway, true);
        $banking = array_merge($this->validBanking(), ['name_on_account' => 'Jane Doe']);

        $action->handle($this->makeWorkItem([
            'actionType'    => PmodActionType::ADD_BANK_ACCOUNT,
            'bankingUpdate' => $banking,
        ]));

        self::assertSame('Jane Doe', $gateway->bankAccountCalls[0]['payload']['account_holder_name']);
    }

    // --- Edge cases ---

    public function test_capture_note_does_not_expose_full_account_number(): void
    {
        $gateway = new FakePmodExecutionGateway();
        $action  = new AddBankAccountAction($gateway, true);

        $action->handle($this->makeWorkItem([
            'actionType'    => PmodActionType::ADD_BANK_ACCOUNT,
            'bankingUpdate' => $this->validBanking(),
        ]));

        $noteContent = $gateway->notes[0]['content'];
        self::assertStringNotContainsString('123456789', $noteContent);
        self::assertStringNotContainsString('021000021', $noteContent);
    }

    public function test_gateway_exception_propagates_so_job_retries(): void
    {
        $gateway                        = new FakePmodExecutionGateway();
        $gateway->addBankAccountException = 'CRM API 500';
        $action                         = new AddBankAccountAction($gateway, true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('CRM API 500');

        $action->handle($this->makeWorkItem([
            'actionType'    => PmodActionType::ADD_BANK_ACCOUNT,
            'bankingUpdate' => $this->validBanking(),
        ]));
    }

    public function test_action_type_is_add_bank_account(): void
    {
        $action = new AddBankAccountAction(new FakePmodExecutionGateway());
        self::assertSame(PmodActionType::ADD_BANK_ACCOUNT, $action->actionType());
    }
}
