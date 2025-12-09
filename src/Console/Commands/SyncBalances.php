<?php

namespace Cmd\Reports\Console\Commands;

use Cmd\Reports\Services\DBConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncBalances extends Command
{
    /**  
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'balance:sync {--batch-size=1000 : Number of records to insert per batch}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync contact balances from Snowflake to SQL Server';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Balance sync: starting.'); // for user to see in terminal
        Log::info('SyncBalances command started.'); // for logging 

        $batchSize = (int) $this->option('batch-size');
        if ($batchSize <= 0) {
            $batchSize = 1000; //batch size decided by jacob
        }

        $connections = ['plaw', 'ldr'];
        $hadException = false;

        foreach ($connections as $connection) {
            $source = strtoupper($connection);

            $this->info("[$source] Starting.");
            Log::info('SyncBalances: starting connection.', [
                'connection' => $connection,
                'source' => $source,
            ]);

            try {
                $connector = DBConnector::fromEnvironment($connection);
                $connector->initializeSqlServer();
                $this->ensureBalancesImportTime($connector);

                $fetchResult = $this->fetchLatestBalancesFromSnowflake($connector);

                if (!is_array($fetchResult)) {
                    $message = sprintf(
                        'Invalid Snowflake response for source %s (not an array).',
                        $source
                    );

                    $this->warn($message);
                    Log::warning('SyncBalances: invalid Snowflake response format.', [
                        'connection' => $connection,
                        'source' => $source,
                        'result' => $fetchResult,
                    ]);

                    $this->ensureLogTable($connector);
                    $this->insertLogRow(
                        $connector,
                        $source,
                        'FAILED',
                        'FAILED',
                        0,
                        0,
                        'Invalid Snowflake response format when fetching balances.'
                    );

                    $this->info("Finished balance sync for connection [{$connection}] (invalid response).");
                    continue;
                }

                $balances = $fetchResult['data'] ?? [];

                if (!is_array($balances) || count($balances) === 0) {
                    $message = sprintf(
                        'No balances fetched from Snowflake for source %s (rowCount: %s).',
                        $source,
                        $fetchResult['rowCount'] ?? '0'
                    );

                    $this->warn($message);
                    Log::warning('SyncBalances: no Snowflake data.', [
                        'connection' => $connection,
                        'source' => $source,
                        'result' => $fetchResult,
                    ]);

                    $this->ensureLogTable($connector);
                    $this->insertLogRow(
                        $connector,
                        $source,
                        'DELETE_AND_INSERT',
                        'SUCCESS',
                        0,
                        0,
                        'No balances found to sync from Snowflake.'
                    );

                    $this->info("Finished balance sync for connection [{$connection}] (no data).");
                    continue;
                }

                $totalToInsert = count($balances);

                $this->info("Fetched {$totalToInsert} balance rows from Snowflake for source {$source}.");
                Log::info('SyncBalances: fetched Snowflake rows.', [
                    'connection' => $connection,
                    'source' => $source,
                    'rowCount' => $fetchResult['rowCount'] ?? $totalToInsert,
                ]);

                $deletedCount = $this->deleteExistingBalances($connector, $source);

                $this->info("[$source] Deleted {$deletedCount} existing rows.");
                Log::info('SyncBalances: deleted existing balances.', [
                    'connection' => $connection,
                    'source' => $source,
                    'recordsDeleted' => $deletedCount,
                ]);

                $this->info("[$source] Preparing to insert " . count($balances) . " rows.");

                $insertedCount = $this->insertBalancesInBatches($connector, $balances, $source, $batchSize);

                $this->info("[$source] Inserted {$insertedCount} rows.");
                Log::info('SyncBalances: inserted balances.', [
                    'connection' => $connection,
                    'source' => $source,
                    'recordsInserted' => $insertedCount,
                ]);

                try {
                    $this->ensureLogTable($connector);

                    $resultSummary = sprintf(
                        'Processed: %d, Deleted: %d',
                        $insertedCount,
                        $deletedCount
                    );

                    $details = sprintf(
                        'Sync completed successfully for source %s at %s. Expected %d rows from Snowflake.',
                        $source,
                        now()->toDateTimeString(),
                        $totalToInsert
                    );

                    $this->insertLogRow(
                        $connector,
                        $source,
                        'DELETE_AND_INSERT',
                        'SUCCESS',
                        $insertedCount,
                        $deletedCount,
                        $resultSummary . ' ' . $details
                    );
                } catch (\Throwable $logException) {
                    Log::warning('SyncBalances: failed to insert success log row.', [
                        'connection' => $connection,
                        'source' => $source,
                        'exception' => $logException,
                    ]);
                }

                if ($insertedCount !== $totalToInsert) {
                    $this->warn(sprintf(
                        '[%s] Inserted count (%d) differs from fetched count (%d).',
                        $source,
                        $insertedCount,
                        $totalToInsert
                    ));
                    Log::warning('SyncBalances: inserted count mismatch.', [
                        'connection' => $connection,
                        'source' => $source,
                        'fetched' => $totalToInsert,
                        'inserted' => $insertedCount,
                    ]);
                }

                $this->info("[$source] Done.");
                Log::info('SyncBalances: connection finished.', [
                    'connection' => $connection,
                    'source' => $source,
                ]);
            } catch (\Throwable $e) {
                $hadException = true;

                $this->error("Balance sync failed for connection [{$connection}] (source: {$source}).");
                $this->error($e->getMessage());

                Log::error('SyncBalances: exception during balance sync.', [
                    'connection' => $connection,
                    'source' => $source,
                    'exception' => $e,
                ]);

                try {
                    if (!isset($connector)) {
                        $connector = DBConnector::fromEnvironment($connection);
                        $connector->initializeSqlServer();
                    }

                    $this->ensureLogTable($connector);

                    $errorMessage = mb_substr($e->getMessage(), 0, 900);

                    $resultSummary = sprintf(
                        'Exception: %s',
                        $errorMessage
                    );

                    $details = sprintf(
                        'Sync failed for source %s at %s. Exception: %s',
                        $source,
                        now()->toDateTimeString(),
                        $errorMessage
                    );

                    $this->insertLogRow(
                        $connector,
                        $source,
                        'FAILED',
                        'FAILED',
                        0,
                        0,
                        $resultSummary . ' ' . $details
                    );
                } catch (\Throwable $logException) {
                    Log::error('SyncBalances: failed to log to TblLog after exception.', [
                        'connection' => $connection,
                        'source' => $source,
                        'exception' => $logException,
                    ]);
                }
            }
        }

        $this->info('Balance sync: finished.');
        Log::info('SyncBalances command finished.', [
            'status' => $hadException ? 'FAILURE' : 'SUCCESS',
        ]);

        return $hadException ? Command::FAILURE : Command::SUCCESS;
    }

    protected function fetchLatestBalancesFromSnowflake(DBConnector $connector): array
    {
        $baseCte = <<<SQL
WITH ranked AS (
    SELECT
        CONTACT_ID,
        BALANCE,
        STAMP,
        ROW_NUMBER() OVER (PARTITION BY CONTACT_ID ORDER BY STAMP DESC) AS rn
    FROM CONTACT_BALANCES
    WHERE CONTACT_ID IN (SELECT ID FROM CONTACTS)
)
SQL;

        // First, get total rows the CTE would return
        $countSql = $baseCte . " SELECT COUNT(*) AS CNT FROM ranked WHERE rn = 1 AND BALANCE <> 0";
        $countResult = $connector->query($countSql);
        $total = (int) ($countResult['data'][0]['CNT'] ?? 0);

        $pageSize = 1000;
        $allRows = [];

        for ($offset = 0; $offset < $total; ) {
            $pagedSql = $baseCte . "
SELECT CONTACT_ID, BALANCE
FROM ranked
WHERE rn = 1
  AND BALANCE <> 0
ORDER BY CONTACT_ID
LIMIT {$pageSize} OFFSET {$offset}";

            $page = $connector->query($pagedSql);
            $pageData = $page['data'] ?? [];
            $pageCount = is_array($pageData) ? count($pageData) : 0;

            if ($pageCount > 0) {
                $allRows = array_merge($allRows, $pageData);
                $offset += $pageCount;
            } else {
                // Safety: avoid infinite loop if total changes mid-run
                break;
            }
        }

        return [
            'data' => $allRows,
            'rowCount' => count($allRows),
            'columns' => ['CONTACT_ID', 'BALANCE'],
        ];
    }

    protected function deleteExistingBalances(DBConnector $connector, string $source): int
    {
        $sourceEscaped = $this->escapeSqlString($source);

        $sql = "DELETE FROM TblBalances WHERE Source = '{$sourceEscaped}';";

        $result = $connector->querySqlServer($sql);

        if (is_array($result)) {
            if (isset($result['rowCount']) && is_numeric($result['rowCount'])) {
                return (int) $result['rowCount'];
            }

            if (isset($result['affected_rows']) && is_numeric($result['affected_rows'])) {
                return (int) $result['affected_rows'];
            }

            if (isset($result['row_count']) && is_numeric($result['row_count'])) {
                return (int) $result['row_count'];
            }
        }

        return 0;
    }

    protected function insertBalancesInBatches(
        DBConnector $connector,
        array $balances,
        string $source,
        int $batchSize
    ): int {
        $totalInserted = 0;
        $sourceTrimmed = $this->truncateString($source, 100);
        $sourceEscaped = $this->escapeSqlString($sourceTrimmed);
        $now = now()->format('Y-m-d H:i:s');
        $nowEscaped = $this->escapeSqlString($now);

        $chunks = array_chunk($balances, $batchSize);

        foreach ($chunks as $index => $chunk) {
            $values = [];

            foreach ($chunk as $row) {
                if (!isset($row['CONTACT_ID'], $row['BALANCE'])) {
                    continue;
                }

                $contactId = (string) $row['CONTACT_ID'];
                $cid = 'LLG-' . $contactId;
                $cid = $this->truncateString($cid, 100);
                $cidEscaped = $this->escapeSqlString($cid);

                $balance = $row['BALANCE'];
                $balanceNumeric = is_numeric($balance) ? (float) $balance : 0.0;
                $balanceNumeric = round($balanceNumeric, 2);
                // Clamp to decimal(9,2) range
                $balanceNumeric = max(min($balanceNumeric, 9999999.99), -9999999.99);
                $balanceValue = number_format($balanceNumeric, 2, '.', '');

                $values[] = sprintf(
                    "('%s', %s, '%s', '%s')",
                    $cidEscaped,
                    $balanceValue,
                    $sourceEscaped,
                    $nowEscaped
                );
            }

            if (empty($values)) {
                continue;
            }

            $sql = 'INSERT INTO TblBalances (CID, Balance, Source, Import_Time) VALUES ' .
                implode(', ', $values) .
                ';';

            $result = $connector->querySqlServer($sql);

            $inserted = 0;
            if (is_array($result)) {
                if (isset($result['rowCount']) && is_numeric($result['rowCount'])) {
                    $inserted = (int) $result['rowCount'];
                } elseif (isset($result['affected_rows']) && is_numeric($result['affected_rows'])) {
                    $inserted = (int) $result['affected_rows'];
                } elseif (isset($result['row_count']) && is_numeric($result['row_count'])) {
                    $inserted = (int) $result['row_count'];
                }
            }

            if ($inserted === 0) {
                $inserted = count($chunk);
            }

            $totalInserted += $inserted;

            Log::info('SyncBalances: batch insert completed.', [
                'source' => $source,
                'batchIndex' => $index,
                'batchSize' => count($chunk),
                'inserted' => $inserted,
            ]);
        }

        return $totalInserted;
    }

    /**
     * Ensure TblBalances has Import_Time column and populate any missing values.
     */
    protected function ensureBalancesImportTime(DBConnector $connector): void
    {
        $sql = <<<SQL
IF COL_LENGTH('dbo.TblBalances', 'Import_Time') IS NULL
BEGIN
    ALTER TABLE [dbo].[TblBalances] ADD [Import_Time] DATETIME NULL;
END

IF NOT EXISTS (
    SELECT 1
    FROM sys.default_constraints dc
    JOIN sys.columns c ON dc.parent_object_id = c.object_id AND dc.parent_column_id = c.column_id
    WHERE dc.parent_object_id = OBJECT_ID('dbo.TblBalances')
      AND c.name = 'Import_Time'
)
BEGIN
    ALTER TABLE [dbo].[TblBalances]
        ADD CONSTRAINT DF_TblBalances_Import_Time
        DEFAULT (GETDATE()) FOR [Import_Time];
END

UPDATE [dbo].[TblBalances]
SET Import_Time = ISNULL(Import_Time, GETDATE())
WHERE Import_Time IS NULL;
SQL;

        $connector->querySqlServer($sql);
    }

    protected function ensureLogTable(DBConnector $connector): void
    {
        // No table creation here; assume TblLog already exists.
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
        $tableName = 'TblBalances';
        $macro = 'SyncBalances';

        $description = $this->truncateString(
            sprintf('Sync contact balances for %s to SQL Server TblBalances', $source),
            510
        );

        $resultSummary = $this->truncateString(
            sprintf(
                'Status: %s | Action: %s | Processed: %d | Deleted: %d | Details: %s',
                $status,
                $action,
                $recordsProcessed,
                $recordsDeleted,
                $details
            ),
            100
        );

        $action = $this->truncateString($action, 510);

        $timestamp = now()->format('Y-m-d H:i:s');

        $tableNameEsc = $this->escapeSqlString($tableName);
        $macroEsc = $this->escapeSqlString($macro);
        $descriptionEsc = $this->escapeSqlString($description);
        $actionEsc = $this->escapeSqlString($action);
        $resultEsc = $this->escapeSqlString($resultSummary);
        $timestampEsc = $this->escapeSqlString($timestamp);

        $sql = <<<SQL
DECLARE @hasPK BIT = CASE WHEN COL_LENGTH('dbo.TblLog', 'PK') IS NULL THEN 0 ELSE 1 END;
DECLARE @isIdentity BIT = CASE WHEN COLUMNPROPERTY(OBJECT_ID('dbo.TblLog'), 'PK', 'IsIdentity') = 1 THEN 1 ELSE 0 END;

IF @hasPK = 1 AND @isIdentity = 0
BEGIN
    DECLARE @nextPK INT = ISNULL((SELECT MAX(PK) FROM dbo.TblLog), 0) + 1;
    INSERT INTO TblLog (PK, Table_Name, Macro, Description, Action, Result, Timestamp)
    VALUES (@nextPK, '{$tableNameEsc}', '{$macroEsc}', '{$descriptionEsc}', '{$actionEsc}', '{$resultEsc}', '{$timestampEsc}');
END
ELSE
BEGIN
    INSERT INTO TblLog (Table_Name, Macro, Description, Action, Result, Timestamp)
    VALUES ('{$tableNameEsc}', '{$macroEsc}', '{$descriptionEsc}', '{$actionEsc}', '{$resultEsc}', '{$timestampEsc}');
END;
SQL;

        try {
            $result = $connector->querySqlServer($sql);

            // Handle non-exception failures returned by querySqlServer
            if (is_array($result) && isset($result['success']) && $result['success'] === false) {
                $errorMsg = $result['error'] ?? 'Unknown SQL Server error';
                $this->error(sprintf('[%s] Log insert failed: %s', $source, $errorMsg));
                Log::error('SyncBalances: log insert failed.', [
                    'source' => $source,
                    'sql' => $sql,
                    'result' => $result,
                ]);
                return;
            }

            $this->info(sprintf('[%s] Log entry inserted into TblLog.', $source));
        } catch (\Throwable $e) {
            $this->error(sprintf('[%s] Log insert failed: %s', $source, $e->getMessage()));
            Log::error('SyncBalances: log insert failed.', [
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
