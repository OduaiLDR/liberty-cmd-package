<?php

namespace Cmd\Reports\Console\Commands;

use Cmd\Reports\Services\DBConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Mirrors VBA Sub SyncNGOEPFClearRates(Optional Dummy As Long) — two near-identical subs (LDR/
 * PLAW). For every active negotiator (TblEmployees.Term_Date IS NULL) with a Snowflake user ID
 * for that source (SF_UID_LDR / SF_UID_PLAW), sums how much post-fee (PF) EPF was scheduled on
 * settlements closed in the target month vs. how much of that actually cleared, and inserts one
 * TblNGOEPFClearRates row per negotiator whose clear rate is > 0.
 *
 * The VBA runs the two SUM queries once per negotiator (2×N Snowflake round trips). This combines
 * both sums into a single GROUP BY so.NEG_ID query per source instead — same filters, same
 * result, just one round trip instead of 2×N.
 *
 * TblEmployees / TblNGOEPFClearRates are one shared SQL Server database regardless of source (the
 * VBA opens CNLDR even inside the PLAW sub) — only the Snowflake TRANSACTIONS/SETTLEMENT_OFFERS/
 * DEBTS/CONTACTS query differs per source's own Snowflake account. The inserted Source value stays
 * the literal string "LDR"/"PLAW" — this is an internal backend value, not report-facing text.
 */
class SyncNGOEPFClearRates extends Command
{
    protected $signature = 'sync:ngo-epf-clear-rates {--dry-run : Compute rates without writing to TblNGOEPFClearRates}';

    protected $description = 'Sync negotiator EPF clear rates (LDR + PLAW) into dbo.TblNGOEPFClearRates.';

    private const SOURCE_UID_COLUMN = ['LDR' => 'SF_UID_LDR', 'PLAW' => 'SF_UID_PLAW'];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        [$startDate, $endDate] = $this->resolveDateRange();
        $this->info("Date range: {$startDate} to {$endDate}" . ($dryRun ? ' (dry run — no writes)' : ''));

        try {
            $sqlServer = $this->initializeSqlServerConnector();
        } catch (\Throwable $e) {
            $this->error('Failed to initialize SQL Server connector: ' . $e->getMessage());
            Log::error('SyncNGOEPFClearRates: SQL Server connector init failed', ['exception' => $e]);
            return Command::FAILURE;
        }

        // Snowflake connectors are always explicit per source (never reused from the SQL Server
        // fallback) so the LDR pass can never accidentally query PLAW's Snowflake account or vice
        // versa, regardless of which environment the SQL Server fallback happened to land on.
        $snowflakeConnectors = [
            'LDR' => DBConnector::fromEnvironment('ldr'),
            'PLAW' => DBConnector::fromEnvironment('plaw'),
        ];

        $hadFailure = false;
        foreach (['LDR', 'PLAW'] as $source) {
            if (!$this->syncSource($source, $sqlServer, $snowflakeConnectors[$source], $startDate, $endDate, $dryRun)) {
                $hadFailure = true;
            }
        }

        return $hadFailure ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * VBA: If Day(Date) < 6 Then use last month's full range; Else use this month's full range.
     * (The "Date - 1" in the Else branch never crosses a month/year boundary since Day>=6 there,
     * so it's equivalent to just using Date directly — verified: Date-1 only differs in
     * month/year from Date when Date is the 1st, which never happens when Day(Date)>=6.)
     *
     * @return array{0: string, 1: string}
     */
    private function resolveDateRange(): array
    {
        $today = new \DateTimeImmutable('today');

        $target = ((int) $today->format('j') < 6)
            ? $today->modify('first day of last month')
            : $today->modify('first day of this month');

        return [
            $target->format('Y-m-d'),
            $target->modify('last day of this month')->format('Y-m-d'),
        ];
    }

    private function syncSource(
        string $source,
        DBConnector $sqlServer,
        DBConnector $snowflake,
        string $startDate,
        string $endDate,
        bool $dryRun
    ): bool {
        $this->info("[{$source}] starting.");
        $uidColumn = self::SOURCE_UID_COLUMN[$source];

        try {
            $employees = $this->fetchActiveEmployees($sqlServer, $uidColumn);
            $this->info("[{$source}] Active employees with {$uidColumn}: " . count($employees));

            if (empty($employees)) {
                return true;
            }

            $uids = array_column($employees, 'uid');
            $sums = $this->fetchEpfSums($snowflake, $uids, $startDate, $endDate);

            $rows = [];
            foreach ($employees as $employee) {
                $employeeSums = $sums[$employee['uid']] ?? null;
                $scheduled = $employeeSums['scheduled'] ?? 0.0;
                $cleared = $employeeSums['cleared'] ?? 0.0;

                // VBA: F = IFERROR(E/D, 0); only inserted when F > 0.
                $rate = $scheduled > 0 ? $cleared / $scheduled : 0.0;
                if ($rate <= 0) {
                    continue;
                }

                $rows[] = [
                    'agent_id' => $employee['pk'],
                    'scheduled' => $scheduled,
                    'cleared' => $cleared,
                    'rate' => round($rate, 6),
                ];
            }

            $this->info("[{$source}] Negotiators with a clear rate > 0: " . count($rows));

            $uploadDate = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

            foreach ($rows as $row) {
                if ($dryRun) {
                    $this->line("  [DRY RUN] Agent_ID={$row['agent_id']} Scheduled={$row['scheduled']} Cleared={$row['cleared']} Rate={$row['rate']}");
                    continue;
                }

                $this->insertClearRate($sqlServer, $source, $startDate, $endDate, $uploadDate, $row);
            }

            if (!$dryRun) {
                $this->info("[{$source}] Inserted " . count($rows) . ' row(s) into TblNGOEPFClearRates.');
            }

            return true;
        } catch (\Throwable $e) {
            $this->error("[{$source}] Sync failed: " . $e->getMessage());
            Log::error('SyncNGOEPFClearRates: sync failed', ['exception' => $e, 'source' => $source]);
            return false;
        }
    }

    /**
     * @return array<int, array{pk: int, uid: int}>
     */
    private function fetchActiveEmployees(DBConnector $sqlServer, string $uidColumn): array
    {
        $result = $sqlServer->querySqlServer("
            SELECT PK, {$uidColumn} AS UID
            FROM TblEmployees
            WHERE Term_Date IS NULL
              AND {$uidColumn} IS NOT NULL
        ");

        $employees = [];
        foreach ($result['data'] ?? [] as $row) {
            $employees[] = [
                'pk' => (int) $row['PK'],
                'uid' => (int) $row['UID'],
            ];
        }

        return $employees;
    }

    /**
     * Combines the VBA's two per-negotiator SUM queries (scheduled, cleared) into a single
     * GROUP BY so.NEG_ID query across every negotiator at once — same filters, same result.
     *
     * @param int[] $uids
     * @return array<int, array{scheduled: float, cleared: float}> Keyed by NEG_ID
     */
    private function fetchEpfSums(DBConnector $snowflake, array $uids, string $startDate, string $endDate): array
    {
        if (empty($uids)) {
            return [];
        }

        $uidList = implode(',', array_map('intval', array_unique($uids)));

        $sql = "
            SELECT
                so.NEG_ID,
                SUM(t.AMOUNT) AS EPF_SCHEDULED,
                SUM(CASE WHEN CAST(CONVERT_TIMEZONE('America/Los_Angeles', t.PROCESS_DATE) AS DATE) >= '{$startDate}' AND CAST(CONVERT_TIMEZONE('America/Los_Angeles', t.PROCESS_DATE) AS DATE) <= '{$endDate}' AND t.CLEARED_DATE IS NOT NULL THEN t.AMOUNT ELSE 0 END) AS EPF_CLEARED
            FROM TRANSACTIONS AS t
            JOIN TRANSACTIONS AS t1 ON (t.CONTACT_ID = t1.CONTACT_ID AND t1.TRANS_TYPE = 'S' AND t.LINKED_TO = t1.ID)
            LEFT JOIN CONTACTS AS c ON t.CONTACT_ID = c.ID
            JOIN SETTLEMENT_OFFERS AS so ON (t1.CONTACT_ID = so.CONTACT_ID AND t1.LINKED_TO = so.ID)
            JOIN DEBTS AS d ON so.DEBT_ID = d.ID
            WHERE t.TRANS_TYPE = 'PF'
              AND t.STATUS IN (0, 1, 4) AND so.OFFER_STATUS = 10
              AND t._FIVETRAN_DELETED = FALSE
              AND c._FIVETRAN_DELETED = FALSE
              AND so._FIVETRAN_DELETED = FALSE
              AND d._FIVETRAN_DELETED = FALSE
              AND d.SETTLEMENT_DATE >= '{$startDate}' AND d.SETTLEMENT_DATE <= '{$endDate}'
              AND so.NEG_ID IN ({$uidList})
            GROUP BY so.NEG_ID
        ";

        $result = $snowflake->query($sql);

        $sums = [];
        foreach ($result['data'] ?? [] as $row) {
            $negId = (int) ($row['NEG_ID'] ?? 0);
            $sums[$negId] = [
                'scheduled' => (float) ($row['EPF_SCHEDULED'] ?? 0),
                'cleared' => (float) ($row['EPF_CLEARED'] ?? 0),
            ];
        }

        return $sums;
    }

    /**
     * @param array{agent_id: int, scheduled: float, cleared: float, rate: float} $row
     */
    private function insertClearRate(
        DBConnector $sqlServer,
        string $source,
        string $startDate,
        string $endDate,
        string $uploadDate,
        array $row
    ): void {
        $sqlServer->querySqlServer("
            INSERT INTO TblNGOEPFClearRates (Start_Date, End_Date, Agent_ID, EPF_Scheduled, EPF_Cleared, EPF_Rate, Upload_Date, Source)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ", [$startDate, $endDate, $row['agent_id'], $row['scheduled'], $row['cleared'], $row['rate'], $uploadDate, $source]);
    }

    private function initializeSqlServerConnector(): DBConnector
    {
        $candidates = ['ldr', 'plaw', 'production', 'sandbox'];
        $errors = [];

        foreach ($candidates as $env) {
            try {
                $connector = DBConnector::fromEnvironment($env);
                $connector->initializeSqlServer();
                return $connector;
            } catch (\Throwable $e) {
                $errors[] = "{$env}: {$e->getMessage()}";
            }
        }

        throw new \RuntimeException('Unable to initialize SQL Server connector. Tried: ' . implode('; ', $errors));
    }
}
