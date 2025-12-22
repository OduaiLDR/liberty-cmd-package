<?php

namespace Cmd\Reports\Console\Commands;

use Cmd\Reports\Services\DBConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncFirstPaymentClearedDate extends Command
{
    protected $signature = 'sync:first-payment-cleared-date';

    protected $description = 'Sync First_Payment_Cleared_Date and Program_Payment in TblEnrollment from Snowflake TRANSACTIONS (first cleared D per contact, last 7 days)';

    public function handle(): int
    {
        $this->info('First payment cleared sync: starting.');
        Log::info('SyncFirstPaymentClearedDate command started.');

        $connections = ['plaw', 'ldr'];
        $hadException = false;

        foreach ($connections as $connection) {
            $source = strtoupper($connection);
            $this->info("[$source] Starting first payment cleared sync.");
            Log::info('SyncFirstPaymentClearedDate: starting connection.', [
                'connection' => $connection,
                'source' => $source,
            ]);

            try {
                $connector = DBConnector::fromEnvironment($connection);
                $connector->initializeSqlServer();
                $this->ensureLogTable($connector);

                $this->info("[$source] Fetching first cleared payments from Snowflake...");
                $payments = $this->fetchFirstClearedFromSnowflake($connector);

                if (empty($payments)) {
                    $this->warn("[$source] No first cleared payments found in Snowflake.");
                    $this->insertLogRow(
                        $connector,
                        $source,
                        'SYNC_FIRST_PAYMENT_CLEARED_DATE',
                        'SUCCESS',
                        0,
                        0,
                        'No first cleared payments found to sync.'
                    );
                    continue;
                }

                $this->info("[$source] Applying first cleared payments to SQL Server...");
                $updated = $this->updateFirstClearedPayments($connector, $payments);

                $this->info("[$source] Updated {$updated} first cleared payment rows.");
                $this->insertLogRow(
                    $connector,
                    $source,
                    'SYNC_FIRST_PAYMENT_CLEARED_DATE',
                    'SUCCESS',
                    $updated,
                    0,
                    sprintf('Payments fetched: %d. Updated: %d.', count($payments), $updated)
                );

                Log::info('SyncFirstPaymentClearedDate: connection finished.', [
                    'connection' => $connection,
                    'source' => $source,
                    'updated' => $updated,
                ]);
            } catch (\Throwable $e) {
                $hadException = true;

                $this->error("First payment cleared sync failed for connection [{$connection}] ({$source}).");
                $this->error($e->getMessage());

                Log::error('SyncFirstPaymentClearedDate: exception during sync.', [
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
                        'SYNC_FIRST_PAYMENT_CLEARED_DATE',
                        'FAILED',
                        0,
                        0,
                        $errorMessage
                    );
                } catch (\Throwable $logException) {
                    Log::error('SyncFirstPaymentClearedDate: failed to log to TblLog after exception.', [
                        'connection' => $connection,
                        'source' => $source,
                        'exception' => $logException,
                    ]);
                }
            }
        }

        $this->info('First payment cleared sync: finished.');
        Log::info('SyncFirstPaymentClearedDate command finished.', [
            'status' => $hadException ? 'FAILURE' : 'SUCCESS',
        ]);

        return $hadException ? Command::FAILURE : Command::SUCCESS;
    }

    protected function fetchFirstClearedFromSnowflake(DBConnector $connector): array
    {
        $sql = <<<SQL
WITH tx AS (
    SELECT
        CONTACT_ID,
        CLEARED_DATE,
        AMOUNT,
        ROW_NUMBER() OVER (PARTITION BY CONTACT_ID ORDER BY CLEARED_DATE) AS rn
    FROM TRANSACTIONS
    WHERE TRANS_TYPE = 'D'
      AND CLEARED_DATE IS NOT NULL
      AND RETURNED_DATE IS NULL
)
SELECT CONTACT_ID, CLEARED_DATE, AMOUNT
FROM tx
WHERE rn = 1
  AND CLEARED_DATE >= DATEADD(day, -7, CURRENT_DATE)
ORDER BY CLEARED_DATE ASC
SQL;

        try {
            $result = $connector->query($sql);
        } catch (\Throwable $e) {
            $this->warn('Snowflake query failed: ' . $e->getMessage());
            Log::warning('SyncFirstPaymentClearedDate: Snowflake query failed.', ['error' => $e->getMessage()]);
            $result = [];
        }

        if (is_array($result)) {
            if (isset($result['success']) && $result['success'] === false) {
                Log::warning('SyncFirstPaymentClearedDate: Snowflake query success=false.', ['result' => $result]);
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

        $payments = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $cid = null;
            $clearedDate = null;
            $amount = null;

            foreach ($row as $key => $value) {
                if (strcasecmp($key, 'CONTACT_ID') === 0) {
                    $cid = (string) $value;
                } elseif (strcasecmp($key, 'CLEARED_DATE') === 0) {
                    $clearedDate = (string) $value;
                } elseif (strcasecmp($key, 'AMOUNT') === 0) {
                    $amount = $value;
                }
            }

            if ($cid === null || $cid === '' || $clearedDate === null || $clearedDate === '') {
                continue;
            }

            $llgId = $this->truncateString('LLG-' . $cid, 100);
            $payments[$llgId] = [
                'cleared_date' => $clearedDate,
                'amount' => $amount,
            ];
        }

        $this->info('Fetched ' . count($payments) . ' first cleared payments from Snowflake.');

        return $payments;
    }

    protected function updateFirstClearedPayments(DBConnector $connector, array $payments): int
    {
        if (empty($payments)) {
            return 0;
        }

        $totalUpdated = 0;
        $batchSize = 500;
        $batches = array_chunk($payments, $batchSize, true);

        foreach ($batches as $chunk) {
            $casesDate = [];
            $casesAmount = [];
            $ids = [];

            foreach ($chunk as $llgId => $data) {
                $llgEsc = $this->truncateString($this->escapeSqlString($llgId), 100);
                $dateEsc = $this->truncateString($this->escapeSqlString($data['cleared_date']), 50);
                $amountVal = $data['amount'];
                $amountSql = 'NULL';
                if ($amountVal !== null && $amountVal !== '' && is_numeric($amountVal)) {
                    $amountSql = (string) $amountVal;
                }

                $casesDate[] = "WHEN '{$llgEsc}' THEN '{$dateEsc}'";
                $casesAmount[] = "WHEN '{$llgEsc}' THEN {$amountSql}";
                $ids[] = "'{$llgEsc}'";
            }

            if (empty($casesDate) || empty($ids)) {
                continue;
            }

            $caseDateSql = implode(' ', $casesDate);
            $caseAmountSql = implode(' ', $casesAmount);
            $idList = implode(', ', $ids);

            $sql = <<<SQL
UPDATE TblEnrollment
SET
    First_Payment_Cleared_Date = CASE LLG_ID {$caseDateSql} END,
    First_Payment_Status = 'Cleared',
    Program_Payment = CASE LLG_ID {$caseAmountSql} END
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
        $macro = 'SyncFirstPaymentClearedDate';
        $actionLabel = $action !== '' ? strtoupper($action) : 'SYNC_FIRST_PAYMENT_CLEARED_DATE';

        $description = $this->truncateString(
            sprintf('Sync first payment cleared date for %s', $source),
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
                Log::error('SyncFirstPaymentClearedDate: log insert failed.', [
                    'source' => $source,
                    'sql' => $sql,
                    'result' => $result,
                ]);
                return;
            }

            $this->info(sprintf('[%s] Log entry inserted into TblLog.', $source));
        } catch (\Throwable $e) {
            $this->error(sprintf('[%s] Log insert failed: %s', $source, $e->getMessage()));
            Log::error('SyncFirstPaymentClearedDate: log insert failed.', [
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
