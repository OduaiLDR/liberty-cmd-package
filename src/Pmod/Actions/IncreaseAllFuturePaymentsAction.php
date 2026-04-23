<?php

declare(strict_types=1);

namespace Cmd\Reports\Pmod\Actions;

use Cmd\Reports\Pmod\Contracts\PmodActionHandler;
use Cmd\Reports\Pmod\Contracts\PmodExecutionGateway;
use Cmd\Reports\Pmod\Data\PmodResult;
use Cmd\Reports\Pmod\Data\PmodWorkItem;
use Cmd\Reports\Pmod\Enums\PmodActionType;
use Cmd\Reports\Pmod\Support\PmodTransactionMatcher;

final class IncreaseAllFuturePaymentsAction implements PmodActionHandler
{
    public function __construct(
        private readonly PmodExecutionGateway $gateway,
        private readonly bool $allowLiveDraftUpdates = false,
    ) {
    }

    public function actionType(): PmodActionType
    {
        return PmodActionType::INCREASE_ALL_FUTURE_PAYMENTS;
    }

    public function handle(PmodWorkItem $workItem): PmodResult
    {
        $newAmount = $workItem->increaseAmount ?? ($workItem->amounts[0] ?? ($workItem->amount ?? null));

        if ($newAmount === null) {
            return $this->captureForManualReview(
                $workItem,
                'Increase All Future Payments is missing the new payment amount.',
                ['reason' => 'missing_required_fields', 'increase_amount' => $newAmount],
            );
        }

        $transactions = $this->gateway->getContactTransactions($workItem);
        $today        = date('Y-m-d');

        $futureDrafts = array_values(array_filter(
            $transactions,
            static fn (array $tx): bool =>
                strtoupper(trim((string) ($tx['type'] ?? $tx['trans_type'] ?? ''))) === 'D' &&
                trim((string) ($tx['process_date'] ?? '')) >= $today &&
                empty($tx['cancelled']) &&
                empty($tx['completed']),
        ));

        if (empty($futureDrafts)) {
            return $this->captureForManualReview(
                $workItem,
                'Increase All Future Payments found no future draft transactions to update.',
                ['reason' => 'no_future_drafts', 'new_amount' => $newAmount],
            );
        }

        if (!$this->allowLiveDraftUpdates || $workItem->dryRun) {
            return $this->captureForManualReview(
                $workItem,
                sprintf('Increase All Future Payments found %d future draft(s) but live updates are disabled.', count($futureDrafts)),
                ['reason' => $workItem->dryRun ? 'dry_run_only' : 'live_draft_updates_disabled', 'new_amount' => $newAmount, 'draft_count' => count($futureDrafts)],
            );
        }

        $updateResults = [];
        $errors        = [];

        foreach ($futureDrafts as $idx => $draft) {
            $draftId = PmodTransactionMatcher::extractAuthoritativeDraftId($draft);
            if ($draftId === null) {
                $errors[] = ['index' => $idx, 'reason' => 'missing_draft_id'];
                continue;
            }

            try {
                $response = $this->gateway->updateDraft($workItem, $draftId, [
                    'client_id'    => $workItem->contactId,
                    'amount'       => $newAmount,
                    'process_date' => $draft['process_date'] ?? null,
                    'memo'         => sprintf('Increase All - Amount updated to $%s by System Admin', number_format((float) $newAmount, 2)),
                ]);
                $updateResults[] = ['draft_id' => $draftId, 'new_amount' => $newAmount, 'response' => $response];
            } catch (\Throwable $e) {
                $errors[] = ['draft_id' => $draftId, 'reason' => $e->getMessage()];
            }
        }

        // Calculate estimated graduation date based on last draft
        $lastDraft = end($futureDrafts);
        $estimatedGradDate = $lastDraft['process_date'] ?? null;
        $effectiveDate = $futureDrafts[0]['process_date'] ?? null;
        
        $noteLines = [
            'Increase All Future Payments Request:',
            'Request Status: Successful',
            'Name: ' . ($workItem->normalizedPayload['name'] ?? 'Test Client'),
            'Customer Id: ' . $workItem->contactId,
            'Action: Increase All Future Payments',
            'New Monthly Payment: $' . number_format((float) $newAmount, 2),
        ];
        
        if ($estimatedGradDate) {
            $noteLines[] = 'Estimated Graduation Date: ' . date('m/d/Y', strtotime($estimatedGradDate));
        }
        if ($effectiveDate) {
            $noteLines[] = 'Effective Next Payment Date: ' . date('m/d/Y', strtotime($effectiveDate));
        }
        
        $noteLines[] = 'User: ' . ($workItem->requestedBy ?? 'Client');
        $noteLines[] = 'Device: ' . ($workItem->normalizedPayload['device'] ?? 'mobile');
        
        $this->gateway->createContactNote($workItem, implode("\n", $noteLines));

        return new PmodResult(
            status: !empty($errors) ? 'partial_update' : 'updated',
            message: sprintf('Increase All Future Payments updated %d of %d draft(s) for contact [%s].', count($updateResults), count($futureDrafts), $workItem->contactId),
            metadata: ['action_type' => $workItem->actionType->value, 'contact_id' => $workItem->contactId, 'new_amount' => $newAmount, 'drafts_updated' => count($updateResults), 'update_results' => $updateResults, 'errors' => $errors],
        );
    }

    /** @param array<string, mixed> $metadata */
    private function captureForManualReview(PmodWorkItem $workItem, string $message, array $metadata): PmodResult
    {
        $note = $this->gateway->createContactNote(
            $workItem,
            implode("\n", [
                'PMOD Increase All Future Payments requires manual review.',
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
