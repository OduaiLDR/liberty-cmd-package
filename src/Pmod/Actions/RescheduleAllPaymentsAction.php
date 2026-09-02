<?php

declare(strict_types=1);

namespace Cmd\Reports\Pmod\Actions;

use Cmd\Reports\Pmod\Contracts\PmodActionHandler;
use Cmd\Reports\Pmod\Contracts\PmodExecutionGateway;
use Cmd\Reports\Pmod\Data\PmodResult;
use Cmd\Reports\Pmod\Data\PmodWorkItem;
use Cmd\Reports\Pmod\Enums\PmodActionType;
use Cmd\Reports\Pmod\Support\PmodBusinessDateResolver;
use Cmd\Reports\Pmod\Support\PmodTransactionMatcher;

final class RescheduleAllPaymentsAction implements PmodActionHandler
{
    public function __construct(
        private readonly PmodExecutionGateway $gateway,
        private readonly bool $allowLiveDraftUpdates = false,
    ) {}

    public function actionType(): PmodActionType
    {
        return PmodActionType::RESCHEDULE_ALL_PAYMENTS;
    }

    public function handle(PmodWorkItem $workItem): PmodResult
    {
        $startDate = $workItem->paymentChange['start_date'] ?? ($workItem->targetDates[0] ?? null);
        $frequency = $workItem->frequency ?? 'monthly';

        // A Reschedule All request may carry a NEW payment amount as well as new
        // dates. The VBA parsed "New Payment Amount" and typed it into #damount
        // (ProcessRescheduleAllPayments, pmodLdr.md line 758 parse / 808 apply);
        // this port discarded it and kept every draft on its existing amount, so
        // a client who asked for a different payment got the dates moved and
        // nothing else.
        //
        // Null when the consumer sends dates only - 6 of 13 measured requests do -
        // and in that case each draft keeps its own amount exactly as before.
        $newAmount = $workItem->amount ?? ($workItem->amounts[0] ?? null);

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
            static fn(array $tx): bool =>
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

        // Resolved BEFORE the live gate so an unhonourable frequency is caught in a
        // dry run too, and so the capture below can report the interval it would
        // have used.
        $interval = $this->resolveMonthInterval($frequency);

        if ($interval === null) {
            return $this->captureForManualReview(
                $workItem,
                sprintf('Reschedule All Payments cannot honour a frequency of "%s".', $frequency),
                [
                    'reason' => 'unsupported_frequency',
                    'frequency' => $frequency,
                    'start_date' => $startDate,
                    'draft_count' => count($futureDrafts),
                ],
            );
        }

        if (!$this->allowLiveDraftUpdates || $workItem->dryRun) {
            return $this->captureForManualReview(
                $workItem,
                sprintf('Reschedule All Payments found %d future draft(s) but live updates are disabled.', count($futureDrafts)),
                [
                    'reason' => $workItem->dryRun ? 'dry_run_only' : 'live_draft_updates_disabled',
                    'start_date' => $startDate,
                    'frequency' => $frequency,
                    'draft_count' => count($futureDrafts),
                    // Surfaced so a dry run can show which amount WOULD be applied.
                    // Without this the only way to verify is a live run, and this
                    // action rewrites every future draft - 59 on the test contact -
                    // which is an expensive thing to revert. null here means the
                    // request sent no amount, so each draft keeps its own.
                    'new_amount' => $newAmount,
                ],
            );
        }

        usort($futureDrafts, static fn(array $a, array $b): int =>
        strcmp((string) ($a['process_date'] ?? ''), (string) ($b['process_date'] ?? '')));

        $updateResults = [];
        $errors        = [];

        foreach ($futureDrafts as $idx => $draft) {
            $draftId = PmodTransactionMatcher::extractAuthoritativeDraftId($draft);
            if ($draftId === null) {
                $errors[] = ['index' => $idx, 'reason' => 'missing_draft_id'];
                continue;
            }

            // Clamped month arithmetic: `strtotime('+1 months')` from a 31st rolls
            // into the following month (§7.7), which on a full reschedule pushed
            // every subsequent draft off the client's drafting day.
            $newDate = PmodBusinessDateResolver::addMonths($startDate, $idx * $interval);

            try {
                $response = $this->gateway->updateDraft($workItem, $draftId, [
                    'client_id'    => $workItem->contactId,
                    'amount'       => $newAmount ?? $draft['amount'] ?? $draft['debit_amount'] ?? null,
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
        // Report the amount the drafts will actually carry: the new one if the
        // request supplied it, otherwise the existing amount. Previously this
        // printed the existing amount DIVIDED BY TWO at three decimal places -
        // a figure that was neither the old nor the new payment - and this note
        // posts with public = true, so the client reads it.
        $appliedAmount = $newAmount ?? ($futureDrafts[0]['amount'] ?? $futureDrafts[0]['debit_amount'] ?? null);
        if ($appliedAmount !== null && trim((string) $appliedAmount) !== '') {
            $noteLines[] = 'New Payment Amount: $' . number_format((float) $appliedAmount, 2);
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

    /**
     * Months between drafts, or NULL when the frequency cannot be honoured.
     *
     * `bi-monthly` and friends used to return **0**, which put every future draft
     * on the same date - one client, one day, twenty debits (§7.2). Nothing about
     * "bi-monthly" justifies that: the word genuinely means either *every two
     * months* or *twice a month*, and the second cannot be expressed as a whole
     * number of months at all. Guessing picks a real payment schedule for a real
     * client, so it returns null and the request goes to a human.
     *
     * An unrecognised frequency also returns null rather than quietly becoming
     * monthly. An ABSENT frequency is different and still defaults to monthly at
     * the call site - 7 of 13 measured requests send none, and monthly is what
     * those clients are already on.
     */
    private function resolveMonthInterval(string $frequency): ?int
    {
        return match (strtolower(trim($frequency))) {
            'monthly', 'month' => 1,
            'quarterly' => 3,
            'semi-annual', 'semi_annual', 'semiannual' => 6,
            'annual', 'yearly' => 12,
            default => null,
        };
    }
}
