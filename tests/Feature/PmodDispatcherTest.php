<?php

namespace Cmd\Reports\Tests\Feature;

use Cmd\Reports\Pmod\Actions\CapturePmodRequestAction;
use Cmd\Reports\Pmod\Contracts\PmodExecutionGateway;
use Cmd\Reports\Pmod\Data\PmodWorkItem;
use Cmd\Reports\Pmod\Enums\PmodActionType;
use Cmd\Reports\Pmod\Exceptions\UnsupportedPmodActionException;
use Cmd\Reports\Pmod\Services\PmodDispatcher;
use Cmd\Reports\Pmod\Support\PmodWorkItemFactory;
use PHPUnit\Framework\TestCase;

class PmodDispatcherTest extends TestCase
{
    public function testDispatcherRoutesSupportedActionToHandler(): void
    {
        $gateway = new FakePmodExecutionGateway();
        $dispatcher = new PmodDispatcher([
            new CapturePmodRequestAction($gateway, PmodActionType::PMOD_INCREASE_PAYMENTS),
        ]);

        $workItem = $this->makeIncreasePaymentsWorkItem();

        $result = $dispatcher->dispatch($workItem);

        $this->assertSame('captured_for_manual_review', $result->status);
        $this->assertCount(1, $gateway->notes);
        $this->assertSame('392014299', $gateway->notes[0]['contact_id']);
        $this->assertStringContainsString('pmod_increase_payments', $gateway->notes[0]['content']);
        $this->assertStringContainsString('Contact ID : 392014299', $gateway->notes[0]['content']);
        $this->assertStringContainsString('Requested by : Admin(Avinash)', $gateway->notes[0]['content']);
    }

    public function testDispatcherRejectsUnsupportedAction(): void
    {
        $this->expectException(UnsupportedPmodActionException::class);

        $dispatcher = new PmodDispatcher([]);

        $dispatcher->dispatch($this->makeIncreasePaymentsWorkItem());
    }

    private function makeIncreasePaymentsWorkItem(): PmodWorkItem
    {
        return PmodWorkItemFactory::fromWebhookPayload(
            normalizedPayload: [
                'customer_id' => '392014299',
                'settlement_id' => '5538063',
                'settlement_ids' => ['5538063'],
                'action' => 'Increase Payments',
                'increase_amount' => '1100.00',
                'total_amount' => '1368.73',
                'start_date' => '2024-11-25',
                'end_date' => '2025-04-25',
                'requested_by' => 'Admin(Avinash)',
                'company' => 'PLAW',
            ],
            rawPayload: [
                'raw_text' => 'Action: Increase Payments',
            ],
            tenantId: 'tenant-123',
            idempotencyKey: 'idem-123',
        );
    }
}

final class FakePmodExecutionGateway implements PmodExecutionGateway
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
