<?php

declare(strict_types=1);

namespace Cmd\Reports\Pmod\Actions;

use Cmd\Reports\Pmod\Contracts\PmodActionHandler;
use Cmd\Reports\Pmod\Contracts\PmodExecutionGateway;
use Cmd\Reports\Pmod\Data\PmodResult;
use Cmd\Reports\Pmod\Data\PmodWorkItem;
use Cmd\Reports\Pmod\Enums\PmodActionType;

/**
 * Handles Add Creditor and Increase Payment action - adds a new creditor and increases payment amount.
 */
final class AddCreditorAndIncreasePaymentAction implements PmodActionHandler
{
    public function __construct(
        private readonly PmodExecutionGateway $gateway,
        private readonly bool $allowLiveDraftUpdates = false,
    ) {
    }

    public function actionType(): PmodActionType
    {
        return PmodActionType::ADD_CREDITOR_AND_INCREASE_PAYMENT;
    }

    public function handle(PmodWorkItem $workItem): PmodResult
    {
        $creditorChange = $workItem->creditorChange;
        $newPaymentAmount = $workItem->amount;

        if (empty($creditorChange)) {
            $this->gateway->createContactNote(
                $workItem,
                "Add Creditor and Increase Payment Failed\n" .
                "Reason: No creditor information provided\n" .
                "Contact ID: {$workItem->contactId}\n" .
                "Requested by: {$workItem->requestedBy}\n" .
                "Timestamp: " . now()->toIso8601String()
            );
            
            return PmodResult::failed(
                'Add Creditor and Increase Payment requires creditor information.',
                ['reason' => 'missing_creditor_info', 'action_type' => 'add_creditor_and_increase_payment', 'contact_id' => $workItem->contactId]
            );
        }

        if ($newPaymentAmount === null || $newPaymentAmount <= 0) {
            $this->gateway->createContactNote(
                $workItem,
                "Add Creditor and Increase Payment Failed\n" .
                "Reason: Invalid payment amount\n" .
                "Contact ID: {$workItem->contactId}\n" .
                "Requested by: {$workItem->requestedBy}\n" .
                "Amount: " . ($newPaymentAmount ?? 'missing') . "\n" .
                "Timestamp: " . now()->toIso8601String()
            );
            
            return PmodResult::failed(
                'Add Creditor and Increase Payment requires a valid payment amount.',
                ['reason' => 'invalid_payment_amount', 'amount' => $newPaymentAmount, 'action_type' => 'add_creditor_and_increase_payment', 'contact_id' => $workItem->contactId]
            );
        }

        if (!$this->allowLiveDraftUpdates || $workItem->dryRun) {
            $this->gateway->createContactNote(
                $workItem,
                "Add Creditor and Increase Payment - Live Updates Disabled\n" .
                "Would add creditor and increase payment but mutations are not allowed\n" .
                "Reason: " . ($workItem->dryRun ? 'Dry run mode' : 'PMOD_LIVE_DRAFT_UPDATES=false') . "\n" .
                "Contact ID: {$workItem->contactId}\n" .
                "Requested by: {$workItem->requestedBy}\n" .
                "Creditor: " . ($creditorChange['creditor_name'] ?? 'N/A') . "\n" .
                "New Payment Amount: \$" . number_format((float) $newPaymentAmount, 2) . "\n" .
                "Timestamp: " . now()->toIso8601String()
            );
            
            return PmodResult::failed(
                'Add Creditor and Increase Payment would process but live updates are disabled.',
                ['reason' => $workItem->dryRun ? 'dry_run_only' : 'live_draft_updates_disabled', 'new_amount' => $newPaymentAmount, 'action_type' => 'add_creditor_and_increase_payment', 'contact_id' => $workItem->contactId]
            );
        }

        $noteLines = [
            'Add Creditor and Increase Payment Request:',
            'Request Status: Captured for Processing',
            'Name: ' . ($workItem->normalizedPayload['name'] ?? 'Client'),
            'Customer Id: ' . $workItem->contactId,
            'Action: Add Creditor and Increase Payment',
            'Creditor Name: ' . ($creditorChange['creditor_name'] ?? 'N/A'),
            'Account Number: ' . ($creditorChange['account_number'] ?? 'N/A'),
            'Balance: $' . number_format((float) ($creditorChange['balance'] ?? 0), 2),
            'New Monthly Payment: $' . number_format((float) $newPaymentAmount, 2),
            'User: ' . ($workItem->requestedBy ?? 'Client'),
            '',
            '⚠️ Manual Processing Required: Creditor addition and payment increase require admin action in CRM.',
        ];

        $this->gateway->createContactNote($workItem, implode("\n", $noteLines));

        return new PmodResult(
            status: 'captured_for_processing',
            message: sprintf('Add Creditor and Increase Payment request captured for contact [%s]. Manual processing required.', $workItem->contactId),
            metadata: [
                'action_type' => $workItem->actionType->value,
                'contact_id' => $workItem->contactId,
                'creditor_name' => $creditorChange['creditor_name'] ?? null,
                'new_payment_amount' => $newPaymentAmount,
                'requires_manual_processing' => true,
            ]
        );
    }
}
