<?php

declare(strict_types=1);

namespace Cmd\Reports\Pmod\Actions;

use Cmd\Reports\Pmod\Contracts\{PmodActionHandler, PmodExecutionGateway};
use Cmd\Reports\Pmod\Data\{PmodResult, PmodWorkItem};
use Cmd\Reports\Pmod\Enums\PmodActionType;

final class CaptureSponsorBankingAction implements PmodActionHandler
{
    public function __construct(
        private readonly PmodExecutionGateway $gateway,
        private readonly bool $allowLiveDraftUpdates = false,
    ) {}

    public function actionType(): PmodActionType
    {
        return PmodActionType::CAPTURE_SPONSOR_BANKING;
    }

    public function handle(PmodWorkItem $workItem): PmodResult
    {
        $sponsorUpdate = $workItem->sponsorUpdate;

        if (empty($sponsorUpdate)) {
            return $this->capture($workItem, 'Capture Sponsor Banking requires sponsor banking data.', ['reason' => 'missing_sponsor_data']);
        }

        $requiredFields = ['sponsor_account_number', 'sponsor_routing_number', 'sponsor_account_type'];
        $missingFields  = array_values(array_filter($requiredFields, fn ($f) => empty($sponsorUpdate[$f])));

        if (!empty($missingFields)) {
            return $this->capture($workItem, 'Capture Sponsor Banking requires: ' . implode(', ', $missingFields), [
                'reason'         => 'missing_required_fields',
                'missing_fields' => $missingFields,
            ]);
        }

        if (!$this->allowLiveDraftUpdates || $workItem->dryRun) {
            return $this->capture($workItem, 'Capture Sponsor Banking matched but live updates are disabled.', [
                'reason'       => $workItem->dryRun ? 'dry_run_only' : 'live_draft_updates_disabled',
                'sponsor_data' => array_keys($sponsorUpdate),
            ]);
        }

        $result = $this->gateway->addBankAccount($workItem, [
            'account_number'      => $sponsorUpdate['sponsor_account_number'],
            'routing_number'      => $sponsorUpdate['sponsor_routing_number'],
            'account_type'        => $sponsorUpdate['sponsor_account_type'],
            'bank_name'           => $sponsorUpdate['sponsor_bank_name'] ?? null,
            'account_holder_name' => $sponsorUpdate['sponsor_name'] ?? null,
            'sponsor'             => true,
            'sponsor_id'          => $sponsorUpdate['sponsor_id'] ?? null,
        ]);

        $noteLines = [
            'Capture Sponsor Banking Request:',
            'Request Status: Successful',
            'Name: ' . ($workItem->normalizedPayload['name'] ?? 'Client'),
            'Customer Id: ' . $workItem->contactId,
            'Action: Capture Sponsor Banking',
            'Sponsor: ' . ($sponsorUpdate['sponsor_name'] ?? 'N/A'),
            'Account Type: ' . $sponsorUpdate['sponsor_account_type'],
            'Routing Number: ***' . substr($sponsorUpdate['sponsor_routing_number'], -4),
            'Account Number: ***' . substr($sponsorUpdate['sponsor_account_number'], -4),
            'User: ' . ($workItem->requestedBy ?? 'Client'),
        ];

        $this->gateway->createContactNote($workItem, implode("\n", $noteLines));

        return new PmodResult(
            status:   'updated',
            message:  sprintf('Capture Sponsor Banking completed for contact [%s].', $workItem->contactId),
            metadata: [
                'action_type'         => $workItem->actionType->value,
                'contact_id'          => $workItem->contactId,
                'sponsor_name'        => $sponsorUpdate['sponsor_name'] ?? null,
                'bank_account_result' => $result,
            ],
        );
    }

    /** @param array<string, mixed> $metadata */
    private function capture(PmodWorkItem $workItem, string $message, array $metadata): PmodResult
    {
        $this->gateway->createContactNote($workItem, implode("\n", [
            'PMOD Capture Sponsor Banking requires manual review.',
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
