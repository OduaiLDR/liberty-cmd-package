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

                $this->info("[$source] Fetching IDs missing First_Payment_Cleared_Date from SQL Server...");
                $missingIds = $this->fetchMissingIdsFromSqlServer($connector);

                if (empty($missingIds)) {
                    $this->warn("[$source] No rows missing First_Payment_Cleared_Date in SQL Server.");
                    $this->insertLogRow(
                        $connector,
                        $source,
                        'SYNC_FIRST_PAYMENT_CLEARED_DATE',
                        'SUCCESS',
                        0,
                        0,
                        'No rows missing First_Payment_Cleared_Date in SQL Server.'
                    );
                    continue;
                }

                $this->info("[$source] Fetching first cleared payments from Snowflake for missing IDs...");
                $payments = $this->fetchFirstClearedFromSnowflake($connector, $missingIds);

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

    protected function fetchMissingIdsFromSqlServer(DBConnector $connector): array
    {
        $sql = <<<SQL
SELECT LLG_ID
FROM dbo.TblEnrollment
WHERE First_Payment_Cleared_Date IS NULL
  AND Cancel_Date IS NULL
  AND LLG_ID LIKE 'LLG-%'
  AND TRY_CONVERT(BIGINT, REPLACE(LLG_ID, 'LLG-', '')) IS NOT NULL
SQL;

        $result = $connector->querySqlServer($sql);

        if (!is_array($result) || ($result['success'] ?? null) !== true) {
            $err = is_array($result) ? ($result['error'] ?? 'Unknown SQL Server error') : 'Non-array SQL Server response';
            throw new \RuntimeException("SQL Server fetchMissingIds failed: {$err}");
        }

        if (isset($result['data']) && is_array($result['data'])) {
            $rows = $result['data'];
        } elseif (array_is_list($result)) {
            $rows = $result;
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

        $this->info('Found ' . count($ids) . ' IDs eligible for first cleared payment sync.');
        if (!empty($ids)) {
            $sample = array_slice($ids, 0, 5);
            $this->info('Sample IDs: ' . implode(', ', $sample));
        }

        return $ids;
    }

    protected function fetchFirstClearedFromSnowflake(DBConnector $connector, array $missingLlgs): array
    {
        if (empty($missingLlgs)) {
            $this->warn('Empty LLG ID array passed to fetchFirstClearedFromSnowflake');
            return [];
        }

        $this->info('Starting fetchFirstClearedFromSnowflake with ' . count($missingLlgs) . ' LLG IDs');
        $this->info('Sample LLG IDs: ' . implode(', ', array_slice($missingLlgs, 0, 5)));

        // Map contact ID -> original LLG ID
        $contactToLlg = [];
        foreach ($missingLlgs as $llgId) {
            $numeric = preg_replace('/\\D+/', '', (string) $llgId);
            if ($numeric === '') {
                $this->warn("Could not extract numeric ID from: {$llgId}");
                continue;
            }
            if (!isset($contactToLlg[$numeric])) {
                $contactToLlg[$numeric] = (string) $llgId;
            }
        }

        $contactIds = array_keys($contactToLlg);

        if (empty($contactIds)) {
            $this->error('No numeric CONTACT_IDs extracted from provided LLG IDs.');
            return [];
        }

        $this->info('Extracted ' . count($contactIds) . ' numeric contact IDs');
        $this->info('Sample contact IDs: ' . implode(', ', array_slice($contactIds, 0, 5)));

        $chunkSize = 500;
        $payments = [];

        foreach (array_chunk($contactIds, $chunkSize) as $chunkIndex => $chunk) {
            $this->info('Processing chunk ' . ($chunkIndex + 1) . ' with ' . count($chunk) . ' contact IDs...');

            $values = implode(', ', array_map(function ($id) {
                return "('" . $this->escapeSqlString($id) . "')";
            }, $chunk));

            // Match VBA logic EXACTLY:
            // 1) Find first CLEARED_DATE per contact.
            $sql = <<<SQL
SELECT
    CONTACT_ID,
    TO_VARCHAR(CLEARED_DATE, 'YYYY-MM-DD') AS CLEARED_DATE,
    TO_VARCHAR(PROCESS_DATE, 'YYYY-MM-DD') AS PROCESS_DATE,
    AMOUNT
FROM (
    SELECT
        t.CONTACT_ID,
        TO_DATE(t.CLEARED_DATE) AS CLEARED_DATE,
        TO_DATE(t.PROCESS_DATE) AS PROCESS_DATE,
        t.AMOUNT,
        ROW_NUMBER() OVER (PARTITION BY t.CONTACT_ID ORDER BY TO_DATE(t.CLEARED_DATE)) AS N
    FROM TRANSACTIONS t
    WHERE t.CONTACT_ID IN (SELECT TO_NUMBER(column1) FROM VALUES {$values})
      AND t.TRANS_TYPE = 'D'
      AND t.CLEARED_DATE IS NOT NULL
      AND t.RETURNED_DATE IS NULL
)
WHERE N = 1
SQL;

            $this->info('SQL Query for chunk ' . ($chunkIndex + 1) . ':');
            $this->info(substr($sql, 0, 400) . '...');

            // DEBUG: Check if data exists at all for first 5 IDs
            if ($chunkIndex === 0) {
                $debugIds = array_slice($chunk, 0, 5);
                $debugIdList = implode(',', $debugIds);
                $debugSql = "SELECT CONTACT_ID, TRANS_TYPE, CLEARED_DATE, RETURNED_DATE FROM TRANSACTIONS WHERE CONTACT_ID IN ({$debugIdList}) AND TRANS_TYPE = 'D' LIMIT 10";
                try {
                    $this->info('DEBUG: Checking if any data exists for first 5 contact IDs...');
                    $debugResult = $connector->query($debugSql);
                    if (isset($debugResult['data']) && count($debugResult['data']) > 0) {
                        $this->info('DEBUG: Found ' . count($debugResult['data']) . ' rows in TRANSACTIONS');
                        $this->info('DEBUG Sample: ' . json_encode($debugResult['data'][0]));
                    } else {
                        $this->error('DEBUG: NO DATA FOUND in TRANSACTIONS for these contact IDs!');
                    }
                } catch (\Throwable $e) {
                    $this->error('DEBUG query failed: ' . $e->getMessage());
                }
            }

            try {
                $this->info('Executing Snowflake query for chunk ' . ($chunkIndex + 1) . '...');
                $result = $connector->query($sql);
                $this->info('Snowflake query completed for chunk ' . ($chunkIndex + 1));

                if (is_array($result)) {
                    $this->info('Result keys: ' . implode(', ', array_keys($result)));
                    if (isset($result['rowCount'])) {
                        $this->info('Result rowCount: ' . $result['rowCount']);
                    }
                } else {
                    $this->warn('Result is not an array, type: ' . gettype($result));
                }
            } catch (\Throwable $e) {
                $this->error('Snowflake query failed: ' . $e->getMessage());
                Log::error('SyncFirstPaymentClearedDate: Snowflake query failed.', [
                    'error' => $e->getMessage(),
                    'chunk' => $chunkIndex + 1,
                    'sample_ids' => array_slice($chunk, 0, 5),
                    'sql' => substr($sql, 0, 500),
                ]);
                continue;
            }

            if (is_array($result)) {
                if (isset($result['success']) && $result['success'] === false) {
                    $this->error('Snowflake query returned success=false');
                    Log::error('SyncFirstPaymentClearedDate: Snowflake query success=false.', ['result' => $result]);
                    $rows = [];
                } elseif (isset($result['data']) && is_array($result['data'])) {
                    $rows = $result['data'];
                    $this->info('Retrieved ' . count($rows) . ' rows from Snowflake data array');
                } elseif (array_is_list($result)) {
                    $rows = $result;
                    $this->info('Retrieved ' . count($rows) . ' rows from Snowflake (list format)');
                } else {
                    $rows = [];
                    $this->warn('Snowflake result format not recognized');
                    $this->info('Result structure: ' . json_encode(array_keys($result)));
                }
            } else {
                $rows = [];
                $this->warn('Snowflake result is not an array');
            }

            $this->info('Processing ' . count($rows) . ' rows from chunk ' . ($chunkIndex + 1));

            foreach ($rows as $rowIndex => $row) {
                if (!is_array($row)) {
                    $this->warn("Row {$rowIndex} is not an array: " . gettype($row));
                    continue;
                }

                $cid = null;
                $clearedDate = null;
                $processDate = null;
                $amount = null;

                foreach ($row as $key => $value) {
                    if (strcasecmp($key, 'CONTACT_ID') === 0) {
                        $cid = (string) $value;
                    } elseif (strcasecmp($key, 'CLEARED_DATE') === 0) {
                        $clearedDate = $this->normalizeSnowflakeDate((string) $value);
                    } elseif (strcasecmp($key, 'PROCESS_DATE') === 0) {
                        $processDate = $this->normalizeSnowflakeDate((string) $value);
                    } elseif (strcasecmp($key, 'AMOUNT') === 0) {
                        $amount = $value;
                    }
                }

                if ($cid === null || $cid === '') {
                    $this->warn('Row ' . $rowIndex . ' missing CONTACT_ID. Keys: ' . implode(', ', array_keys($row)));
                    continue;
                }

                if ($clearedDate === null || $clearedDate === '') {
                    $this->warn("Row {$rowIndex} (CID: {$cid}) missing CLEARED_DATE");
                    continue;
                }

                $llgOriginal = $contactToLlg[$cid] ?? ('LLG-' . $cid);
                $llgId = $this->truncateString($llgOriginal, 100);

                $today = now()->format('Y-m-d');
                if ($clearedDate > $today) {
                    $this->warn("Skipping FUTURE cleared date for {$llgId}: {$clearedDate}");
                    continue;
                }

                $this->info("✓ Found payment for {$llgId}: {$clearedDate}, Amount: " . ($amount ?? 'NULL'));

                $payments[$llgId] = [
                    'cleared_date' => $clearedDate,
                    'process_date' => $processDate,
                    'amount' => $amount,
                ];
            }

            $this->info('Chunk ' . ($chunkIndex + 1) . ' complete. Total payments so far: ' . count($payments));
        }

        $this->info('=== FINAL RESULT ===');
        $this->info('Fetched ' . count($payments) . ' first cleared payments from Snowflake');

        if (count($payments) > 0) {
            $this->info('Sample payments: ' . json_encode(array_slice($payments, 0, 3, true)));
        } else {
            $this->error('NO PAYMENTS FOUND');
            $this->info('This means either:');
            $this->info('1. No contacts had their FIRST payment occur in the last 7 days');
            $this->info('2. PROCESS_DATE is also NULL (run the debug query above to check)');
            $this->info('3. Different database/schema than VBA uses');
        }

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
            $casesProcessDate = [];
            $casesAmount = [];
            $ids = [];

            foreach ($chunk as $llgId => $data) {
                $llgEsc = $this->truncateString($this->escapeSqlString($llgId), 100);
                $dateEsc = $this->truncateString($this->escapeSqlString($data['cleared_date']), 50);
                $processDateVal = $data['process_date'] ?? null;
                $processDateEsc = null;
                if ($processDateVal !== null && $processDateVal !== '') {
                    $processDateEsc = $this->truncateString(
                        $this->escapeSqlString((string) $processDateVal),
                        50
                    );
                }
                $amountVal = $data['amount'];
                $amountSql = 'NULL';
                if ($amountVal !== null && $amountVal !== '' && is_numeric($amountVal)) {
                    $amountSql = (string) $amountVal;
                }

                $casesDate[] = "WHEN '{$llgEsc}' THEN '{$dateEsc}'";
                if ($processDateEsc !== null) {
                    $casesProcessDate[] = "WHEN '{$llgEsc}' THEN '{$processDateEsc}'";
                }
                $casesAmount[] = "WHEN '{$llgEsc}' THEN {$amountSql}";
                $ids[] = "'{$llgEsc}'";
            }

            if (empty($casesDate) || empty($ids)) {
                continue;
            }

            $caseDateSql = implode(' ', $casesDate);
            $caseProcessDateSql = implode(' ', $casesProcessDate);
            $caseAmountSql = implode(' ', $casesAmount);
            $idList = implode(', ', $ids);

            $setClauses = [
                "First_Payment_Cleared_Date = CASE LLG_ID {$caseDateSql} END",
                "First_Payment_Status = 'Cleared'",
                "Program_Payment = CASE LLG_ID {$caseAmountSql} END",
            ];
            if ($caseProcessDateSql !== '') {
                $setClauses[] = "First_Payment_Date = CASE LLG_ID {$caseProcessDateSql} ELSE First_Payment_Date END";
            }
            $setSql = implode(",\n    ", $setClauses);

            $sql = <<<SQL
UPDATE TblEnrollment
SET
    {$setSql}
WHERE LLG_ID IN ({$idList});
SQL;

            $this->info('Executing UPDATE for ' . count($chunk) . ' records');
            Log::debug('SyncFirstPaymentClearedDate: SQL UPDATE statement', ['sql' => mb_substr($sql, 0, 500)]);

            $result = $connector->querySqlServer($sql);
            Log::debug('SyncFirstPaymentClearedDate: SQL UPDATE result', ['result' => $result]);

            if (!is_array($result)) {
                $this->error('SQL Server update returned non-array result; treating as 0 updated for this batch.');
                continue;
            }

            if (isset($result['success']) && $result['success'] === false) {
                $errorMsg = $result['error'] ?? 'Unknown SQL Server error';
                $this->error('SQL Server update failed: ' . $errorMsg);
                Log::error('SyncFirstPaymentClearedDate: SQL Server update failed.', [
                    'result' => $result,
                    'sql' => mb_substr($sql, 0, 500),
                ]);
                continue;
            }

            $updated = null;
            foreach (['rowCount', 'affected_rows', 'row_count'] as $key) {
                if (isset($result[$key]) && is_numeric($result[$key])) {
                    $updated = (int) $result[$key];
                    break;
                }
            }

            if ($updated !== null) {
                $totalUpdated += $updated;
                continue;
            }

            $this->warn('SQL Server update did not return a row count; assuming 0 updated for this batch.');
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

    protected function normalizeSnowflakeDate(string $value): ?string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        if (preg_match('/^\\d{4}-\\d{2}-\\d{2}/', $trimmed) === 1) {
            return substr($trimmed, 0, 10);
        }

        if (preg_match('/^\\d{8}$/', $trimmed) === 1) {
            $parsed = \DateTimeImmutable::createFromFormat('Ymd', $trimmed);
            return $parsed ? $parsed->format('Y-m-d') : null;
        }

        if (preg_match('/^\\d+(?:\\.\\d+)?$/', $trimmed) === 1) {
            // Snowflake can return DATE as days since 1970-01-01.
            $days = (int) floor((float) $trimmed);
            if ($days <= 0) {
                return null;
            }
            $epoch = new \DateTimeImmutable('1970-01-01', new \DateTimeZone('UTC'));
            $parsed = $epoch->modify('+' . $days . ' days');
            return $parsed ? $parsed->format('Y-m-d') : null;
        }

        try {
            $parsed = new \DateTimeImmutable($trimmed);
            return $parsed->format('Y-m-d');
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function truncateString(string $value, int $maxLength): string
    {
        if (mb_strlen($value) <= $maxLength) {
            return $value;
        }

        return mb_substr($value, 0, $maxLength);
    }
}
