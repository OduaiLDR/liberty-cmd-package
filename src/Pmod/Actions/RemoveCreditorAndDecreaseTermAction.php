<?php

declare(strict_types=1);

namespace Cmd\Reports\Pmod\Actions;

use Cmd\Reports\Pmod\Contracts\PmodActionHandler;
use Cmd\Reports\Pmod\Contracts\PmodExecutionGateway;
use Cmd\Reports\Pmod\Data\PmodResult;
use Cmd\Reports\Pmod\Data\PmodWorkItem;
use Cmd\Reports\Pmod\Enums\PmodActionType;

final class RemoveCreditorAndDecreaseTermAction implements PmodActionHandler
{
    public function __construct(
        private readonly PmodExecutionGateway $gateway,
        private readonly bool $allowLiveDraftUpdates = false,
    ) {
    }

    public function actionType(): PmodActionType
    {
        return PmodActionType::REMOVE_CREDITOR_AND_DECREASE_TERM;
    }

    public function handle(PmodWorkItem $workItem): PmodResult
    {
        $creditorChange  = $workItem->creditorChange;
        $monthsToDecrease = (int) ($workItem->normalizedPayload['months_to_decrease'] ?? $creditorChange['months_to_decrease'] ?? 0);
        $creditorName    = $creditorChange['creditor_name'] ?? null;
        $creditorId      = $creditorChange['creditor_id'] ?? null;

        if (empty($creditorChange) || ($creditorName === null && $creditorId === null)) {
            return $this->capture($workItem, 'Remove Creditor and Decrease Term requires creditor information.', ['reason' => 'missing_creditor_info']);
        }

        if ($monthsToDecrease <= 0 || $monthsToDecrease > 120) {
            return $this->capture($workItem, 'Remove Creditor and Decrease Term requires months_to_decrease between 1 and 120.', [
                'reason'            => 'invalid_months',
                'months_to_decrease' => $monthsToDecrease,
            ]);
        }

        if (!$this->allowLiveDraftUpdates || $workItem->dryRun) {
            return $this->capture($workItem, 'Remove Creditor and Decrease Term matched but live updates are disabled.', [
                'reason'             => $workItem->dryRun ? 'dry_run_only' : 'live_draft_updates_disabled',
                'creditor'           => $creditorName,
                'months_to_decrease' => $monthsToDecrease,
            ]);
        }

        // Step 1: find the debt — throws if CRM unreachable
        $debts = $this->gateway->getContactDebts($workItem);
        $debt  = $this->findDebt($debts, $creditorName, $creditorId);

        if ($debt === null) {
            return $this->capture($workItem, sprintf('Remove Creditor and Decrease Term could not find debt for creditor [%s].', $creditorName ?? $creditorId), [
                'reason'      => 'debt_not_found',
                'creditor'    => $creditorName ?? $creditorId,
                'debts_found' => count($debts),
            ]);
        }

        // Step 2: cancel the debt — if this throws, no drafts are touched
        $debtId       = (string) ($debt['id'] ?? '');
        $cancelResult = $this->gateway->cancelDebt($workItem, $debtId);

        // Step 3: cancel the last N future type-D drafts (shorten the program)
        $transactions = $this->gateway->getContactTransactions($workItem);
        $today        = now()->toDateString();

        $futureDrafts = array_values(array_filter(
            $transactions,
            fn ($t) => ($t['type'] ?? '') === 'D' && ($t['active'] ?? '') === '1' && ($t['process_date'] ?? '') >= $today,
        ));

        usort($futureDrafts, fn ($a, $b) => strcmp($a['process_date'] ?? '', $b['process_date'] ?? ''));

        $toCancel     = array_slice($futureDrafts, -min($monthsToDecrease, count($futureDrafts)));
        $cancelResults = [];
        $cancelErrors  = [];

        foreach ($toCancel as $draft) {
            $draftId = (string) ($draft['transaction_id'] ?? $draft['id'] ?? '');
            if ($draftId === '') {
                continue;
            }
            try {
                $cancelResults[] = $this->gateway->cancelDraft($workItem, $draftId);
            } catch (\Throwable $e) {
                $cancelErrors[] = ['draft_id' => $draftId, 'error' => $e->getMessage()];
            }
        }

        $noteLines = [
            'Remove Creditor and Decrease Term Request:',
            'Request Status: ' . (empty($cancelErrors) ? 'Successful' : 'Partial — see errors'),
            'Name: ' . ($workItem->normalizedPayload['name'] ?? 'Client'),
            'Customer Id: ' . $workItem->contactId,
            'Action: Remove Creditor and Decrease Term',
            'Creditor Removed: ' . ($creditorName ?? $creditorId ?? 'N/A'),
            'Months Decreased: ' . $monthsToDecrease,
            'Drafts Cancelled: ' . count($cancelResults),
            'User: ' . ($workItem->requestedBy ?? 'Client'),
        ];

        $this->gateway->createContactNote($workItem, implode("\n", $noteLines));

        return new PmodResult(
            status:   empty($cancelErrors) ? 'updated' : 'captured_for_manual_review',
            message:  sprintf('Remove Creditor and Decrease Term: debt cancelled, %d draft(s) removed for contact [%s].', count($cancelResults), $workItem->contactId),
            metadata: [
                'action_type'       => $workItem->actionType->value,
                'contact_id'        => $workItem->contactId,
                'debt_id'           => $debtId,
                'creditor_name'     => $creditorName,
                'months_to_decrease' => $monthsToDecrease,
                'cancel_result'     => $cancelResult,
                'drafts_cancelled'  => count($cancelResults),
                'cancel_errors'     => $cancelErrors,
            ],
        );
    }

    /** @param array<string, mixed> $metadata */
    private function capture(PmodWorkItem $workItem, string $message, array $metadata): PmodResult
    {
        $this->gateway->createContactNote($workItem, implode("\n", [
            'PMOD Remove Creditor and Decrease Term requires manual review.',
            'Reason: ' . $message,
            'Contact ID: ' . $workItem->contactId,
            'Requested By: ' . $workItem->requestedBy,
        ]));

        return new PmodResult(
            status:   'captured_for_manual_review',
            message:  $message,
            metadata: [...$metadata, 'action_type' => $workItem->actionType->value, 'contact_id' => $workItem->contactId],
        );
    }

    /**
     * @param list<array<string, mixed>> $debts
     * @return array<string, mixed>|null
     */
    private function findDebt(array $debts, ?string $creditorName, ?string $creditorId): ?array
    {
        foreach ($debts as $debt) {
            if ($creditorId !== null && (string) ($debt['id'] ?? '') === $creditorId) {
                return $debt;
            }
            if ($creditorName !== null) {
                $name = strtolower(trim((string) ($debt['creditor']['company_name'] ?? $debt['creditor_name'] ?? '')));
                if ($name === strtolower(trim($creditorName))) {
                    return $debt;
                }
            }
        }
        return null;
    }
}
