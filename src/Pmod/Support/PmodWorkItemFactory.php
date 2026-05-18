<?php

declare(strict_types=1);

namespace Cmd\Reports\Pmod\Support;

use Cmd\Reports\Pmod\Data\PmodWorkItem;
use Cmd\Reports\Pmod\Enums\PmodActionType;
use Illuminate\Support\Str;

final class PmodWorkItemFactory
{
    /**
     * @param array<string, mixed> $normalizedPayload
     * @param array<string, mixed> $rawPayload
     * @param array<string, mixed> $executionOptions
     */
    public static function fromWebhookPayload(
        array $normalizedPayload,
        array $rawPayload,
        string $tenantId,
        string $idempotencyKey,
        string $source = 'webhook:pmod-approval',
        array $executionOptions = [],
        bool $dryRun = false,
    ): PmodWorkItem {
        $contactId = trim((string) ($normalizedPayload['customer_id'] ?? ''));
        $actionType = PmodActionMapper::fromLabel((string) ($normalizedPayload['action'] ?? ''));
        $settlementIds = self::normalizeStringList($normalizedPayload['settlement_ids'] ?? []);

        $settlementId = trim((string) ($normalizedPayload['settlement_id'] ?? ''));
        if ($settlementId !== '') {
            $settlementIds[] = $settlementId;
        }
        $settlementIds = array_values(array_unique(array_filter($settlementIds, static fn (string $value): bool => $value !== '')));

        $originalDates = self::normalizeStringList($normalizedPayload['original_dates'] ?? []);
        if ($originalDates === []) {
            $originalDate = self::nullableString($normalizedPayload['original_date'] ?? null);
            if ($originalDate !== null) {
                $originalDates[] = $originalDate;
            }
        }

        $targetDates = self::normalizeStringList($normalizedPayload['target_dates'] ?? []);
        if ($targetDates === []) {
            foreach (['target_date', 'process_date', 'start_date'] as $candidateKey) {
                $candidate = self::nullableString($normalizedPayload[$candidateKey] ?? null);
                if ($candidate !== null) {
                    $targetDates[] = $candidate;
                    break;
                }
            }
        }

        $amounts = self::normalizeStringList($normalizedPayload['amounts'] ?? []);
        if ($amounts === []) {
            foreach (['amount', 'total_amount'] as $candidateKey) {
                $candidate = self::nullableString($normalizedPayload[$candidateKey] ?? null);
                if ($candidate !== null) {
                    $amounts[] = $candidate;
                    break;
                }
            }
        }

        $amount = self::nullableString($normalizedPayload['amount'] ?? null) ?? ($amounts[0] ?? null);
        $monthsToExtend = self::nullableString($normalizedPayload['months_to_extend'] ?? null)
            ?? self::nullableString($normalizedPayload['extend_months'] ?? null);
        if ($monthsToExtend === null && ! empty($normalizedPayload['extended_start_date']) && ! empty($normalizedPayload['extended_end_date'])) {
            $monthsToExtend = self::monthsBetweenInclusive(
                (string) $normalizedPayload['extended_start_date'],
                (string) $normalizedPayload['extended_end_date'],
            );
        }
        $extendedAmount = self::nullableString($normalizedPayload['extended_amount'] ?? null);
        $paymentAmount = $amount ?? $extendedAmount ?? self::nullableString($normalizedPayload['increase_amount'] ?? null);

        return new PmodWorkItem(
            requestId: (string) Str::uuid(),
            tenantId: $tenantId,
            source: $source,
            company: PmodCompanyResolver::fromTenantContext($normalizedPayload['company'] ?? $normalizedPayload['tenant_id'] ?? $tenantId ?? null),
            actionType: $actionType,
            requestedBy: trim((string) ($normalizedPayload['requested_by'] ?? '')),
            contactId: $contactId,
            idempotencyKey: $idempotencyKey,
            receivedAt: now()->toIso8601String(),
            rawPayload: $rawPayload,
            normalizedPayload: $normalizedPayload,
            settlementIds: $settlementIds,
            originalDates: $originalDates,
            targetDates: $targetDates,
            targetDate: $targetDates[0] ?? null,
            amounts: $amounts,
            amount: $paymentAmount,
            increaseAmount: self::nullableString($normalizedPayload['increase_amount'] ?? null),
            totalAmount: self::nullableString($normalizedPayload['total_amount'] ?? null) ?? self::nullableString($normalizedPayload['amount'] ?? null),
            frequency: self::nullableString($normalizedPayload['frequency'] ?? null),
            paymentChange: [
                'mode' => self::modeForAction($actionType),
                'original_dates' => $originalDates,
                'target_dates' => $targetDates,
                'amounts' => $amounts,
                'amount' => $amount,
                'increase_amount' => self::nullableString($normalizedPayload['increase_amount'] ?? null),
                'total_amount' => self::nullableString($normalizedPayload['total_amount'] ?? null),
                'start_date' => self::nullableString($normalizedPayload['start_date'] ?? null)
                    ?? self::nullableString($normalizedPayload['process_date'] ?? null)
                    ?? ($targetDates[0] ?? null),
                'end_date' => self::nullableString($normalizedPayload['end_date'] ?? null),
                'extended_start_date' => self::nullableString($normalizedPayload['extended_start_date'] ?? null),
                'extended_end_date' => self::nullableString($normalizedPayload['extended_end_date'] ?? null),
                'extended_amount' => $extendedAmount,
                'extend_months' => $monthsToExtend,
                'months_to_extend' => $monthsToExtend,
                'frequency' => self::nullableString($normalizedPayload['frequency'] ?? null),
                'void_settlements' => (bool) ($normalizedPayload['void_settlements'] ?? false),
                'settlement_ids' => $settlementIds,
            ],
            bankingUpdate: self::structuredSubset($normalizedPayload, ['banking_update', 'banking', 'bank_account'], [
                'account_number',
                'routing_number',
                'account_type',
                'bank_name',
                'account_holder_name',
                'name_on_account',
            ]),
            creditorChange: self::structuredSubset($normalizedPayload, ['creditor_change', 'creditor', 'creditor_update'], [
                'creditor_name',
                'creditor_id',
                'account_number',
                'balance',
                'settlement_amount',
                'months_to_extend',
                'months_to_decrease',
                'amount',
            ]),
            sponsorUpdate: self::structuredSubset($normalizedPayload, ['sponsor_update', 'sponsor', 'sponsor_banking'], [
                'sponsor_id',
                'sponsor_name',
                'sponsor_account_number',
                'sponsor_routing_number',
                'sponsor_account_type',
                'sponsor_bank_name',
            ]),
            executionOptions: $executionOptions,
            dryRun: $dryRun,
        );
    }

    /**
     * @param array<string, mixed> $normalizedPayload
     * @param array<string, mixed> $rawPayload
     * @param array<string, mixed> $executionOptions
     */
    public static function fromApprovalPayload(
        array $normalizedPayload,
        array $rawPayload,
        string $tenantId,
        string $idempotencyKey,
        string $source = 'webhook:pmod-approval',
        array $executionOptions = [],
        bool $dryRun = false,
    ): PmodWorkItem {
        return self::fromWebhookPayload(
            normalizedPayload: $normalizedPayload,
            rawPayload: $rawPayload,
            tenantId: $tenantId,
            idempotencyKey: $idempotencyKey,
            source: $source,
            executionOptions: $executionOptions,
            dryRun: $dryRun,
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private static function normalizeStringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $normalized = [];

        foreach ($value as $item) {
            $stringValue = trim((string) $item);

            if ($stringValue === '') {
                continue;
            }

            $normalized[] = $stringValue;
        }

        return array_values($normalized);
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string> $containerKeys
     * @param list<string> $flatKeys
     * @return array<string, mixed>
     */
    private static function structuredSubset(array $payload, array $containerKeys, array $flatKeys): array
    {
        $structured = [];

        foreach ($containerKeys as $containerKey) {
            $candidate = $payload[$containerKey] ?? null;
            if (! is_array($candidate)) {
                continue;
            }

            foreach ($candidate as $key => $value) {
                if (is_string($key) && (is_scalar($value) || $value === null)) {
                    $structured[$key] = $value;
                }
            }
        }

        foreach ($flatKeys as $flatKey) {
            if (! array_key_exists($flatKey, $payload)) {
                continue;
            }

            $value = $payload[$flatKey];
            if (is_scalar($value) || $value === null) {
                $structured[$flatKey] = $value;
            }
        }

        return $structured;
    }

    private static function monthsBetweenInclusive(string $startDate, string $endDate): ?string
    {
        try {
            $start = new \DateTimeImmutable($startDate);
            $end = new \DateTimeImmutable($endDate);
        } catch (\Throwable) {
            return null;
        }

        if ($end < $start) {
            return null;
        }

        $months = (($end->format('Y') - $start->format('Y')) * 12)
            + ((int) $end->format('n') - (int) $start->format('n'))
            + 1;

        return (string) max(1, $months);
    }

    private static function modeForAction(PmodActionType $actionType): string
    {
        return match ($actionType) {
            PmodActionType::ADDITIONAL_PAYMENT,
            PmodActionType::CHANGE_PAYMENT,
            PmodActionType::PMOD_LUMP_SUM => 'single',
            PmodActionType::SKIP_PAYMENT,
            PmodActionType::PAYMENT_REFUND => 'multi',
            PmodActionType::RESCHEDULE_ALL_PAYMENTS,
            PmodActionType::INCREASE_ALL_FUTURE_PAYMENTS => 'recurring',
            PmodActionType::PMOD_INCREASE_PAYMENTS,
            PmodActionType::PMOD_INCREASE_PAYMENTS_AND_EXTEND_PROGRAM,
            PmodActionType::PMOD_EXTEND_PROGRAM => 'range',
            default => 'manual',
        };
    }
}
