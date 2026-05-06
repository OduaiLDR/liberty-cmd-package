<?php

declare(strict_types=1);

namespace Cmd\Reports\Pmod\Actions;

use Cmd\Reports\Pmod\Contracts\PmodActionHandler;
use Cmd\Reports\Pmod\Contracts\PmodExecutionGateway;
use Cmd\Reports\Pmod\Data\PmodResult;
use Cmd\Reports\Pmod\Data\PmodWorkItem;
use Cmd\Reports\Pmod\Enums\PmodActionType;

/**
 * Handles Add Creditor and Extend Program action - adds a new creditor and extends program duration.
 */
final class AddCreditorAndExtendProgramAction implements PmodActionHandler
{
    public function __construct(
        private readonly PmodExecutionGateway $gateway,
        private readonly bool $allowLiveDraftUpdates = false,
    ) {
    }

    public function actionType(): PmodActionType
    {
        return PmodActionType::ADD_CREDITOR_AND_EXTEND_PROGRAM;
    }

    public function handle(PmodWorkItem $workItem): PmodResult
    {
        $creditorChange = $workItem->creditorChange;
        $monthsToExtend = $workItem->normalizedPayload['months_to_extend'] ?? null;
        $amount = $workItem->amount;

        if (empty($creditorChange)) {
            $this->gateway->createContactNote(
                $workItem,
                "Add Creditor and Extend Program Failed\n" .
                "Reason: No creditor information provided\n" .
                "Contact ID: {$workItem->contactId}\n" .
                "Requested by: {$workItem->requestedBy}\n" .
                "Timestamp: " . now()->toIso8601String()
            );
            
            return PmodResult::failed(
                'Add Creditor and Extend Program requires creditor information.',
                ['reason' => 'missing_creditor_info', 'action_type' => 'add_creditor_and_extend_program', 'contact_id' => $workItem->contactId]
            );
        }

        if ($monthsToExtend === null || $monthsToExtend <= 0) {
            $this->gateway->createContactNote(
                $workItem,
                "Add Creditor and Extend Program Failed\n" .
                "Reason: Invalid months to extend\n" .
                "Contact ID: {$workItem->contactId}\n" .
                "Requested by: {$workItem->requestedBy}\n" .
                "Months to extend: " . ($monthsToExtend ?? 'missing') . "\n" .
                "Timestamp: " . now()->toIso8601String()
            );
            
            return PmodResult::failed(
                'Add Creditor and Extend Program requires valid months_to_extend value.',
                ['reason' => 'invalid_months', 'months_to_extend' => $monthsToExtend, 'action_type' => 'add_creditor_and_extend_program', 'contact_id' => $workItem->contactId]
            );
        }

        if (!$this->allowLiveDraftUpdates || $workItem->dryRun) {
            $this->gateway->createContactNote(
                $workItem,
                "Add Creditor and Extend Program - Live Updates Disabled\n" .
                "Would add creditor and extend program but mutations are not allowed\n" .
                "Reason: " . ($workItem->dryRun ? 'Dry run mode' : 'PMOD_LIVE_DRAFT_UPDATES=false') . "\n" .
                "Contact ID: {$workItem->contactId}\n" .
                "Requested by: {$workItem->requestedBy}\n" .
                "Creditor: " . ($creditorChange['creditor_name'] ?? 'N/A') . "\n" .
                "Months to extend: {$monthsToExtend}\n" .
                "Timestamp: " . now()->toIso8601String()
            );
            
            return PmodResult::failed(
                'Add Creditor and Extend Program would process but live updates are disabled.',
                ['reason' => $workItem->dryRun ? 'dry_run_only' : 'live_draft_updates_disabled', 'months_to_extend' => $monthsToExtend, 'action_type' => 'add_creditor_and_extend_program', 'contact_id' => $workItem->contactId]
            );
        }

        $noteLines = [
            'Add Creditor and Extend Program Request:',
            'Request Status: Captured for Processing',
            'Name: ' . ($workItem->normalizedPayload['name'] ?? 'Client'),
            'Customer Id: ' . $workItem->contactId,
            'Action: Add Creditor and Extend Program',
            'Creditor Name: ' . ($creditorChange['creditor_name'] ?? 'N/A'),
            'Account Number: ' . ($creditorChange['account_number'] ?? 'N/A'),
            'Balance: $' . number_format((float) ($creditorChange['balance'] ?? 0), 2),
            'Months to Extend: ' . $monthsToExtend,
        ];

        if ($amount) {
            $noteLines[] = 'Monthly Payment Amount: $' . number_format((float) $amount, 2);
        }

        $noteLines[] = 'User: ' . ($workItem->requestedBy ?? 'Client');
        $noteLines[] = '';
        $noteLines[] = '⚠️ Manual Processing Required: Creditor addition and program extension require admin action in CRM.';

        $this->gateway->createContactNote($workItem, implode("\n", $noteLines));

        return new PmodResult(
            status: 'captured_for_processing',
            message: sprintf('Add Creditor and Extend Program request captured for contact [%s]. Manual processing required.', $workItem->contactId),
            metadata: [
                'action_type' => $workItem->actionType->value,
                'contact_id' => $workItem->contactId,
                'creditor_name' => $creditorChange['creditor_name'] ?? null,
                'months_to_extend' => $monthsToExtend,
                'requires_manual_processing' => true,
            ]
        );
    }
}
