<?php

namespace Cmd\Reports\Console\Commands;

use Cmd\Reports\Services\DBConnector;
use Cmd\Reports\Services\SqlServerRowSanitizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncSettlementData extends Command
{
    protected $signature = 'sync:settlement-data';

    protected $description = 'Sync settlement data into TblSettlementsNGF from Snowflake DEBTS for NGF clients';

    public function handle(): int
    {
        $this->info('Settlement sync: starting.');
        Log::info('SyncSettlementData command started.');

        $connections = ['plaw', 'ldr'];
        $hadException = false;

        foreach ($connections as $connection) {
            $source = strtoupper($connection);
            $this->info("[$source] Starting settlement sync.");
            Log::info('SyncSettlementData: starting connection.', [
                'connection' => $connection,
                'source' => $source,
            ]);

            try {
                $connector = DBConnector::fromEnvironment($connection);
                $connector->initializeSqlServer();
                $this->ensureLogTable($connector);

                $this->info("[$source] Fetching NGF client IDs from SQL Server...");
                $llgIds = $this->fetchNgfClientIds($connector);

                if (empty($llgIds)) {
                    $this->warn("[$source] No NGF clients found in TblEnrollment.");
                    $this->insertLogRow(
                        $connector,
                        $source,
                        'SYNC_SETTLEMENT_DATA',
                        'SUCCESS',
                        0,
                        0,
                        'No NGF clients found.'
                    );
                    continue;
                }

                $this->info("[$source] Fetching creditors from Snowflake...");
                $creditorMap = $this->fetchCreditorMap($connector);

                $this->info("[$source] Fetching settlements from Snowflake...");
                $rows = $this->fetchSettlementsFromSnowflake($connector, $llgIds, $creditorMap);

                // Repair anything that cannot survive the INSERT, and abort here if the
                // damage is structural. Must run BEFORE the delete below: discovering a
                // bad value mid-insert is what left this table half-written on 2026-09-10.
                $rows = $this->sanitizeRows($connector, $rows, $source);

                $this->info("[$source] Deleting existing settlements for source...");
                $deleted = $this->deleteSettlementsBySource($connector, $source);

                if (empty($rows)) {
                    $this->warn("[$source] No settlement rows found in Snowflake.");
                    $this->insertLogRow(
                        $connector,
                        $source,
                        'SYNC_SETTLEMENT_DATA',
                        'SUCCESS',
                        0,
                        $deleted,
                        'No settlements found to insert.'
                    );
                    continue;
                }

                $this->info("[$source] Inserting settlement rows to SQL Server...");
                $inserted = $this->insertSettlements($connector, $rows, $source);

                $this->info("[$source] Inserted {$inserted} settlement rows.");
                $this->insertLogRow(
                    $connector,
                    $source,
                    'SYNC_SETTLEMENT_DATA',
                    'SUCCESS',
                    $inserted,
                    $deleted,
                    sprintf('Rows fetched: %d. Inserted: %d.', count($rows), $inserted)
                );

                Log::info('SyncSettlementData: connection finished.', [
                    'connection' => $connection,
                    'source' => $source,
                    'deleted' => $deleted,
                    'inserted' => $inserted,
                ]);
            } catch (\Throwable $e) {
                $hadException = true;

                $this->error("Settlement sync failed for connection [{$connection}] ({$source}).");
                $this->error($e->getMessage());

                Log::error('SyncSettlementData: exception during sync.', [
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
                        'SYNC_SETTLEMENT_DATA',
                        'FAILED',
                        0,
                        0,
                        $errorMessage
                    );
                } catch (\Throwable $logException) {
                    Log::error('SyncSettlementData: failed to log to TblLog after exception.', [
                        'connection' => $connection,
                        'source' => $source,
                        'exception' => $logException,
                    ]);
                }
            }
        }

        $this->info('Settlement sync: finished.');
        Log::info('SyncSettlementData command finished.', [
            'status' => $hadException ? 'FAILURE' : 'SUCCESS',
        ]);

        return $hadException ? Command::FAILURE : Command::SUCCESS;
    }

    protected function fetchNgfClientIds(DBConnector $connector): array
    {
        $sql = <<<SQL
SELECT LLG_ID
FROM TblEnrollment
WHERE Debt_Sold_To = 'NGF'
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
            foreach ($row as $key => $value) {
                if (strcasecmp($key, 'LLG_ID') === 0 && $value !== null && $value !== '') {
                    $llgIds[] = (string) $value;
                    break;
                }
            }
        }

        $this->info('Found ' . count($llgIds) . ' NGF clients.');

        return $llgIds;
    }

    protected function fetchCreditorMap(DBConnector $connector): array
    {
        $sql = "SELECT ID, COMPANY FROM Creditors";

        try {
            $result = $connector->query($sql);
        } catch (\Throwable $e) {
            $this->warn('Snowflake creditors query failed: ' . $e->getMessage());
            Log::warning('SyncSettlementData: creditors query failed.', ['error' => $e->getMessage()]);
            $result = [];
        }

        if (is_array($result)) {
            if (isset($result['success']) && $result['success'] === false) {
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

        $map = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = null;
            $company = null;
            foreach ($row as $key => $value) {
                if (strcasecmp($key, 'ID') === 0) {
                    $id = (string) $value;
                } elseif (strcasecmp($key, 'COMPANY') === 0) {
                    $company = (string) $value;
                }
            }
            if ($id !== null && $id !== '' && $company !== null) {
                $map[$id] = $company;
            }
        }

        $this->info('Loaded ' . count($map) . ' creditors.');

        return $map;
    }

    protected function fetchSettlementsFromSnowflake(DBConnector $connector, array $llgIds, array $creditorMap): array
    {
        if (empty($llgIds)) {
            return [];
        }

        $contactIds = [];
        foreach ($llgIds as $llg) {
            $id = preg_replace('/^LLG-?/i', '', (string) $llg);
            $id = trim($id);
            if ($id !== '' && ctype_digit($id)) {
                $contactIds[] = $id;
            }
        }
        $contactIds = array_values(array_unique($contactIds));

        $rows = [];
        $chunkSize = 1000;

        foreach (array_chunk($contactIds, $chunkSize) as $chunk) {
            $values = implode(', ', array_map(function ($id) {
                return "('" . $this->escapeSqlString($id) . "')";
            }, $chunk));

            $sql = <<<SQL
WITH missing AS (
    SELECT column1 AS CONTACT_ID_STR
    FROM VALUES {$values}
)
SELECT
    d.CONTACT_ID,
    d.CREDITOR_ID,
    d.DEBT_BUYER,
    d.ACCOUNT_NUM,
    d.ORIGINAL_DEBT_AMOUNT,
    d.CURRENT_AMOUNT,
    d.SETTLEMENT_AMOUNT,
    'Actual' AS STATUS,
    TO_CHAR(CAST(CONVERT_TIMEZONE('America/Los_Angeles', d.SETTLEMENT_DATE) AS DATE), 'YYYY-MM-DD') AS SETTLEMENT_DATE,
    d.SETTLEMENT_ID
FROM DEBTS d
JOIN missing m ON TO_VARCHAR(d.CONTACT_ID) = m.CONTACT_ID_STR
WHERE d.Enrolled = 1
  AND COALESCE(CAST(CONVERT_TIMEZONE('America/Los_Angeles', d.SETTLEMENT_DATE) AS DATE), '2018-01-01') >= '2018-01-01'
ORDER BY d.CONTACT_ID ASC, CONVERT_TIMEZONE('America/Los_Angeles', d.SETTLEMENT_DATE) ASC
SQL;

            try {
                $result = $connector->query($sql);
            } catch (\Throwable $e) {
                $this->warn('Snowflake settlements query failed: ' . $e->getMessage());
                Log::warning('SyncSettlementData: settlements query failed.', ['error' => $e->getMessage()]);
                $result = [];
            }

            $chunkRows = [];
            if (is_array($result)) {
                if (isset($result['success']) && $result['success'] === false) {
                    $chunkRows = [];
                } elseif (isset($result['data']) && is_array($result['data'])) {
                    $chunkRows = $result['data'];
                } elseif (array_is_list($result)) {
                    $chunkRows = $result;
                }
            }

            $rows = array_merge($rows, $chunkRows);
        }

        $records = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $cid = $this->valueForKey($row, 'CONTACT_ID');
            if ($cid === null || $cid === '') {
                continue;
            }

            $creditorId = $this->valueForKey($row, 'CREDITOR_ID');
            $debtBuyer = $this->valueForKey($row, 'DEBT_BUYER');
            $accountNum = $this->valueForKey($row, 'ACCOUNT_NUM');
            $originalAmount = $this->valueForKey($row, 'ORIGINAL_DEBT_AMOUNT');
            $currentAmount = $this->valueForKey($row, 'CURRENT_AMOUNT');
            $settlementAmount = $this->valueForKey($row, 'SETTLEMENT_AMOUNT');
            $status = $this->valueForKey($row, 'STATUS');
            $settlementDate = $this->normalizeDate($this->valueForKey($row, 'SETTLEMENT_DATE'));
            $settlementId = $this->valueForKey($row, 'SETTLEMENT_ID');

            $creditorName = $creditorId !== null && isset($creditorMap[(string) $creditorId])
                ? $creditorMap[(string) $creditorId]
                : (string) $creditorId;

            $debtBuyerName = $debtBuyer !== null && $debtBuyer !== '' && isset($creditorMap[(string) $debtBuyer])
                ? $creditorMap[(string) $debtBuyer]
                : (string) $debtBuyer;

            $settlementAmountNum = $this->toNumber($settlementAmount);
            $currentAmountNum = $this->toNumber($currentAmount);

            if ($settlementAmount === null || $settlementAmount === '') {
                $status = 'Estimate';
                if ($this->containsLaw($debtBuyerName)) {
                    $settlementAmountNum = $currentAmountNum;
                } elseif (stripos($debtBuyerName, 'Discover') !== false) {
                    $settlementAmountNum = $currentAmountNum * 0.8;
                } else {
                    $settlementAmountNum = $currentAmountNum * 0.65;
                }
            }

            $llgId = 'LLG-' . $cid;
            $records[] = [
                'llg_id' => $llgId,
                'creditor' => $creditorName,
                'debt_buyer' => $debtBuyerName,
                'account_number' => $this->lastFour($accountNum),
                'original_debt_amount' => $this->toNumber($originalAmount),
                'current_amount' => $currentAmountNum,
                'settlement_amount' => $settlementAmountNum,
                'status' => $status ?: 'Actual',
                'settlement_date' => $settlementDate,
                'settlement_id' => $settlementId,
            ];
        }

        $this->info('Fetched ' . count($records) . ' settlement rows from Snowflake.');

        return $records;
    }

    protected function deleteSettlementsBySource(DBConnector $connector, string $source): int
    {
        $sourceEsc = $this->escapeSqlString($source);
        
        // Delete current source with DP_ prefix + legacy ProLaw/PLAW/LDR
        if (strtoupper($source) === 'PLAW') {
            $sql = "DELETE FROM TblSettlementsNGF WHERE Source IN ('DP_PLAW', 'PLAW', 'ProLaw')";
        } else {
            $sql = "DELETE FROM TblSettlementsNGF WHERE Source IN ('DP_LDR', 'LDR')";
        }

        $result = $connector->querySqlServer($sql);
        if (is_array($result) && isset($result['success']) && $result['success'] === false) {
            $errorMsg = $result['error'] ?? 'Unknown SQL Server error';
            throw new \RuntimeException('Delete failed: ' . $errorMsg);
        }
        if (is_array($result)) {
            foreach (['rowCount', 'affected_rows', 'row_count'] as $key) {
                if (isset($result[$key]) && is_numeric($result[$key])) {
                    return (int) $result[$key];
                }
            }
        }

        return 0;
    }

    /**
     * Repair values that TblSettlementsNGF would reject, BEFORE anything destructive runs.
     *
     * Division of responsibility: SqlServerRowSanitizer enforces the table's physical
     * constraints (string widths, DECIMAL capacity) read live from INFORMATION_SCHEMA,
     * while numericLiteral() enforces the business plausibility ceiling. The former is a
     * storage fact, the latter a judgement about what a real debt can be — keeping them
     * apart means a schema change updates one and a policy change the other.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    protected function sanitizeRows(DBConnector $connector, array $rows, string $source): array
    {
        if (empty($rows)) {
            return $rows;
        }

        $sanitizer = SqlServerRowSanitizer::forTable($connector, 'TblSettlementsNGF', 'SyncSettlementData');

        $map = [
            'creditor'             => 'Creditor',
            'debt_buyer'           => 'Debt_Buyer',
            'account_number'       => 'Account_Number',
            'status'               => 'Status',
            'settlement_id'        => 'Settlement_ID',
            'original_debt_amount' => 'Original_Debt_Amount',
            'current_amount'       => 'Current_Amount',
            'settlement_amount'    => 'Settlement_Amount',
        ];

        foreach ($rows as &$row) {
            $id = (string) ($row['llg_id'] ?? 'unknown');

            foreach ($map as $key => $column) {
                if (array_key_exists($key, $row)) {
                    $row[$key] = $sanitizer->clean($column, $row[$key], $id);
                }
            }
        }
        unset($row);

        // Throws if the damage looks structural, so the caller never reaches the DELETE.
        $sanitizer->assertWithinTolerance(count($rows));

        if ($sanitizer->violations() > 0) {
            $this->warn("[{$source}] Pre-flight: " . $sanitizer->summary() . '.');
        }

        return $rows;
    }

    protected function insertSettlements(DBConnector $connector, array $rows, string $source): int
    {
        if (empty($rows)) {
            return 0;
        }

        $fields = "LLG_ID, Creditor, Debt_Buyer, Account_Number, Original_Debt_Amount, Current_Amount, Settlement_Amount, Status, Settlement_Date, Settlement_ID, Source";
        $inserted = 0;
        $batchSize = 1000;

        for ($i = 0; $i < count($rows); $i += $batchSize) {
            $batch = array_slice($rows, $i, $batchSize);
            $values = '';

            foreach ($batch as $row) {
                $values .= ',(';
                $values .= "'" . $this->escapeSqlString($row['llg_id']) . "'";
                $values .= ", '" . $this->escapeSqlString($row['creditor']) . "'";
                $values .= ", '" . $this->escapeSqlString($row['debt_buyer']) . "'";
                $values .= ", '" . $this->escapeSqlString($row['account_number']) . "'";
                $values .= ', ' . $this->numericLiteral($row['original_debt_amount'], $row['llg_id'], 'Original_Debt_Amount');
                $values .= ', ' . $this->numericLiteral($row['current_amount'], $row['llg_id'], 'Current_Amount');
                $values .= ', ' . $this->numericLiteral($row['settlement_amount'], $row['llg_id'], 'Settlement_Amount');
                $values .= ", '" . $this->escapeSqlString((string) $row['status']) . "'";

                if ($row['settlement_date'] === null || $row['settlement_date'] === '') {
                    $values .= ", NULL";
                } else {
                    $values .= ", '" . $this->escapeSqlString((string) $row['settlement_date']) . "'";
                }

                $values .= ", '" . $this->escapeSqlString((string) $row['settlement_id']) . "'";
                $values .= ", '" . $this->escapeSqlString('DP_' . strtoupper($source)) . "'";
                $values .= ')';
            }

            $sql = "INSERT INTO TblSettlementsNGF ({$fields}) VALUES " . ltrim($values, ',');
            $result = $connector->querySqlServer($sql);
            if (is_array($result) && isset($result['success']) && $result['success'] === false) {
                $errorMsg = $result['error'] ?? 'Unknown SQL Server error';
                Log::error('SyncSettlementData: insert failed.', [
                    'source' => $source,
                    'error' => $errorMsg,
                    'sql_sample' => mb_substr($sql, 0, 500),
                ]);
                throw new \RuntimeException('Insert failed: ' . $errorMsg);
            }
            $inserted += count($batch);
        }

        return $inserted;
    }

    /**
     * Ceiling for the money columns on TblSettlementsNGF.
     *
     * They are DECIMAL(18,2), which holds 16 integer digits - i.e. just under 1e16.
     * Anything at or beyond this cannot be stored at all, so it is logged and written
     * as NULL rather than aborting the batch.
     */
    private const MONEY_MAX = 1.0e16;

    /**
     * Plausibility ceiling for a SINGLE debt account, in dollars.
     *
     * A data-quality guard, not a storage limit - it sits far below MONEY_MAX. Forth
     * occasionally carries placeholder rows (2026-09-10: CONTACT_ID 462464571,
     * ACCOUNT_NUM 67676767, ORIGINAL and CURRENT both exactly 999999999.99, no
     * settlement amount). Widening the column alone would let that land as a real $1bn
     * debt and quietly inflate every report summing these columns, so anything above
     * this ceiling is logged and stored as NULL instead.
     *
     * $10m is deliberately generous: observed settlements top out near $64k and
     * per-contact enrolled debt near $128k, so it cannot reach real data.
     */
    private const MONEY_SANITY_CEILING = 10000000.0;

    /**
     * Render a money value as an UNQUOTED SQL numeric literal.
     *
     * These fields used to be written as quoted strings, which forced SQL Server into an
     * implicit varchar->numeric conversion. That is why an out-of-range value surfaced as
     * "Arithmetic overflow error converting varchar to data type numeric" - naming the
     * conversion rather than the column, and aborting the whole insert. An unquoted
     * literal also avoids PHP's float-to-string switching to scientific notation
     * ("1.0E+17") past ~15 digits, which SQL Server cannot parse at all.
     *
     * Out-of-range values become NULL and are logged with their LLG_ID, so one bad source
     * row costs a single field instead of the remaining batches. (2026-09-10: a PLAW debt
     * carrying 999999999.99 overflowed the then-DECIMAL(10,2) column and stranded
     * TblSettlementsNGF at 67,000 of 71,252 PLAW rows.)
     */
    protected function numericLiteral($value, string $llgId, string $column): string
    {
        if ($value === null || $value === '') {
            return 'NULL';
        }

        $number = (float) $value;

        if (is_nan($number) || is_infinite($number)) {
            Log::warning('SyncSettlementData: non-finite money value; storing NULL.', [
                'llg_id' => $llgId,
                'column' => $column,
                'value'  => (string) $value,
            ]);

            return 'NULL';
        }

        if (abs($number) >= self::MONEY_SANITY_CEILING) {
            Log::warning('SyncSettlementData: implausible money value; storing NULL.', [
                'llg_id'  => $llgId,
                'column'  => $column,
                'value'   => sprintf('%.2f', $number),
                'ceiling' => sprintf('%.2f', self::MONEY_SANITY_CEILING),
            ]);

            return 'NULL';
        }

        if (abs($number) >= self::MONEY_MAX) {
            Log::warning('SyncSettlementData: money value exceeds column capacity; storing NULL.', [
                'llg_id' => $llgId,
                'column' => $column,
                'value'  => sprintf('%.2f', $number),
                'max'    => sprintf('%.2f', self::MONEY_MAX),
            ]);

            return 'NULL';
        }

        // Fixed-point, no thousands separator, never scientific notation.
        return number_format($number, 2, '.', '');
    }

    protected function containsLaw(string $value): bool
    {
        return stripos($value, 'Law ') !== false || stripos($value, ' Law') !== false;
    }

    protected function lastFour(?string $value): string
    {
        $value = (string) $value;
        if ($value === '') {
            return '';
        }
        return strlen($value) <= 4 ? $value : substr($value, -4);
    }

    protected function toNumber($value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        return (float) $value;
    }

    protected function normalizeDate($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        $string = trim((string) $value);
        if ($string === '') {
            return null;
        }

        $timestamp = strtotime($string);
        if ($timestamp === false) {
            return null;
        }

        return date('Y-m-d H:i:s', $timestamp);
    }

    protected function valueForKey(array $row, string $key): ?string
    {
        foreach ($row as $rowKey => $value) {
            if (strcasecmp($rowKey, $key) === 0) {
                return $value !== null ? (string) $value : null;
            }
        }

        return null;
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
        $tableName = 'TblSettlementsNGF';
        $macro = 'SyncSettlementData';
        $actionLabel = $action !== '' ? strtoupper($action) : 'SYNC_SETTLEMENT_DATA';
        
        // Use DP_ prefix in TblLog for automation tracking
        $logSource = 'DP_' . strtoupper($source);

        $description = $this->truncateString(
            sprintf('Sync settlements for %s', $logSource),
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
                Log::error('SyncSettlementData: log insert failed.', [
                    'source' => $source,
                    'sql' => $sql,
                    'result' => $result,
                ]);
                return;
            }

            $this->info(sprintf('[%s] Log entry inserted into TblLog.', $source));
        } catch (\Throwable $e) {
            $this->error(sprintf('[%s] Log insert failed: %s', $source, $e->getMessage()));
            Log::error('SyncSettlementData: log insert failed.', [
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
