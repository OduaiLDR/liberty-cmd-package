<?php

declare(strict_types=1);

namespace Cmd\Reports\Pmod\Actions;

use Cmd\Reports\Pmod\Contracts\PmodActionHandler;
use Cmd\Reports\Pmod\Contracts\PmodExecutionGateway;
use Cmd\Reports\Pmod\Data\PmodResult;
use Cmd\Reports\Pmod\Data\PmodWorkItem;
use Cmd\Reports\Pmod\Enums\PmodActionType;

final class RemoveCreditorAndDecreasePaymentAction implements PmodActionHandler
{
    public function __construct(
        private readonly PmodExecutionGateway $gateway,
        private readonly bool $allowLiveDraftUpdates = false,
    ) {
    }

    public function actionType(): PmodActionType
    {
        return PmodActionType::REMOVE_CREDITOR_AND_DECREASE_PAYMENT;
    }

    public function handle(PmodWorkItem $workItem): PmodResult
    {
        $creditorChange   = $workItem->creditorChange;
        $newPaymentAmount = $workItem->amount;
        $creditorName     = $creditorChange['creditor_name'] ?? null;
        $creditorId       = $creditorChange['creditor_id'] ?? null;

        if (empty($creditorChange) || ($creditorName === null && $creditorId === null)) {
            return $this->capture($workItem, 'Remove Creditor and Decrease Payment requires creditor information.', ['reason' => 'missing_creditor_info']);
        }

        if ($newPaymentAmount === null || (float) $newPaymentAmount <= 0) {
            return $this->capture($workItem, 'Remove Creditor and Decrease Payment requires a valid new payment amount.', ['reason' => 'invalid_payment_amount', 'amount' => $newPaymentAmount]);
        }

        if (!$this->allowLiveDraftUpdates || $workItem->dryRun) {
            return $this->capture($workItem, 'Remove Creditor and Decrease Payment matched but live updates are disabled.', [
                'reason'       => $workItem->dryRun ? 'dry_run_only' : 'live_draft_updates_disabled',
                'creditor'     => $creditorName,
                'new_payment'  => $newPaymentAmount,
            ]);
        }

        // Step 1: find the debt on the contact
        $debts  = $this->gateway->getContactDebts($workItem);
        $debt   = $this->findDebt($debts, $creditorName, $creditorId);

        if ($debt === null) {
            return $this->capture($workItem, sprintf('Remove Creditor and Decrease Payment could not find debt for creditor [%s].', $creditorName ?? $creditorId), [
                'reason'      => 'debt_not_found',
                'creditor'    => $creditorName ?? $creditorId,
                'debts_found' => count($debts),
            ]);
        }

        // Step 2: cancel the debt — if this throws, nothing else runs
        $debtId     = (string) ($debt['id'] ?? '');
        $cancelResult = $this->gateway->cancelDebt($workItem, $debtId);

        // Step 3: update all future active type-D drafts to the new lower amount
        $transactions  = $this->gateway->getContactTransactions($workItem);
        $today         = now()->toDateString();
        $updateResults = [];
        $updateErrors  = [];

        foreach ($transactions as $txn) {
            if (($txn['type'] ?? '') !== 'D' || ($txn['active'] ?? '') !== '1' || ($txn['process_date'] ?? '') < $today) {
                continue;
            }
            $draftId = (string) ($txn['transaction_id'] ?? $txn['id'] ?? '');
            if ($draftId === '') {
                continue;
            }
            try {
                $updateResults[] = $this->gateway->updateDraft($workItem, $draftId, [
                    'client_id'    => $workItem->contactId,
                    'amount'       => $newPaymentAmount,
                    'process_date' => $txn['process_date'],
                    'memo'         => sprintf('Remove Creditor - Amount decreased to $%s by System Admin', number_format((float) $newPaymentAmount, 2)),
                ]);
            } catch (\Throwable $e) {
                $updateErrors[] = ['draft_id' => $draftId, 'error' => $e->getMessage()];
            }
        }

        $noteLines = [
            'Remove Creditor and Decrease Payment Request:',
            'Request Status: ' . (empty($updateErrors) ? 'Successful' : 'Partial — see errors'),
            'Name: ' . ($workItem->normalizedPayload['name'] ?? 'Client'),
            'Customer Id: ' . $workItem->contactId,
            'Action: Remove Creditor and Decrease Payment',
            'Creditor Removed: ' . ($creditorName ?? $creditorId ?? 'N/A'),
            'New Monthly Payment: $' . number_format((float) $newPaymentAmount, 2),
            'Drafts Updated: ' . count($updateResults),
            'User: ' . ($workItem->requestedBy ?? 'Client'),
        ];

        $this->gateway->createContactNote($workItem, implode("\n", $noteLines));

        return new PmodResult(
            status:   empty($updateErrors) ? 'updated' : 'captured_for_manual_review',
            message:  sprintf('Remove Creditor and Decrease Payment: debt cancelled, %d draft(s) updated for contact [%s].', count($updateResults), $workItem->contactId),
            metadata: [
                'action_type'    => $workItem->actionType->value,
                'contact_id'     => $workItem->contactId,
                'debt_id'        => $debtId,
                'creditor_name'  => $creditorName,
                'new_payment'    => $newPaymentAmount,
                'cancel_result'  => $cancelResult,
                'drafts_updated' => count($updateResults),
                'update_errors'  => $updateErrors,
            ],
        );
    }

    /** @param array<string, mixed> $metadata */
    private function capture(PmodWorkItem $workItem, string $message, array $metadata): PmodResult
    {
        $this->gateway->createContactNote($workItem, implode("\n", [
            'PMOD Remove Creditor and Decrease Payment requires manual review.',
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
