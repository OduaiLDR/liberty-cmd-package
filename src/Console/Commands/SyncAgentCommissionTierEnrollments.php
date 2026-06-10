<?php

namespace Cmd\Reports\Console\Commands;

use Cmd\Reports\Services\DBConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PDO;

/**
 * Sync monthly agent commission tier deals/debt from CCS Snowflake into
 * CommissionDatabaseCCS.dbo.TblAgentCommissionTierEnrollments.
 *
 * Commission period (matches legacy VBA exactly):
 *   - If today's day-of-month > 6: Start_Date = 6th of CURRENT month
 *   - Otherwise:                   Start_Date = 6th of PREVIOUS month
 *   - End_Date  = 5th of the month AFTER Start_Date
 *   - CutOff    = 21st of the month AFTER Start_Date (status freeze for cancellations / NSF)
 *
 * Per period the table is replaced atomically: DELETE WHERE Start_Date = period,
 * then INSERT freshly aggregated rows. Re-running for the same period is idempotent.
 */
class SyncAgentCommissionTierEnrollments extends Command
{
    protected $signature = 'Sync:agent-commission-tier-enrollments';

    protected $description = 'Sync monthly agent commission tier deals/debt from CCS Snowflake into TblAgentCommissionTierEnrollments';

    // CCS Snowflake CONTACTS_USERFIELDS custom IDs
    private const CF_FPC_DATE    = 676514; // First Payment Cleared Date
    private const CF_CANCEL_DATE = 679265; // Cancel / Dropped Date

    public function handle(): int
    {
        $this->info('[INFO] SyncAgentCommissionTierEnrollments: starting.');

        [$startDate, $endDate, $cutOffDate] = $this->computePeriod();

        $this->info(sprintf(
            '[INFO] Period: %s -> %s (status cutoff %s)',
            $startDate,
            $endDate,
            $cutOffDate
        ));

        // ── CCS Snowflake ─────────────────────────────────────────────────────
        $this->info('[INFO] Initializing CCS Snowflake connector...');
        try {
            $snowflake = DBConnector::fromEnvironment('ccs');
        } catch (\Throwable $e) {
            $this->error('[ERROR] Failed to initialize CCS Snowflake connector: ' . $e->getMessage());
            Log::error('SyncAgentCommissionTierEnrollments: Snowflake init failed', ['exception' => $e]);
            return Command::FAILURE;
        }

        // ── CCS SQL Server (raw PDO; same pattern as SyncContactsCCS) ─────────
        $this->info('[INFO] Initializing CCS SQL Server connection...');
        try {
            $ccsPdo = $this->initializeCcsPdo();
        } catch (\Throwable $e) {
            $this->error('[ERROR] Failed to connect to CCS SQL Server: ' . $e->getMessage());
            Log::error('SyncAgentCommissionTierEnrollments: CCS PDO failed', ['exception' => $e]);
            return Command::FAILURE;
        }

        // ── Fetch aggregated agent rows ───────────────────────────────────────
        $this->info('[INFO] Fetching agent commission tier data from Snowflake...');
        try {
            $rows = $this->fetchAgentData($snowflake, $startDate, $endDate, $cutOffDate);
        } catch (\Throwable $e) {
            $this->error('[ERROR] Snowflake query failed: ' . $e->getMessage());
            Log::error('SyncAgentCommissionTierEnrollments: Snowflake query failed', ['exception' => $e]);
            return Command::FAILURE;
        }

        $this->info(sprintf('[INFO] Fetched %d agent rows from Snowflake.', count($rows)));

        // ── Replace the period's rows atomically ──────────────────────────────
        try {
            $deleted = $this->deletePeriod($ccsPdo, $startDate);
            $this->info("[INFO] Deleted {$deleted} existing rows for Start_Date = {$startDate}.");

            $inserted = $this->insertRows($ccsPdo, $rows, $startDate, $endDate);
            $this->info("[INFO] Inserted {$inserted} rows.");
        } catch (\Throwable $e) {
            $this->error('[ERROR] SQL Server write failed: ' . $e->getMessage());
            Log::error('SyncAgentCommissionTierEnrollments: write failed', ['exception' => $e]);
            return Command::FAILURE;
        }

        $this->info('[SUCCESS] SyncAgentCommissionTierEnrollments completed successfully!');
        return Command::SUCCESS;
    }

    // -------------------------------------------------------------------------
    // Period computation (matches legacy VBA exactly)
    // -------------------------------------------------------------------------

    /**
     * @return array{0:string, 1:string, 2:string} [startDate, endDate, cutOffDate] as 'Y-m-d'
     */
    private function computePeriod(): array
    {
        $today = new \DateTimeImmutable('today');
        $day   = (int) $today->format('j');
        $year  = (int) $today->format('Y');
        $month = (int) $today->format('n');

        if ($day > 6) {
            // Start = 6th of CURRENT month
            $startY = $year;
            $startM = $month;
        } else {
            // Start = 6th of PREVIOUS month. mktime() handles year rollover (Jan -> Dec last year).
            $prev   = mktime(0, 0, 0, $month - 1, 6, $year);
            $startY = (int) date('Y', $prev);
            $startM = (int) date('n', $prev);
        }

        $startDate  = sprintf('%04d-%02d-06', $startY, $startM);
        $endDate    = date('Y-m-d', mktime(0, 0, 0, $startM + 1, 5,  $startY));
        $cutOffDate = date('Y-m-d', mktime(0, 0, 0, $startM + 1, 21, $startY));

        return [$startDate, $endDate, $cutOffDate];
    }

    // -------------------------------------------------------------------------
    // Snowflake fetch
    // -------------------------------------------------------------------------

    private function fetchAgentData(
        DBConnector $snowflake,
        string $startDate,
        string $endDate,
        string $cutOffDate
    ): array {
        $cfFpc    = self::CF_FPC_DATE;
        $cfCancel = self::CF_CANCEL_DATE;

        $sql = <<<SQL
SELECT
    AGENT,
    COUNT(*)           AS TIER_DEALS,
    SUM(ENROLLED_DEBT) AS ENROLLED_DEBT_TOTAL
FROM (
    SELECT
        CONCAT(u.FIRSTNAME, ' ', u.LASTNAME) AS AGENT,
        cu1.CONTACT_ID,
        cu1.F_DATE                          AS FIRST_PAYMENT_DATE,
        d.ENROLLED_DEBT,
        cu2.CANCEL_DATE,
        cls.TITLE                           AS CUTOFF_STATUS,
        LEFT(cs.STAMP, 10)                  AS STATUS_DATE,
        ROW_NUMBER() OVER (PARTITION BY cs.CONTACT_ID ORDER BY cs.STAMP DESC) AS N
    FROM CONTACTS_USERFIELDS AS cu1
    LEFT JOIN CONTACTS             AS c   ON cu1.CONTACT_ID = c.ID
    LEFT JOIN USERS                AS u   ON c.ASSIGNED_TO  = u.UID
    LEFT JOIN (
        SELECT CONTACT_ID, F_DATE AS CANCEL_DATE
        FROM CONTACTS_USERFIELDS
        WHERE CUSTOM_ID = {$cfCancel}
    ) AS cu2                                ON cu1.CONTACT_ID = cu2.CONTACT_ID
    LEFT JOIN (
        SELECT CONTACT_ID, SUM(ORIGINAL_DEBT_AMOUNT) AS ENROLLED_DEBT
        FROM DEBTS
        WHERE ENROLLED = 1
        GROUP BY CONTACT_ID
    ) AS d                                  ON cu1.CONTACT_ID = d.CONTACT_ID
    LEFT JOIN CONTACTS_STATUS      AS cs    ON cu1.CONTACT_ID = cs.CONTACT_ID
    LEFT JOIN CONTACTS_LEAD_STATUS AS cls   ON cs.STATUS_ID   = cls.ID
    WHERE cu1.CUSTOM_ID = {$cfFpc}
      AND cu1.F_DATE  >= '{$startDate}'
      AND cu1.F_DATE  <= '{$endDate}'
      AND cs.STAMP    <  '{$cutOffDate}'
)
WHERE N = 1
  AND (CANCEL_DATE IS NULL OR CANCEL_DATE >= '{$cutOffDate}')
  AND CUTOFF_STATUS <> 'NSF'
GROUP BY AGENT
ORDER BY AGENT ASC
SQL;

        $result = $snowflake->query($sql);
        return $result['data'] ?? [];
    }

    // -------------------------------------------------------------------------
    // CCS SQL Server (raw PDO — mirrors SyncContactsCCS::initializeCcsPdo)
    // -------------------------------------------------------------------------

    private function initializeCcsPdo(): PDO
    {
        $host     = env('CCS_DB_HOST', '');
        $port     = env('CCS_DB_PORT', '1433');
        $database = env('CCS_DB_DATABASE', '');
        $username = env('CCS_DB_USERNAME', '');
        $password = env('CCS_DB_PASSWORD', '');

        if (empty($host) || empty($database)) {
            throw new \RuntimeException(
                'CCS SQL Server credentials are not configured. Set CCS_DB_HOST and CCS_DB_DATABASE in .env.'
            );
        }

        $dsn = "sqlsrv:Server={$host},{$port};Database={$database};TrustServerCertificate=true;Encrypt=yes";
        return new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    private function deletePeriod(PDO $pdo, string $startDate): int
    {
        $stmt = $pdo->prepare("DELETE FROM dbo.TblAgentCommissionTierEnrollments WHERE Start_Date = :start");
        $stmt->execute([':start' => $startDate]);
        return $stmt->rowCount();
    }

    private function insertRows(PDO $pdo, array $rows, string $startDate, string $endDate): int
    {
        if (empty($rows)) {
            return 0;
        }

        $pdo->beginTransaction();
        try {
            $total = 0;
            foreach (array_chunk($rows, 500) as $batch) {
                $valueParts = [];

                foreach ($batch as $row) {
                    $agent = $this->getField($row, 'AGENT');
                    $deals = $this->getField($row, 'TIER_DEALS');
                    $debt  = $this->getField($row, 'ENROLLED_DEBT_TOTAL');

                    // Skip rows where AGENT is null/blank (unassigned contacts).
                    if ($agent === null || trim((string) $agent) === '') {
                        continue;
                    }

                    $agentEsc  = $this->escSql(mb_substr((string) $agent, 0, 50));
                    $dealsInt  = (int) $deals;
                    $debtFloat = is_numeric($debt) ? (float) $debt : 0.0;

                    $valueParts[] = sprintf(
                        "('%s', %d, %s, '%s', '%s')",
                        $agentEsc,
                        $dealsInt,
                        number_format($debtFloat, 2, '.', ''),
                        $startDate,
                        $endDate
                    );
                }

                if (empty($valueParts)) {
                    continue;
                }

                $sql = "INSERT INTO dbo.TblAgentCommissionTierEnrollments "
                    . "(Agent, Tier_Deals, Enrolled_Debt, Start_Date, End_Date) "
                    . "VALUES " . implode(', ', $valueParts);

                $pdo->exec($sql);
                $total += count($valueParts);
            }

            $pdo->commit();
            return $total;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Case-insensitive field lookup (Snowflake API typically returns UPPERCASE keys).
     *
     * @return mixed
     */
    private function getField(array $row, string $key)
    {
        foreach ($row as $k => $v) {
            if (strcasecmp($k, $key) === 0) {
                return $v;
            }
        }
        return null;
    }

    private function escSql(string $value): string
    {
        return str_replace("'", "''", $value);
    }
}
