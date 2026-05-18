<?php

declare(strict_types=1);

namespace Cmd\Reports\Pmod\Actions;

use Cmd\Reports\Pmod\Contracts\PmodActionHandler;
use Cmd\Reports\Pmod\Contracts\PmodExecutionGateway;
use Cmd\Reports\Pmod\Data\PmodResult;
use Cmd\Reports\Pmod\Data\PmodWorkItem;
use Cmd\Reports\Pmod\Enums\PmodActionType;

final class AdditionalPaymentAction implements PmodActionHandler
{
    public function __construct(
        private readonly PmodExecutionGateway $gateway,
        private readonly bool $allowLiveDraftUpdates = false,
    ) {
    }

    public function actionType(): PmodActionType
    {
        return PmodActionType::ADDITIONAL_PAYMENT;
    }

    public function handle(PmodWorkItem $workItem): PmodResult
    {
        $amounts     = $workItem->amounts;
        $targetDates = $workItem->targetDates;
        $amount      = $amounts[0] ?? ($workItem->amount ?? null);
        $processDate = $targetDates[0] ?? ($workItem->targetDate ?? null);

        if ($amount === null || $processDate === null) {
            return $this->captureForManualReview(
                $workItem,
                'Additional Payment is missing required amount or process date.',
                ['reason' => 'missing_required_fields', 'amount' => $amount, 'process_date' => $processDate],
            );
        }

        if (!$this->allowLiveDraftUpdates || $workItem->dryRun) {
            return $this->captureForManualReview(
                $workItem,
                sprintf('Additional Payment of $%s on %s is ready but live updates are disabled.', number_format((float) $amount, 2), $processDate),
                ['reason' => $workItem->dryRun ? 'dry_run_only' : 'live_draft_updates_disabled', 'amount' => $amount, 'process_date' => $processDate],
            );
        }

        $draftPayload = [
            'client_id'    => $workItem->contactId,
            'amount'       => $amount,
            'process_date' => $processDate,
            'memo'         => sprintf('Additional Payment - $%s on %s by System Admin', number_format((float) $amount, 2), $processDate),
        ];

        $draftResult = $this->gateway->createDraft($workItem, $draftPayload);

        $noteLines = [
            'PMOD Additional Payment processed by System Admin.',
            sprintf('  Amount       : $%s', number_format((float) $amount, 2)),
            sprintf('  Process Date : %s', $processDate),
        ];
        $this->gateway->createContactNote($workItem, implode("\n", $noteLines));

        return new PmodResult(
            status: 'updated',
            message: sprintf('Additional Payment of $%s created for contact [%s] on %s.', number_format((float) $amount, 2), $workItem->contactId, $processDate),
            metadata: ['action_type' => $workItem->actionType->value, 'contact_id' => $workItem->contactId, 'draft_result' => $draftResult, 'amount' => $amount, 'process_date' => $processDate],
        );
    }

    /** @param array<string, mixed> $metadata */
    private function captureForManualReview(PmodWorkItem $workItem, string $message, array $metadata): PmodResult
    {
        $note = $this->gateway->createContactNote(
            $workItem,
            implode("\n", [
                'PMOD Additional Payment requires manual review.',
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
