<?php

declare(strict_types=1);

namespace Cmd\Reports\Pmod\Actions;

use Cmd\Reports\Pmod\Contracts\PmodActionHandler;
use Cmd\Reports\Pmod\Contracts\PmodExecutionGateway;
use Cmd\Reports\Pmod\Data\PmodResult;
use Cmd\Reports\Pmod\Data\PmodWorkItem;
use Cmd\Reports\Pmod\Enums\PmodActionType;

final class PmodExtendProgramAction implements PmodActionHandler
{
    public function __construct(
        private readonly PmodExecutionGateway $gateway,
        private readonly bool $allowLiveDraftUpdates = false,
    ) {
    }

    public function actionType(): PmodActionType
    {
        return PmodActionType::PMOD_EXTEND_PROGRAM;
    }

    public function handle(PmodWorkItem $workItem): PmodResult
    {
        $amount = $workItem->paymentChange['extended_amount'] ?? $workItem->amount;
        $months = $this->extensionMonths($workItem);
        $explicitDates = $this->explicitExtensionDates($workItem);

        if ($amount === null || (float) $amount <= 0) {
            return $this->capture($workItem, 'PMOD Extend Program requires a payment amount.', ['reason' => 'missing_required_fields']);
        }

        if ($months === null && $explicitDates === []) {
            return $this->capture($workItem, 'PMOD Extend Program requires extension duration.', ['reason' => 'missing_extension_duration']);
        }

        if ($months !== null && $months > 60) {
            return $this->capture($workItem, 'PMOD Extend Program exceeds safety cap.', ['reason' => 'extension_exceeds_safety_cap']);
        }

        $transactions = $this->gateway->getContactTransactions($workItem);
        $extensionDates = $explicitDates ?: $this->generateAfterLastDraft($transactions, (int) $months);

        if ($extensionDates === []) {
            return $this->capture($workItem, 'PMOD Extend Program could not resolve extension start.', ['reason' => 'cannot_resolve_extension_start']);
        }

        if (! $this->allowLiveDraftUpdates || $workItem->dryRun) {
            return $this->capture($workItem, 'PMOD Extend Program planned drafts but live updates are disabled.', [
                'reason' => $workItem->dryRun ? 'dry_run_only' : 'live_draft_updates_disabled',
                'planned_dates' => $extensionDates,
            ]);
        }

        $created = [];
        $errors = [];
        foreach ($extensionDates as $date) {
            try {
                $created[] = $this->gateway->createDraft($workItem, [
                    'client_id' => $workItem->contactId,
                    'amount' => $amount,
                    'process_date' => $date,
                    'memo' => sprintf('PMOD Extend Program - Extended by %s', $workItem->requestedBy ?: 'System'),
                    'type' => 'monthly_draft',
                ]);
            } catch (\Throwable $e) {
                $errors[] = ['date' => $date, 'reason' => $e->getMessage()];
            }
        }

        $this->gateway->createContactNote($workItem, 'PMOD Extend Program Request:' . "\n" . 'Request Status: Successful');

        return new PmodResult(
            status: $errors === [] ? 'updated' : 'partial_update',
            message: sprintf('PMOD Extend Program created %d draft(s).', count($created)),
            metadata: [
                'action_type' => $workItem->actionType->value,
                'contact_id' => $workItem->contactId,
                'drafts_created' => count($created),
                'create_results' => $created,
                'errors' => $errors,
            ],
        );
    }

    private function extensionMonths(PmodWorkItem $workItem): ?int
    {
        $value = $workItem->paymentChange['extend_months']
            ?? $workItem->paymentChange['months_to_extend']
            ?? $workItem->normalizedPayload['months_to_extend']
            ?? null;

        return $value === null ? null : (int) $value;
    }

    /** @return list<string> */
    private function explicitExtensionDates(PmodWorkItem $workItem): array
    {
        $start = $workItem->paymentChange['extended_start_date'] ?? null;
        $end = $workItem->paymentChange['extended_end_date'] ?? null;

        if ($start === null || $end === null) {
            return [];
        }

        return $this->monthlyDates((string) $start, (string) $end);
    }

    /** @param list<array<string, mixed>> $transactions @return list<string> */
    private function generateAfterLastDraft(array $transactions, int $months): array
    {
        $last = null;
        foreach ($transactions as $tx) {
            $date = trim((string) ($tx['process_date'] ?? ''));
            if ($date !== '' && ($last === null || $date > $last)) {
                $last = $date;
            }
        }

        if ($last === null) {
            return [];
        }

        $base = new \DateTimeImmutable($last);
        $dates = [];
        for ($i = 1; $i <= $months; $i++) {
            $dates[] = $base->modify("+{$i} month")->format('Y-m-d');
        }

        return $dates;
    }

    /** @return list<string> */
    private function monthlyDates(string $start, string $end): array
    {
        $cursor = new \DateTimeImmutable($start);
        $endDate = new \DateTimeImmutable($end);
        $dates = [];

        while ($cursor <= $endDate) {
            $dates[] = $cursor->format('Y-m-d');
            $cursor = $cursor->modify('+1 month');
        }

        return $dates;
    }

    /** @param array<string, mixed> $metadata */
    private function capture(PmodWorkItem $workItem, string $message, array $metadata): PmodResult
    {
        $note = $this->gateway->createContactNote($workItem, "PMOD Extend Program requires manual review.\nReason: {$message}\n\n- oduai");

        return new PmodResult(
            status: 'captured_for_manual_review',
            message: $message,
            metadata: [...$metadata, 'action_type' => $workItem->actionType->value, 'contact_id' => $workItem->contactId, 'note' => $note],
        );
    }
}
