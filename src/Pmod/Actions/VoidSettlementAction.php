<?php

declare(strict_types=1);

namespace Cmd\Reports\Pmod\Actions;

use Cmd\Reports\Pmod\Contracts\PmodActionHandler;
use Cmd\Reports\Pmod\Contracts\PmodExecutionGateway;
use Cmd\Reports\Pmod\Data\PmodResult;
use Cmd\Reports\Pmod\Data\PmodWorkItem;
use Cmd\Reports\Pmod\Enums\PmodActionType;

/**
 * Handles Void Settlement action - voids existing settlement offers.
 */
final class VoidSettlementAction implements PmodActionHandler
{
    public function __construct(
        private readonly PmodExecutionGateway $gateway,
        private readonly bool $allowLiveDraftUpdates = false,
    ) {
    }

    public function actionType(): PmodActionType
    {
        return PmodActionType::VOID_SETTLEMENT;
    }

    public function handle(PmodWorkItem $workItem): PmodResult
    {
        $settlementIds = $workItem->settlementIds;

        if (empty($settlementIds)) {
            $this->gateway->createContactNote(
                $workItem,
                "Void Settlement Failed\n" .
                "Reason: No settlement IDs provided\n" .
                "Contact ID: {$workItem->contactId}\n" .
                "Requested by: {$workItem->requestedBy}\n" .
                "Timestamp: " . now()->toIso8601String()
            );
            
            return PmodResult::failed(
                'Void Settlement requires at least one settlement ID.',
                ['reason' => 'missing_settlement_ids', 'action_type' => 'void_settlement', 'contact_id' => $workItem->contactId]
            );
        }

        if (!$this->allowLiveDraftUpdates || $workItem->dryRun) {
            $this->gateway->createContactNote(
                $workItem,
                "Void Settlement - Live Updates Disabled\n" .
                "Would void " . count($settlementIds) . " settlement(s) but mutations are not allowed\n" .
                "Reason: " . ($workItem->dryRun ? 'Dry run mode' : 'PMOD_LIVE_DRAFT_UPDATES=false') . "\n" .
                "Contact ID: {$workItem->contactId}\n" .
                "Requested by: {$workItem->requestedBy}\n" .
                "Settlement IDs: " . implode(', ', $settlementIds) . "\n" .
                "Timestamp: " . now()->toIso8601String()
            );
            
            return PmodResult::failed(
                sprintf('Void Settlement would void %d settlement(s) but live updates are disabled.', count($settlementIds)),
                ['reason' => $workItem->dryRun ? 'dry_run_only' : 'live_draft_updates_disabled', 'settlement_count' => count($settlementIds), 'settlement_ids' => $settlementIds, 'action_type' => 'void_settlement', 'contact_id' => $workItem->contactId]
            );
        }

        $voidResults = [];
        foreach ($settlementIds as $settlementId) {
            try {
                $response = $this->gateway->voidSettlementOffer($workItem, $settlementId);
                $voidResults[] = [
                    'settlement_id' => $settlementId,
                    'status' => 'voided',
                    'response' => $response,
                ];
            } catch (\Exception $e) {
                $voidResults[] = [
                    'settlement_id' => $settlementId,
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                ];
            }
        }

        $successCount = count(array_filter($voidResults, fn($r) => $r['status'] === 'voided'));
        $failCount = count($voidResults) - $successCount;

        $noteLines = [
            'Void Settlement Request:',
            'Request Status: ' . ($failCount === 0 ? 'Successful' : 'Partial Success'),
            'Name: ' . ($workItem->normalizedPayload['name'] ?? 'Client'),
            'Customer Id: ' . $workItem->contactId,
            'Action: Void Settlement',
            'Settlements Voided: ' . $successCount,
            'Failed: ' . $failCount,
        ];

        foreach ($voidResults as $result) {
            if ($result['status'] === 'voided') {
                $noteLines[] = 'Voided Settlement ID: ' . $result['settlement_id'];
            } else {
                $noteLines[] = 'Failed Settlement ID: ' . $result['settlement_id'] . ' - ' . ($result['error'] ?? 'Unknown error');
            }
        }

        $noteLines[] = 'User: ' . ($workItem->requestedBy ?? 'Client');

        $this->gateway->createContactNote($workItem, implode("\n", $noteLines));

        if ($failCount > 0) {
            return PmodResult::failed(
                sprintf('Void Settlement completed with %d success(es) and %d failure(s).', $successCount, $failCount),
                [
                    'action_type' => $workItem->actionType->value,
                    'contact_id' => $workItem->contactId,
                    'success_count' => $successCount,
                    'fail_count' => $failCount,
                    'void_results' => $voidResults,
                ]
            );
        }

        return new PmodResult(
            status: 'updated',
            message: sprintf('Void Settlement successfully voided %d settlement(s) for contact [%s].', $successCount, $workItem->contactId),
            metadata: [
                'action_type' => $workItem->actionType->value,
                'contact_id' => $workItem->contactId,
                'settlements_voided' => $successCount,
                'void_results' => $voidResults,
            ]
        );
    }
}
