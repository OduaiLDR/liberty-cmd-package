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
 * TblNGOEPFClearRates row per negotiator, including verified zero collection.
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
    protected $signature = 'sync:ngo-epf-clear-rates {--period= : Explicit payroll earnings month YYYY-MM} {--dry-run : Compute rates without writing to TblNGOEPFClearRates}';

    protected $description = 'Sync negotiator EPF clear rates (LDR + PLAW) into dbo.TblNGOEPFClearRates.';

    private const SOURCE_UID_COLUMN = ['LDR' => 'SF_UID_LDR', 'PLAW' => 'SF_UID_PLAW'];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        try {
            [$startDate, $endDate] = $this->resolveDateRange();
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());
            return Command::FAILURE;
        }
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
            'LDR' => $this->initializeSnowflakeConnector('ldr'),
            'PLAW' => $this->initializeSnowflakeConnector('plaw'),
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
        $period = $this->option('period');
        if ($period !== null && $period !== '') {
            if (!is_string($period) || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $period)) {
                throw new \InvalidArgumentException('period must be YYYY-MM.');
            }
            $start = new \DateTimeImmutable($period . '-01');
            return [$start->format('Y-m-d'), $start->modify('last day of this month')->format('Y-m-d')];
        }
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
            $sums = $this->fetchEpfSums($snowflake, $uids, $startDate, $endDate, $source);

            $rows = [];
            foreach ($employees as $employee) {
                $employeeSums = $sums[$employee['uid']] ?? null;
                $scheduled = $employeeSums['scheduled'] ?? 0.0;
                $cleared = $employeeSums['cleared'] ?? 0.0;

                // Keep the actual operands, including zero. A zero denominator is not a
                // verified collection ratio; Payroll Review applies its reviewed policy.
                $rate = $scheduled > 0 ? $cleared / $scheduled : 0.0;

                $rows[] = [
                    'agent_id' => $employee['pk'],
                    'scheduled' => $scheduled,
                    'cleared' => $cleared,
                    'rate' => round($rate, 6),
                ];
            }

            $this->info("[{$source}] Negotiators with verified collection operands: " . count($rows));

            $uploadDate = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

            foreach ($rows as $row) {
                if ($dryRun) {
                    $this->line("  [DRY RUN] Agent_ID={$row['agent_id']} Scheduled={$row['scheduled']} Cleared={$row['cleared']} Rate={$row['rate']}");
                    continue;
                }

                $this->insertClearRate($sqlServer, $source, $startDate, $endDate, $uploadDate, $row);
            }

            if (!$dryRun) {
                $this->info("[{$source}] Replaced " . count($rows) . ' agent-period row(s) in TblNGOEPFClearRates.');
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
        if (($result['success'] ?? false) !== true || !is_array($result['data'] ?? null)) {
            throw new \RuntimeException('Active negotiator source failed. No collection data was replaced.');
        }
        foreach ($result['data'] ?? [] as $row) {
            if (!is_numeric($row['PK'] ?? null) || !is_numeric($row['UID'] ?? null) || (int)$row['PK'] <= 0 || (int)$row['UID'] <= 0) {
                throw new \RuntimeException('Invalid employee/Forth collection identity.');
            }
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
    private function fetchEpfSums(DBConnector $snowflake, array $uids, string $startDate, string $endDate, string $source): array
    {
        if (empty($uids)) {
            return [];
        }

        $uidList = implode(',', array_map('intval', array_unique($uids)));

        $nextMonth = (new \DateTimeImmutable($endDate))->modify('+1 day')->format('Y-m-d');
        $companyId = $source === 'LDR' ? 85781 : 85785;
        // The connector is company-specific; the join is additionally contact-qualified.
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
              AND (t._FIVETRAN_DELETED = FALSE OR t._FIVETRAN_DELETED IS NULL)
              AND (c._FIVETRAN_DELETED = FALSE OR c._FIVETRAN_DELETED IS NULL)
              AND (t1._FIVETRAN_DELETED = FALSE OR t1._FIVETRAN_DELETED IS NULL)
              AND (so._FIVETRAN_DELETED = FALSE OR so._FIVETRAN_DELETED IS NULL)
              AND (d._FIVETRAN_DELETED = FALSE OR d._FIVETRAN_DELETED IS NULL)
              AND t1.CONTACT_ID = d.CONTACT_ID AND c.ID = so.CONTACT_ID
              AND c.COMPANY_ID = {$companyId}
              AND d.SETTLEMENT_DATE >= '{$startDate}' AND d.SETTLEMENT_DATE < '{$nextMonth}'
              AND so.NEG_ID IN ({$uidList})
            GROUP BY so.NEG_ID
        ";

        $result = $snowflake->query($sql);

        $sums = [];
        if (($result['success'] ?? true) !== true || !is_array($result['data'] ?? null)
            || !isset($result['rowCount']) || (int)$result['rowCount'] !== count($result['data'])) {
            throw new \RuntimeException('EPF source is unavailable or incomplete. Existing rates were preserved.');
        }
        foreach ($result['data'] ?? [] as $row) {
            $negId = (int) ($row['NEG_ID'] ?? 0);
            if ($negId <= 0 || isset($sums[$negId]) || !is_numeric($row['EPF_SCHEDULED'] ?? null) || !is_numeric($row['EPF_CLEARED'] ?? null)
                || (float)$row['EPF_SCHEDULED'] < 0 || (float)$row['EPF_CLEARED'] < 0) {
                throw new \RuntimeException('EPF source contains ambiguous or invalid collection amounts.');
            }
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
        $result = $sqlServer->querySqlServer("
            SET XACT_ABORT ON;
            BEGIN TRY
            BEGIN TRANSACTION;
            DELETE FROM TblNGOEPFClearRates WITH (UPDLOCK, HOLDLOCK)
            WHERE Start_Date = ? AND End_Date = ? AND Agent_ID = ? AND Source = ?;
            INSERT INTO TblNGOEPFClearRates (Start_Date, End_Date, Agent_ID, EPF_Scheduled, EPF_Cleared, EPF_Rate, Upload_Date, Source)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?);
            COMMIT TRANSACTION;
            END TRY
            BEGIN CATCH
              IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
              THROW;
            END CATCH
        ", [$startDate, $endDate, $row['agent_id'], $source,
            $startDate, $endDate, $row['agent_id'], $row['scheduled'], $row['cleared'], $row['rate'], $uploadDate, $source]);
        if (($result['success'] ?? false) !== true) {
            throw new \RuntimeException('Collection rate replacement failed; the agent transaction was rolled back.');
        }
    }

    protected function initializeSnowflakeConnector(string $source): DBConnector
    {
        return DBConnector::fromEnvironment($source);
    }

    protected function initializeSqlServerConnector(): DBConnector
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
