<?php

declare(strict_types=1);

namespace Cmd\Reports\Pmod\Actions;

use Cmd\Reports\Pmod\Contracts\PmodActionHandler;
use Cmd\Reports\Pmod\Contracts\PmodExecutionGateway;
use Cmd\Reports\Pmod\Data\PmodResult;
use Cmd\Reports\Pmod\Data\PmodWorkItem;
use Cmd\Reports\Pmod\Enums\PmodActionType;

final class AddBankAccountAction implements PmodActionHandler
{
    public function __construct(
        private readonly PmodExecutionGateway $gateway,
        private readonly bool $allowLiveDraftUpdates = false,
    ) {}

    public function actionType(): PmodActionType
    {
        return PmodActionType::ADD_BANK_ACCOUNT;
    }

    public function handle(PmodWorkItem $workItem): PmodResult
    {
        $bankingUpdate = $workItem->bankingUpdate;

        if (empty($bankingUpdate)) {
            return $this->capture($workItem, 'Add Bank Account requires banking information.', ['reason' => 'missing_banking_info']);
        }

        $requiredFields = ['account_number', 'routing_number', 'account_type'];
        $missingFields  = array_values(array_filter($requiredFields, fn ($f) => empty($bankingUpdate[$f])));

        if (!empty($missingFields)) {
            return $this->capture($workItem, 'Add Bank Account requires: ' . implode(', ', $missingFields), [
                'reason'         => 'missing_required_fields',
                'missing_fields' => $missingFields,
            ]);
        }

        if (!$this->allowLiveDraftUpdates || $workItem->dryRun) {
            return $this->capture($workItem, 'Add Bank Account matched but live updates are disabled.', [
                'reason'       => $workItem->dryRun ? 'dry_run_only' : 'live_draft_updates_disabled',
                'banking_data' => array_keys($bankingUpdate),
            ]);
        }

        $result = $this->gateway->addBankAccount($workItem, [
            'account_number'      => $bankingUpdate['account_number'],
            'routing_number'      => $bankingUpdate['routing_number'],
            'account_type'        => $bankingUpdate['account_type'],
            'bank_name'           => $bankingUpdate['bank_name'] ?? null,
            'account_holder_name' => $bankingUpdate['account_holder_name'] ?? $bankingUpdate['name_on_account'] ?? null,
        ]);

        $noteLines = [
            'Add Bank Account Request:',
            'Request Status: Successful',
            'Name: ' . ($workItem->normalizedPayload['name'] ?? 'Client'),
            'Customer Id: ' . $workItem->contactId,
            'Action: Add Bank Account',
            'Account Type: ' . $bankingUpdate['account_type'],
            'Routing Number: ***' . substr($bankingUpdate['routing_number'], -4),
            'Account Number: ***' . substr($bankingUpdate['account_number'], -4),
            'User: ' . ($workItem->requestedBy ?? 'Client'),
        ];

        $this->gateway->createContactNote($workItem, implode("\n", $noteLines));

        return new PmodResult(
            status:   'updated',
            message:  sprintf('Add Bank Account completed for contact [%s].', $workItem->contactId),
            metadata: [
                'action_type'         => $workItem->actionType->value,
                'contact_id'          => $workItem->contactId,
                'account_type'        => $bankingUpdate['account_type'],
                'bank_account_result' => $result,
            ],
        );
    }

    /** @param array<string, mixed> $metadata */
    private function capture(PmodWorkItem $workItem, string $message, array $metadata): PmodResult
    {
        $this->gateway->createContactNote($workItem, implode("\n", [
            'PMOD Add Bank Account requires manual review.',
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
