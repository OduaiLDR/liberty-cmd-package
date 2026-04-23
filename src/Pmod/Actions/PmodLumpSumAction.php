<?php

declare(strict_types=1);

namespace Cmd\Reports\Pmod\Actions;

use Cmd\Reports\Pmod\Contracts\PmodActionHandler;
use Cmd\Reports\Pmod\Contracts\PmodExecutionGateway;
use Cmd\Reports\Pmod\Data\PmodResult;
use Cmd\Reports\Pmod\Data\PmodWorkItem;
use Cmd\Reports\Pmod\Enums\PmodActionType;

final class PmodLumpSumAction implements PmodActionHandler
{
    public function __construct(
        private readonly PmodExecutionGateway $gateway,
        private readonly bool $allowLiveDraftUpdates = false,
    ) {
    }

    public function actionType(): PmodActionType
    {
        return PmodActionType::PMOD_LUMP_SUM;
    }

    public function handle(PmodWorkItem $workItem): PmodResult
    {
        $totalAmount = $workItem->totalAmount ?? ($workItem->amounts[0] ?? ($workItem->amount ?? null));
        $processDate = $workItem->targetDate ?? ($workItem->targetDates[0] ?? null);

        if ($totalAmount === null || $processDate === null) {
            return $this->captureForManualReview(
                $workItem,
                'Lump Sum Payment is missing required total amount or process date.',
                ['reason' => 'missing_required_fields', 'total_amount' => $totalAmount, 'process_date' => $processDate],
            );
        }

        if (!$this->allowLiveDraftUpdates || $workItem->dryRun) {
            return $this->captureForManualReview(
                $workItem,
                sprintf('Lump Sum Payment of $%s on %s is ready but live updates are disabled.', number_format((float) $totalAmount, 2), $processDate),
                ['reason' => $workItem->dryRun ? 'dry_run_only' : 'live_draft_updates_disabled', 'total_amount' => $totalAmount, 'process_date' => $processDate],
            );
        }

        $settlementIds = $workItem->settlementIds;
        $voidResults   = [];
        foreach ($settlementIds as $settlementId) {
            try {
                $voidResults[] = $this->gateway->voidSettlementOffer($workItem, $settlementId);
            } catch (\Throwable $e) {
                $voidResults[] = ['error' => $e->getMessage(), 'settlement_id' => $settlementId];
            }
        }

        $draftPayload = [
            'client_id'    => $workItem->contactId,
            'amount'       => $totalAmount,
            'process_date' => $processDate,
            'memo'         => sprintf('Lump Sum Deposit - $%s on %s by System Admin', number_format((float) $totalAmount, 2), $processDate),
        ];

        $draftResult = $this->gateway->createDraft($workItem, $draftPayload);

        $noteLines = [
            'PMOD Lump Sum Payment processed by System Admin.',
            sprintf('  Total Amount : $%s', number_format((float) $totalAmount, 2)),
            sprintf('  Process Date : %s', $processDate),
        ];
        if (count($voidResults) > 0) {
            $noteLines[] = 'Settlements Voided: ' . count($voidResults);
        }
        $this->gateway->createContactNote($workItem, implode("\n", $noteLines));

        return new PmodResult(
            status: 'updated',
            message: sprintf('Lump Sum Payment of $%s created for contact [%s] on %s.', number_format((float) $totalAmount, 2), $workItem->contactId, $processDate),
            metadata: ['action_type' => $workItem->actionType->value, 'contact_id' => $workItem->contactId, 'total_amount' => $totalAmount, 'process_date' => $processDate, 'draft_result' => $draftResult, 'void_results' => $voidResults],
        );
    }

    /** @param array<string, mixed> $metadata */
    private function captureForManualReview(PmodWorkItem $workItem, string $message, array $metadata): PmodResult
    {
        $note = $this->gateway->createContactNote(
            $workItem,
            implode("\n", [
                'PMOD Lump Sum Payment requires manual review.',
                'Reason: ' . $message,
                'Contact ID: ' . $workItem->contactId,
                'Requested By: ' . $workItem->requestedBy,
                '',
                'This action could not be processed automatically and has been flagged for manual review.',
                '',
                '- oduai',
            ]),
        );

        return new PmodResult(
            status: 'captured_for_manual_review',
            message: $message,
            metadata: [...$metadata, 'action_type' => $workItem->actionType->value, 'contact_id' => $workItem->contactId, 'note' => $note],
        );
    }
}
