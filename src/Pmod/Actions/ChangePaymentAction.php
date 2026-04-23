<?php

declare(strict_types=1);

namespace Cmd\Reports\Pmod\Actions;

use Cmd\Reports\Pmod\Contracts\PmodActionHandler;
use Cmd\Reports\Pmod\Contracts\PmodExecutionGateway;
use Cmd\Reports\Pmod\Data\PmodResult;
use Cmd\Reports\Pmod\Data\PmodWorkItem;
use Cmd\Reports\Pmod\Enums\PmodActionType;
use Cmd\Reports\Pmod\Support\PmodTransactionMatcher;

final class ChangePaymentAction implements PmodActionHandler
{
    public function __construct(
        private readonly PmodExecutionGateway $gateway,
        private readonly bool $allowLiveDraftUpdates = false,
    ) {
    }

    public function actionType(): PmodActionType
    {
        return PmodActionType::CHANGE_PAYMENT;
    }

    public function handle(PmodWorkItem $workItem): PmodResult
    {
        $originalDate = $workItem->originalDates[0] ?? null;
        $targetDate = $workItem->targetDates[0] ?? null;
        $amount = $workItem->amount ?? ($workItem->amounts[0] ?? null);

        if ($originalDate === null || $targetDate === null || $amount === null) {
            return $this->captureForManualReview(
                $workItem,
                'Change Payment request is missing original date, target date, or amount.',
                [
                    'reason' => 'missing_required_fields',
                ],
            );
        }

        $transactions = $this->gateway->getContactTransactions($workItem);
        $matches = PmodTransactionMatcher::findCandidatesByDateAndAmount($transactions, $originalDate, $amount);

        if ($matches === []) {
            return $this->captureForManualReview(
                $workItem,
                'No unique transaction candidate was found for Change Payment.',
                [
                    'reason' => 'no_matching_transaction',
                    'original_date' => $originalDate,
                    'amount' => $amount,
                    'transaction_count' => count($transactions),
                ],
            );
        }

        if (count($matches) > 1) {
            return $this->captureForManualReview(
                $workItem,
                'Multiple transaction candidates matched Change Payment.',
                [
                    'reason' => 'ambiguous_transaction_match',
                    'original_date' => $originalDate,
                    'amount' => $amount,
                    'match_count' => count($matches),
                ],
            );
        }

        $match = $matches[0];
        $draftId = PmodTransactionMatcher::extractAuthoritativeDraftId($match);

        if ($draftId === null) {
            return $this->captureForManualReview(
                $workItem,
                'Matched transaction does not expose an authoritative Forth Pay draft id.',
                [
                    'reason' => 'missing_authoritative_draft_id',
                    'matched_transaction_keys' => array_keys($match),
                ],
            );
        }

        $memo = $this->extractMemo($match);

        if ($memo === null) {
            return $this->captureForManualReview(
                $workItem,
                'Matched draft cannot be updated because the required memo field is missing from transaction data.',
                [
                    'reason' => 'missing_memo_for_update',
                    'draft_id' => $draftId,
                ],
            );
        }

        if (!$this->allowLiveDraftUpdates || $workItem->dryRun) {
            return $this->captureForManualReview(
                $workItem,
                'Matched a unique draft candidate for Change Payment, but live draft updates are disabled.',
                [
                    'reason' => $workItem->dryRun ? 'dry_run_only' : 'live_draft_updates_disabled',
                    'draft_id' => $draftId,
                    'target_date' => $targetDate,
                    'amount' => $amount,
                ],
            );
        }

        $response = $this->gateway->updateDraft($workItem, $draftId, [
            'client_id' => $workItem->contactId,
            'amount' => $amount,
            'process_date' => $targetDate,
            'memo' => $memo,
        ]);

        return new PmodResult(
            status: 'updated',
            message: sprintf(
                'Updated Change Payment draft [%s] for contact [%s].',
                $draftId,
                $workItem->contactId,
            ),
            metadata: [
                'action_type' => $workItem->actionType->value,
                'contact_id' => $workItem->contactId,
                'draft_id' => $draftId,
                'target_date' => $targetDate,
                'amount' => $amount,
                'response' => $response,
            ],
        );
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function captureForManualReview(PmodWorkItem $workItem, string $message, array $metadata): PmodResult
    {
        $amount = $workItem->amount ?? ($workItem->amounts[0] ?? null);

        $note = $this->gateway->createContactNote(
            $workItem,
            implode("\n", array_filter([
                'Change Payment — Requires Manual Review',
                '',
                'Contact ID : ' . $workItem->contactId,
                'Requested by : ' . $workItem->requestedBy,
                'Source : ' . $workItem->source,
                !empty($workItem->originalDates) ? 'Original date : ' . implode(', ', $workItem->originalDates) : null,
                !empty($workItem->targetDates)   ? 'New date : '      . implode(', ', $workItem->targetDates)   : null,
                $amount !== null                 ? 'Amount : $'       . $amount                                : null,
                '',
                'Reason : ' . $message,
                '',
                'This payment change could not be processed automatically and has been flagged for manual review.',
                '',
                '- oduai',
            ])),
        );

        return new PmodResult(
            status: 'captured_for_manual_review',
            message: $message,
            metadata: [
                ...$metadata,
                'action_type' => $workItem->actionType->value,
                'contact_id' => $workItem->contactId,
                'note' => $note,
            ],
        );
    }

    private function extractMemo(array $transaction): ?string
    {
        foreach (['memo', 'description', 'comment', 'notes'] as $key) {
            if (!array_key_exists($key, $transaction)) {
                continue;
            }

            $value = trim((string) $transaction[$key]);

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }
}
