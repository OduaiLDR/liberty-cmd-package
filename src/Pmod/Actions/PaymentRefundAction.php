<?php

declare(strict_types=1);

namespace Cmd\Reports\Pmod\Actions;

use Cmd\Reports\Pmod\Contracts\PmodActionHandler;
use Cmd\Reports\Pmod\Contracts\PmodExecutionGateway;
use Cmd\Reports\Pmod\Data\PmodResult;
use Cmd\Reports\Pmod\Data\PmodWorkItem;
use Cmd\Reports\Pmod\Enums\PmodActionType;
use Cmd\Reports\Pmod\Support\PmodTransactionMatcher;

final class PaymentRefundAction implements PmodActionHandler
{
    public function __construct(
        private readonly PmodExecutionGateway $gateway,
        private readonly bool $allowLiveDraftUpdates = false,
    ) {
    }

    public function actionType(): PmodActionType
    {
        return PmodActionType::PAYMENT_REFUND;
    }

    public function handle(PmodWorkItem $workItem): PmodResult
    {
        if ($workItem->originalDates === [] || $workItem->amounts === []) {
            return $this->capture($workItem, 'Payment Refund requires original dates and amounts.', [
                'reason' => 'missing_required_fields',
            ]);
        }

        if (count($workItem->originalDates) !== count($workItem->amounts)) {
            return $this->capture($workItem, 'Payment Refund date and amount counts do not match.', [
                'reason' => 'date_amount_count_mismatch',
            ]);
        }

        $transactions = $this->gateway->getContactTransactions($workItem);
        $matched = [];
        $errors = [];

        foreach ($workItem->originalDates as $index => $originalDate) {
            $amount = $workItem->amounts[$index];
            $matches = PmodTransactionMatcher::findCandidatesByDateAndAmount($transactions, $originalDate, $amount);

            if (count($matches) !== 1) {
                $errors[] = [
                    'index' => $index,
                    'date' => $originalDate,
                    'amount' => $amount,
                    'reason' => count($matches) === 0 ? 'no_match' : 'ambiguous_match',
                    'match_count' => count($matches),
                ];
                continue;
            }

            $draftId = PmodTransactionMatcher::extractAuthoritativeDraftId($matches[0]);
            if ($draftId === null) {
                $errors[] = ['index' => $index, 'date' => $originalDate, 'reason' => 'missing_draft_id'];
                continue;
            }

            $matched[] = [
                'draft_id' => $draftId,
                'original_date' => $originalDate,
                'amount' => $amount,
                'replacement_date' => $workItem->targetDates[$index] ?? null,
            ];
        }

        $reason = $errors === [] ? 'awaiting_manual_refund' : 'partial_match_failure';
        $note = $this->gateway->createContactNote($workItem, implode("\n", [
            'Payment Refund - Awaiting Manual Refund',
            'Contact ID: ' . $workItem->contactId,
            'Requested By: ' . $workItem->requestedBy,
            'Matched Drafts: ' . count($matched),
            'Errors: ' . count($errors),
            '',
            'Refund mutations are intentionally manual-review only in PMOD v1.',
            '',
            '- oduai',
        ]));

        return new PmodResult(
            status: 'captured_for_manual_review',
            message: $errors === [] ? 'Payment Refund matched drafts and is awaiting manual refund.' : 'Payment Refund has match errors and requires manual review.',
            metadata: [
                'reason' => $reason,
                'action_type' => $workItem->actionType->value,
                'contact_id' => $workItem->contactId,
                'matched' => $matched,
                'errors' => $errors,
                'live_draft_updates_enabled' => $this->allowLiveDraftUpdates,
                'note' => $note,
            ],
        );
    }

    /** @param array<string, mixed> $metadata */
    private function capture(PmodWorkItem $workItem, string $message, array $metadata): PmodResult
    {
        $note = $this->gateway->createContactNote($workItem, implode("\n", [
            'Payment Refund requires manual review.',
            'Reason: ' . $message,
            'Contact ID: ' . $workItem->contactId,
            'Requested By: ' . $workItem->requestedBy,
            '',
            '- oduai',
        ]));

        return new PmodResult(
            status: 'captured_for_manual_review',
            message: $message,
            metadata: [...$metadata, 'action_type' => $workItem->actionType->value, 'contact_id' => $workItem->contactId, 'note' => $note],
        );
    }
}
