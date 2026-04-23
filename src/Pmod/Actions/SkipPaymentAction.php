<?php

declare(strict_types=1);

namespace Cmd\Reports\Pmod\Actions;

use Cmd\Reports\Pmod\Contracts\PmodActionHandler;
use Cmd\Reports\Pmod\Contracts\PmodExecutionGateway;
use Cmd\Reports\Pmod\Data\PmodResult;
use Cmd\Reports\Pmod\Data\PmodWorkItem;
use Cmd\Reports\Pmod\Enums\PmodActionType;
use Cmd\Reports\Pmod\Support\PmodTransactionMatcher;

final class SkipPaymentAction implements PmodActionHandler
{
    public function __construct(
        private readonly PmodExecutionGateway $gateway,
        private readonly bool $allowLiveDraftUpdates = false,
    ) {
    }

    public function actionType(): PmodActionType
    {
        return PmodActionType::SKIP_PAYMENT;
    }

    public function handle(PmodWorkItem $workItem): PmodResult
    {
        $originalDates = $workItem->originalDates;
        $targetDates   = $workItem->targetDates;
        $amounts       = $workItem->amounts;
        $settlementIds = $workItem->settlementIds;

        if (empty($originalDates)) {
            return $this->captureForManualReview(
                $workItem,
                'Skip Payment request is missing original dates.',
                ['reason' => 'missing_required_fields', 'original_dates' => $originalDates],
            );
        }

        $transactions = $this->gateway->getContactTransactions($workItem);

        if (empty($targetDates)) {
            $endOfTerm = $this->resolveEndOfTerm($transactions);
            if ($endOfTerm === null) {
                return $this->captureForManualReview(
                    $workItem,
                    'Skip Payment could not determine end of term from transactions.',
                    ['reason' => 'cannot_resolve_end_of_term', 'original_dates' => $originalDates],
                );
            }
            $targetDates = array_fill(0, count($originalDates), $endOfTerm);
        }

        if (count($originalDates) !== count($targetDates)) {
            return $this->captureForManualReview(
                $workItem,
                'Skip Payment has mismatched original and target date counts.',
                [
                    'reason' => 'date_count_mismatch',
                    'original_count' => count($originalDates),
                    'target_count'   => count($targetDates),
                ],
            );
        }

        $updates = [];
        $errors  = [];

        foreach ($originalDates as $index => $originalDate) {
            $targetDate = $targetDates[$index] ?? null;
            $amount     = $amounts[$index] ?? ($workItem->amount ?? null);

            if ($targetDate === null || $amount === null) {
                $errors[] = ['index' => $index, 'original_date' => $originalDate, 'reason' => 'missing_target_date_or_amount'];
                continue;
            }

            $matches = PmodTransactionMatcher::findCandidatesByDateAndAmount($transactions, $originalDate, $amount);
            if (count($matches) !== 1) {
                $errors[] = ['index' => $index, 'original_date' => $originalDate, 'amount' => $amount, 'reason' => count($matches) === 0 ? 'no_match' : 'ambiguous_match', 'match_count' => count($matches)];
                continue;
            }

            $draftId = PmodTransactionMatcher::extractAuthoritativeDraftId($matches[0]);
            if ($draftId === null) {
                $errors[] = ['index' => $index, 'original_date' => $originalDate, 'reason' => 'missing_draft_id'];
                continue;
            }

            $updates[] = ['draft_id' => $draftId, 'original_date' => $originalDate, 'target_date' => $targetDate, 'amount' => $amount];
        }

        if (!empty($errors)) {
            return $this->captureForManualReview(
                $workItem,
                sprintf('Skip Payment could not match %d of %d payments.', count($errors), count($originalDates)),
                ['reason' => 'partial_match_failure', 'updates_found' => count($updates), 'errors' => $errors],
            );
        }

        if (!$this->allowLiveDraftUpdates || $workItem->dryRun) {
            return $this->captureForManualReview(
                $workItem,
                sprintf('Skip Payment matched %d draft(s) but live updates are disabled.', count($updates)),
                ['reason' => $workItem->dryRun ? 'dry_run_only' : 'live_draft_updates_disabled', 'updates' => $updates],
            );
        }

        $voidResults = [];
        foreach ($settlementIds as $settlementId) {
            try {
                $voidResults[] = $this->gateway->voidSettlementOffer($workItem, $settlementId);
            } catch (\Throwable $e) {
                $voidResults[] = ['error' => $e->getMessage(), 'settlement_id' => $settlementId];
            }
        }

        $updateResults = [];
        foreach ($updates as $update) {
            $response = $this->gateway->updateDraft($workItem, $update['draft_id'], [
                'client_id'    => $workItem->contactId,
                'amount'       => $update['amount'],
                'process_date' => $update['target_date'],
                'memo'         => sprintf('Skip Payment - Rescheduled from %s to %s by System Admin', $update['original_date'], $update['target_date']),
            ]);
            $updateResults[] = ['draft_id' => $update['draft_id'], 'response' => $response];
        }

        $noteLines = [
            'Skip Payment Request:',
            'Request Status: Successful',
            'Name: ' . ($workItem->normalizedPayload['name'] ?? 'Client'),
            'Customer Id: ' . $workItem->contactId,
            'Action: Skip Payment',
        ];
        
        foreach ($updates as $index => $update) {
            $noteLines[] = 'Original Scheduled Date: ' . date('m/d/Y', strtotime($update['original_date']));
            $noteLines[] = 'Payment Amount: $' . number_format((float) $update['amount'], 2);
            $noteLines[] = 'Add Payment Date: ' . date('m/d/Y', strtotime($update['target_date']));
            $noteLines[] = 'Add Payment Amount: $' . number_format((float) $update['amount'], 2);
        }
        
        $noteLines[] = 'Dedicated Account Balance:';
        $noteLines[] = 'Total Fees Schedule:';
        $noteLines[] = 'User: ' . ($workItem->requestedBy ?? 'Client');
        $noteLines[] = 'Device: ' . ($workItem->normalizedPayload['device'] ?? 'mobile');
        
        $this->gateway->createContactNote($workItem, implode("\n", $noteLines));

        return new PmodResult(
            status: 'updated',
            message: sprintf('Skip Payment updated %d draft(s) for contact [%s].', count($updateResults), $workItem->contactId),
            metadata: ['action_type' => $workItem->actionType->value, 'contact_id' => $workItem->contactId, 'drafts_updated' => count($updateResults), 'settlements_voided' => count($voidResults), 'update_results' => $updateResults, 'void_results' => $voidResults],
        );
    }

    /** @param array<string, mixed> $metadata */
    private function captureForManualReview(PmodWorkItem $workItem, string $message, array $metadata): PmodResult
    {
        $note = $this->gateway->createContactNote(
            $workItem,
            implode("\n", [
                'PMOD Skip Payment requires manual review.',
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

    /** @param list<array<string, mixed>> $transactions */
    private function resolveEndOfTerm(array $transactions): ?string
    {
        $lastDate = null;
        foreach ($transactions as $tx) {
            $date = trim((string) ($tx['process_date'] ?? ''));
            if ($date !== '' && ($lastDate === null || $date > $lastDate)) {
                $lastDate = $date;
            }
        }
        return $lastDate;
    }
}
