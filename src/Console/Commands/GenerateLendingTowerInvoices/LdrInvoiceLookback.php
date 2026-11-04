<?php

declare(strict_types=1);

namespace Cmd\Reports\Console\Commands\GenerateLendingTowerInvoices;

use DateTimeImmutable;
use RuntimeException;

/** Evaluate only the clients and debt basis retained with the original paid invoice. */
final class LdrInvoiceLookback
{
    public static function paymentCount(int $rawCount, string $frequency): float
    {
        $frequency = strtolower(preg_replace('/[^a-z]/i', '', trim($frequency)) ?? '');
        if (str_contains($frequency, 'biweekly') || str_contains($frequency, 'semimonthly')) {
            return $rawCount / 2;
        }
        return $frequency === 'weekly' ? $rawCount / 4 : (float) $rawCount;
    }

    /** Snowflake histories are bounded at each individual's deadline, including that entire day. */
    public static function paymentSql(array $population): string
    {
        $values = [];
        foreach ($population as $row) {
            $id = preg_replace('/^LLG-/i', '', (string) ($row['LLG_ID'] ?? ''));
            if (!preg_match('/^\d+$/D', $id)) {
                throw new RuntimeException('The billed LDR population contains an invalid contact ID.');
            }
            $first = (string) ($row['First_Payment_Cleared_Date'] ?? '');
            $cutoff = LdrInvoiceWindow::paymentCutoff($first);
            $before = (new DateTimeImmutable($cutoff))->modify('+1 day')->format('Y-m-d');
            $values[] = "('{$id}', '{$first}', '{$before}')";
        }
        if ($values === []) {
            throw new RuntimeException('Cannot query payments without billed clients.');
        }
        $list = implode(', ', $values);
        return "WITH billed AS (
            SELECT column1::VARCHAR AS CONTACT_ID, column2::DATE AS FIRST_CLEARED, column3::DATE AS CUTOFF_BEFORE
            FROM VALUES {$list}
        )
        SELECT b.CONTACT_ID, COUNT(*) AS PAYMENT_COUNT
        FROM billed b
        JOIN TRANSACTIONS t ON TO_VARCHAR(t.CONTACT_ID) = b.CONTACT_ID
        WHERE t.TRANS_TYPE = 'D'
          AND t.CLEARED_DATE IS NOT NULL
          AND CAST(t.CLEARED_DATE AS DATE) >= b.FIRST_CLEARED
          AND CAST(t.CLEARED_DATE AS DATE) < b.CUTOFF_BEFORE
          AND (t.RETURNED_DATE IS NULL OR CAST(t.RETURNED_DATE AS DATE) >= b.CUTOFF_BEFORE)
          AND (t._FIVETRAN_DELETED = FALSE OR t._FIVETRAN_DELETED IS NULL)
        GROUP BY b.CONTACT_ID";
    }

    public static function evaluate(array $population, array $current, array $rawCounts): array
    {
        $deductions = [];
        foreach ($population as $billed) {
            $id = (string) $billed['LLG_ID'];
            if (!isset($current[$id])) {
                throw new RuntimeException("Billed client {$id} is missing from Azure; cannot evaluate the lookback.");
            }
            $row = $current[$id];
            $cutoff = LdrInvoiceWindow::paymentCutoff((string) $billed['First_Payment_Cleared_Date']);
            $cancelled = substr((string) ($row['Cancel_Date'] ?? ''), 0, 10);
            $cancelledInWindow = $cancelled !== '' && $cancelled <= $cutoff;
            $contactId = preg_replace('/^LLG-/i', '', $id);
            $payments = self::paymentCount((int) ($rawCounts[$contactId] ?? 0), (string) ($row['Payment_Frequency'] ?? ''));
            if (!$cancelledInWindow && $payments >= 3) {
                continue;
            }
            $deductions[] = array_merge($billed, [
                'Lookback_Cutoff' => $cutoff,
                'Lookback_Cancel_Date' => $cancelled,
                'Payments_At_Cutoff' => $payments,
                'Azure_Payments_Current' => $row['Payments'] ?? null,
                'Payment_Frequency' => $row['Payment_Frequency'] ?? '',
                'Insufficient_Payments' => $payments < 3,
                'Cancelled_By_Cutoff' => $cancelledInWindow,
                'Deduction_Reason' => $cancelledInWindow
                    ? ($payments < 3 ? 'Cancelled by cutoff; fewer than 3 payments' : 'Cancelled by cutoff')
                    : 'Fewer than 3 payments at cutoff',
            ]);
        }
        return $deductions;
    }
}
