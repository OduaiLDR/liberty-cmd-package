<?php

declare(strict_types=1);

namespace Cmd\Reports\Pmod\Actions;

use Cmd\Reports\Pmod\Contracts\{PmodActionHandler, PmodExecutionGateway};
use Cmd\Reports\Pmod\Data\{PmodResult, PmodWorkItem};
use Cmd\Reports\Pmod\Enums\PmodActionType;

final class CaptureSponsorBankingAction implements PmodActionHandler
{
    public function __construct(private readonly PmodExecutionGateway $gateway, private readonly bool $allowLiveDraftUpdates = false) {}

    public function actionType(): PmodActionType { return PmodActionType::CAPTURE_SPONSOR_BANKING; }

    public function handle(PmodWorkItem $workItem): PmodResult
    {
        $sponsorUpdate = $workItem->sponsorUpdate;
        if (empty($sponsorUpdate)) {
            $this->gateway->createContactNote($workItem, "Capture Sponsor Banking Failed\nReason: No sponsor banking data\nContact: {$workItem->contactId}\nRequested by: {$workItem->requestedBy}\nTimestamp: " . now()->toIso8601String());
            return PmodResult::failed('Capture Sponsor Banking requires sponsor banking data.', ['reason' => 'missing_sponsor_data', 'action_type' => 'capture_sponsor_banking', 'contact_id' => $workItem->contactId]);
        }

        $this->gateway->createContactNote($workItem, "Capture Sponsor Banking Request:\nStatus: Captured for Processing\nName: " . ($workItem->normalizedPayload['name'] ?? 'Client') . "\nContact: {$workItem->contactId}\nSponsor Info Captured\nUser: {$workItem->requestedBy}\n\n⚠️ Manual Processing Required");
        return new PmodResult('captured_for_processing', sprintf('Capture Sponsor Banking request for [%s]. Manual processing required.', $workItem->contactId), ['action_type' => $workItem->actionType->value, 'contact_id' => $workItem->contactId, 'requires_manual_processing' => true]);
    }
}
