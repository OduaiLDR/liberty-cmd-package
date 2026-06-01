<?php

declare(strict_types=1);

namespace Cmd\Reports\Pmod\Actions;

use Cmd\Reports\Pmod\Contracts\PmodActionHandler;
use Cmd\Reports\Pmod\Contracts\PmodExecutionGateway;
use Cmd\Reports\Pmod\Data\PmodResult;
use Cmd\Reports\Pmod\Data\PmodWorkItem;
use Cmd\Reports\Pmod\Enums\PmodActionType;

final class AddCreditorAndExtendProgramAction implements PmodActionHandler
{
    public function __construct(
        private readonly PmodExecutionGateway $gateway,
        private readonly bool $allowLiveDraftUpdates = false,
    ) {}

    public function actionType(): PmodActionType
    {
        return PmodActionType::ADD_CREDITOR_AND_EXTEND_PROGRAM;
    }

    public function handle(PmodWorkItem $workItem): PmodResult
    {
        $creditorChange = $workItem->creditorChange;
        $monthsToExtend = (int) ($workItem->normalizedPayload['months_to_extend'] ?? $workItem->creditorChange['months_to_extend'] ?? 0);
        $amount         = $workItem->amount;

        if (empty($creditorChange)) {
            return $this->capture($workItem, 'Add Creditor and Extend Program requires creditor information.', ['reason' => 'missing_creditor_info']);
        }

        if ($monthsToExtend <= 0 || $monthsToExtend > 60) {
            return $this->capture($workItem, 'Add Creditor and Extend Program requires months_to_extend between 1 and 60.', [
                'reason'          => 'invalid_months',
                'months_to_extend' => $monthsToExtend,
            ]);
        }

        if ($amount === null || (float) $amount <= 0) {
            return $this->capture($workItem, 'Add Creditor and Extend Program requires a valid payment amount.', [
                'reason' => 'missing_amount',
            ]);
        }

        if (!$this->allowLiveDraftUpdates || $workItem->dryRun) {
            return $this->capture($workItem, 'Add Creditor and Extend Program matched but live updates are disabled.', [
                'reason'          => $workItem->dryRun ? 'dry_run_only' : 'live_draft_updates_disabled',
                'months_to_extend' => $monthsToExtend,
            ]);
        }

        // Step 1: create debt — if this throws, nothing else runs
        $debtResult = $this->gateway->createDebt($workItem, [
            'creditor_name'  => $creditorChange['creditor_name'] ?? null,
            'account_number' => $creditorChange['account_number'] ?? null,
            'balance'        => $creditorChange['balance'] ?? null,
        ]);

        $debtId = $debtResult['id'] ?? $debtResult['debt_id'] ?? null;

        // Step 2: generate extension drafts after the last existing draft
        $transactions = $this->gateway->getContactTransactions($workItem);
        $lastDate     = null;
        foreach ($transactions as $txn) {
            $d = $txn['process_date'] ?? '';
            if ($txn['type'] === 'D' && $txn['active'] === '1' && ($lastDate === null || $d > $lastDate)) {
                $lastDate = $d;
            }
        }

        $extensionResults = [];
        $extensionErrors  = [];

        if ($lastDate !== null) {
            $cursor = new \DateTime($lastDate);
            for ($i = 0; $i < $monthsToExtend; $i++) {
                $cursor->modify('+1 month');
                try {
                    $extensionResults[] = $this->gateway->createDraft($workItem, [
                        'client_id'    => $workItem->contactId,
                        'amount'       => $amount,
                        'process_date' => $cursor->format('Y-m-d'),
                        'memo'         => sprintf('Add Creditor Extension - $%s by System Admin', number_format((float) $amount, 2)),
                    ]);
                } catch (\Throwable $e) {
                    $extensionErrors[] = ['date' => $cursor->format('Y-m-d'), 'error' => $e->getMessage()];
                }
            }
        }

        $noteLines = [
            'Add Creditor and Extend Program Request:',
            'Request Status: ' . (empty($extensionErrors) ? 'Successful' : 'Partial — see errors'),
            'Name: ' . ($workItem->normalizedPayload['name'] ?? 'Client'),
            'Customer Id: ' . $workItem->contactId,
            'Action: Add Creditor and Extend Program',
            'Creditor Name: ' . ($creditorChange['creditor_name'] ?? 'N/A'),
            'Account Number: ' . ($creditorChange['account_number'] ?? 'N/A'),
            'Balance: $' . number_format((float) ($creditorChange['balance'] ?? 0), 2),
            'Months Extended: ' . $monthsToExtend,
            'Drafts Created: ' . count($extensionResults),
            'Monthly Payment: $' . number_format((float) $amount, 2),
            'User: ' . ($workItem->requestedBy ?? 'Client'),
        ];

        $this->gateway->createContactNote($workItem, implode("\n", $noteLines));

        return new PmodResult(
            status:   empty($extensionErrors) ? 'updated' : 'captured_for_manual_review',
            message:  sprintf('Add Creditor and Extend Program: debt created, %d draft(s) added for contact [%s].', count($extensionResults), $workItem->contactId),
            metadata: [
                'action_type'      => $workItem->actionType->value,
                'contact_id'       => $workItem->contactId,
                'debt_id'          => $debtId,
                'creditor_name'    => $creditorChange['creditor_name'] ?? null,
                'months_to_extend' => $monthsToExtend,
                'drafts_created'   => count($extensionResults),
                'extension_errors' => $extensionErrors,
            ],
        );
    }

    /** @param array<string, mixed> $metadata */
    private function capture(PmodWorkItem $workItem, string $message, array $metadata): PmodResult
    {
        $this->gateway->createContactNote($workItem, implode("\n", [
            'PMOD Add Creditor and Extend Program requires manual review.',
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
