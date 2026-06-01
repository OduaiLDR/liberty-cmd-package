<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Support;

use Cmd\Reports\Pmod\Contracts\PmodExecutionGateway;
use Cmd\Reports\Pmod\Data\PmodWorkItem;
use RuntimeException;

final class FakePmodExecutionGateway implements PmodExecutionGateway
{
    /**
     * @var list<array<string, mixed>>
     */
    public array $transactions = [];

    /**
     * @var list<array<string, mixed>>
     */
    public array $notes = [];

    /**
     * @var list<array<string, mixed>>
     */
    public array $draftCreations = [];

    /**
     * @var list<array<string, mixed>>
     */
    public array $draftUpdates = [];

    /**
     * @var list<array<string, mixed>>
     */
    public array $settlementVoids = [];

    /**
     * @var array<string, array<string, mixed>>
     */
    public array $createDraftResponses = [];

    /**
     * @var array<string, array<string, mixed>>
     */
    public array $updateDraftResponses = [];

    /**
     * @var array<string, array<string, mixed>>
     */
    public array $voidSettlementResponses = [];

    /**
     * @var array<string, string>
     */
    public array $updateDraftExceptions = [];

    /**
     * Indexed by 1-based createDraft call sequence; throw the listed message on that call.
     *
     * @var array<int, string>
     */
    public array $createDraftExceptionsByCall = [];

    /** @var list<array<string, mixed>> */
    public array $bankAccountCalls = [];

    /** @var list<array<string, mixed>> */
    public array $debtCalls = [];

    public ?string $addBankAccountException = null;
    public ?string $createDebtException     = null;
    public ?string $cancelDebtException     = null;
    public ?string $cancelDraftException    = null;

    /** @var list<array<string, mixed>> */
    public array $cancelDebtCalls  = [];

    /** @var list<array<string, mixed>> */
    public array $cancelDraftCalls = [];

    /** @var list<array<string, mixed>> */
    public array $debts = [];

    public function createContactNote(PmodWorkItem $workItem, string $content, bool $public = true): array
    {
        $note = [
            'note_id' => 'note-' . (count($this->notes) + 1),
            'contact_id' => $workItem->contactId,
            'content' => $content,
            'public' => $public,
        ];

        $this->notes[] = $note;

        return $note;
    }

    public function createDraft(PmodWorkItem $workItem, array $payload): array
    {
        $this->draftCreations[] = [
            'work_item' => $workItem,
            'payload' => $payload,
        ];

        $callIndex = count($this->draftCreations);
        if (isset($this->createDraftExceptionsByCall[$callIndex])) {
            throw new RuntimeException($this->createDraftExceptionsByCall[$callIndex]);
        }

        $draftId = (string) ($payload['draft_id'] ?? ('draft-' . $callIndex));

        return $this->createDraftResponses[$draftId] ?? ['draft_id' => $draftId, 'status' => 'created'];
    }

    public function getContactTransactions(PmodWorkItem $workItem): array
    {
        return $this->transactions;
    }

    public function getContactDebts(PmodWorkItem $workItem): array
    {
        return $this->debts;
    }

    public function getContactSummary(PmodWorkItem $workItem): array
    {
        return ['contact_id' => $workItem->contactId];
    }

    public function updateDraft(PmodWorkItem $workItem, string $draftId, array $payload): array
    {
        $this->draftUpdates[] = [
            'work_item' => $workItem,
            'draft_id' => $draftId,
            'payload' => $payload,
        ];

        if (isset($this->updateDraftExceptions[$draftId])) {
            throw new RuntimeException($this->updateDraftExceptions[$draftId]);
        }

        return $this->updateDraftResponses[$draftId] ?? ['draft_id' => $draftId, 'status' => 'updated'];
    }

    public function voidSettlementOffer(PmodWorkItem $workItem, string $settlementId): array
    {
        $this->settlementVoids[] = [
            'work_item' => $workItem,
            'settlement_id' => $settlementId,
        ];

        return $this->voidSettlementResponses[$settlementId] ?? ['settlement_id' => $settlementId, 'status' => 'voided'];
    }

    public function cancelDraft(PmodWorkItem $workItem, string $draftId): array
    {
        $this->cancelDraftCalls[] = ['work_item' => $workItem, 'draft_id' => $draftId];

        if ($this->cancelDraftException !== null) {
            throw new RuntimeException($this->cancelDraftException);
        }

        return ['draft_id' => $draftId, 'status' => 'cancelled'];
    }

    public function createRefund(PmodWorkItem $workItem, array $payload): array
    {
        return ['refund_id' => 'refund-' . uniqid(), 'status' => 'created', 'payload' => $payload];
    }

    public function refundDraft(PmodWorkItem $workItem, string $draftId, array $payload): array
    {
        return ['draft_id' => $draftId, 'status' => 'refunded', 'payload' => $payload];
    }

    public function addBankAccount(PmodWorkItem $workItem, array $payload): array
    {
        $this->bankAccountCalls[] = ['work_item' => $workItem, 'payload' => $payload];

        if ($this->addBankAccountException !== null) {
            throw new RuntimeException($this->addBankAccountException);
        }

        return ['bank_account_id' => 'bank-' . count($this->bankAccountCalls), 'status' => 'created', 'payload' => $payload];
    }

    public function uploadContactDocument(PmodWorkItem $workItem, string $base64Content, string $fileName, string $description): array
    {
        return ['file_name' => $fileName, 'description' => $description, 'status' => 'uploaded'];
    }

    public function createDebt(PmodWorkItem $workItem, array $payload): array
    {
        $this->debtCalls[] = ['work_item' => $workItem, 'payload' => $payload];

        if ($this->createDebtException !== null) {
            throw new RuntimeException($this->createDebtException);
        }

        return ['id' => 'debt-' . count($this->debtCalls), 'status' => 'created', 'payload' => $payload];
    }

    public function cancelDebt(PmodWorkItem $workItem, string $debtId): array
    {
        $this->cancelDebtCalls[] = ['work_item' => $workItem, 'debt_id' => $debtId];

        if ($this->cancelDebtException !== null) {
            throw new RuntimeException($this->cancelDebtException);
        }

        return ['debt_id' => $debtId, 'status' => 'cancelled'];
    }
}
