<?php

declare(strict_types=1);

namespace Cmd\Reports\Pmod\Actions;

use Cmd\Reports\Pmod\Contracts\PmodActionHandler;
use Cmd\Reports\Pmod\Contracts\PmodExecutionGateway;
use Cmd\Reports\Pmod\Data\PmodResult;
use Cmd\Reports\Pmod\Data\PmodWorkItem;
use Cmd\Reports\Pmod\Enums\PmodActionType;
use Cmd\Reports\Pmod\Support\PmodTransactionMatcher;

final class PmodIncreasePaymentsAndExtendProgramAction implements PmodActionHandler
{
    public function __construct(
        private readonly PmodExecutionGateway $gateway,
        private readonly bool $allowLiveDraftUpdates = false,
    ) {
    }

    public function actionType(): PmodActionType
    {
        return PmodActionType::PMOD_INCREASE_PAYMENTS_AND_EXTEND_PROGRAM;
    }

    public function handle(PmodWorkItem $workItem): PmodResult
    {
        $increaseAmount = $workItem->increaseAmount ?? $workItem->amount;
        $extensionAmount = $workItem->paymentChange['extended_amount'] ?? $increaseAmount;
        $months = $workItem->paymentChange['extend_months'] ?? $workItem->paymentChange['months_to_extend'] ?? null;

        if ($increaseAmount === null || (float) $increaseAmount <= 0) {
            return $this->capture($workItem, 'PMOD Increase And Extend requires amount.', ['reason' => 'missing_required_fields']);
        }

        if ($months === null || (int) $months <= 0) {
            return $this->capture($workItem, 'PMOD Increase And Extend requires extension duration.', ['reason' => 'missing_extension_duration']);
        }

        $futureDrafts = $this->futureDrafts($this->gateway->getContactTransactions($workItem));
        $extensionDates = $this->extensionDates($futureDrafts, (int) $months);

        if ($extensionDates === []) {
            return $this->capture($workItem, 'PMOD Increase And Extend could not resolve extension start.', ['reason' => 'cannot_resolve_extension_start']);
        }

        if (! $this->allowLiveDraftUpdates || $workItem->dryRun) {
            return $this->capture($workItem, 'PMOD Increase And Extend planned updates but live updates are disabled.', [
                'reason' => $workItem->dryRun ? 'dry_run_only' : 'live_draft_updates_disabled',
                'existing_draft_count' => count($futureDrafts),
                'extension_count' => count($extensionDates),
            ]);
        }

        $updates = [];
        $creates = [];
        $errors = [];

        foreach ($futureDrafts as $draft) {
            $draftId = PmodTransactionMatcher::extractAuthoritativeDraftId($draft);
            if ($draftId === null) {
                $errors[] = ['reason' => 'missing_draft_id'];
                continue;
            }

            try {
                $updates[] = $this->gateway->updateDraft($workItem, $draftId, [
                    'client_id' => $workItem->contactId,
                    'amount' => $increaseAmount,
                    'process_date' => $draft['process_date'] ?? null,
                    'memo' => sprintf('PMOD Increase And Extend - Updated by %s', $workItem->requestedBy ?: 'System'),
                ]);
            } catch (\Throwable $e) {
                $errors[] = ['draft_id' => $draftId, 'reason' => $e->getMessage()];
            }
        }

        foreach ($extensionDates as $date) {
            try {
                $creates[] = $this->gateway->createDraft($workItem, [
                    'client_id' => $workItem->contactId,
                    'amount' => $extensionAmount,
                    'process_date' => $date,
                    'memo' => sprintf('PMOD Increase And Extend - Extended by %s', $workItem->requestedBy ?: 'System'),
                    'type' => 'monthly_draft',
                ]);
            } catch (\Throwable $e) {
                $errors[] = ['date' => $date, 'reason' => $e->getMessage()];
            }
        }

        $this->gateway->createContactNote($workItem, 'PMOD Increase Payments And Extend Program Request:' . "\n" . 'Request Status: Successful');

        return new PmodResult(
            status: $errors === [] ? 'updated' : 'partial_update',
            message: sprintf('PMOD Increase And Extend updated %d draft(s) and created %d draft(s).', count($updates), count($creates)),
            metadata: [
                'action_type' => $workItem->actionType->value,
                'contact_id' => $workItem->contactId,
                'drafts_updated' => count($updates),
                'drafts_created' => count($creates),
                'update_results' => $updates,
                'create_results' => $creates,
                'errors' => $errors,
            ],
        );
    }

    /** @return list<array<string, mixed>> */
    private function futureDrafts(array $transactions): array
    {
        $today = date('Y-m-d');

        return array_values(array_filter($transactions, static fn (array $tx): bool =>
            strtoupper(trim((string) ($tx['type'] ?? $tx['trans_type'] ?? ''))) === 'D'
            && trim((string) ($tx['process_date'] ?? '')) >= $today
            && empty($tx['cancelled'])
            && empty($tx['completed'])
        ));
    }

    /** @param list<array<string, mixed>> $futureDrafts @return list<string> */
    private function extensionDates(array $futureDrafts, int $months): array
    {
        $last = null;
        foreach ($futureDrafts as $draft) {
            $date = trim((string) ($draft['process_date'] ?? ''));
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

    /** @param array<string, mixed> $metadata */
    private function capture(PmodWorkItem $workItem, string $message, array $metadata): PmodResult
    {
        $note = $this->gateway->createContactNote($workItem, "PMOD Increase And Extend requires manual review.\nReason: {$message}\n\n- oduai");

        return new PmodResult(
            status: 'captured_for_manual_review',
            message: $message,
            metadata: [...$metadata, 'action_type' => $workItem->actionType->value, 'contact_id' => $workItem->contactId, 'note' => $note],
        );
    }
}
