<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use Cmd\Reports\Console\Commands\GenerateRetentionBonusCommission\BonusFormatter;
use DateTimeZone;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/** Selected Retention commission lines in source order, independent of Sales deductions. */
final class LookbackPricingSnapshot
{
    public static function capture(string $captureId, array $rows, array $component): array
    {
        $lines = [];
        $utc = new DateTimeZone('UTC');
        foreach ($rows as $row) {
            $agentKey = self::key(RetentionAgentIdentity::canonicalName((string) ($row['RETENTION_AGENT'] ?? '')));
            if ($agentKey === '') {
                continue;
            }
            $line = [
                'agentKey' => $agentKey,
                'clientId' => (string) ($row['ID'] ?? ''),
                'amount' => $row['RETENTION_COMMISSION'] ?? null,
            ];
            foreach (['RETENTION_DATE' => 'retentionDate', 'FIRST_PAYMENT_CLEARED_DATE' => 'paymentDate'] as $input => $field) {
                $serial = BonusFormatter::detailDateSerial($row[$input] ?? null);
                if ($serial !== null) {
                    $line[$field] = ExcelDate::excelToDateTimeObject($serial, $utc)->format('Y-m-d');
                }
            }
            $lines[] = $line;
        }

        $snapshot = ['version' => 1, 'captureId' => $captureId, 'rows' => $lines];
        self::validatedLines($snapshot, $component);
        return $snapshot;
    }

    /** Reconcile pricing with the already validated commission component. */
    public static function validatedLines(array $snapshot, array $component): array
    {
        if (count($snapshot) !== 3 || ($snapshot['version'] ?? null) !== 1
            || !is_string($snapshot['captureId'] ?? null) || !preg_match('/^[a-f0-9]{32}$/D', $snapshot['captureId'])
            || !is_array($snapshot['rows'] ?? null) || !array_is_list($snapshot['rows']) || count($snapshot['rows']) > 99999) {
            self::invalid();
        }

        $totals = array_column($component['rows'], 'amount', 'agentKey');
        $lines = $sums = $seen = [];
        foreach ($snapshot['rows'] as $row) {
            if (!is_array($row) || array_diff(array_keys($row), ['agentKey', 'clientId', 'amount', 'retentionDate', 'paymentDate'])) {
                self::invalid();
            }
            $agent = $row['agentKey'] ?? null;
            $id = $row['clientId'] ?? null;
            $amount = $row['amount'] ?? null;
            if (!is_string($agent) || !array_key_exists($agent, $totals)
                || !is_string($id) || !preg_match('/^[1-9][0-9]*$/D', $id) || isset($seen[$id])
                || !array_key_exists('paymentDate', $row)
                || (!is_int($amount) && !is_float($amount)) || !is_finite((float) $amount)
                || round((float) $amount, 2) !== (float) $amount) {
                self::invalid();
            }
            $line = ['clientId' => $id, 'amount' => (float) $amount];
            foreach (['retentionDate', 'paymentDate'] as $field) {
                if (array_key_exists($field, $row)) {
                    if (!is_string($row[$field]) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $row[$field], $parts)
                        || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
                        self::invalid();
                    }
                    $line[$field] = $row[$field];
                }
            }
            $seen[$id] = true;
            $sums[$agent] = ($sums[$agent] ?? 0.0) + $line['amount'];
            $lines[$agent][] = $line;
        }
        foreach ($totals as $agent => $total) {
            if (!isset($sums[$agent]) || round($sums[$agent], 2) !== (float) $total) {
                self::invalid();
            }
        }

        return $lines;
    }

    private static function key(string $name): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($name)) ?? '', 'UTF-8');
    }

    private static function invalid(): never
    {
        throw new InvalidArgumentException('Frozen Retention Lookback pricing is invalid or does not reconcile.');
    }
}
