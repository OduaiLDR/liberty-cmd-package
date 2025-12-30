<?php

namespace Cmd\Reports\Console\Commands;

use Cmd\Reports\Services\DBConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncVerifiedDebts extends Command
{
    protected $signature = 'sync:verified-debts';
    protected $description = 'Sync TblEnrollment.Verified_Debt_Amount using Snowflake DEBTS aggregates (VBA-equivalent rules).';

    /**
     * VBA rules:
     * - Only update contacts that exist in Query1 => they must have at least one debt row where VERIFIED_AMOUNT is 0.
     *   (In Snowflake terms: ROWS_VERIFIED_ZERO > 0)
     *
     * If you EVER want to ignore that rule and update everyone:
     * set this to false.
     */
    private const REQUIRE_VERIFIED_ZERO = true;

    /**
     * VBA hard rule:
     * - Only update TblEnrollment where Category = 'LDR'
     */
    private const ENROLLMENT_CATEGORY = 'LDR';

    /**
     * VBA hard rule:
     * - Skip if expected >= 1,000,000
     */
    private const MAX_EXPECTED = 1000000.00;

    /** Paging for Snowflake */
    private const SF_LIMIT = 10000;

    /** Update batch size for SQL Server VALUES list */
    private const MSSQL_BATCH = 400;

    public function handle(): int
    {
        $this->info('SyncVerifiedDebts: starting.');
        Log::info('SyncVerifiedDebts command started.');

        $connections = ['plaw', 'ldr'];
        $hadException = false;

        foreach ($connections as $connection) {
            $source = strtoupper($connection);
            $connector = null;

            $this->info("[$source] Initializing connectors...");
            Log::info('SyncVerifiedDebts: starting connection.', [
                'connection' => $connection,
                'source' => $source,
            ]);

            try {
                $connector = DBConnector::fromEnvironment($connection);
                $connector->initializeSqlServer();
                $this->ensureLogTable($connector);

                $this->info("[$source] Fetching Snowflake debt aggregates...");
                [$contactsProcessed, $eligibleMap] = $this->fetchEligibleDebtAggregatesFromSnowflake($connector);

                $eligibleCount = count($eligibleMap);

                if ($eligibleCount === 0) {
                    $this->warn("[$source] No eligible rows (VBA rules) found.");
                    $this->insertLogRow(
                        $connector,
                        $source,
                        'SYNC_VERIFIED_DEBTS',
                        'SUCCESS',
                        $contactsProcessed,
                        0,
                        "No eligible rows. Contacts processed={$contactsProcessed}."
                    );
                    continue;
                }

                $this->info("[$source] Applying updates to SQL Server in batches...");
                $updated = $this->applyVerifiedDebtUpdatesSqlServer($connector, $source, $eligibleMap);

                $this->info("[$source] Done. Contacts processed: {$contactsProcessed} | Eligible: {$eligibleCount} | Updated rows: {$updated}");

                $this->insertLogRow(
                    $connector,
                    $source,
                    'SYNC_VERIFIED_DEBTS',
                    'SUCCESS',
                    $contactsProcessed,
                    0,
                    "Contacts processed={$contactsProcessed}. Eligible={$eligibleCount}. Updated={$updated}."
                );

                Log::info('SyncVerifiedDebts: connection finished.', [
                    'connection' => $connection,
                    'source' => $source,
                    'processed' => $contactsProcessed,
                    'eligible' => $eligibleCount,
                    'updated' => $updated,
                ]);
            } catch (\Throwable $e) {
                $hadException = true;

                $this->error("[$source] SyncVerifiedDebts failed.");
                $this->error($e->getMessage());

                Log::error('SyncVerifiedDebts: exception during sync.', [
                    'connection' => $connection,
                    'source' => $source,
                    'exception' => $e,
                ]);

                // Try logging the failure to TblLog
                try {
                    if ($connector === null) {
                        $connector = DBConnector::fromEnvironment($connection);
                        $connector->initializeSqlServer();
                        $this->ensureLogTable($connector);
                    }

                    $errorMessage = $this->escapeAndTruncate($e->getMessage(), 900);
                    $this->insertLogRow(
                        $connector,
                        $source,
                        'SYNC_VERIFIED_DEBTS',
                        'FAILED',
                        0,
                        0,
                        $errorMessage
                    );
                } catch (\Throwable $logException) {
                    Log::error('SyncVerifiedDebts: failed to log to TblLog after exception.', [
                        'connection' => $connection,
                        'source' => $source,
                        'exception' => $logException,
                    ]);
                }
            }
        }

        $this->info('SyncVerifiedDebts: finished.');
        Log::info('SyncVerifiedDebts command finished.', [
            'status' => $hadException ? 'FAILURE' : 'SUCCESS',
        ]);

        return $hadException ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Returns:
     *  - contactsProcessed: int (grouped CONTACT_ID rows read from Snowflake)
     *  - eligibleMap: array llg_id => expected_verified_debt (string with 2 decimals)
     */
    protected function fetchEligibleDebtAggregatesFromSnowflake(DBConnector $connector): array
    {
        $limit = self::SF_LIMIT;
        $offset = 0;

        $contactsProcessed = 0;
        $eligibleMap = [];

        while (true) {
            $sql = <<<SQL
SELECT
    CONTACT_ID,
    COUNT(*) AS DEBT_ROWS,
    SUM(IFF(COALESCE(VERIFIED_AMOUNT, 0)=0, 1, 0)) AS ROWS_VERIFIED_ZERO,
    SUM(IFF(COALESCE(VERIFIED_AMOUNT, 0)=0, ORIGINAL_DEBT_AMOUNT, 0)) AS SUM_ORIGINAL_WHEN_VERIFIED_ZERO,
    SUM(IFF(COALESCE(VERIFIED_AMOUNT, 0)<>0, VERIFIED_AMOUNT, 0))     AS SUM_VERIFIED_WHEN_NONZERO
FROM DEBTS
WHERE ENROLLED = 1
  AND _FIVETRAN_DELETED = FALSE
GROUP BY CONTACT_ID
ORDER BY CONTACT_ID
LIMIT {$limit} OFFSET {$offset}
SQL;

            $result = $connector->query($sql);

            $rows = $this->normalizeConnectorRows($result);
            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                if (!is_array($row)) continue;

                $cid = $this->valueForKey($row, 'CONTACT_ID');
                if ($cid === null || trim((string)$cid) === '') continue;
                $cid = trim((string)$cid);

                $rowsVerifiedZero = $this->toInt($this->valueForKey($row, 'ROWS_VERIFIED_ZERO'));
                $sumOriginalZero = $this->toFloat($this->valueForKey($row, 'SUM_ORIGINAL_WHEN_VERIFIED_ZERO'));
                $sumVerifiedNonZero = $this->toFloat($this->valueForKey($row, 'SUM_VERIFIED_WHEN_NONZERO'));

                $expected = round($sumOriginalZero + $sumVerifiedNonZero, 2);

                $contactsProcessed++;

                // VBA VLOOKUP existence check (Query1 membership)
                if (self::REQUIRE_VERIFIED_ZERO && $rowsVerifiedZero <= 0) {
                    continue;
                }

                // VBA skip
                if ($expected >= self::MAX_EXPECTED) {
                    continue;
                }

                $llgId = $this->truncateString('LLG-' . $cid, 100);
                $eligibleMap[$llgId] = number_format($expected, 2, '.', '');
            }

            $offset += $limit;
        }

        $this->info("Fetched {$contactsProcessed} grouped contacts from Snowflake (before VBA filtering).");
        $this->info('Eligible (VBA rules) = ' . count($eligibleMap));

        return [$contactsProcessed, $eligibleMap];
    }

    /**
     * SQL Server updates using VALUES table + JOIN for speed.
     * - NULL-safe comparison
     * - tiny tolerance to avoid float noise
     * - Category filter = 'LDR' (VBA)
     */
    protected function applyVerifiedDebtUpdatesSqlServer(DBConnector $connector, string $source, array $eligibleMap): int
    {
        if (empty($eligibleMap)) return 0;

        $totalUpdated = 0;

        $batchSize = self::MSSQL_BATCH;
        $chunks = array_chunk($eligibleMap, $batchSize, true);

        $hasCategory = $this->sqlServerHasColumn($connector, 'dbo.TblEnrollment', 'Category');

        foreach ($chunks as $idx => $chunk) {
            $this->info("[$source] Applying updates... (" . ($idx + 1) . "/" . count($chunks) . ")");

            $valuesRows = [];
            foreach ($chunk as $llgId => $expected) {
                $llgEsc = $this->escapeAndTruncate($llgId, 100);

                $num = is_numeric($expected) ? (float)$expected : 0.0;
                $expectedNum = number_format($num, 2, '.', '');

                $valuesRows[] = "('{$llgEsc}', CAST({$expectedNum} AS DECIMAL(18,2)))";
            }

            if (empty($valuesRows)) continue;
            $valuesSql = implode(",\n", $valuesRows);

            $categoryFilterSql = '';
            if ($hasCategory) {
                $catEsc = $this->escapeAndTruncate(self::ENROLLMENT_CATEGORY, 10);
                $categoryFilterSql = " AND e.Category = '{$catEsc}'";
            }

            $sql = <<<SQL
;WITH src(LLG_ID, ExpectedDebt) AS (
    SELECT v.LLG_ID, v.ExpectedDebt
    FROM (VALUES
{$valuesSql}
    ) v(LLG_ID, ExpectedDebt)
)
UPDATE e
SET e.Verified_Debt_Amount = src.ExpectedDebt
FROM dbo.TblEnrollment e
JOIN src ON src.LLG_ID = e.LLG_ID
WHERE
    (
        e.Verified_Debt_Amount IS NULL
        OR ABS(CAST(e.Verified_Debt_Amount AS DECIMAL(18,2)) - CAST(src.ExpectedDebt AS DECIMAL(18,2))) > 0.005
    )
{$categoryFilterSql};

SELECT @@ROWCOUNT AS row_count;
SQL;

            $result = $connector->querySqlServer($sql);
            $updated = $this->extractRowCount($result);

            $totalUpdated += $updated;
        }

        return $totalUpdated;
    }

    protected function extractRowCount($result): int
    {
        // Try common shapes
        if (is_array($result)) {
            // Case 1: { data: [ { row_count: 123 } ] }
            if (isset($result['data'][0]) && is_array($result['data'][0])) {
                $rc = $this->valueForKey($result['data'][0], 'row_count');
                if ($rc !== null && is_numeric($rc)) return (int)$rc;
            }
            // Case 2: { rowCount: 123 } / { affected_rows: 123 } / etc
            foreach (['rowCount', 'affected_rows', 'row_count'] as $k) {
                if (isset($result[$k]) && is_numeric($result[$k])) return (int)$result[$k];
            }
        }
        return 0;
    }

    protected function normalizeConnectorRows($result): array
    {
        if (!is_array($result)) return [];

        if (isset($result['success']) && $result['success'] === false) {
            throw new \RuntimeException('Connector query returned success=false.');
        }

        if (isset($result['data']) && is_array($result['data'])) return $result['data'];
        if (array_is_list($result)) return $result;

        return [];
    }

    protected function sqlServerHasColumn(DBConnector $connector, string $table, string $column): bool
    {
        $tableEsc = $this->escapeSqlString($table);
        $colEsc = $this->escapeSqlString($column);

        $sql = "SELECT CASE WHEN COL_LENGTH('{$tableEsc}', '{$colEsc}') IS NULL THEN 0 ELSE 1 END AS has_col;";
        try {
            $res = $connector->querySqlServer($sql);
            if (is_array($res) && isset($res['data'][0]) && is_array($res['data'][0])) {
                $v = $this->valueForKey($res['data'][0], 'has_col');
                return $v !== null && (int)$v === 1;
            }
        } catch (\Throwable $e) {
            // ignore
        }
        return false;
    }

    protected function ensureLogTable(DBConnector $connector): void
    {
        // Assume TblLog exists.
    }

    /**
     * Writes TblLog AND prints to console so you SEE it in artisan output.
     * Also uses safe escape+truncate so logging can’t fail due to nvarchar limits.
     */
    protected function insertLogRow(
        DBConnector $connector,
        string $source,
        string $action,
        string $status,
        int $recordsProcessed,
        int $recordsDeleted,
        string $details
    ): void {
        $tableName = $this->escapeAndTruncate('TblEnrollment', 50);
        $macro = $this->escapeAndTruncate('SyncVerifiedDebts', 50);
        $actionLabel = $this->escapeAndTruncate(strtoupper($action ?: 'SYNC_VERIFIED_DEBTS'), 255);

        $description = $this->escapeAndTruncate("Sync verified debts for {$source}", 255);

        // IMPORTANT: details column isn’t in TblLog schema here, so we keep it only in Laravel logs
        $detailsSafe = $this->truncateString($details, 900);

        $resultSummary = $this->escapeAndTruncate(
            sprintf('S=%s A=%s P=%d D=%d', $status, $actionLabel, $recordsProcessed, $recordsDeleted),
            200 // adjust if your TblLog.Result is 200; if it's 50, change to 50
        );

        $timestamp = $this->escapeAndTruncate(now()->format('Y-m-d H:i:s'), 30);

        $this->info("[$source] TblLog: WRITE (status={$status}, processed={$recordsProcessed}, deleted={$recordsDeleted})");
        Log::info('SyncVerifiedDebts: TblLog write attempt.', [
            'source' => $source,
            'status' => $status,
            'action' => $actionLabel,
            'processed' => $recordsProcessed,
            'deleted' => $recordsDeleted,
            'details' => $detailsSafe,
        ]);

        $sql = <<<SQL
DECLARE @hasPK BIT = CASE WHEN COL_LENGTH('dbo.TblLog', 'PK') IS NULL THEN 0 ELSE 1 END;
DECLARE @isIdentity BIT = CASE WHEN COLUMNPROPERTY(OBJECT_ID('dbo.TblLog'), 'PK', 'IsIdentity') = 1 THEN 1 ELSE 0 END;

IF @hasPK = 1 AND @isIdentity = 0
BEGIN
    DECLARE @nextPK INT = ISNULL((SELECT MAX([PK]) FROM [dbo].[TblLog]), 0) + 1;
    INSERT INTO [dbo].[TblLog] ([PK], [Table_Name], [Macro], [Description], [Action], [Result], [Timestamp])
    VALUES (@nextPK, '{$tableName}', '{$macro}', '{$description}', '{$actionLabel}', '{$resultSummary}', '{$timestamp}');
END
ELSE
BEGIN
    INSERT INTO [dbo].[TblLog] ([Table_Name], [Macro], [Description], [Action], [Result], [Timestamp])
    VALUES ('{$tableName}', '{$macro}', '{$description}', '{$actionLabel}', '{$resultSummary}', '{$timestamp}');
END;
SQL;

        try {
            $res = $connector->querySqlServer($sql);

            if (is_array($res) && isset($res['success']) && $res['success'] === false) {
                $err = $res['error'] ?? 'Unknown SQL Server error';
                $this->error("[$source] TblLog: FAILED - {$err}");
                Log::error('SyncVerifiedDebts: TblLog write failed (success=false).', [
                    'source' => $source,
                    'error' => $err,
                    'result' => $res,
                ]);
                return;
            }

            $this->info("[$source] TblLog: OK");
            Log::info('SyncVerifiedDebts: TblLog write OK.', ['source' => $source]);
        } catch (\Throwable $e) {
            $this->error("[$source] TblLog: FAILED - " . $e->getMessage());
            Log::error('SyncVerifiedDebts: TblLog write exception.', [
                'source' => $source,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    protected function valueForKey(array $row, string $key): ?string
    {
        foreach ($row as $rowKey => $value) {
            if (strcasecmp((string)$rowKey, $key) === 0) {
                return $value !== null ? (string)$value : null;
            }
        }
        return null;
    }

    protected function toFloat($v): float
    {
        if ($v === null) return 0.0;
        $s = trim((string)$v);
        if ($s === '') return 0.0;
        return is_numeric($s) ? (float)$s : 0.0;
    }

    protected function toInt($v): int
    {
        if ($v === null) return 0;
        $s = trim((string)$v);
        if ($s === '') return 0;
        return is_numeric($s) ? (int)$s : 0;
    }

    protected function escapeSqlString(string $value): string
    {
        return str_replace("'", "''", $value);
    }

    /**
     * Escape + truncate safely so SQL literals don't break.
     * If truncation ends on a single quote, we drop it to avoid breaking the SQL string.
     */
    protected function escapeAndTruncate(string $value, int $maxLength): string
    {
        $escaped = $this->escapeSqlString($value);
        if (mb_strlen($escaped) <= $maxLength) {
            return $escaped;
        }

        $tr = mb_substr($escaped, 0, $maxLength);

        // If we ended with a single quote, drop it to avoid ending on an unmatched quote.
        if (mb_substr($tr, -1) === "'" && mb_substr($tr, -2) !== "''") {
            $tr = mb_substr($tr, 0, $maxLength - 1);
        }

        return $tr;
    }

    protected function truncateString(string $value, int $maxLength): string
    {
        return mb_strlen($value) <= $maxLength ? $value : mb_substr($value, 0, $maxLength);
    }
}
