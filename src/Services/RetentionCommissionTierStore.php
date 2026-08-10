<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use Illuminate\Support\Facades\Log;

/**
 * Monthly retention agent tiers (CommissionDatabase.dbo.TblRetentionCommissionTiers).
 * Best-effort: failures are logged and never interrupt report generation.
 *
 * PK: (Source, Period_Start, Agent)
 */
final class RetentionCommissionTierStore
{
    private const TABLE = 'TblRetentionCommissionTiers';

    /**
     * @param  array<int,array{agent:string,tier:int,assigned?:int,retained?:int,pct_retained?:float}> $rows
     */
    public static function persist(DBConnector $sql, string $source, string $periodStart, array $rows): void
    {
        $source = strtolower(trim($source));
        if ($source === '' || $periodStart === '' || $rows === []) {
            return;
        }

        try {
            $written = 0;
            foreach ($rows as $row) {
                $agent = trim((string) ($row['agent'] ?? ''));
                if ($agent === '') {
                    continue;
                }

                $tier = max(0, min(4, (int) ($row['tier'] ?? 0)));
                $assigned = (int) ($row['assigned'] ?? 0);
                $retained = (int) ($row['retained'] ?? 0);
                $pct = round((float) ($row['pct_retained'] ?? 0), 6);

                $merge = 'MERGE dbo.' . self::TABLE . ' AS t
                    USING (SELECT ? AS Source, CAST(? AS DATE) AS Period_Start, ? AS Agent,
                                  ? AS Tier, ? AS Assigned, ? AS Retained, CAST(? AS DECIMAL(9,6)) AS Pct_Retained) AS s
                    ON t.Source = s.Source AND t.Period_Start = s.Period_Start AND t.Agent = s.Agent
                    WHEN MATCHED THEN UPDATE SET
                        t.Tier = s.Tier,
                        t.Assigned = s.Assigned,
                        t.Retained = s.Retained,
                        t.Pct_Retained = s.Pct_Retained,
                        t.Updated_At = GETDATE()
                    WHEN NOT MATCHED THEN INSERT (Source, Period_Start, Agent, Tier, Assigned, Retained, Pct_Retained, Updated_At)
                        VALUES (s.Source, s.Period_Start, s.Agent, s.Tier, s.Assigned, s.Retained, s.Pct_Retained, GETDATE());';

                $res = $sql->querySqlServer($merge, [
                    $source,
                    $periodStart,
                    $agent,
                    $tier,
                    $assigned,
                    $retained,
                    $pct,
                ]);

                if (($res['success'] ?? false) === true) {
                    $written++;
                } else {
                    Log::warning("RetentionCommissionTierStore: upsert failed for agent '{$agent}'", [
                        'error' => $res['error'] ?? '',
                        'source' => $source,
                        'period' => $periodStart,
                    ]);
                }
            }

            Log::info("RetentionCommissionTierStore: {$source} {$periodStart} — {$written}/" . count($rows) . ' tiers written.');
        } catch (\Throwable $e) {
            Log::warning('RetentionCommissionTierStore: persist skipped', ['ex' => $e->getMessage()]);
        }
    }

    /**
     * @param  list<string> $periodStarts Y-m-01 values
     * @return array<string,int> map key => tier
     */
    public static function fetchMap(DBConnector $sql, string $source, array $periodStarts): array
    {
        $source = strtolower(trim($source));
        $periodStarts = array_values(array_unique(array_filter($periodStarts, static fn ($p) => is_string($p) && $p !== '')));
        if ($source === '' || $periodStarts === []) {
            return [];
        }

        try {
            $placeholders = implode(',', array_fill(0, count($periodStarts), '?'));
            $sqlText = 'SELECT Period_Start, Agent, Tier
                FROM dbo.' . self::TABLE . '
                WHERE Source = ? AND Period_Start IN (' . $placeholders . ')';

            $params = array_merge([$source], $periodStarts);
            $res = $sql->querySqlServer($sqlText, $params);
            if (($res['success'] ?? false) !== true) {
                Log::warning('RetentionCommissionTierStore: fetchMap failed', [
                    'error' => $res['error'] ?? '',
                    'source' => $source,
                ]);
                return [];
            }

            $map = [];
            foreach ($res['data'] ?? [] as $row) {
                $rawPeriod = $row['Period_Start'] ?? $row['period_start'] ?? '';
                if ($rawPeriod instanceof \DateTimeInterface) {
                    $rawPeriod = $rawPeriod->format('Y-m-d');
                }
                $period = self::periodStartFromDate((string) $rawPeriod);
                $agent = (string) ($row['Agent'] ?? $row['agent'] ?? '');
                if ($period === null || $agent === '') {
                    continue;
                }
                $map[self::tierMapKey($period, $agent)] = max(0, min(4, (int) ($row['Tier'] ?? $row['tier'] ?? 0)));
            }

            return $map;
        } catch (\Throwable $e) {
            Log::warning('RetentionCommissionTierStore: fetchMap skipped', ['ex' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * @param  array<string,array{tier?:int,assigned?:int,retained?:int,pct_retained?:float}> $summaryRows
     * @return array<int,array{agent:string,tier:int,assigned:int,retained:int,pct_retained:float}>
     */
    public static function rowsFromSummary(array $summaryRows): array
    {
        $out = [];
        foreach ($summaryRows as $agentName => $sum) {
            $agent = trim((string) $agentName);
            if ($agent === '') {
                continue;
            }
            $out[] = [
                'agent' => $agent,
                'tier' => (int) ($sum['tier'] ?? 0),
                'assigned' => (int) ($sum['assigned'] ?? 0),
                'retained' => (int) ($sum['retained'] ?? 0),
                'pct_retained' => (float) ($sum['pct_retained'] ?? 0),
            ];
        }

        return $out;
    }

    public static function periodStartFromDate(?string $date): ?string
    {
        if ($date === null) {
            return null;
        }
        $date = trim($date);
        if ($date === '') {
            return null;
        }

        $ts = strtotime(substr($date, 0, 10));
        if ($ts === false) {
            return null;
        }

        return date('Y-m-01', $ts);
    }

    public static function tierMapKey(string $periodStart, string $agent): string
    {
        return $periodStart . '|' . strtoupper(trim($agent));
    }

    public static function resolveTierForPayment(int $currentTier, ?int $snapshotTier): int
    {
        if ($snapshotTier === null) {
            return $currentTier;
        }

        return max(0, min(4, $snapshotTier));
    }

    /**
     * Look up retained-month tier from a fetchMap() result.
     *
     * @param  array<string,int> $tierSnapshotMap
     */
    public static function snapshotTierFor(array $tierSnapshotMap, string $agent, ?string $retentionDate): ?int
    {
        $period = self::periodStartFromDate($retentionDate);
        if ($period === null) {
            return null;
        }

        $key = self::tierMapKey($period, $agent);
        if (!array_key_exists($key, $tierSnapshotMap)) {
            return null;
        }

        return $tierSnapshotMap[$key];
    }

    /**
     * Amounts for one paid contact row (T1-T4 flat dollars already on the row).
     *
     * @param  array{T1?:float|int|null,T2?:float|int|null,T3?:float|int|null,T4?:float|int|null} $tierAmounts
     * @param  array<string,int> $tierSnapshotMap
     * @return array{old:float,new:float,paid:float,current_tier:int,pay_tier:int,snapshot_tier:?int}
     */
    public static function commissionForPaidRow(
        array $tierAmounts,
        int $currentMonthTier,
        array $tierSnapshotMap,
        string $agent,
        ?string $retentionDate,
        bool $useRetainedMonthTier
    ): array {
        $currentMonthTier = max(0, min(4, $currentMonthTier));
        $snapshotTier = self::snapshotTierFor($tierSnapshotMap, $agent, $retentionDate);
        $payTier = self::resolveTierForPayment($currentMonthTier, $snapshotTier);
        $old = self::amountForTier($tierAmounts, $currentMonthTier);
        $new = self::amountForTier($tierAmounts, $payTier);

        return [
            'old' => $old,
            'new' => $new,
            'paid' => $useRetainedMonthTier ? $new : $old,
            'current_tier' => $currentMonthTier,
            'pay_tier' => $payTier,
            'snapshot_tier' => $snapshotTier,
        ];
    }

    /**
     * @param  array{T1?:float|int|null,T2?:float|int|null,T3?:float|int|null,T4?:float|int|null} $tierAmounts
     */
    public static function amountForTier(array $tierAmounts, int $tier): float
    {
        if ($tier <= 0) {
            return 0.0;
        }

        $key = 'T' . $tier;
        return (float) ($tierAmounts[$key] ?? 0);
    }
}
