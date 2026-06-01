<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit\Pmod\Actions;

use Cmd\Reports\Pmod\Actions\CaptureSponsorBankingAction;
use Cmd\Reports\Pmod\Enums\PmodActionType;
use Cmd\Reports\Tests\Support\FakePmodExecutionGateway;
use Cmd\Reports\Tests\TestCase;

final class CaptureSponsorBankingActionTest extends TestCase
{
    private function validSponsor(): array
    {
        return [
            'sponsor_account_number' => '987654321',
            'sponsor_routing_number' => '021000021',
            'sponsor_account_type'   => 'checking',
            'sponsor_name'           => 'Test Sponsor',
            'sponsor_id'             => 'sp-99',
        ];
    }

    // --- Validation / capture paths ---

    public function test_captures_when_sponsor_update_is_empty(): void
    {
        $gateway = new FakePmodExecutionGateway();
        $action  = new CaptureSponsorBankingAction($gateway, true);

        $result = $action->handle($this->makeWorkItem([
            'actionType'    => PmodActionType::CAPTURE_SPONSOR_BANKING,
            'sponsorUpdate' => [],
        ]));

        self::assertSame('captured_for_manual_review', $result->status);
        self::assertSame('missing_sponsor_data', $result->metadata['reason']);
        self::assertCount(0, $gateway->bankAccountCalls);
    }

    public function test_captures_when_sponsor_account_number_missing(): void
    {
        $gateway = new FakePmodExecutionGateway();
        $action  = new CaptureSponsorBankingAction($gateway, true);
        $sponsor = $this->validSponsor();
        unset($sponsor['sponsor_account_number']);

        $result = $action->handle($this->makeWorkItem([
            'actionType'    => PmodActionType::CAPTURE_SPONSOR_BANKING,
            'sponsorUpdate' => $sponsor,
        ]));

        self::assertSame('captured_for_manual_review', $result->status);
        self::assertContains('sponsor_account_number', $result->metadata['missing_fields']);
        self::assertCount(0, $gateway->bankAccountCalls);
    }

    public function test_captures_when_live_updates_disabled(): void
    {
        $gateway = new FakePmodExecutionGateway();
        $action  = new CaptureSponsorBankingAction($gateway, false);

        $result = $action->handle($this->makeWorkItem([
            'actionType'    => PmodActionType::CAPTURE_SPONSOR_BANKING,
            'sponsorUpdate' => $this->validSponsor(),
        ]));

        self::assertSame('captured_for_manual_review', $result->status);
        self::assertSame('live_draft_updates_disabled', $result->metadata['reason']);
        self::assertCount(0, $gateway->bankAccountCalls);
    }

    public function test_captures_when_dry_run(): void
    {
        $gateway = new FakePmodExecutionGateway();
        $action  = new CaptureSponsorBankingAction($gateway, true);

        $result = $action->handle($this->makeWorkItem([
            'actionType'    => PmodActionType::CAPTURE_SPONSOR_BANKING,
            'sponsorUpdate' => $this->validSponsor(),
            'dryRun'        => true,
        ]));

        self::assertSame('captured_for_manual_review', $result->status);
        self::assertSame('dry_run_only', $result->metadata['reason']);
        self::assertCount(0, $gateway->bankAccountCalls);
    }

    // --- Happy path ---

    public function test_calls_gateway_and_returns_updated_on_success(): void
    {
        $gateway = new FakePmodExecutionGateway();
        $action  = new CaptureSponsorBankingAction($gateway, true);

        $result = $action->handle($this->makeWorkItem([
            'actionType'    => PmodActionType::CAPTURE_SPONSOR_BANKING,
            'sponsorUpdate' => $this->validSponsor(),
        ]));

        self::assertSame('updated', $result->status);
        self::assertCount(1, $gateway->bankAccountCalls);
        $payload = $gateway->bankAccountCalls[0]['payload'];
        self::assertSame('987654321', $payload['account_number']);
        self::assertSame('021000021', $payload['routing_number']);
        self::assertSame('checking', $payload['account_type']);
        self::assertTrue($payload['sponsor']);
        self::assertSame('sp-99', $payload['sponsor_id']);
        // CRM note created
        self::assertCount(1, $gateway->notes);
        self::assertStringContainsString('Request Status: Successful', $gateway->notes[0]['content']);
        self::assertStringContainsString('Test Sponsor', $gateway->notes[0]['content']);
    }

    // --- Edge cases ---

    public function test_note_does_not_expose_full_account_number(): void
    {
        $gateway = new FakePmodExecutionGateway();
        $action  = new CaptureSponsorBankingAction($gateway, true);

        $action->handle($this->makeWorkItem([
            'actionType'    => PmodActionType::CAPTURE_SPONSOR_BANKING,
            'sponsorUpdate' => $this->validSponsor(),
        ]));

        $noteContent = $gateway->notes[0]['content'];
        self::assertStringNotContainsString('987654321', $noteContent);
    }

    public function test_gateway_exception_propagates_so_job_retries(): void
    {
        $gateway                          = new FakePmodExecutionGateway();
        $gateway->addBankAccountException = 'Sponsor banking CRM 500';
        $action                           = new CaptureSponsorBankingAction($gateway, true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Sponsor banking CRM 500');

        $action->handle($this->makeWorkItem([
            'actionType'    => PmodActionType::CAPTURE_SPONSOR_BANKING,
            'sponsorUpdate' => $this->validSponsor(),
        ]));
    }

    public function test_action_type_is_capture_sponsor_banking(): void
    {
        $action = new CaptureSponsorBankingAction(new FakePmodExecutionGateway());
        self::assertSame(PmodActionType::CAPTURE_SPONSOR_BANKING, $action->actionType());
    }
}
