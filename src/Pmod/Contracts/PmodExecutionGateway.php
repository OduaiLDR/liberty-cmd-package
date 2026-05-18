<?php

declare(strict_types=1);

namespace Cmd\Reports\Pmod\Contracts;

use Cmd\Reports\Pmod\Data\PmodWorkItem;

interface PmodExecutionGateway
{
    /** @return array<string, mixed> */
    public function createContactNote(PmodWorkItem $workItem, string $content, bool $public = true): array;

    /** @return list<array<string, mixed>> */
    public function getContactTransactions(PmodWorkItem $workItem): array;

    /** @return list<array<string, mixed>> */
    public function getContactDebts(PmodWorkItem $workItem): array;

    /** @return array<string, mixed> */
    public function getContactSummary(PmodWorkItem $workItem): array;

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function createDraft(PmodWorkItem $workItem, array $payload): array;

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function updateDraft(PmodWorkItem $workItem, string $draftId, array $payload): array;

    /** @return array<string, mixed> */
    public function cancelDraft(PmodWorkItem $workItem, string $draftId): array;

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function createRefund(PmodWorkItem $workItem, array $payload): array;

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function refundDraft(PmodWorkItem $workItem, string $draftId, array $payload): array;

    /** @return array<string, mixed> */
    public function voidSettlementOffer(PmodWorkItem $workItem, string $settlementId): array;

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function addBankAccount(PmodWorkItem $workItem, array $payload): array;

    /**
     * Upload a base64-encoded document to the contact's CRM file tab.
     * @return array<string, mixed>
     */
    public function uploadContactDocument(PmodWorkItem $workItem, string $base64Content, string $fileName, string $description): array;

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function createDebt(PmodWorkItem $workItem, array $payload): array;

    /** @return array<string, mixed> */
    public function cancelDebt(PmodWorkItem $workItem, string $debtId): array;
}
