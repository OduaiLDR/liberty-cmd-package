<?php

declare(strict_types=1);

namespace Cmd\Reports\Pmod\Actions;

use Cmd\Reports\Pmod\Contracts\PmodActionHandler;
use Cmd\Reports\Pmod\Contracts\PmodExecutionGateway;
use Cmd\Reports\Pmod\Data\PmodResult;
use Cmd\Reports\Pmod\Data\PmodWorkItem;
use Cmd\Reports\Pmod\Enums\PmodActionType;
use Cmd\Reports\Pmod\Enums\PmodCompany;
use Cmd\Reports\Pmod\Support\SettlementApprovalRules;
use Illuminate\Support\Carbon;

final class SettlementApprovalAction implements PmodActionHandler
{
    public function __construct(
        private readonly PmodExecutionGateway $gateway,
        private readonly bool $allowLiveDraftUpdates = false,
    ) {
    }

    public function actionType(): PmodActionType
    {
        return PmodActionType::SETTLEMENT_APPROVAL;
    }

    public function handle(PmodWorkItem $workItem): PmodResult
    {
        $settlementId = $workItem->settlementIds[0] ?? null;
        $trace = [
            'contact_id' => $workItem->contactId,
            'settlement_id' => $settlementId,
            'company' => $workItem->company->value,
            'tenant_id' => $workItem->tenantId,
            'requested_by' => $workItem->requestedBy,
            'source' => $workItem->source,
            'idempotency_key' => $workItem->idempotencyKey,
            'received_at' => $workItem->receivedAt,
            'dry_run' => $workItem->dryRun,
            'live_allowed' => $this->allowLiveDraftUpdates,
            'eligible_status_ids' => SettlementApprovalRules::LDR_ELIGIBLE_STATUS_IDS,
            'accepted_status_id' => SettlementApprovalRules::ACCEPTED_STATUS_ID,
            'out_of_timestamp_status_id' => SettlementApprovalRules::LDR_OUT_OF_TIMESTAMP_STATUS_ID,
        ];

        if ($workItem->company !== PmodCompany::LDR) {
            return $this->capture($workItem, 'Settlement Approval live completion is LDR only.', [
                'reason' => 'company_not_ldr',
                'company' => $workItem->company->value,
                'trace' => $trace,
            ]);
        }

        if ($settlementId === null || $settlementId === '') {
            return $this->capture($workItem, 'Settlement Approval requires a settlement ID.', [
                'reason' => 'missing_settlement_id',
                'trace' => $trace,
            ]);
        }

        $offer = $this->gateway->getSettlementOffer($workItem, $settlementId);
        $statusId = $this->statusId($offer);
        $approvalYmd = $this->approvalDate($workItem);
        $validUntil = $this->ymd($offer['offer_valid_date'] ?? $offer['valid_until'] ?? $offer['valid_until_date'] ?? null);
        $startDate = $this->startDate($offer);
        $path = SettlementApprovalRules::path($approvalYmd, $validUntil, $startDate);
        $failedCheck = SettlementApprovalRules::failedCheck($approvalYmd, $validUntil, $startDate);
        $targetStatus = $path === 'A'
            ? SettlementApprovalRules::ACCEPTED_STATUS_ID
            : ($path === 'B' ? SettlementApprovalRules::LDR_OUT_OF_TIMESTAMP_STATUS_ID : null);

        $trace = array_merge($trace, [
            'offer_status_id' => $statusId,
            'offer_status_raw' => $offer['offer_status'] ?? $offer['offer_status_id'] ?? $offer['status_id'] ?? $offer['status'] ?? null,
            'status_is_eligible' => SettlementApprovalRules::isLdrEligibleStatus($statusId),
            'approval_date_pt' => $approvalYmd,
            'valid_until' => $validUntil,
            'start_date' => $startDate,
            'path' => $path,
            'failed_check' => $failedCheck,
            'target_status_id' => $targetStatus,
            'offer_summary' => [
                'id' => is_scalar($offer['id'] ?? null) ? (string) $offer['id'] : $settlementId,
                'contact_id' => is_scalar($offer['contact_id'] ?? null) ? (string) $offer['contact_id'] : $workItem->contactId,
                'debt_id' => is_scalar($offer['debt_id'] ?? null) ? (string) $offer['debt_id'] : null,
                'offer_status' => $statusId,
                'offer_valid_date' => $validUntil,
                'start_date' => $startDate,
            ],
        ]);

        if (! SettlementApprovalRules::isLdrEligibleStatus($statusId)) {
            $trace['forth_write'] = 'none';
            return new PmodResult(
                status: 'captured_for_manual_review',
                message: 'Settlement Approval rejected: offer is not in a portal-eligible status.',
                metadata: [
                    'reason' => 'ineligible_status',
                    'offer_status_id' => $statusId,
                    'trace' => $trace,
                ],
            );
        }

        if ($path === null) {
            $trace['forth_write'] = 'none';
            return $this->capture($workItem, 'Settlement Approval cannot compare Valid Until / Start Date.', [
                'reason' => 'missing_dates',
                'valid_until' => $validUntil,
                'start_date' => $startDate,
                'trace' => $trace,
            ]);
        }

        if (! $this->allowLiveDraftUpdates || $workItem->dryRun) {
            $trace['forth_write'] = 'none (dry run / live off)';
            $trace['would_put_status_id'] = $targetStatus;
            $trace['would_write_note'] = true;
            return new PmodResult(
                status: 'captured_for_manual_review',
                message: $path === 'A'
                    ? 'Would set LDR offer to Accepted (live updates off).'
                    : 'Would set LDR offer to Accepted out of timestamp (live updates off).',
                metadata: [
                    'reason' => $workItem->dryRun ? 'dry_run_only' : 'live_draft_updates_disabled',
                    'path' => $path,
                    'target_status_id' => $targetStatus,
                    'failed_check' => $failedCheck,
                    'trace' => $trace,
                ],
            );
        }

        $this->gateway->updateSettlementOfferStatus($workItem, $settlementId, (string) $targetStatus);
        $this->gateway->createContactNote($workItem, $this->note($workItem, $offer, $path, $approvalYmd, $validUntil, $startDate, $failedCheck, dryRun: false));
        $trace['forth_write'] = 'PUT status + contact note';
        $trace['wrote_status_id'] = $targetStatus;
        $trace['wrote_note'] = true;

        if ($path === 'A') {
            return new PmodResult(
                status: 'updated',
                message: sprintf('Settlement %s set to Accepted for contact [%s].', $settlementId, $workItem->contactId),
                metadata: [
                    'path' => 'A',
                    'settlement_id' => $settlementId,
                    'status_id' => $targetStatus,
                    'trace' => $trace,
                ],
            );
        }

        $compared = $failedCheck === 'start_date' ? $startDate : $validUntil;

        return new PmodResult(
            status: 'captured_for_manual_review',
            message: sprintf('Settlement %s set to Accepted out of timestamp. Billing must complete manually.', $settlementId),
            metadata: [
                'path' => 'B',
                'settlement_id' => $settlementId,
                'status_id' => $targetStatus,
                'failed_check' => $failedCheck,
                'days_past' => ($approvalYmd !== null && $compared !== null)
                    ? SettlementApprovalRules::daysPast($approvalYmd, $compared)
                    : 0,
                'trace' => $trace,
            ],
        );
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function capture(PmodWorkItem $workItem, string $message, array $metadata): PmodResult
    {
        if ($this->allowLiveDraftUpdates && ! $workItem->dryRun) {
            $this->gateway->createContactNote($workItem, implode("\n", [
                'Settlement Approval — Requires Manual Review',
                '',
                'Contact ID : ' . $workItem->contactId,
                'Reason : ' . ($metadata['reason'] ?? 'unknown'),
                $message,
            ]));
        }

        return new PmodResult(
            status: 'captured_for_manual_review',
            message: $message,
            metadata: $metadata,
        );
    }

    /**
     * @param array<string, mixed> $offer
     */
    private function statusId(array $offer): string
    {
        $raw = $offer['offer_status'] ?? $offer['offer_status_id'] ?? $offer['status_id'] ?? $offer['status'] ?? '';
        if (is_array($raw)) {
            $raw = $raw['status_id'] ?? $raw['id'] ?? $raw['offer_status'] ?? '';
        }

        return trim((string) $raw);
    }

    /**
     * @param array<string, mixed> $offer
     */
    private function startDate(array $offer): ?string
    {
        $direct = $this->ymd($offer['start_date'] ?? null);
        if ($direct !== null) {
            return $direct;
        }

        $json = $offer['json'] ?? null;
        if (is_string($json) && $json !== '') {
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                return $this->ymd($decoded['start_date'] ?? null);
            }
        }

        if (is_array($json)) {
            return $this->ymd($json['start_date'] ?? null);
        }

        return null;
    }

    private function approvalDate(PmodWorkItem $workItem): ?string
    {
        try {
            return Carbon::parse($workItem->receivedAt)->timezone('America/Los_Angeles')->toDateString();
        } catch (\Throwable) {
            return Carbon::now('America/Los_Angeles')->toDateString();
        }
    }

    private function ymd(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));
        if ($text === '') {
            return null;
        }

        try {
            return Carbon::parse($text)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $offer
     */
    private function note(
        PmodWorkItem $workItem,
        array $offer,
        string $path,
        ?string $approvalYmd,
        ?string $validUntil,
        ?string $startDate,
        ?string $failedCheck,
        bool $dryRun,
    ): string {
        $last4 = $workItem->bankingUpdate['account_number_last4']
            ?? $workItem->normalizedPayload['account_number_last4']
            ?? $workItem->rawPayload['Last 4 Account Number']
            ?? '';
        $debtId = $offer['debt_id'] ?? $workItem->normalizedPayload['debt_id'] ?? '';
        $sid = $workItem->settlementIds[0] ?? '';

        if ($path === 'A') {
            $lines = [
                'SETTLEMENT ACCEPTED VIA CLIENT PORTAL',
                '',
                'Client ID:        ' . $workItem->contactId,
                'Debt ID:          ' . $debtId,
                'SID:              ' . $sid,
                'Account ending:   ' . $last4,
                '',
                'Approved on:      ' . ($approvalYmd ?? ''),
                'Valid Until:      ' . ($validUntil ?? ''),
                'Start Date:       ' . ($startDate ?? ''),
                '',
                'Timestamp check:  PASSED',
                'Status set to:    Accepted',
            ];
            $lines[] = $dryRun ? 'Dry run. No Billing action required after live run.' : 'Completed automatically. No Billing action required.';

            return implode("\n", $lines);
        }

        return implode("\n", [
            'SETTLEMENT ACCEPTED OUT OF TIMESTAMP',
            '',
            'Client ID:        ' . $workItem->contactId,
            'Debt ID:          ' . $debtId,
            'SID:              ' . $sid,
            'Account ending:   ' . $last4,
            '',
            'Approved on:      ' . ($approvalYmd ?? ''),
            'Valid Until:      ' . ($validUntil ?? ''),
            'Start Date:       ' . ($startDate ?? ''),
            '',
            'Failed check:     ' . ($failedCheck ?? ''),
            '',
            'Offer NOT completed automatically.',
            'Billing to contact the creditor to confirm the',
            'terms will still be honored, or re-paper the',
            'dates, then complete the offer manually.',
        ]);
    }
}
