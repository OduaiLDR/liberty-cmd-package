<?php

declare(strict_types=1);

namespace Cmd\Reports\Pmod\Contracts;

use Cmd\Reports\Pmod\Data\PmodWorkItem;

interface PmodExecutionGateway
{
    /**
     * @return array<string, mixed>
     */
    public function createContactNote(PmodWorkItem $workItem, string $content, bool $public = true): array;

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createDraft(PmodWorkItem $workItem, array $payload): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function getContactTransactions(PmodWorkItem $workItem): array;

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function updateDraft(PmodWorkItem $workItem, string $draftId, array $payload): array;

    /**
     * @return array<string, mixed>
     */
    public function voidSettlementOffer(PmodWorkItem $workItem, string $settlementId): array;
}
