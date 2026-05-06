<?php

declare(strict_types=1);

namespace Cmd\Reports\Pmod\Actions;

use Cmd\Reports\Pmod\Contracts\PmodActionHandler;
use Cmd\Reports\Pmod\Contracts\PmodExecutionGateway;
use Cmd\Reports\Pmod\Data\PmodResult;
use Cmd\Reports\Pmod\Data\PmodWorkItem;
use Cmd\Reports\Pmod\Enums\PmodActionType;

/**
 * Handles Add Bank Account action - adds new bank account information for a contact.
 */
final class AddBankAccountAction implements PmodActionHandler
{
    public function __construct(
        private readonly PmodExecutionGateway $gateway,
        private readonly bool $allowLiveDraftUpdates = false,
    ) {
    }

    public function actionType(): PmodActionType
    {
        return PmodActionType::ADD_BANK_ACCOUNT;
    }

    public function handle(PmodWorkItem $workItem): PmodResult
    {
        $bankingUpdate = $workItem->bankingUpdate;

        if (empty($bankingUpdate)) {
            $this->gateway->createContactNote(
                $workItem,
                "Add Bank Account Failed\n" .
                "Reason: No banking information provided\n" .
                "Contact ID: {$workItem->contactId}\n" .
                "Requested by: {$workItem->requestedBy}\n" .
                "Timestamp: " . now()->toIso8601String()
            );
            
            return PmodResult::failed(
                'Add Bank Account requires banking information.',
                ['reason' => 'missing_banking_info', 'action_type' => 'add_bank_account', 'contact_id' => $workItem->contactId]
            );
        }

        // Validate required fields
        $requiredFields = ['account_number', 'routing_number', 'account_type'];
        $missingFields = [];
        foreach ($requiredFields as $field) {
            if (empty($bankingUpdate[$field])) {
                $missingFields[] = $field;
            }
        }

        if (!empty($missingFields)) {
            $this->gateway->createContactNote(
                $workItem,
                "Add Bank Account Failed\n" .
                "Reason: Missing required banking fields\n" .
                "Contact ID: {$workItem->contactId}\n" .
                "Requested by: {$workItem->requestedBy}\n" .
                "Missing fields: " . implode(', ', $missingFields) . "\n" .
                "Timestamp: " . now()->toIso8601String()
            );
            
            return PmodResult::failed(
                'Add Bank Account requires: ' . implode(', ', $missingFields),
                ['reason' => 'missing_required_fields', 'missing_fields' => $missingFields, 'action_type' => 'add_bank_account', 'contact_id' => $workItem->contactId]
            );
        }

        if (!$this->allowLiveDraftUpdates || $workItem->dryRun) {
            $this->gateway->createContactNote(
                $workItem,
                "Add Bank Account - Live Updates Disabled\n" .
                "Would add bank account but mutations are not allowed\n" .
                "Reason: " . ($workItem->dryRun ? 'Dry run mode' : 'PMOD_LIVE_DRAFT_UPDATES=false') . "\n" .
                "Contact ID: {$workItem->contactId}\n" .
                "Requested by: {$workItem->requestedBy}\n" .
                "Account Type: " . ($bankingUpdate['account_type'] ?? 'N/A') . "\n" .
                "Timestamp: " . now()->toIso8601String()
            );
            
            return PmodResult::failed(
                'Add Bank Account would add banking information but live updates are disabled.',
                ['reason' => $workItem->dryRun ? 'dry_run_only' : 'live_draft_updates_disabled', 'banking_data' => array_keys($bankingUpdate), 'action_type' => 'add_bank_account', 'contact_id' => $workItem->contactId]
            );
        }

        // Note: Actual bank account addition would require specific DPP API endpoint
        // For now, create comprehensive note documenting the request
        $noteLines = [
            'Add Bank Account Request:',
            'Request Status: Captured for Processing',
            'Name: ' . ($workItem->normalizedPayload['name'] ?? 'Client'),
            'Customer Id: ' . $workItem->contactId,
            'Action: Add Bank Account',
            'Account Type: ' . ($bankingUpdate['account_type'] ?? 'N/A'),
            'Routing Number: ***' . substr($bankingUpdate['routing_number'] ?? '', -4),
            'Account Number: ***' . substr($bankingUpdate['account_number'] ?? '', -4),
        ];

        if (!empty($bankingUpdate['bank_name'])) {
            $noteLines[] = 'Bank Name: ' . $bankingUpdate['bank_name'];
        }

        $noteLines[] = 'User: ' . ($workItem->requestedBy ?? 'Client');
        $noteLines[] = '';
        $noteLines[] = '⚠️ Manual Processing Required: Bank account information captured. Admin must complete setup in CRM.';

        $this->gateway->createContactNote($workItem, implode("\n", $noteLines));

        return new PmodResult(
            status: 'captured_for_processing',
            message: sprintf('Add Bank Account request captured for contact [%s]. Manual processing required.', $workItem->contactId),
            metadata: [
                'action_type' => $workItem->actionType->value,
                'contact_id' => $workItem->contactId,
                'account_type' => $bankingUpdate['account_type'] ?? null,
                'requires_manual_processing' => true,
            ]
        );
    }
}
