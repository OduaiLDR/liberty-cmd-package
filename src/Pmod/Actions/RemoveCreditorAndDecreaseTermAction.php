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
        $creditorChange = $workItem->creditorChange;
        $monthsToDecrease = $workItem->normalizedPayload['months_to_decrease'] ?? null;

        if (empty($creditorChange)) {
            $this->gateway->createContactNote(
                $workItem,
                "Remove Creditor and Decrease Term Failed\nReason: No creditor information provided\nContact ID: {$workItem->contactId}\nRequested by: {$workItem->requestedBy}\nTimestamp: " . now()->toIso8601String()
            );
            return PmodResult::failed('Remove Creditor and Decrease Term requires creditor information.', ['reason' => 'missing_creditor_info', 'action_type' => 'remove_creditor_and_decrease_term', 'contact_id' => $workItem->contactId]);
        }

        if ($monthsToDecrease === null || $monthsToDecrease <= 0) {
            $this->gateway->createContactNote(
                $workItem,
                "Remove Creditor and Decrease Term Failed\nReason: Invalid months to decrease\nContact ID: {$workItem->contactId}\nRequested by: {$workItem->requestedBy}\nMonths: " . ($monthsToDecrease ?? 'missing') . "\nTimestamp: " . now()->toIso8601String()
            );
            return PmodResult::failed('Remove Creditor and Decrease Term requires valid months_to_decrease value.', ['reason' => 'invalid_months', 'action_type' => 'remove_creditor_and_decrease_term', 'contact_id' => $workItem->contactId]);
        }

        if (!$this->allowLiveDraftUpdates || $workItem->dryRun) {
            $this->gateway->createContactNote(
                $workItem,
                "Remove Creditor and Decrease Term - Live Updates Disabled\nWould remove creditor and decrease term but mutations are not allowed\nReason: " . ($workItem->dryRun ? 'Dry run mode' : 'PMOD_LIVE_DRAFT_UPDATES=false') . "\nContact ID: {$workItem->contactId}\nRequested by: {$workItem->requestedBy}\nCreditor: " . ($creditorChange['creditor_name'] ?? 'N/A') . "\nMonths to decrease: {$monthsToDecrease}\nTimestamp: " . now()->toIso8601String()
            );
            return PmodResult::failed('Remove Creditor and Decrease Term would process but live updates are disabled.', ['reason' => $workItem->dryRun ? 'dry_run_only' : 'live_draft_updates_disabled', 'action_type' => 'remove_creditor_and_decrease_term', 'contact_id' => $workItem->contactId]);
        }

        $this->gateway->createContactNote($workItem, "Remove Creditor and Decrease Term Request:\nRequest Status: Captured for Processing\nName: " . ($workItem->normalizedPayload['name'] ?? 'Client') . "\nCustomer Id: {$workItem->contactId}\nAction: Remove Creditor and Decrease Term\nCreditor Name: " . ($creditorChange['creditor_name'] ?? 'N/A') . "\nMonths to Decrease: {$monthsToDecrease}\nUser: " . ($workItem->requestedBy ?? 'Client') . "\n\n⚠️ Manual Processing Required: Creditor removal and term decrease require admin action in CRM.");

        return new PmodResult(
            status: 'captured_for_processing',
            message: sprintf('Remove Creditor and Decrease Term request captured for contact [%s]. Manual processing required.', $workItem->contactId),
            metadata: ['action_type' => $workItem->actionType->value, 'contact_id' => $workItem->contactId, 'creditor_name' => $creditorChange['creditor_name'] ?? null, 'months_to_decrease' => $monthsToDecrease, 'requires_manual_processing' => true]
        );
    }
}
