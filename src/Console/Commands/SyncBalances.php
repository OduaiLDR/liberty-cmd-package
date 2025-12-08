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
        $sourceEscaped = $this->escapeSqlString($source);
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
                $cidEscaped = $this->escapeSqlString($cid);

                $balance = $row['BALANCE'];

                if (!is_numeric($balance)) {
                    $balanceValue = '0';
                } else {
                    $balanceValue = (string) $balance;
                }

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

            $sql = 'INSERT INTO TblBalances (CID, Balance, Source, UpdatedAt) VALUES ' .
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

    protected function ensureLogTable(DBConnector $connector): void
    {
        $sql = <<<SQL
IF NOT EXISTS (
    SELECT *
    FROM sys.objects
    WHERE object_id = OBJECT_ID(N'[dbo].[TblLog]')
      AND type IN (N'U')
)
BEGIN
    CREATE TABLE [dbo].[TblLog] (
        [ID] INT IDENTITY(1,1) PRIMARY KEY,
        [Table_Name] VARCHAR(100),
        [Macro] VARCHAR(100),
        [Description] VARCHAR(500),
        [Action] VARCHAR(100),
        [Result] VARCHAR(100),
        [Timestamp] DATETIME NOT NULL,
        [Operation] VARCHAR(100) NOT NULL,
        [Source] VARCHAR(50) NOT NULL,
        [Status] VARCHAR(20) NOT NULL,
        [RecordsProcessed] INT DEFAULT 0,
        [RecordsDeleted] INT DEFAULT 0,
        [Details] VARCHAR(1000)
    );
END
SQL;

        $connector->querySqlServer($sql);
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

        $description = sprintf(
            'Sync contact balances from Snowflake %s to SQL Server TblBalances',
            $source
        );

        $resultSummary = sprintf(
            'Processed: %d, Deleted: %d',
            $recordsProcessed,
            $recordsDeleted
        );

        $timestamp = now()->format('Y-m-d H:i:s');

        $tableNameEsc = $this->escapeSqlString($tableName);
        $macroEsc = $this->escapeSqlString($macro);
        $descriptionEsc = $this->escapeSqlString($description);
        $actionEsc = $this->escapeSqlString($action);
        $resultEsc = $this->escapeSqlString($resultSummary);
        $timestampEsc = $this->escapeSqlString($timestamp);
        $operationEsc = $this->escapeSqlString('BALANCE_SYNC');
        $sourceEsc = $this->escapeSqlString($source);
        $statusEsc = $this->escapeSqlString($status);
        $detailsEsc = $this->escapeSqlString($details);

        $sql = sprintf(
            "INSERT INTO TblLog (Table_Name, Macro, Description, Action, Result, Timestamp, Operation, Source, Status, RecordsProcessed, RecordsDeleted, Details)
            VALUES ('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', %d, %d, '%s');",
            $tableNameEsc,
            $macroEsc,
            $descriptionEsc,
            $actionEsc,
            $resultEsc,
            $timestampEsc,
            $operationEsc,
            $sourceEsc,
            $statusEsc,
            $recordsProcessed,
            $recordsDeleted,
            $detailsEsc
        );

        $connector->querySqlServer($sql);
    }

    protected function escapeSqlString(string $value): string
    {
        return str_replace("'", "''", $value);
    }
}
