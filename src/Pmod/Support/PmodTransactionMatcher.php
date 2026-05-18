<?php

declare(strict_types=1);

namespace Cmd\Reports\Pmod\Support;

final class PmodTransactionMatcher
{
    /**
     * @param list<array<string, mixed>> $transactions
     * @return list<array<string, mixed>>
     */
    public static function findCandidatesByDateAndAmount(array $transactions, string $processDate, string $amount): array
    {
        $normalizedDate = self::normalizeDate($processDate);
        $normalizedAmount = self::normalizeAmount($amount);

        if ($normalizedDate === null || $normalizedAmount === null) {
            return [];
        }

        $matches = array_filter($transactions, static function (array $transaction) use ($normalizedDate, $normalizedAmount): bool {
            return self::matchesDate($transaction, $normalizedDate) && self::matchesAmount($transaction, $normalizedAmount);
        });

        return array_values($matches);
    }

    /**
     * @param list<array<string, mixed>> $transactions
     * @return array<string, mixed>|null
     */
    public static function findUniqueByDateAndAmount(array $transactions, string $processDate, string $amount): ?array
    {
        $matches = self::findCandidatesByDateAndAmount($transactions, $processDate, $amount);

        return count($matches) === 1 ? $matches[0] : null;
    }

    public static function extractAuthoritativeDraftId(array $transaction): ?string
    {
        foreach (['draft_id', 'draftId', 'forthpay_draft_id', 'forthpayDraftId'] as $key) {
            $value = self::readString($transaction, [$key]);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private static function matchesDate(array $transaction, string $expectedDate): bool
    {
        $actualDate = self::readString($transaction, [
            'process_date',
            'processDate',
            'scheduled_date',
            'scheduledDate',
            'date',
        ]);

        return self::normalizeDate($actualDate) === $expectedDate;
    }

    private static function matchesAmount(array $transaction, string $expectedAmount): bool
    {
        $actualAmount = self::readString($transaction, [
            'amount',
            'payment_amount',
            'paymentAmount',
            'total_amount',
            'totalAmount',
        ]);

        return self::normalizeAmount($actualAmount) === $expectedAmount;
    }

    /**
     * @param list<string> $keys
     */
    private static function readString(array $transaction, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $transaction)) {
                continue;
            }

            $value = trim((string) $transaction[$key]);

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private static function normalizeDate(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value, $matches) === 1) {
            return $matches[0];
        }

        if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $value) === 1) {
            [$month, $day, $year] = explode('/', $value);

            return sprintf('%04d-%02d-%02d', (int) $year, (int) $month, (int) $day);
        }

        return null;
    }

    private static function normalizeAmount(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $clean = str_replace(['$', ',', ' '], '', trim($value));

        if ($clean === '' || preg_match('/^-?\d+(\.\d+)?$/', $clean) !== 1) {
            return null;
        }

        return number_format((float) $clean, 2, '.', '');
    }
}
