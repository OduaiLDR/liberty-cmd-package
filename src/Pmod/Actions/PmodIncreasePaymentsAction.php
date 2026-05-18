<?php

declare(strict_types=1);

namespace Cmd\Reports\Pmod\Actions;

use Cmd\Reports\Pmod\Contracts\PmodActionHandler;
use Cmd\Reports\Pmod\Contracts\PmodExecutionGateway;
use Cmd\Reports\Pmod\Data\PmodResult;
use Cmd\Reports\Pmod\Data\PmodWorkItem;
use Cmd\Reports\Pmod\Enums\PmodActionType;
use Cmd\Reports\Pmod\Support\PmodTransactionMatcher;

final class PmodIncreasePaymentsAction implements PmodActionHandler
{
    public function __construct(
        private readonly PmodExecutionGateway $gateway,
        private readonly bool $allowLiveDraftUpdates = false,
    ) {
    }

    public function actionType(): PmodActionType
    {
        return PmodActionType::PMOD_INCREASE_PAYMENTS;
    }

    public function handle(PmodWorkItem $workItem): PmodResult
    {
        $newAmount = $workItem->increaseAmount ?? $workItem->amount;
        $startDate = $workItem->paymentChange['start_date'] ?? ($workItem->normalizedPayload['start_date'] ?? null);
        $endDate = $workItem->paymentChange['end_date'] ?? ($workItem->normalizedPayload['end_date'] ?? null);

        if ($newAmount === null || (float) $newAmount <= 0) {
            return $this->capture($workItem, 'PMOD Increase Payments requires a valid positive amount.', ['reason' => 'missing_required_fields']);
        }

        if ($startDate !== null && $endDate !== null && $startDate > $endDate) {
            return $this->capture($workItem, 'PMOD Increase Payments has an inverted date range.', ['reason' => 'inverted_date_range']);
        }

        $futureDrafts = $this->futureDrafts($this->gateway->getContactTransactions($workItem));
        $targetDrafts = array_values(array_filter($futureDrafts, static function (array $draft) use ($startDate, $endDate): bool {
            $date = trim((string) ($draft['process_date'] ?? ''));

            return ($startDate === null || $date >= $startDate)
                && ($endDate === null || $date <= $endDate);
        }));

        if ($targetDrafts === []) {
            return $this->capture($workItem, 'PMOD Increase Payments found no drafts in the requested range.', [
                'reason' => 'no_drafts_in_range',
                'future_draft_count' => count($futureDrafts),
            ]);
        }

        if (! $this->allowLiveDraftUpdates || $workItem->dryRun) {
            return $this->capture($workItem, 'PMOD Increase Payments matched drafts but live updates are disabled.', [
                'reason' => $workItem->dryRun ? 'dry_run_only' : 'live_draft_updates_disabled',
                'draft_count' => count($targetDrafts),
            ]);
        }

        $updates = [];
        $errors = [];

        foreach ($targetDrafts as $draft) {
            $draftId = PmodTransactionMatcher::extractAuthoritativeDraftId($draft);
            if ($draftId === null) {
                $errors[] = ['reason' => 'missing_draft_id'];
                continue;
            }

            try {
                $updates[] = [
                    'draft_id' => $draftId,
                    'response' => $this->gateway->updateDraft($workItem, $draftId, [
                        'client_id' => $workItem->contactId,
                        'amount' => $newAmount,
                        'process_date' => $draft['process_date'] ?? null,
                        'memo' => sprintf('PMOD Increase Payments - Updated to $%.2f by %s', (float) $newAmount, $workItem->requestedBy ?: 'System'),
                    ]),
                ];
            } catch (\Throwable $e) {
                $errors[] = ['draft_id' => $draftId, 'reason' => $e->getMessage()];
            }
        }

        $this->gateway->createContactNote($workItem, implode("\n", [
            'PMOD Increase Payments Request:',
            'Request Status: Successful',
            'Customer Id: ' . $workItem->contactId,
            'New Payment Amount: $' . number_format((float) $newAmount, 2),
            'Drafts Updated: ' . count($updates),
            'User: ' . ($workItem->requestedBy ?: 'Client'),
        ]));

        return new PmodResult(
            status: $errors === [] ? 'updated' : 'partial_update',
            message: sprintf('PMOD Increase Payments updated %d of %d draft(s).', count($updates), count($targetDrafts)),
            metadata: [
                'action_type' => $workItem->actionType->value,
                'contact_id' => $workItem->contactId,
                'drafts_updated' => count($updates),
                'update_results' => $updates,
                'errors' => $errors,
            ],
        );
    }

    /** @return list<array<string, mixed>> */
    private function futureDrafts(array $transactions): array
    {
        $today = date('Y-m-d');

        return array_values(array_filter($transactions, static fn (array $tx): bool =>
            strtoupper(trim((string) ($tx['type'] ?? $tx['trans_type'] ?? ''))) === 'D'
            && trim((string) ($tx['process_date'] ?? '')) >= $today
            && empty($tx['cancelled'])
            && empty($tx['completed'])
        ));
    }

    /** @param array<string, mixed> $metadata */
    private function capture(PmodWorkItem $workItem, string $message, array $metadata): PmodResult
    {
        $note = $this->gateway->createContactNote($workItem, implode("\n", [
            'PMOD Increase Payments requires manual review.',
            'Reason: ' . $message,
            'Contact ID: ' . $workItem->contactId,
            'Requested By: ' . $workItem->requestedBy,
            '',
            'This action could not be processed automatically and has been flagged for manual review.',
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
