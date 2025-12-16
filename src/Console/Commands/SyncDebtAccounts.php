<?php

namespace Cmd\Reports\Console\Commands;

use Cmd\Reports\Services\DBConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncDebtAccounts extends Command
{
    protected $signature = 'enrollment:update-debts';

    protected $description = 'Update TblEnrollment.Enrolled_Debt_Accounts from Snowflake DEBTS counts';

    public function handle(): int
    {
        $this->info('Debt account sync: starting.');
        Log::info('SyncDebtAccounts command started.');

        $connections = ['plaw', 'ldr'];
        $hadException = false;

        foreach ($connections as $connection) {
            $source = strtoupper($connection);

            $this->info("[$source] Starting debt account sync.");
            Log::info('SyncDebtAccounts: starting connection.', [
                'connection' => $connection,
                'source' => $source,
            ]);

            try {
                $connector = DBConnector::fromEnvironment($connection);
                $connector->initializeSqlServer();
                $this->ensureLogTable($connector);

                $llgIds = $this->fetchMissingEnrollmentIds($connector);

                if (empty($llgIds)) {
                    $this->warn("[$source] No enrollment rows missing Enrolled_Debt_Accounts.");
                    $this->insertLogRow(
                        $connector,
                        $source,
                        'UPDATE_DEBT_ACCOUNTS',
                        'SUCCESS',
                        0,
                        0,
                        'No rows to update.'
                    );
                    continue;
                }

                $this->info("[$source] Found " . count($llgIds) . " rows to update.");

                $counts = $this->fetchDebtCountsFromSnowflake($connector, $llgIds);

                if (empty($counts)) {
                    $this->warn("[$source] No debt counts returned from Snowflake for missing rows.");
                    $this->insertLogRow(
                        $connector,
                        $source,
                        'UPDATE_DEBT_ACCOUNTS',
                        'SUCCESS',
                        0,
                        0,
                        'No matching debt accounts found in Snowflake.'
                    );
                    continue;
                }

                $updated = $this->updateEnrollmentDebtCounts($connector, $counts);

                $this->info("[$source] Updated {$updated} debt account counts.");
                $this->insertLogRow(
                    $connector,
                    $source,
                    'UPDATE_DEBT_ACCOUNTS',
                    'SUCCESS',
                    $updated,
                    0,
                    sprintf('Rows needing update: %d. Updated: %d.', count($llgIds), $updated)
                );

                Log::info('SyncDebtAccounts: connection finished.', [
                    'connection' => $connection,
                    'source' => $source,
                    'updated' => $updated,
                ]);
            } catch (\Throwable $e) {
                $hadException = true;

                $this->error("Debt account sync failed for connection [{$connection}] ({$source}).");
                $this->error($e->getMessage());

                Log::error('SyncDebtAccounts: exception during sync.', [
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
                        'UPDATE_DEBT_ACCOUNTS',
                        'FAILED',
                        0,
                        0,
                        $errorMessage
                    );
                } catch (\Throwable $logException) {
                    Log::error('SyncDebtAccounts: failed to log to TblLog after exception.', [
                        'connection' => $connection,
                        'source' => $source,
                        'exception' => $logException,
                    ]);
                }
            }
        }

        $this->info('Debt account sync: finished.');
        Log::info('SyncDebtAccounts command finished.', [
            'status' => $hadException ? 'FAILURE' : 'SUCCESS',
        ]);

        return $hadException ? Command::FAILURE : Command::SUCCESS;
    }

    protected function fetchMissingEnrollmentIds(DBConnector $connector): array
    {
        $sql = <<<SQL
SELECT LLG_ID
FROM TblEnrollment
WHERE Category IN ('LDR', 'CCS')
  AND Enrolled_Debt_Accounts IS NULL
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

        $llgIds = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $llgId = null;
            foreach ($row as $key => $value) {
                if (strcasecmp($key, 'LLG_ID') === 0) {
                    $llgId = $value;
                    break;
                }
            }

            if ($llgId !== null && $llgId !== '') {
                $llgIds[] = (string) $llgId;
            }
        }

        $this->info('Found ' . count($llgIds) . ' enrollment rows needing debt counts.');

        return $llgIds;
    }

    protected function fetchDebtCountsFromSnowflake(DBConnector $connector, array $llgIds): array
    {
        if (empty($llgIds)) {
            return [];
        }

        $counts = [];
        $chunks = array_chunk($llgIds, 500);

        foreach ($chunks as $chunk) {
            $contactIds = array_map(function ($llg) {
                $contactId = preg_replace('/^LLG-?/i', '', (string) $llg);
                return "'" . $this->escapeSqlString((string) $contactId) . "'";
            }, $chunk);

            $inList = implode(', ', $contactIds);

            $sql = <<<SQL
SELECT CONTACT_ID, COUNT(*) AS CNT
FROM DEBTS
WHERE CONTACT_ID IN ({$inList})
  AND ENROLLED = 1
GROUP BY CONTACT_ID
SQL;

            $result = $connector->query($sql);

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

            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $cid = null;
                $cnt = null;

                foreach ($row as $key => $value) {
                    if (strcasecmp($key, 'CONTACT_ID') === 0) {
                        $cid = (string) $value;
                    } elseif (strcasecmp($key, 'CNT') === 0) {
                        $cnt = (int) $value;
                    }
                }

                if ($cid === null) {
                    continue;
                }

                $llgId = $this->truncateString('LLG-' . $cid, 100);
                $counts[$llgId] = $cnt ?? 0;
            }
        }

        $this->info('Fetched ' . count($counts) . ' debt counts from Snowflake.');

        return $counts;
    }

    protected function updateEnrollmentDebtCounts(DBConnector $connector, array $counts): int
    {
        if (empty($counts)) {
            return 0;
        }

        $totalUpdated = 0;
        $batchSize = 500;
        $batches = array_chunk($counts, $batchSize, true);

        foreach ($batches as $chunk) {
            $cases = [];
            $ids = [];

            foreach ($chunk as $llgId => $count) {
                $llgEsc = $this->truncateString($this->escapeSqlString($llgId), 100);
                $countValue = is_numeric($count) ? (int) $count : 0;
                $cases[] = "WHEN '{$llgEsc}' THEN {$countValue}";
                $ids[] = "'{$llgEsc}'";
            }

            if (empty($cases) || empty($ids)) {
                continue;
            }

            $caseSql = implode(' ', $cases);
            $idList = implode(', ', $ids);

            $sql = <<<SQL
UPDATE TblEnrollment
SET Enrolled_Debt_Accounts = CASE LLG_ID {$caseSql} END
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
        $macro = 'SyncDebtAccounts';
        $actionLabel = $action !== '' ? strtoupper($action) : 'UPDATE_DEBT_ACCOUNTS';

        $description = $this->truncateString(
            sprintf('Sync enrolled debt accounts for %s', $source),
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
                Log::error('SyncDebtAccounts: log insert failed.', [
                    'source' => $source,
                    'sql' => $sql,
                    'result' => $result,
                ]);
                return;
            }

            $this->info(sprintf('[%s] Log entry inserted into TblLog.', $source));
        } catch (\Throwable $e) {
            $this->error(sprintf('[%s] Log insert failed: %s', $source, $e->getMessage()));
            Log::error('SyncDebtAccounts: log insert failed.', [
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
