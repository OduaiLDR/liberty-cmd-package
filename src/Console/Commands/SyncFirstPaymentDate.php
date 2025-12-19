<?php

namespace Cmd\Reports\Console\Commands;

use Cmd\Reports\Services\DBConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncFirstPaymentDate extends Command
{
    protected $signature = 'sync:first-payment-date';

    protected $description = 'Sync First_Payment_Date and First_Payment_Status in TblEnrollment from Snowflake TRANSACTIONS (first D per contact, last 60 days)';

    public function handle(): int
    {
        $this->info('First payment sync: starting.');
        Log::info('SyncFirstPaymentDate command started.');

        $connections = ['plaw', 'ldr'];
        $hadException = false;

        foreach ($connections as $connection) {
            $source = strtoupper($connection);
            $this->info("[$source] Starting first payment sync.");
            Log::info('SyncFirstPaymentDate: starting connection.', [
                'connection' => $connection,
                'source' => $source,
            ]);

            try {
                $connector = DBConnector::fromEnvironment($connection);
                $connector->initializeSqlServer();
                $this->ensureLogTable($connector);

                $this->info("[$source] Fetching IDs missing First_Payment_Date from SQL Server...");
                $missingIds = $this->fetchMissingIdsFromSqlServer($connector);

                if (empty($missingIds)) {
                    $this->warn("[$source] No rows missing First_Payment_Date in SQL Server.");
                    $this->insertLogRow(
                        $connector,
                        $source,
                        'SYNC_FIRST_PAYMENT_DATE',
                        'SUCCESS',
                        0,
                        0,
                        'No rows missing First_Payment_Date in SQL Server.'
                    );
                    continue;
                }

                $this->info("[$source] Fetching first payments from Snowflake for missing IDs...");
                $payments = $this->fetchFirstPaymentsFromSnowflake($connector, $missingIds);

                if (empty($payments)) {
                    $this->warn("[$source] No first payments found in Snowflake for missing IDs.");
                    $this->insertLogRow(
                        $connector,
                        $source,
                        'SYNC_FIRST_PAYMENT_DATE',
                        'SUCCESS',
                        0,
                        0,
                        'No first payments found to sync.'
                    );
                    continue;
                }

                $this->info("[$source] Applying first payments to SQL Server...");
                $updated = $this->updateFirstPayments($connector, $payments);

                $this->info("[$source] Updated {$updated} first payment rows.");
                $this->insertLogRow(
                    $connector,
                    $source,
                    'SYNC_FIRST_PAYMENT_DATE',
                    'SUCCESS',
                    $updated,
                    0,
                    sprintf('Payments fetched: %d. Updated: %d.', count($payments), $updated)
                );

                Log::info('SyncFirstPaymentDate: connection finished.', [
                    'connection' => $connection,
                    'source' => $source,
                    'updated' => $updated,
                ]);
            } catch (\Throwable $e) {
                $hadException = true;

                $this->error("First payment sync failed for connection [{$connection}] ({$source}).");
                $this->error($e->getMessage());

                Log::error('SyncFirstPaymentDate: exception during sync.', [
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
                        'SYNC_FIRST_PAYMENT_DATE',
                        'FAILED',
                        0,
                        0,
                        $errorMessage
                    );
                } catch (\Throwable $logException) {
                    Log::error('SyncFirstPaymentDate: failed to log to TblLog after exception.', [
                        'connection' => $connection,
                        'source' => $source,
                        'exception' => $logException,
                    ]);
                }
            }
        }

        $this->info('First payment sync: finished.');
        Log::info('SyncFirstPaymentDate command finished.', [
            'status' => $hadException ? 'FAILURE' : 'SUCCESS',
        ]);

        return $hadException ? Command::FAILURE : Command::SUCCESS;
    }

    protected function fetchMissingIdsFromSqlServer(DBConnector $connector): array
    {
        $sql = <<<SQL
SELECT LLG_ID
FROM TblEnrollment
WHERE First_Payment_Date IS NULL
  AND LLG_ID LIKE 'LLG-%'
  AND TRY_CONVERT(BIGINT, REPLACE(LLG_ID, 'LLG-', '')) IS NOT NULL
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

        $this->info('Found ' . count($ids) . ' IDs missing First_Payment_Date.');

        return $ids;
    }

    protected function fetchFirstPaymentsFromSnowflake(DBConnector $connector, array $missingLlgs): array
    {
        if (empty($missingLlgs)) {
            return [];
        }

        $contactIds = array_values(array_filter(array_map(function ($llg) {
            $id = preg_replace('/^LLG-?/i', '', (string) $llg);
            $id = trim($id);
            return ctype_digit($id) ? $id : null;
        }, $missingLlgs)));

        if (empty($contactIds)) {
            $this->warn('No numeric CONTACT_IDs extracted from missing LLG IDs.');
            return [];
        }

        $payments = [];
        $chunkSize = 1000;

        foreach (array_chunk($contactIds, $chunkSize) as $chunk) {
            $values = implode(', ', array_map(function ($id) {
                return "('" . $this->escapeSqlString($id) . "')";
            }, $chunk));

            $sql = <<<SQL
WITH missing AS (
    SELECT column1 AS CONTACT_ID_STR
    FROM VALUES {$values}
),
tx AS (
    SELECT
        CONTACT_ID,
        PROCESS_DATE,
        CLEARED_DATE,
        RETURNED_DATE,
        ROW_NUMBER() OVER (PARTITION BY CONTACT_ID ORDER BY PROCESS_DATE) AS rn
    FROM TRANSACTIONS
    WHERE TRANS_TYPE = 'D'
)
SELECT
    TO_VARCHAR(tx.CONTACT_ID) AS CONTACT_ID,
    tx.PROCESS_DATE,
    tx.CLEARED_DATE,
    tx.RETURNED_DATE
FROM tx
JOIN missing
  ON TO_VARCHAR(tx.CONTACT_ID) = missing.CONTACT_ID_STR
WHERE tx.rn = 1
  AND tx.PROCESS_DATE >= DATEADD(day, -60, CURRENT_DATE)
ORDER BY tx.PROCESS_DATE ASC
SQL;

            $result = $connector->query($sql);

            $rows = [];
            if (is_array($result)) {
                if (isset($result['success']) && $result['success'] === false) {
                    $rows = [];
                } elseif (isset($result['data']) && is_array($result['data'])) {
                    $rows = $result['data'];
                } elseif (array_is_list($result)) {
                    $rows = $result;
                }
            }

            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $cid = null;
                $processDate = null;
                $clearedDate = null;

                foreach ($row as $key => $value) {
                    if (strcasecmp($key, 'CONTACT_ID') === 0) {
                        $cid = (string) $value;
                    }
                    if (strcasecmp($key, 'PROCESS_DATE') === 0) {
                        $processDate = (string) $value;
                    }
                    if (strcasecmp($key, 'CLEARED_DATE') === 0) {
                        $clearedDate = (string) $value;
                    }
                }

                if (!$cid || !$processDate) {
                    continue;
                }

                $status = ($clearedDate === null || $clearedDate === '') ? 'Pending' : 'Cleared';
                $llgId = 'LLG-' . $cid;

                $payments[$llgId] = [
                    'date' => $processDate,
                    'status' => $status,
                ];
            }
        }

        $this->info('Fetched ' . count($payments) . ' first payments from Snowflake.');

        return $payments;
    }

    protected function updateFirstPayments(DBConnector $connector, array $payments): int
    {
        if (empty($payments)) {
            return 0;
        }

        $totalUpdated = 0;
        $batchSize = 500;
        $batches = array_chunk($payments, $batchSize, true);

        foreach ($batches as $chunk) {
            $casesDate = [];
            $casesStatus = [];
            $ids = [];

            foreach ($chunk as $llgId => $data) {
                $llgEsc = $this->truncateString($this->escapeSqlString($llgId), 100);
                $dateEsc = $this->truncateString($this->escapeSqlString($data['date']), 50);
                $statusEsc = $this->truncateString($this->escapeSqlString($data['status']), 50);
                $casesDate[] = "WHEN '{$llgEsc}' THEN '{$dateEsc}'";
                $casesStatus[] = "WHEN '{$llgEsc}' THEN '{$statusEsc}'";
                $ids[] = "'{$llgEsc}'";
            }

            if (empty($casesDate) || empty($ids)) {
                continue;
            }

            $caseDateSql = implode(' ', $casesDate);
            $caseStatusSql = implode(' ', $casesStatus);
            $idList = implode(', ', $ids);

            $sql = <<<SQL
UPDATE TblEnrollment
SET
    First_Payment_Date = CASE LLG_ID {$caseDateSql} END,
    First_Payment_Status = CASE LLG_ID {$caseStatusSql} END
WHERE LLG_ID IN ({$idList})
  AND First_Payment_Date IS NULL;
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
        $macro = 'SyncFirstPaymentDate';
        $actionLabel = $action !== '' ? strtoupper($action) : 'SYNC_FIRST_PAYMENT_DATE';

        $description = $this->truncateString(
            sprintf('Sync first payment date for %s', $source),
            255
        );
        $descriptionEsc = $this->escapeSqlString($description);

        $details = $this->truncateString($details, 200);
        $resultSummary = $this->truncateString(
            sprintf('S=%s A=%s P=%d D=%d', $status, $actionLabel, $recordsProcessed, $recordsDeleted),
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
                Log::error('SyncFirstPaymentDate: log insert failed.', [
                    'source' => $source,
                    'sql' => $sql,
                    'result' => $result,
                ]);
                return;
            }

            $this->info(sprintf('[%s] Log entry inserted into TblLog.', $source));
        } catch (\Throwable $e) {
            $this->error(sprintf('[%s] Log insert failed: %s', $source, $e->getMessage()));
            Log::error('SyncFirstPaymentDate: log insert failed.', [
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
