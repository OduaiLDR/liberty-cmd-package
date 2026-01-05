<?php

namespace Cmd\Reports\Console\Commands;

use Cmd\Reports\Services\DBConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncSubmittedDate extends Command
{
    protected $signature = 'sync:submitted-date';

    protected $description = 'Sync Submitted_Date in TblEnrollment from Snowflake CONTACTS_STATUS (statuses 123480, 377643, 377680, 37768; last 7 days)';

    public function handle(): int
    {
        $this->info('Submitted date sync: starting.');
        Log::info('SyncSubmittedDate command started.');

        $connections = ['plaw', 'ldr'];
        $hadException = false;

        foreach ($connections as $connection) {
            $source = strtoupper($connection);

            $this->info("[$source] Starting submitted date sync.");
            Log::info('SyncSubmittedDate: starting connection.', [
                'connection' => $connection,
                'source' => $source,
            ]);

            try {
                $connector = DBConnector::fromEnvironment($connection);
                $connector->initializeSqlServer();
                $this->ensureLogTable($connector);

                $this->info("[$source] Fetching submitted dates from Snowflake...");
                $submitted = $this->fetchSubmittedFromSnowflake($connector);

                if (empty($submitted)) {
                    $this->warn("[$source] No submitted dates found in Snowflake.");
                    $this->insertLogRow(
                        $connector,
                        $source,
                        'SYNC_SUBMITTED_DATE',
                        'SUCCESS',
                        0,
                        0,
                        'No submitted dates found to sync.'
                    );
                    continue;
                }

                $this->info("[$source] Applying submitted dates to SQL Server...");
                $updated = $this->updateSubmittedDates($connector, $submitted);

                $this->info("[$source] Updated {$updated} submitted dates.");
                $this->insertLogRow(
                    $connector,
                    $source,
                    'SYNC_SUBMITTED_DATE',
                    'SUCCESS',
                    $updated,
                    0,
                    sprintf('Submitted rows fetched: %d. Updated: %d.', count($submitted), $updated)
                );

                Log::info('SyncSubmittedDate: connection finished.', [
                    'connection' => $connection,
                    'source' => $source,
                    'updated' => $updated,
                ]);
            } catch (\Throwable $e) {
                $hadException = true;

                $this->error("Submitted date sync failed for connection [{$connection}] ({$source}).");
                $this->error($e->getMessage());

                Log::error('SyncSubmittedDate: exception during sync.', [
                    'connection' => $connection,
                    'source' => $source,
                    'exception' => $e,
                ]);

                try {
                    if (!isset($connector)) {
                        $connector = DBConnector::fromEnvironment($connection);
                        $connector->initializeSqlServer();
                        $this->ensureLogTable($connector);
                    }

                    $errorMessage = mb_substr($e->getMessage(), 0, 900);

                    $this->insertLogRow(
                        $connector,
                        $source,
                        'SYNC_SUBMITTED_DATE',
                        'FAILED',
                        0,
                        0,
                        $errorMessage
                    );
                } catch (\Throwable $logException) {
                    Log::error('SyncSubmittedDate: failed to log to TblLog after exception.', [
                        'connection' => $connection,
                        'source' => $source,
                        'exception' => $logException,
                    ]);
                }
            }
        }

        $this->info('Submitted date sync: finished.');
        Log::info('SyncSubmittedDate command finished.', [
            'status' => $hadException ? 'FAILURE' : 'SUCCESS',
        ]);

        return $hadException ? Command::FAILURE : Command::SUCCESS;
    }

    protected function fetchMissingIdsFromSqlServer(DBConnector $connector): array
    {
        $hasLookbackDate = false;
        $columnCheckSql = <<<SQL
SELECT CASE WHEN COL_LENGTH('dbo.TblEnrollment', 'Lookback_Date') IS NULL THEN 0 ELSE 1 END AS has_col
SQL;

        $columnCheck = $connector->querySqlServer($columnCheckSql);
        if (is_array($columnCheck)) {
            if (isset($columnCheck['data'][0]['has_col'])) {
                $hasLookbackDate = (int) $columnCheck['data'][0]['has_col'] === 1;
            } elseif (isset($columnCheck['has_col'])) {
                $hasLookbackDate = (int) $columnCheck['has_col'] === 1;
            }
        }

        $lookbackFilter = '';
        if ($hasLookbackDate) {
            $lookbackFilter = "  AND (Lookback_Date IS NULL OR Lookback_Date >= DATEADD(day, -90, CAST(GETDATE() AS date)))\n";
        }

        $sql = <<<SQL
SELECT LLG_ID
FROM TblEnrollment
WHERE Submitted_Date IS NULL
{$lookbackFilter}
SQL;

        $result = $connector->querySqlServer($sql);

        if (is_array($result)) {
            if (isset($result['data']) && is_array($result['data'])) {
                $rows = $result['data'];
            } elseif (array_is_list($result)) {
                $rows = $result;
            } else {
                $rows = [];
            }
        } else {
            $rows = [];
        }

        $ids = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach ($row as $key => $value) {
                if (strcasecmp($key, 'LLG_ID') === 0 && $value !== null && $value !== '') {
                    $ids[] = (string) $value;
                    break;
                }
            }
        }

        $this->info('Found ' . count($ids) . ' IDs missing Submitted_Date.');

        return $ids;
    }

    protected function fetchSubmittedFromSnowflake(DBConnector $connector): array
    {
        $baseSql = <<<SQL
SELECT
    CONTACT_ID,
    TO_CHAR(STAMP, 'YYYY-MM-DD') AS SUBMITTED_DATE
FROM CONTACTS_STATUS
WHERE STATUS_ID IN (123480, 377643, 377680, 37768)
  AND STAMP >= DATEADD(day, -7, CURRENT_DATE)
ORDER BY STAMP ASC
SQL;

        $submitted = [];
        try {
            $result = $connector->query($baseSql);
        } catch (\Throwable $e) {
            $this->warn("[$connector->getConnectionName()] Snowflake query failed: {$e->getMessage()}");
            Log::warning('SyncSubmittedDate: Snowflake query failed.', [
                'error' => $e->getMessage(),
            ]);
            $result = [];
        }

        if (is_array($result)) {
            if (isset($result['success']) && $result['success'] === false) {
                Log::warning('SyncSubmittedDate: Snowflake returned success=false.', [
                    'result' => $result,
                ]);
                $rows = [];
            } elseif (isset($result['data']) && is_array($result['data'])) {
                $rows = $result['data'];
            } elseif (array_is_list($result)) {
                $rows = $result;
            } else {
                $rows = [];
            }
        } else {
            $rows = [];
        }

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $cid = null;
            $submittedDate = null;

            foreach ($row as $key => $value) {
                if (strcasecmp($key, 'CONTACT_ID') === 0) {
                    $cid = (string) $value;
                } elseif (strcasecmp($key, 'SUBMITTED_DATE') === 0) {
                    $submittedDate = (string) $value;
                }
            }

            if ($cid === null || $cid === '' || $submittedDate === null || $submittedDate === '') {
                continue;
            }

            $llgId = $this->truncateString('LLG-' . $cid, 100);
            $submitted[$llgId] = $submittedDate;
        }

        $this->info('Fetched ' . count($submitted) . ' submitted dates from Snowflake.');

        return $submitted;
    }

    protected function updateSubmittedDates(DBConnector $connector, array $submitted): int
    {
        if (empty($submitted)) {
            return 0;
        }

        $totalUpdated = 0;
        $batchSize = 500;
        $batches = array_chunk($submitted, $batchSize, true);
        $batchIndex = 0;

        foreach ($batches as $chunk) {
            $batchIndex++;
            $cases = [];
            $ids = [];

            foreach ($chunk as $llgId => $submittedDate) {
                $llgEsc = $this->truncateString($this->escapeSqlString($llgId), 100);
                $dateEsc = $this->truncateString($this->escapeSqlString($submittedDate), 50);
                $cases[] = "WHEN '{$llgEsc}' THEN '{$dateEsc}'";
                $ids[] = "'{$llgEsc}'";
            }

            if (empty($cases) || empty($ids)) {
                continue;
            }

            $caseSql = implode(' ', $cases);
            $idList = implode(', ', $ids);

            $sql = <<<SQL
UPDATE TblEnrollment
SET Submitted_Date = CASE LLG_ID {$caseSql} END
WHERE LLG_ID IN ({$idList});
SQL;

            $result = $connector->querySqlServer($sql);

            if (is_array($result)) {
                foreach (['rowCount', 'affected_rows', 'row_count'] as $key) {
                    if (isset($result[$key]) && is_numeric($result[$key])) {
                        $totalUpdated += (int) $result[$key];
                        continue 2;
                    }
                }
            }

            // Fallback to chunk size if no count returned
            $totalUpdated += count($chunk);
        }

        return $totalUpdated;
    }

    protected function ensureLogTable(DBConnector $connector): void
    {
        // Assume TblLog exists.
    }

    protected function insertLogRow(
        DBConnector $connector,
        string $source,
        string $action,
        string $status,
        int $recordsProcessed,
        int $recordsDeleted,
        string $details
    ): void {
        // TblLog schema: Table_Name/Macro NVARCHAR(50), Description/Action NVARCHAR(255), Result NVARCHAR(50)
        $tableName = 'TblEnrollment';
        $macro = 'SyncSubmittedDate';
        $actionLabel = $action !== '' ? strtoupper($action) : 'SYNC_SUBMITTED_DATE';

        $description = $this->truncateString(
            sprintf('Sync submitted date for %s', $source),
            255
        );
        $descriptionEsc = $this->escapeSqlString($description);

        $details = $this->truncateString($details, 200);
        $resultSummary = $this->truncateString(
            sprintf(
                'S=%s A=%s P=%d D=%d',
                $status,
                $actionLabel,
                $recordsProcessed,
                $recordsDeleted
            ),
            50
        );
        $resultSummaryEsc = $this->escapeSqlString($resultSummary);

        $actionSanitized = $this->truncateString($actionLabel, 255);
        $actionEsc = $this->escapeSqlString($actionSanitized);
        $tableName = $this->truncateString($tableName, 50);
        $tableNameEsc = $this->escapeSqlString($tableName);
        $macro = $this->truncateString($macro, 50);
        $macroEsc = $this->escapeSqlString($macro);

        $timestamp = now()->format('Y-m-d H:i:s');
        $timestampEsc = $this->escapeSqlString($timestamp);

        $this->info(sprintf('[%s] Writing log entry to TblLog...', $source));

        $sql = <<<SQL
DECLARE @hasPK BIT = CASE WHEN COL_LENGTH('dbo.TblLog', 'PK') IS NULL THEN 0 ELSE 1 END;
DECLARE @isIdentity BIT = CASE WHEN COLUMNPROPERTY(OBJECT_ID('dbo.TblLog'), 'PK', 'IsIdentity') = 1 THEN 1 ELSE 0 END;

IF @hasPK = 1 AND @isIdentity = 0
BEGIN
    DECLARE @nextPK INT = ISNULL((SELECT MAX([PK]) FROM [dbo].[TblLog]), 0) + 1;
    INSERT INTO [dbo].[TblLog] ([PK], [Table_Name], [Macro], [Description], [Action], [Result], [Timestamp])
    VALUES (@nextPK, '{$tableNameEsc}', '{$macroEsc}', '{$descriptionEsc}', '{$actionEsc}', '{$resultSummaryEsc}', '{$timestampEsc}');
END
ELSE
BEGIN
    INSERT INTO [dbo].[TblLog] ([Table_Name], [Macro], [Description], [Action], [Result], [Timestamp])
    VALUES ('{$tableNameEsc}', '{$macroEsc}', '{$descriptionEsc}', '{$actionEsc}', '{$resultSummaryEsc}', '{$timestampEsc}');
END;
SQL;

        try {
            $result = $connector->querySqlServer($sql);

            if (is_array($result) && isset($result['success']) && $result['success'] === false) {
                $errorMsg = $result['error'] ?? 'Unknown SQL Server error';
                $this->error(sprintf('[%s] Log insert failed: %s', $source, $errorMsg));
                Log::error('SyncSubmittedDate: log insert failed.', [
                    'source' => $source,
                    'sql' => $sql,
                    'result' => $result,
                ]);
                return;
            }

            $this->info(sprintf('[%s] Log entry inserted into TblLog.', $source));
        } catch (\Throwable $e) {
            $this->error(sprintf('[%s] Log insert failed: %s', $source, $e->getMessage()));
            Log::error('SyncSubmittedDate: log insert failed.', [
                'source' => $source,
                'sql' => $sql,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    protected function escapeSqlString(string $value): string
    {
        return str_replace("'", "''", $value);
    }

    protected function truncateString(string $value, int $maxLength): string
    {
        if (mb_strlen($value) <= $maxLength) {
            return $value;
        }

        return mb_substr($value, 0, $maxLength);
    }
}
