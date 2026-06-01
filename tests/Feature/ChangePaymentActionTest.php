<?php

namespace Cmd\Reports\Tests\Feature;

use Cmd\Reports\Pmod\Actions\ChangePaymentAction;
use Cmd\Reports\Pmod\Contracts\PmodExecutionGateway;
use Cmd\Reports\Pmod\Data\PmodWorkItem;
use Cmd\Reports\Pmod\Support\PmodWorkItemFactory;
use PHPUnit\Framework\TestCase;

class ChangePaymentActionTest extends TestCase
{
    public function testHandleCapturesForManualReviewWhenNoTransactionMatches(): void
    {
        $gateway = new ChangePaymentGatewayFake();
        $action = new ChangePaymentAction($gateway, false);

        $result = $action->handle($this->makeWorkItem());

        $this->assertSame('captured_for_manual_review', $result->status);
        $this->assertSame('no_matching_transaction', $result->metadata['reason']);
        $this->assertCount(1, $gateway->notes);
        $this->assertCount(0, $gateway->draftUpdates);
    }

    public function testHandleCapturesForManualReviewWhenDraftIdIsMissing(): void
    {
        $gateway = new ChangePaymentGatewayFake();
        $gateway->transactions = [[
            'process_date' => '2024-11-25',
            'amount' => '250.50',
            'memo' => 'ACH Client Debit',
            'id' => 'crm-123',
        ]];

        $action = new ChangePaymentAction($gateway, false);
        $result = $action->handle($this->makeWorkItem());

        $this->assertSame('captured_for_manual_review', $result->status);
        $this->assertSame('missing_authoritative_draft_id', $result->metadata['reason']);
        $this->assertCount(1, $gateway->notes);
        $this->assertCount(0, $gateway->draftUpdates);
    }

    public function testHandleUpdatesDraftWhenUniqueMatchAndLiveUpdatesEnabled(): void
    {
        $gateway = new ChangePaymentGatewayFake();
        $gateway->transactions = [[
            'process_date' => '2024-11-25',
            'amount' => '250.50',
            'memo' => 'ACH Client Debit',
            'draft_id' => 'draft-123',
        ]];

        $action = new ChangePaymentAction($gateway, true);
        $result = $action->handle($this->makeWorkItem());

        $this->assertSame('updated', $result->status);
        $this->assertSame('draft-123', $result->metadata['draft_id']);
        $this->assertCount(0, $gateway->notes);
        $this->assertCount(1, $gateway->draftUpdates);
        $this->assertSame('2024-12-03', $gateway->draftUpdates[0]['payload']['process_date']);
        $this->assertSame('250.50', $gateway->draftUpdates[0]['payload']['amount']);
    }

    private function makeWorkItem(): PmodWorkItem
    {
        return PmodWorkItemFactory::fromWebhookPayload(
            normalizedPayload: [
                'customer_id' => '392014299',
                'action' => 'Reschedule Payment',
                'requested_by' => 'Admin(Avinash)',
                'company' => 'PLAW',
                'amount' => '250.50',
                'amounts' => ['250.50'],
                'original_date' => '2024-11-25',
                'original_dates' => ['2024-11-25'],
                'target_date' => '2024-12-03',
                'target_dates' => ['2024-12-03'],
            ],
            rawPayload: [
                'raw_text' => 'Action: Reschedule Payment',
            ],
            tenantId: 'tenant-123',
            idempotencyKey: 'idem-123',
        );
    }
}

final class ChangePaymentGatewayFake implements PmodExecutionGateway
{
    /** @var array<int, array<string, mixed>> */
    public array $notes = [];

    /** @var list<array<string, mixed>> */
    public array $transactions = [];

    /** @var array<int, array<string, mixed>> */
    public array $draftUpdates = [];

    public function createContactNote(PmodWorkItem $workItem, string $content, bool $public = true): array
    {
        $this->notes[] = ['contact_id' => $workItem->contactId, 'content' => $content, 'public' => $public];
        return ['note_id' => 12345, 'contact_id' => $workItem->contactId, 'public' => $public];
    }

    public function getContactTransactions(PmodWorkItem $workItem): array
    {
        return $this->transactions;
    }

    public function getContactDebts(PmodWorkItem $workItem): array
    {
        return [];
    }

    public function getContactSummary(PmodWorkItem $workItem): array
    {
        return ['contact_id' => $workItem->contactId];
    }

    public function createDraft(PmodWorkItem $workItem, array $payload): array
    {
        return ['draft_id' => 'fake-draft-' . uniqid(), 'status' => 'created'];
    }

    public function updateDraft(PmodWorkItem $workItem, string $draftId, array $payload): array
    {
        $record = ['contact_id' => $workItem->contactId, 'draft_id' => $draftId, 'payload' => $payload];
        $this->draftUpdates[] = $record;
        return ['status' => ['code' => 200], 'response' => $record];
    }

    public function voidSettlementOffer(PmodWorkItem $workItem, string $settlementId): array
    {
        return ['status' => 'voided', 'settlement_id' => $settlementId];
    }

    public function cancelDraft(PmodWorkItem $workItem, string $draftId): array
    {
        return ['draft_id' => $draftId, 'status' => 'cancelled'];
    }

    public function createRefund(PmodWorkItem $workItem, array $payload): array
    {
        return ['refund_id' => 'fake-refund', 'payload' => $payload];
    }

    public function refundDraft(PmodWorkItem $workItem, string $draftId, array $payload): array
    {
        return ['draft_id' => $draftId, 'status' => 'refunded', 'payload' => $payload];
    }

    public function addBankAccount(PmodWorkItem $workItem, array $payload): array
    {
        return ['bank_account_id' => 'fake-bank', 'payload' => $payload];
    }

    public function uploadContactDocument(PmodWorkItem $workItem, string $base64Content, string $fileName, string $description): array
    {
        return ['file_name' => $fileName, 'description' => $description];
    }

    public function createDebt(PmodWorkItem $workItem, array $payload): array
    {
        return ['debt_id' => 'fake-debt', 'payload' => $payload];
    }

    public function cancelDebt(PmodWorkItem $workItem, string $debtId): array
    {
        return ['debt_id' => $debtId, 'status' => 'cancelled'];
    }
}
