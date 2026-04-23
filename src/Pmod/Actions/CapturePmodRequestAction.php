<?php

declare(strict_types=1);

namespace Cmd\Reports\Pmod\Actions;

use Cmd\Reports\Pmod\Contracts\PmodActionHandler;
use Cmd\Reports\Pmod\Contracts\PmodExecutionGateway;
use Cmd\Reports\Pmod\Data\PmodResult;
use Cmd\Reports\Pmod\Data\PmodWorkItem;
use Cmd\Reports\Pmod\Enums\PmodActionType;

final class CapturePmodRequestAction implements PmodActionHandler
{
    public function __construct(
        private readonly PmodExecutionGateway $gateway,
        private readonly PmodActionType $actionType,
    ) {
    }

    public function actionType(): PmodActionType
    {
        return $this->actionType;
    }

    public function handle(PmodWorkItem $workItem): PmodResult
    {
        $noteMetadata = $this->gateway->createContactNote($workItem, $this->buildNote($workItem));

        return new PmodResult(
            status: 'captured_for_manual_review',
            message: sprintf(
                'Captured PMOD action [%s] as a CRM note. Automated transaction mutation is not implemented yet.',
                $workItem->actionType->value,
            ),
            metadata: [
                'action_type' => $workItem->actionType->value,
                'contact_id' => $workItem->contactId,
                'tenant_id' => $workItem->tenantId,
                'idempotency_key' => $workItem->idempotencyKey,
                'gateway' => 'crm_note',
                'note' => $noteMetadata,
            ],
        );
    }

    private function buildNote(PmodWorkItem $workItem): string
    {
        $action = $workItem->actionType->label ?? $workItem->actionType->value;
        $dates  = implode(', ', $workItem->originalDates ?: $workItem->targetDates ?: []);
        $amount = $workItem->amount ?? ($workItem->amounts[0] ?? null);

        $lines = [
            "{$action} — Requires Manual Review",
            '',
            'Contact ID : ' . $workItem->contactId,
            'Requested by : ' . $workItem->requestedBy,
            'Source : ' . $workItem->source,
        ];

        if ($dates) {
            $lines[] = 'Date(s) : ' . $dates;
        }

        if ($amount !== null) {
            $lines[] = 'Amount : $' . $amount;
        }

        if (!empty($workItem->targetDates) && !empty($workItem->originalDates)) {
            $lines[] = 'Rescheduled to : ' . implode(', ', $workItem->targetDates);
        }

        $lines[] = '';
        $lines[] = 'This action could not be processed automatically and has been flagged for manual review.';
        $lines[] = '';
        $lines[] = '- oduai';

        return implode("\n", $lines);
    }

    private function formatValue(mixed $value): string
    {
        if (is_array($value)) {
            if ($value === []) {
                return '[]';
            }

            if (array_is_list($value)) {
                return implode(', ', array_map([$this, 'formatValue'], $value));
            }

            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[unserializable]';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return 'null';
        }

        return trim((string) $value);
    }
}
