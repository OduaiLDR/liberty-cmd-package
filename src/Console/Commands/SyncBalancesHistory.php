<?php

namespace Cmd\Reports\Console\Commands;

use Cmd\Reports\Services\DBConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncBalancesHistory extends Command
{
    protected $signature = 'balance:sync-history';

    protected $description = 'Sync contact balance history from Snowflake to SQL Server TblBalancesHistory';

    public function handle(): int
    {
        $this->info('Balance history sync: starting.');
        Log::info('SyncBalancesHistory command started.');

        $connections = ['plaw', 'ldr'];
        $hadException = false;

        foreach ($connections as $connection) {
            $source = strtoupper($connection);
            $totalFetched = 0;

            $this->info("[$source] Starting history sync.");
            Log::info('SyncBalancesHistory: starting connection.', [
                'connection' => $connection,
                'source' => $source,
            ]);

            try {
                $connector = DBConnector::fromEnvironment($connection);
                $connector->initializeSqlServer();
                $this->ensureLogTable($connector);

                $historyRows = $this->fetchHistoryFromSnowflake($connector);
                $totalFetched = is_array($historyRows) ? count($historyRows) : 0;

                if ($totalFetched === 0) {
                    $this->warn("No history rows fetched from Snowflake for source {$source}.");
                    Log::warning('SyncBalancesHistory: no Snowflake data.', [
                        'connection' => $connection,
                        'source' => $source,
                    ]);

                    $deleted = $this->deleteExistingHistory($connector, $source);
                    $this->insertLogRow(
                        $connector,
                        $source,
                        'DELETE_AND_INSERT',
                        'SUCCESS',
                        0,
                        $deleted,
                        'No balance history rows found to sync from Snowflake.'
                    );
                    continue;
                }

                $this->info("Fetched {$totalFetched} history rows for {$source}.");

                $deleted = $this->deleteExistingHistory($connector, $source);
                $this->info("[$source] Deleted {$deleted} existing history rows.");

                $insertResult = $this->insertHistoryInBatches($connector, $historyRows, $source, 1000);
                $inserted = $insertResult['inserted'] ?? 0;
                $skipped = $insertResult['skipped'] ?? 0;

                $this->info("[$source] Inserted {$inserted} history rows.");
                Log::info('SyncBalancesHistory: connection finished.', [
                    'connection' => $connection,
                    'source' => $source,
                    'recordsInserted' => $inserted,
                    'recordsSkipped' => $skipped,
                ]);

                try {
                    $resultSummary = sprintf(
                        'Processed: %d, Deleted: %d, Skipped: %d',
                        $inserted,
                        $deleted,
                        $skipped
                    );

                    $details = sprintf(
                        'History sync completed successfully for source %s at %s. Expected %d rows from Snowflake. Skipped %d rows missing required fields.',
                        $source,
                        now()->toDateTimeString(),
                        $totalFetched,
                        $skipped
                    );

                    $this->insertLogRow(
                        $connector,
                        $source,
                        'DELETE_AND_INSERT',
                        'SUCCESS',
                        $inserted,
                        $deleted,
                        $resultSummary . ' ' . $details
                    );
                } catch (\Throwable $logException) {
                    Log::warning('SyncBalancesHistory: failed to insert success log row.', [
                        'connection' => $connection,
                        'source' => $source,
                        'exception' => $logException,
                    ]);
                }

                if ($inserted !== $totalFetched) {
                    $this->warn(sprintf(
                        '[%s] Inserted count (%d) differs from fetched count (%d). Skipped: %d.',
                        $source,
                        $inserted,
                        $totalFetched,
                        $skipped
                    ));
                    Log::warning('SyncBalancesHistory: inserted count mismatch.', [
                        'connection' => $connection,
                        'source' => $source,
                        'fetched' => $totalFetched,
                        'inserted' => $inserted,
                        'skipped' => $skipped,
                    ]);
                }
            } catch (\Throwable $e) {
                $hadException = true;

                $this->error("Balance history sync failed for connection [{$connection}] ({$source}).");
                $this->error($e->getMessage());

                Log::error('SyncBalancesHistory: exception during sync.', [
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

                    $resultSummary = sprintf(
                        'Exception: %s',
                        $errorMessage
                    );

                    $details = sprintf(
                        'History sync failed for source %s at %s. Exception: %s',
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
                    Log::error('SyncBalancesHistory: failed to log to TblLog after exception.', [
                        'connection' => $connection,
                        'source' => $source,
                        'exception' => $logException,
                    ]);
                }
            }
        }

        $this->info('Balance history sync: finished.');
        Log::info('SyncBalancesHistory command finished.', [
            'status' => $hadException ? 'FAILURE' : 'SUCCESS',
        ]);

        return $hadException ? Command::FAILURE : Command::SUCCESS;
    }

    protected function fetchHistoryFromSnowflake(DBConnector $connector): array
    {
        $baseQuery = <<<SQL
WITH month_end_stamps AS (
    SELECT LAST_DAY(CURRENT_DATE - 1 * INTERVAL '1' MONTH) AS stamp UNION ALL
    SELECT LAST_DAY(CURRENT_DATE - 2 * INTERVAL '1' MONTH) UNION ALL
    SELECT LAST_DAY(CURRENT_DATE - 3 * INTERVAL '1' MONTH) UNION ALL
    SELECT LAST_DAY(CURRENT_DATE - 4 * INTERVAL '1' MONTH)
)
SELECT
    CONTACT_ID,
    "CURRENT" AS BALANCE,
    TO_CHAR(STAMP, 'YYYY-MM-DD') AS STAMP
FROM CONTACT_BALANCES
WHERE DATE(STAMP) IN (SELECT stamp FROM month_end_stamps)
SQL;

        $countSql = "SELECT COUNT(*) AS CNT FROM ({$baseQuery}) AS history_count";
        $countResult = $connector->query($countSql);
        if (!is_array($countResult)) {
            throw new \RuntimeException('Snowflake count query returned an unexpected format.');
        }
        if (isset($countResult['success']) && $countResult['success'] === false) {
            $error = $countResult['error'] ?? 'Unknown Snowflake error';
            throw new \RuntimeException('Snowflake count query failed: ' . $error);
        }

        $total = (int) ($countResult['data'][0]['CNT'] ?? 0);

        if ($total === 0) {
            return [];
        }

        $pageSize = 2000;
        $allRows = [];

        for ($offset = 0; $offset < $total; ) {
            $pagedSql = $baseQuery . "
ORDER BY CONTACT_ID, STAMP DESC
LIMIT {$pageSize} OFFSET {$offset}";

            $pageResult = $connector->query($pagedSql);
            if (!is_array($pageResult)) {
                throw new \RuntimeException('Snowflake page query returned an unexpected format.');
            }
            if (isset($pageResult['success']) && $pageResult['success'] === false) {
                $error = $pageResult['error'] ?? 'Unknown Snowflake error';
                throw new \RuntimeException('Snowflake page query failed: ' . $error);
            }

            $pageData = $pageResult['data'] ?? [];
            $pageCount = is_array($pageData) ? count($pageData) : 0;

            if ($pageCount === 0) {
                break;
            }

            $allRows = array_merge($allRows, $pageData);
            $offset += $pageCount;
        }

        return array_map(function ($row) {
            return [
                'CONTACT_ID' => $row['CONTACT_ID'] ?? null,
                'BALANCE' => $row['BALANCE'] ?? 0,
                'STAMP' => $row['STAMP'] ?? null,
            ];
        }, $allRows);
    }

    protected function deleteExistingHistory(DBConnector $connector, string $source): int
    {
        $sourceEscaped = $this->escapeSqlString($source);
        $sql = "DELETE FROM TblBalancesHistory WHERE Source = '{$sourceEscaped}';";

        $result = $connector->querySqlServer($sql);

        if (is_array($result)) {
            if (isset($result['success']) && $result['success'] === false) {
                $error = $result['error'] ?? 'Unknown SQL Server error';
                throw new \RuntimeException("Failed to delete existing history for source {$source}: {$error}");
            }
            foreach (['rowCount', 'affected_rows', 'row_count'] as $key) {
                if (isset($result[$key]) && is_numeric($result[$key])) {
                    return (int) $result[$key];
                }
            }
        }

        return 0;
    }

    protected function insertHistoryInBatches(
        DBConnector $connector,
        array $rows,
        string $source,
        int $batchSize = 1000
    ): array {
        $chunks = array_chunk($rows, $batchSize);
        $sourceEscaped = $this->escapeSqlString($source);
        $totalInserted = 0;
        $skipped = 0;

        foreach ($chunks as $index => $chunk) {
            $values = [];

            foreach ($chunk as $row) {
                if (!isset($row['CONTACT_ID'], $row['STAMP'])) {
                    $skipped++;
                    continue;
                }

                $cid = 'LLG-' . $row['CONTACT_ID'];
                $cidEscaped = $this->escapeSqlString((string) $cid);

                $balanceValue = is_numeric($row['BALANCE']) ? (float) $row['BALANCE'] : 0;

                $stampValue = $row['STAMP'];
                if (!$stampValue) {
                    $skipped++;
                    continue;
                }
                $stampEscaped = $this->escapeSqlString((string) $stampValue);

                $values[] = sprintf(
                    "('%s', %s, '%s', '%s')",
                    $cidEscaped,
                    $balanceValue,
                    $stampEscaped,
                    $sourceEscaped
                );
            }

            if (empty($values)) {
                continue;
            }

            $sql = 'INSERT INTO TblBalancesHistory (LLG_ID, Balance, Balance_Date, Source) VALUES ' .
                implode(', ', $values) .
                ';';

            $result = $connector->querySqlServer($sql);

            if (is_array($result)) {
                if (isset($result['success']) && $result['success'] === false) {
                    $error = $result['error'] ?? 'Unknown SQL Server error';
                    throw new \RuntimeException("Failed to insert history batch for source {$source}: {$error}");
                }
                foreach (['rowCount', 'affected_rows', 'row_count'] as $key) {
                    if (isset($result[$key]) && is_numeric($result[$key])) {
                        $totalInserted += (int) $result[$key];
                        Log::info('SyncBalancesHistory: batch insert completed.', [
                            'source' => $source,
                            'batchIndex' => $index,
                            'batchSize' => count($chunk),
                            'inserted' => (int) $result[$key],
                            'skipped' => $skipped,
                        ]);
                        continue 2;
                    }
                }
            }

            $totalInserted += count($chunk);

            Log::info('SyncBalancesHistory: batch insert fallback count used.', [
                'source' => $source,
                'batchIndex' => $index,
                'batchSize' => count($chunk),
                'inserted' => count($chunk),
                'skipped' => $skipped,
            ]);
        }

        return [
            'inserted' => $totalInserted,
            'skipped' => $skipped,
        ];
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
        $tableName = 'TblBalancesHistory';
        $macro = 'SyncBalancesHistory';

        $description = $this->truncateString(
            sprintf('Sync contact balance history for %s to SQL Server TblBalancesHistory', $source),
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
        $tableName = $this->truncateString($tableName, 100);
        $macro = $this->truncateString($macro, 100);

        $timestamp = now()->format('Y-m-d H:i:s');

        $tableNameEsc = $this->escapeSqlString($tableName);
        $macroEsc = $this->escapeSqlString($macro);
        $descriptionEsc = $this->escapeSqlString($description);
        $actionEsc = $this->escapeSqlString($action);
        $resultEsc = $this->escapeSqlString($resultSummary);
        $timestampEsc = $this->escapeSqlString($timestamp);

        $this->info(sprintf('[%s] Writing log entry to TblLog...', $source));

        $sql = <<<SQL
DECLARE @hasPK BIT = CASE WHEN COL_LENGTH('dbo.TblLog', 'PK') IS NULL THEN 0 ELSE 1 END;
DECLARE @isIdentity BIT = CASE WHEN COLUMNPROPERTY(OBJECT_ID('dbo.TblLog'), 'PK', 'IsIdentity') = 1 THEN 1 ELSE 0 END;

IF @hasPK = 1 AND @isIdentity = 0
BEGIN
    DECLARE @nextPK INT = ISNULL((SELECT MAX([PK]) FROM [dbo].[TblLog]), 0) + 1;
    INSERT INTO [dbo].[TblLog] ([PK], [Table_Name], [Macro], [Description], [Action], [Result], [Timestamp])
    VALUES (@nextPK, '{$tableNameEsc}', '{$macroEsc}', '{$descriptionEsc}', '{$actionEsc}', '{$resultEsc}', '{$timestampEsc}');
END
ELSE
BEGIN
    INSERT INTO [dbo].[TblLog] ([Table_Name], [Macro], [Description], [Action], [Result], [Timestamp])
    VALUES ('{$tableNameEsc}', '{$macroEsc}', '{$descriptionEsc}', '{$actionEsc}', '{$resultEsc}', '{$timestampEsc}');
END;
SQL;

        try {
            $result = $connector->querySqlServer($sql);

            if (is_array($result) && isset($result['success']) && $result['success'] === false) {
                $errorMsg = $result['error'] ?? 'Unknown SQL Server error';
                $this->error(sprintf('[%s] Log insert failed: %s', $source, $errorMsg));
                Log::error('SyncBalancesHistory: log insert failed.', [
                    'source' => $source,
                    'sql' => $sql,
                    'result' => $result,
                ]);
                return;
            }

            $this->info(sprintf('[%s] Log entry inserted into TblLog.', $source));
        } catch (\Throwable $e) {
            $this->error(sprintf('[%s] Log insert failed: %s', $source, $e->getMessage()));
            Log::error('SyncBalancesHistory: log insert failed.', [
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

    // VBA equivalent uses raw values; normalization helpers removed for strict mirroring.
}
