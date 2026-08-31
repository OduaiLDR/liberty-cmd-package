<?php

declare(strict_types=1);

namespace Cmd\Reports\Pmod\Actions;

use Cmd\Reports\Pmod\Contracts\PmodActionHandler;
use Cmd\Reports\Pmod\Contracts\PmodCreditorDirectory;
use Cmd\Reports\Pmod\Contracts\PmodExecutionGateway;
use Cmd\Reports\Pmod\Data\PmodResult;
use Cmd\Reports\Pmod\Data\PmodWorkItem;
use Cmd\Reports\Pmod\Enums\PmodActionType;

final class AddCreditorAndIncreasePaymentAction implements PmodActionHandler
{
    public function __construct(
        private readonly PmodExecutionGateway $gateway,
        private readonly bool $allowLiveDraftUpdates = false,
    ) {}

    public function actionType(): PmodActionType
    {
        return PmodActionType::ADD_CREDITOR_AND_INCREASE_PAYMENT;
    }

    public function handle(PmodWorkItem $workItem): PmodResult
    {
        $creditorChange   = $workItem->creditorChange;
        $newPaymentAmount = $workItem->amount;

        if (empty($creditorChange)) {
            return $this->capture($workItem, 'Add Creditor and Increase Payment requires creditor information.', ['reason' => 'missing_creditor_info']);
        }

        if ($newPaymentAmount === null || (float) $newPaymentAmount <= 0) {
            return $this->capture($workItem, 'Add Creditor and Increase Payment requires a valid payment amount.', [
                'reason' => 'invalid_payment_amount',
                'amount' => $newPaymentAmount,
            ]);
        }


        // Resolve the creditor BEFORE the live-updates gate. Resolution is a
        // read-only catalogue lookup, so it is safe in dry run - and doing it
        // here is what makes a dry run worth running: the capture below reports
        // the creditor id it WOULD have used. With this after the gate, a dry run
        // exercised none of the resolution logic and proved nothing.
        //
        // The id consumers send cannot be trusted: measured 2026-08-31, only 1 of
        // 4 real payload creditor_ids existed in the catalogue. resolveCreditorId
        // validates a claimed id and falls back to matching the name, failing
        // closed rather than guessing.
        $creditorId = null;

        if ($this->gateway instanceof PmodCreditorDirectory) {
            $creditorId = $this->gateway->resolveCreditorId(
                $workItem->tenantId,
                isset($creditorChange['creditor_id']) ? (string) $creditorChange['creditor_id'] : null,
                (string) ($creditorChange['creditor_name'] ?? ''),
            );
        } else {
            $creditorId = $creditorChange['creditor_id'] ?? null;
        }

        if ($creditorId === null) {
            return $this->capture($workItem, 'Add Creditor and Increase Payment could not determine the Forth creditor id.', [
                'reason'         => 'missing_creditor_id',
                'creditor_name'  => $creditorChange['creditor_name'] ?? null,
                'claimed_id'     => $creditorChange['creditor_id'] ?? null,
            ]);
        }

        if (!$this->allowLiveDraftUpdates || $workItem->dryRun) {
            return $this->capture($workItem, 'Add Creditor and Increase Payment matched but live updates are disabled.', [
                'reason'      => $workItem->dryRun ? 'dry_run_only' : 'live_draft_updates_disabled',
                'creditor_id' => $creditorId,
                'would_send'  => ["new_amount" => $newPaymentAmount],
            ]);
        }

        // Forth's own field names, read off a live debt record 2026-08-28. The
        // port had used account_number / balance / original_amount, none of which
        // Forth recognises — the INSERT then failed on non-nullable columns and
        // returned 500 code 1048 ("Column cannot be null"). client_id is added by
        // the gateway. Minimum accepted set confirmed as
        // client_id + creditor + original_debt_amount + current_debt_amount.
        $debtResult = $this->gateway->createDebt($workItem, [
            'creditor'             => $creditorId,
            'original_debt_amount' => $creditorChange['balance'] ?? null,
            'current_debt_amount'  => $creditorChange['balance'] ?? null,
            'og_account_num'       => $creditorChange['account_number'] ?? null,
        ]);

        $debtId = $debtResult['id'] ?? $debtResult['debt_id'] ?? null;

        // Step 2: increase all future drafts — if this fails we note the partial state so a human can fix it
        $futureDrafts    = $this->gateway->getContactTransactions($workItem);
        $updateResults   = [];
        $updateErrors    = [];
        $today           = now()->toDateString();

        foreach ($futureDrafts as $txn) {
            $processDate = $txn['process_date'] ?? '';
            // active === 1 is not enough: Forth leaves active = 1 on a cancelled
            // draft (verified 2026-08-31), so without this the update would push a
            // new amount onto drafts that will never be taken.
            if ($txn['type'] !== 'D' || $txn['active'] !== '1' || ! empty($txn['cancelled']) || $processDate < $today) {
                continue;
            }
            try {
                $updateResults[] = $this->gateway->updateDraft($workItem, (string) $txn['transaction_id'], [
                    'client_id'    => $workItem->contactId,
                    'amount'       => $newPaymentAmount,
                    'process_date' => $processDate,
                    'memo'         => sprintf('Add Creditor - Amount updated to $%s by System Admin', number_format((float) $newPaymentAmount, 2)),
                ]);
            } catch (\Throwable $e) {
                $updateErrors[] = ['draft_id' => $txn['transaction_id'], 'error' => $e->getMessage()];
            }
        }

        $noteLines = [
            'Add Creditor and Increase Payment Request:',
            'Request Status: ' . (empty($updateErrors) ? 'Successful' : 'Partial — see errors'),
            'Name: ' . ($workItem->normalizedPayload['name'] ?? 'Client'),
            'Customer Id: ' . $workItem->contactId,
            'Action: Add Creditor and Increase Payment',
            'Creditor Name: ' . ($creditorChange['creditor_name'] ?? 'N/A'),
            'Account Number: ' . ($creditorChange['account_number'] ?? 'N/A'),
            'Balance: $' . number_format((float) ($creditorChange['balance'] ?? 0), 2),
            'New Monthly Payment: $' . number_format((float) $newPaymentAmount, 2),
            'Drafts Updated: ' . count($updateResults),
            'User: ' . ($workItem->requestedBy ?? 'Client'),
        ];

        $this->gateway->createContactNote($workItem, implode("\n", $noteLines));

        return new PmodResult(
            status:   empty($updateErrors) ? 'updated' : 'captured_for_manual_review',
            message:  sprintf('Add Creditor and Increase Payment: debt created, %d draft(s) updated for contact [%s].', count($updateResults), $workItem->contactId),
            metadata: [
                'action_type'    => $workItem->actionType->value,
                'contact_id'     => $workItem->contactId,
                'debt_id'        => $debtId,
                'creditor_name'  => $creditorChange['creditor_name'] ?? null,
                'drafts_updated' => count($updateResults),
                'update_errors'  => $updateErrors,
            ],
        );
    }

    /** @param array<string, mixed> $metadata */
    private function capture(PmodWorkItem $workItem, string $message, array $metadata): PmodResult
    {
        $this->gateway->createContactNote($workItem, implode("\n", [
            'PMOD Add Creditor and Increase Payment requires manual review.',
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
}
