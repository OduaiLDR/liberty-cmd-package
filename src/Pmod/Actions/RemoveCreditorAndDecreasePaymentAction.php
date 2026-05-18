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
        $creditorChange = $workItem->creditorChange;
        $newPaymentAmount = $workItem->amount;

        if (empty($creditorChange)) {
            $this->gateway->createContactNote($workItem, "Remove Creditor and Decrease Payment Failed\nReason: No creditor information provided\nContact ID: {$workItem->contactId}\nRequested by: {$workItem->requestedBy}\nTimestamp: " . now()->toIso8601String());
            return PmodResult::failed('Remove Creditor and Decrease Payment requires creditor information.', ['reason' => 'missing_creditor_info', 'action_type' => 'remove_creditor_and_decrease_payment', 'contact_id' => $workItem->contactId]);
        }

        if ($newPaymentAmount === null || $newPaymentAmount <= 0) {
            $this->gateway->createContactNote($workItem, "Remove Creditor and Decrease Payment Failed\nReason: Invalid payment amount\nContact ID: {$workItem->contactId}\nRequested by: {$workItem->requestedBy}\nAmount: " . ($newPaymentAmount ?? 'missing') . "\nTimestamp: " . now()->toIso8601String());
            return PmodResult::failed('Remove Creditor and Decrease Payment requires a valid payment amount.', ['reason' => 'invalid_payment_amount', 'action_type' => 'remove_creditor_and_decrease_payment', 'contact_id' => $workItem->contactId]);
        }

        if (!$this->allowLiveDraftUpdates || $workItem->dryRun) {
            $this->gateway->createContactNote($workItem, "Remove Creditor and Decrease Payment - Live Updates Disabled\nWould remove creditor and decrease payment but mutations are not allowed\nReason: " . ($workItem->dryRun ? 'Dry run mode' : 'PMOD_LIVE_DRAFT_UPDATES=false') . "\nContact ID: {$workItem->contactId}\nRequested by: {$workItem->requestedBy}\nCreditor: " . ($creditorChange['creditor_name'] ?? 'N/A') . "\nNew Payment: \$" . number_format((float) $newPaymentAmount, 2) . "\nTimestamp: " . now()->toIso8601String());
            return PmodResult::failed('Remove Creditor and Decrease Payment would process but live updates are disabled.', ['reason' => $workItem->dryRun ? 'dry_run_only' : 'live_draft_updates_disabled', 'action_type' => 'remove_creditor_and_decrease_payment', 'contact_id' => $workItem->contactId]);
        }

        $this->gateway->createContactNote($workItem, "Remove Creditor and Decrease Payment Request:\nRequest Status: Captured for Processing\nName: " . ($workItem->normalizedPayload['name'] ?? 'Client') . "\nCustomer Id: {$workItem->contactId}\nAction: Remove Creditor and Decrease Payment\nCreditor Name: " . ($creditorChange['creditor_name'] ?? 'N/A') . "\nNew Monthly Payment: \$" . number_format((float) $newPaymentAmount, 2) . "\nUser: " . ($workItem->requestedBy ?? 'Client') . "\n\n⚠️ Manual Processing Required: Creditor removal and payment decrease require admin action in CRM.");

        return new PmodResult(status: 'captured_for_processing', message: sprintf('Remove Creditor and Decrease Payment request captured for contact [%s]. Manual processing required.', $workItem->contactId), metadata: ['action_type' => $workItem->actionType->value, 'contact_id' => $workItem->contactId, 'creditor_name' => $creditorChange['creditor_name'] ?? null, 'new_payment_amount' => $newPaymentAmount, 'requires_manual_processing' => true]);
    }
}
