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

        $lines = [...$lines, ...$this->detailLines($workItem)];

        $lines[] = '';
        $lines[] = 'This action could not be processed automatically and has been flagged for manual review.';
        $lines[] = '';
        $lines[] = '- oduai';

        return implode("\n", $lines);
    }

    /**
     * Action-specific detail a reviewer needs to complete the work by hand.
     * Mirrors PmodEmailNotificationService::capturedBody() so the CRM note and
     * the manual-review email carry the same facts - the note previously held
     * only contact/requested-by/source/amount, which is not enough to action a
     * creditor or banking change.
     *
     * Bank and routing numbers are masked: createContactNote() posts with
     * public = true, so this note is visible to the client.
     *
     * @return list<string>
     */
    private function detailLines(PmodWorkItem $workItem): array
    {
        $banking  = $workItem->bankingUpdate;
        $creditor = $workItem->creditorChange;
        $sponsor  = $workItem->sponsorUpdate;

        // Flat month counts reach us inside creditor_change (the parser folds
        // them in) as well as at the top level, so check both.
        $months = $creditor['months_to_extend']
            ?? $creditor['months_to_decrease']
            ?? $workItem->normalizedPayload['months_to_extend']
            ?? $workItem->normalizedPayload['months_to_decrease']
            ?? null;

        $rows = match ($workItem->actionType->value) {
            'add_bank_account' => [
                'Bank Name'      => $banking['bank_name'] ?? null,
                'Account Type'   => $banking['account_type'] ?? null,
                'Routing Number' => $this->mask($banking['routing_number'] ?? null),
                'Account Number' => $this->mask($banking['account_number'] ?? null),
                'Account Holder' => $banking['account_holder_name'] ?? $banking['name_on_account'] ?? null,
            ],
            'capture_sponsor_banking' => [
                'Sponsor Name'   => $sponsor['sponsor_name'] ?? null,
                'Bank Name'      => $sponsor['sponsor_bank_name'] ?? null,
                'Account Type'   => $sponsor['sponsor_account_type'] ?? null,
                'Routing Number' => $this->mask($sponsor['sponsor_routing_number'] ?? null),
                'Account Number' => $this->mask($sponsor['sponsor_account_number'] ?? null),
            ],
            'add_creditor_and_increase_payment', 'add_creditor_and_extend_program' => [
                'Creditor Name'      => $creditor['creditor_name'] ?? null,
                'Creditor ID'        => $creditor['creditor_id'] ?? null,
                'Creditor Account #' => $creditor['account_number'] ?? null,
                'Balance'            => $this->money($creditor['balance'] ?? null),
                'Months to Extend'   => $months,
            ],
            'remove_creditor_and_decrease_payment', 'remove_creditor_and_decrease_term' => [
                'Creditor Name'      => $creditor['creditor_name'] ?? null,
                'Creditor ID'        => $creditor['creditor_id'] ?? null,
                'Creditor Account #' => $creditor['account_number'] ?? null,
                'Months to Decrease' => $months,
            ],
            default => [],
        };

        $lines = [];

        foreach ($rows as $label => $value) {
            $value = trim((string) ($value ?? ''));

            if ($value !== '') {
                $lines[] = $label . ' : ' . $value;
            }
        }

        return $lines;
    }

    private function mask(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : '***' . substr($value, -4);
    }

    private function money(mixed $value): ?string
    {
        if ($value === null || ! is_numeric($value)) {
            return null;
        }

        return '$' . number_format((float) $value, 2);
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
