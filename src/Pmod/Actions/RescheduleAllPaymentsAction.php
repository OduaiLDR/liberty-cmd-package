<?php

declare(strict_types=1);

namespace Cmd\Reports\Pmod\Actions;

use Cmd\Reports\Pmod\Contracts\PmodActionHandler;
use Cmd\Reports\Pmod\Contracts\PmodExecutionGateway;
use Cmd\Reports\Pmod\Data\PmodResult;
use Cmd\Reports\Pmod\Data\PmodWorkItem;
use Cmd\Reports\Pmod\Enums\PmodActionType;
use Cmd\Reports\Pmod\Support\PmodTransactionMatcher;

final class RescheduleAllPaymentsAction implements PmodActionHandler
{
    public function __construct(
        private readonly PmodExecutionGateway $gateway,
        private readonly bool $allowLiveDraftUpdates = false,
    ) {
    }

    public function actionType(): PmodActionType
    {
        return PmodActionType::RESCHEDULE_ALL_PAYMENTS;
    }

    public function handle(PmodWorkItem $workItem): PmodResult
    {
        $startDate = $workItem->paymentChange['start_date'] ?? ($workItem->targetDates[0] ?? null);
        $frequency = $workItem->frequency ?? 'monthly';

        if ($startDate === null) {
            return $this->captureForManualReview(
                $workItem,
                'Reschedule All Payments is missing required start date.',
                ['reason' => 'missing_required_fields', 'start_date' => $startDate],
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
                'Reschedule All Payments found no future draft transactions to reschedule.',
                ['reason' => 'no_future_drafts', 'start_date' => $startDate, 'frequency' => $frequency],
            );
        }

        if (!$this->allowLiveDraftUpdates || $workItem->dryRun) {
            return $this->captureForManualReview(
                $workItem,
                sprintf('Reschedule All Payments found %d future draft(s) but live updates are disabled.', count($futureDrafts)),
                ['reason' => $workItem->dryRun ? 'dry_run_only' : 'live_draft_updates_disabled', 'start_date' => $startDate, 'frequency' => $frequency, 'draft_count' => count($futureDrafts)],
            );
        }

        usort($futureDrafts, static fn (array $a, array $b): int =>
            strcmp((string) ($a['process_date'] ?? ''), (string) ($b['process_date'] ?? '')));

        $interval    = $this->resolveMonthInterval($frequency);
        $updateResults = [];
        $errors        = [];

        foreach ($futureDrafts as $idx => $draft) {
            $draftId = PmodTransactionMatcher::extractAuthoritativeDraftId($draft);
            if ($draftId === null) {
                $errors[] = ['index' => $idx, 'reason' => 'missing_draft_id'];
                continue;
            }

            $newDate = date('Y-m-d', strtotime($startDate . ' +' . ($idx * $interval) . ' months'));

            try {
                $response = $this->gateway->updateDraft($workItem, $draftId, [
                    'client_id'    => $workItem->contactId,
                    'amount'       => $draft['amount'] ?? $draft['debit_amount'] ?? null,
                    'process_date' => $newDate,
                    'memo'         => sprintf('Reschedule All - moved to %s by System Admin', $newDate),
                ]);
                $updateResults[] = ['draft_id' => $draftId, 'new_date' => $newDate, 'response' => $response];
            } catch (\Throwable $e) {
                $errors[] = ['draft_id' => $draftId, 'reason' => $e->getMessage()];
            }
        }

        // Calculate estimated graduation based on last draft date
        $lastDraftDate = end($futureDrafts)['process_date'] ?? null;
        $firstOriginalDate = $futureDrafts[0]['process_date'] ?? null;
        
        $noteLines = [
            'Reschedule All Future Payments Request:',
            'Request Status: Successful',
            'Name: ' . ($workItem->normalizedPayload['name'] ?? 'Test Client'),
            'Customer Id: ' . $workItem->contactId,
            'Action: Reschedule Payment',
            'Void Settlement:',
            'Current Frequency: ' . ucfirst($frequency),
        ];
        
        if ($firstOriginalDate) {
            $noteLines[] = 'Original Scheduled Date: ' . date('m/d/Y', strtotime($firstOriginalDate));
        }
        if ($startDate) {
            $noteLines[] = 'New Draft Date: ' . date('m/d/Y', strtotime($startDate));
        }
        
        $noteLines[] = 'New Frequency: ' . ucfirst($frequency);
        if (!empty($futureDrafts[0]['amount'])) {
            $noteLines[] = 'New Payment Amount: $' . number_format((float) ($futureDrafts[0]['amount'] ?? $futureDrafts[0]['debit_amount'] ?? 0) / 2, 3);
        }
        
        $noteLines[] = 'User: ' . ($workItem->requestedBy ?? 'Client');
        $noteLines[] = 'Device: ' . ($workItem->normalizedPayload['device'] ?? 'mobile');
        
        $this->gateway->createContactNote($workItem, implode("\n", $noteLines));

        return new PmodResult(
            status: !empty($errors) ? 'partial_update' : 'updated',
            message: sprintf('Reschedule All updated %d of %d draft(s) for contact [%s].', count($updateResults), count($futureDrafts), $workItem->contactId),
            metadata: ['action_type' => $workItem->actionType->value, 'contact_id' => $workItem->contactId, 'start_date' => $startDate, 'frequency' => $frequency, 'drafts_updated' => count($updateResults), 'update_results' => $updateResults, 'errors' => $errors],
        );
    }

    /** @param array<string, mixed> $metadata */
    private function captureForManualReview(PmodWorkItem $workItem, string $message, array $metadata): PmodResult
    {
        $note = $this->gateway->createContactNote(
            $workItem,
            implode("\n", [
                'PMOD Reschedule All Payments requires manual review.',
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

    private function resolveMonthInterval(string $frequency): int
    {
        return match (strtolower(trim($frequency))) {
            'bi-monthly', 'bi_monthly', 'bimonthly', 'twice-monthly' => 0,
            'quarterly' => 3,
            'semi-annual', 'semi_annual', 'semiannual' => 6,
            'annual', 'yearly' => 12,
            default => 1,
        };
    }
}
