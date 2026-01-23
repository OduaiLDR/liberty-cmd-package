<?php

namespace Cmd\Reports\Console\Commands;

use Cmd\Reports\Services\DBConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncEPFData extends Command
{
    protected $signature = 'sync:epf-data {--full-refresh : Delete existing EPF rows before inserting}';

    protected $description = 'Sync EPF payment data into TblEPFs from Snowflake transactions and settlements';

    public function handle(): int
    {
        $this->info('EPF sync: starting.');
        Log::info('SyncEPFData command started.');

        $connections = ['plaw', 'ldr'];
        $hadException = false;

        foreach ($connections as $connection) {
            $source = $this->sourceLabelForConnection($connection);
            $logSource = $this->buildLogSource($source);
            $this->info("[$source] Starting EPF sync.");
            Log::info('SyncEPFData: starting connection.', [
                'connection' => $connection,
                'source' => $source,
            ]);

            try {
                $connector = DBConnector::fromEnvironment($connection);
                $connector->initializeSqlServer();
                $this->ensureLogTable($connector);
                if (env('EPF_DEBUG', false)) {
                    $this->logSqlServerCounts($connector, $source, 'before');
                }

                $this->info("[$source] Fetching EPF rows from Snowflake...");
                $rows = $this->fetchEpfRowsFromSnowflake($connector);

                $this->info("[$source] Deleting existing EPF rows for source...");
                $deleted = $this->deleteEpfBySource($connector, $source);

                if (empty($rows)) {
                    $this->warn("[$source] No EPF rows found in Snowflake.");
                    $this->insertLogRow(
                        $connector,
                        $logSource,
                        'SYNC_EPF_DATA',
                        'SUCCESS',
                        0,
                        $deleted,
                        'No EPF rows found to insert.'
                    );
                    continue;
                }

                $this->info("[$source] Inserting EPF rows to SQL Server...");
                $inserted = $this->insertEpfRows($connector, $rows, $source);

                $this->info("[$source] Inserted {$inserted} EPF rows.");
                if (env('EPF_DEBUG', false)) {
                    $this->logSqlServerCounts($connector, $source, 'after');
                }
                $this->insertLogRow(
                    $connector,
                    $logSource,
                    'SYNC_EPF_DATA',
                    'SUCCESS',
                    $inserted,
                    $deleted,
                    sprintf('Rows fetched: %d. Inserted: %d.', count($rows), $inserted)
                );

                Log::info('SyncEPFData: connection finished.', [
                    'connection' => $connection,
                    'source' => $source,
                    'deleted' => $deleted,
                    'inserted' => $inserted,
                ]);
            } catch (\Throwable $e) {
                $hadException = true;

                $this->error("EPF sync failed for connection [{$connection}] ({$source}).");
                $this->error($e->getMessage());

                Log::error('SyncEPFData: exception during sync.', [
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
                        $logSource,
                        'SYNC_EPF_DATA',
                        'FAILED',
                        0,
                        0,
                        $errorMessage
                    );
                } catch (\Throwable $logException) {
                    Log::error('SyncEPFData: failed to log to TblLog after exception.', [
                        'connection' => $connection,
                        'source' => $source,
                        'exception' => $logException,
                    ]);
                }
            }
        }

        $this->info('EPF sync: finished.');
        Log::info('SyncEPFData command finished.', [
            'status' => $hadException ? 'FAILURE' : 'SUCCESS',
        ]);

        return $hadException ? Command::FAILURE : Command::SUCCESS;
    }

    protected function fetchEpfRowsFromSnowflake(DBConnector $connector): array
    {
        Log::info('SyncEPFData: fetch mode single query (VBA-style).');
        $this->logSnowflakeCounts($connector);

        // Exact VBA query - single query, no pagination
        $sql = <<<SQL
SELECT
    CONCAT('LLG-', t.CONTACT_ID) AS LLG_ID,
    t.PAID_TO,
    t.AMOUNT,
    t.DRAFT_DATE,
    t.PROCESS_DATE,
    t.RETURNED_DATE,
    t.CLEARED_DATE,
    s.ID AS SETTLEMENT_ID,
    s.ORIGINAL_AMOUNT,
    s.SETTLEMENT_AMOUNT,
    s.CREDITOR_NAME,
    s.OFFER_ID,
    ROW_NUMBER() OVER(PARTITION BY t.CONTACT_ID, s.OFFER_ID ORDER BY t.DRAFT_DATE ASC) AS N
FROM TRANSACTIONS AS t, SETTLEMENTS AS s
WHERE t.LINKED_TO = s.TRANS_ID
  AND t.TRANS_TYPE = 'PF'
  AND t.RETURNED_DATE IS NULL
  AND t.LINKED_TO <> 0
  AND t._FIVETRAN_DELETED = 'FALSE'
  AND s._FIVETRAN_DELETED = 'FALSE'
ORDER BY t.CONTACT_ID ASC, s.OFFER_ID ASC, t.CLEARED_DATE ASC, t.DRAFT_DATE ASC
SQL;

        try {
            $result = $connector->query($sql);
        } catch (\Throwable $e) {
            Log::error('SyncEPFData: Snowflake query failed.', ['error' => $e->getMessage()]);
            throw $e;
        }

        $rows = $this->extractSnowflakeRows($result);

        if (!empty($rows) && env('EPF_DEBUG', false)) {
            $sample = $rows[0];
            Log::info('SyncEPFData: Snowflake sample dates.', [
                'draft_date' => $this->valueForKey($sample, 'DRAFT_DATE'),
                'process_date' => $this->valueForKey($sample, 'PROCESS_DATE'),
                'cleared_date' => $this->valueForKey($sample, 'CLEARED_DATE'),
                'returned_date' => $this->valueForKey($sample, 'RETURNED_DATE'),
                'keys' => array_keys($sample),
            ]);
        }

        $records = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $llgId = $this->valueForKey($row, 'LLG_ID') ?? '';
            $paymentNumber = $this->valueForKey($row, 'N');
            if ($paymentNumber === null || $paymentNumber === '') {
                $paymentNumber = $this->valueForKey($row, 'PAYMENT_NUMBER');
            }

            $records[] = [
                'llg_id' => $llgId,
                'paid_to' => $this->valueForKey($row, 'PAID_TO') ?? '',
                'amount' => $this->valueForKey($row, 'AMOUNT') ?? '',
                'draft_date' => $this->valueForKey($row, 'DRAFT_DATE') ?? '',
                'process_date' => $this->valueForKey($row, 'PROCESS_DATE') ?? '',
                'returned_date' => $this->valueForKey($row, 'RETURNED_DATE') ?? '',
                'cleared_date' => $this->valueForKey($row, 'CLEARED_DATE') ?? '',
                'settlement_id' => $this->valueForKey($row, 'SETTLEMENT_ID') ?? '',
                'original_amount' => $this->valueForKey($row, 'ORIGINAL_AMOUNT') ?? '',
                'settlement_amount' => $this->valueForKey($row, 'SETTLEMENT_AMOUNT') ?? '',
                'creditor_name' => $this->valueForKey($row, 'CREDITOR_NAME') ?? '',
                'offer_id' => $this->valueForKey($row, 'OFFER_ID') ?? '',
                'payment_number' => $paymentNumber ?? '',
            ];
        }

        if (!empty($records) && env('EPF_DEBUG', false)) {
            Log::info('SyncEPFData: sample EPF record.', [
                'sample' => $records[0],
            ]);
        }

        $this->info('Fetched ' . count($records) . ' EPF rows from Snowflake.');

        return $records;
    }

    protected function extractSnowflakeRows($result): array
    {
        if (is_array($result)) {
            if (isset($result['success']) && $result['success'] === false) {
                Log::warning('SyncEPFData: Snowflake query success=false.', ['result' => $result]);
                return [];
            }
            if (isset($result['data']) && is_array($result['data'])) {
                return $result['data'];
            }
            if (array_is_list($result)) {
                return $result;
            }
        }

        return [];
    }

    protected function fetchEpfRowsFromSnowflakePaged(
        DBConnector $connector,
        string $baseSql,
        string $orderBy,
        ?int $expectedRowCount = null
    ): array {
        $pageSize = (int) env('EPF_PAGE_SIZE', 10000);
        if ($pageSize <= 0) {
            $pageSize = 10000;
        }

        $offset = 0;
        $allRows = [];

        while (true) {
            $pagedSql = $baseSql . "\n" . $orderBy . "\nLIMIT {$pageSize} OFFSET {$offset}";

            try {
                $result = $connector->query($pagedSql);
            } catch (\Throwable $e) {
                $this->warn('Snowflake paged query failed: ' . $e->getMessage());
                Log::warning('SyncEPFData: Snowflake paged query failed.', [
                    'error' => $e->getMessage(),
                    'offset' => $offset,
                    'limit' => $pageSize,
                ]);
                break;
            }

            $rows = $this->extractSnowflakeRows($result);
            $count = count($rows);
            if ($count === 0) {
                break;
            }

            $allRows = array_merge($allRows, $rows);

            if (env('EPF_DEBUG', false)) {
                Log::info('SyncEPFData: Snowflake paged fetch batch.', [
                    'offset' => $offset,
                    'limit' => $pageSize,
                    'count' => $count,
                    'total' => count($allRows),
                ]);
            }

            if ($count < $pageSize) {
                if ($expectedRowCount !== null && count($allRows) < $expectedRowCount) {
                    // Keep paginating until we reach the expected total, even if Snowflake returns short pages.
                } else {
                    break;
                }
            }

            $offset += $pageSize;

            if ($expectedRowCount !== null && count($allRows) >= $expectedRowCount) {
                break;
            }
        }

        return $allRows;
    }

    protected function fetchEpfRowsFromSnowflakeChunked(
        DBConnector $connector,
        string $baseSql,
        string $orderBy,
        int $pageSize,
        ?int $expectedRowCount = null
    ): array {
        $start = 0;
        $allRows = [];

        while (true) {
            $chunkSql = <<<SQL
WITH base AS (
{$baseSql}
),
numbered AS (
    SELECT base.*, ROW_NUMBER() OVER (ORDER BY {$orderBy}) AS RN_GLOBAL
    FROM base
)
SELECT *
FROM numbered
WHERE RN_GLOBAL > {$start}
  AND RN_GLOBAL <= {$start} + {$pageSize}
ORDER BY RN_GLOBAL
SQL;

            try {
                $result = $connector->query($chunkSql);
            } catch (\Throwable $e) {
                $this->warn('Snowflake chunked query failed: ' . $e->getMessage());
                Log::warning('SyncEPFData: Snowflake chunked query failed.', [
                    'error' => $e->getMessage(),
                    'start' => $start,
                    'page_size' => $pageSize,
                ]);
                break;
            }

            $rows = $this->extractSnowflakeRows($result);
            $count = count($rows);
            if ($count === 0) {
                break;
            }

            $allRows = array_merge($allRows, $rows);

            if (env('EPF_DEBUG', false)) {
                Log::info('SyncEPFData: Snowflake chunked fetch batch.', [
                    'start' => $start,
                    'page_size' => $pageSize,
                    'count' => $count,
                    'total' => count($allRows),
                ]);
            }

            if ($count < $pageSize) {
                if ($expectedRowCount !== null && count($allRows) < $expectedRowCount) {
                    // keep looping
                } else {
                    break;
                }
            }

            $start += $pageSize;

            if ($expectedRowCount !== null && count($allRows) >= $expectedRowCount) {
                break;
            }
        }

        return $allRows;
    }

    protected function logSnowflakeCounts(DBConnector $connector): array
    {
        try {
            $info = $connector->getConnectionInfo();
            Log::info('SyncEPFData: Snowflake connection info.', $info);
        } catch (\Throwable $e) {
            Log::warning('SyncEPFData: unable to read Snowflake connection info.', ['error' => $e->getMessage()]);
        }

        $counts = [
            'pf_total' => <<<SQL
SELECT COUNT(*) AS CNT
FROM TRANSACTIONS t
WHERE t.TRANS_TYPE = 'PF'
  AND t._FIVETRAN_DELETED = 'FALSE'
SQL,
            'pf_linked' => <<<SQL
SELECT COUNT(*) AS CNT
FROM TRANSACTIONS t
WHERE t.TRANS_TYPE = 'PF'
  AND t.RETURNED_DATE IS NULL
  AND t.LINKED_TO <> 0
  AND t._FIVETRAN_DELETED = 'FALSE'
SQL,
            'pf_joined' => <<<SQL
SELECT COUNT(*) AS CNT
FROM TRANSACTIONS AS t, SETTLEMENTS AS s
WHERE t.LINKED_TO = s.TRANS_ID
  AND t.TRANS_TYPE = 'PF'
  AND t.RETURNED_DATE IS NULL
  AND t.LINKED_TO <> 0
  AND t._FIVETRAN_DELETED = 'FALSE'
  AND s._FIVETRAN_DELETED = 'FALSE'
SQL,
        ];

        $results = [];
        foreach ($counts as $label => $sql) {
            $count = $this->snowflakeCount($connector, $sql);
            if ($count === null) {
                Log::warning('SyncEPFData: Snowflake count unavailable.', ['label' => $label]);
                $results[$label] = null;
                continue;
            }

            Log::info('SyncEPFData: Snowflake count.', [
                'label' => $label,
                'count' => $count,
            ]);
            $results[$label] = $count;
        }

        return $results;
    }

    protected function snowflakeCount(DBConnector $connector, string $sql): ?int
    {
        try {
            $result = $connector->query($sql);
        } catch (\Throwable $e) {
            Log::warning('SyncEPFData: Snowflake count query failed.', [
                'error' => $e->getMessage(),
                'sql' => mb_substr($sql, 0, 200),
            ]);
            return null;
        }

        if (is_array($result) && isset($result['data'][0]) && is_array($result['data'][0])) {
            $value = $this->valueForKey($result['data'][0], 'CNT');
            if ($value !== null && is_numeric($value)) {
                return (int) $value;
            }
        }

        return null;
    }

    protected function logSqlServerCounts(DBConnector $connector, string $source, string $stage): void
    {
        $info = $connector->querySqlServer('SELECT @@SERVERNAME AS SERVER_NAME, DB_NAME() AS DATABASE_NAME');
        if (is_array($info) && isset($info['data'][0]) && is_array($info['data'][0])) {
            $row = $info['data'][0];
            Log::info('SyncEPFData: SQL Server connection info.', [
                'stage' => $stage,
                'server' => $row['SERVER_NAME'] ?? null,
                'database' => $row['DATABASE_NAME'] ?? null,
            ]);
        }

        $sourceCount = $this->sqlServerCount(
            $connector,
            'SELECT COUNT(*) AS CNT FROM TblEPFs WHERE Source = ?',
            [$source]
        );
        $totalCount = $this->sqlServerCount(
            $connector,
            'SELECT COUNT(*) AS CNT FROM TblEPFs',
            []
        );

        Log::info('SyncEPFData: SQL Server counts.', [
            'stage' => $stage,
            'source' => $source,
            'source_count' => $sourceCount,
            'total_count' => $totalCount,
        ]);
    }

    protected function sqlServerCount(DBConnector $connector, string $sql, array $params = []): ?int
    {
        $result = $connector->querySqlServer($sql, $params);
        if (!is_array($result) || !isset($result['data'][0]) || !is_array($result['data'][0])) {
            return null;
        }

        $value = $this->valueForKey($result['data'][0], 'CNT');
        if ($value !== null && is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }

    protected function sourceLabelForConnection(string $connection): string
    {
        return strtoupper($connection);
    }

    protected function deleteEpfBySource(DBConnector $connector, string $source): int
    {
        $sourceList = $this->buildDeleteSourceList($source);
        $sql = "DELETE FROM TblEPFs WHERE Source IN ({$sourceList})";

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

    protected function buildDeleteSourceList(string $source): string
    {
        $baseSource = $source;
        $sources = [];
        // Delete DP_ sources + legacy ProLaw/PLAW/LDR
        if ($baseSource === 'PLAW') {
            $sources = ['DP_PLAW', 'PLAW', 'ProLaw'];
        } elseif ($baseSource === 'LDR') {
            $sources = ['DP_LDR', 'LDR'];
        } else {
            $sources = ['DP_' . strtoupper($baseSource), $baseSource];
        }

        $escaped = array_map(function ($value) {
            return "'" . $this->escapeSqlString((string) $value) . "'";
        }, $sources);

        return implode(', ', $escaped);
    }

    protected function buildLogSource(string $source): string
    {
        return 'DP_' . $source;
    }

    protected function insertEpfRows(DBConnector $connector, array $rows, string $source): int
    {
        if (empty($rows)) {
            return 0;
        }

        $fieldList = [
            'LLG_ID',
            'Paid_To',
            'Amount',
            'Draft_Date',
            'Process_Date',
            'Returned_Date',
            'Cleared_Date',
            'Settlement_ID',
            'Original_Amount',
            'Settlement_Amount',
            'Creditor_Name',
            'Offer_ID',
            'Payment_Number',
            'Source',
        ];
        $fields = implode(', ', $fieldList);
        $inserted = 0;
        $batchSize = 1000;
        $sourceEsc = $this->escapeSqlString('DP_' . strtoupper($source));

        for ($i = 0; $i < count($rows); $i += $batchSize) {
            $batch = array_slice($rows, $i, $batchSize);
            $values = '';

            foreach ($batch as $row) {
                $values .= ',(';
                $values .= "'" . $this->escapeSqlString((string) $row['llg_id']) . "'";
                $values .= ", '" . $this->escapeSqlString((string) $row['paid_to']) . "'";
                $values .= ', ' . $this->vbaVal($row['amount']);
                $values .= ', ' . $this->sqlNullableDateTime($row['draft_date']);
                $values .= ', ' . $this->sqlNullableDateTime($row['process_date']);
                $values .= ', ' . $this->sqlNullableDateTime($row['returned_date']);
                $values .= ', ' . $this->sqlNullableDateTime($row['cleared_date']);
                $values .= ', ' . $this->sqlNullableString($row['settlement_id']);
                $values .= ', ' . $this->sqlNullableString($row['original_amount']);
                $values .= ', ' . $this->sqlNullableString($row['settlement_amount']);
                $values .= ", '" . $this->escapeSqlString((string) $row['creditor_name']) . "'";
                $values .= ', ' . $this->vbaVal($row['offer_id']);
                $values .= ', ' . $this->vbaVal($row['payment_number']);
                $values .= ", '" . $sourceEsc . "'";
                $values .= ')';
            }

            $valuesSql = ltrim($values, ',');
            $sql = "INSERT INTO TblEPFs ({$fields}) VALUES {$valuesSql}";
            $result = $connector->querySqlServer($sql);
            if (is_array($result) && isset($result['success']) && $result['success'] === false) {
                $errorMsg = $result['error'] ?? 'Unknown SQL Server error';
                Log::error('SyncEPFData: insert failed.', [
                    'source' => $source,
                    'error' => $errorMsg,
                    'sql_sample' => mb_substr($sql, 0, 500),
                ]);
                throw new \RuntimeException('Insert failed: ' . $errorMsg);
            }

            if (is_array($result) && isset($result['row_count']) && is_numeric($result['row_count'])) {
                $inserted += (int) $result['row_count'];
            } else {
                $inserted += count($batch);
            }
        }

        return $inserted;
    }

    protected function sqlNullableString($value): string
    {
        $string = $value === null ? '' : (string) $value;
        if ($string === '') {
            return 'NULL';
        }

        return "'" . $this->escapeSqlString($string) . "'";
    }

    protected function sqlNullableDateTime($value): string
    {
        if ($value instanceof \DateTimeInterface) {
            $formatted = $value->format('Y-m-d H:i:s');
            return "CONVERT(datetime, '" . $this->escapeSqlString($formatted) . "', 120)";
        }

        $string = trim((string) ($value ?? ''));
        if ($string === '' || strcasecmp($string, 'null') === 0) {
            return 'NULL';
        }

        // Handle Snowflake epoch days (integer representing days since 1970-01-01)
        // Values like 20028, 20024, etc. are days since Unix epoch
        if (preg_match('/^\d{4,5}$/', $string) && (int) $string > 10000 && (int) $string < 50000) {
            $epochDays = (int) $string;
            $dt = (new \DateTimeImmutable('1970-01-01'))->modify("+{$epochDays} days");
            $formatted = $dt->format('Y-m-d H:i:s');
            return "CONVERT(datetime, '" . $this->escapeSqlString($formatted) . "', 120)";
        }

        // Fast-path ISO-ish values without re-parsing.
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $string)) {
            $normalized = substr($string, 0, 19);
            if (strlen($normalized) === 10) {
                $normalized .= ' 00:00:00';
            }
            $normalized = str_replace('T', ' ', $normalized);
            return "CONVERT(datetime, '" . $this->escapeSqlString($normalized) . "', 120)";
        }

        try {
            $dt = new \DateTimeImmutable($string);
            $formatted = $dt->format('Y-m-d H:i:s');
            return "CONVERT(datetime, '" . $this->escapeSqlString($formatted) . "', 120)";
        } catch (\Throwable $e) {
            // Fall back to common Snowflake patterns.
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $string)) {
            $formatted = $string . ' 00:00:00';
            return "CONVERT(datetime, '" . $this->escapeSqlString($formatted) . "', 120)";
        }

        return 'NULL';
    }

    protected function vbaVal($value): string
    {
        if ($value === null || $value === '') {
            return '0';
        }

        $num = (float) $value;
        if (is_nan($num) || is_infinite($num)) {
            return '0';
        }

        $string = (string) $num;
        if (stripos($string, 'e') !== false) {
            $string = sprintf('%.0f', $num);
        }

        return $string;
    }

    protected function valueForKey(array $row, string $key): ?string
    {
        foreach ($row as $rowKey => $value) {
            if (strcasecmp($rowKey, $key) === 0) {
                if ($value instanceof \DateTimeInterface) {
                    return $value->format('Y-m-d H:i:s');
                }

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
        $tableName = 'TblEPFs';
        $macro = 'SyncEPFData';
        $actionLabel = $action !== '' ? strtoupper($action) : 'SYNC_EPF_DATA';

        $description = $this->truncateString(
            sprintf('Sync EPF data for %s', $source),
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
                Log::error('SyncEPFData: log insert failed.', [
                    'source' => $source,
                    'sql' => $sql,
                    'result' => $result,
                ]);
                return;
            }

            $this->info(sprintf('[%s] Log entry inserted into TblLog.', $source));
        } catch (\Throwable $e) {
            $this->error(sprintf('[%s] Log insert failed: %s', $source, $e->getMessage()));
            Log::error('SyncEPFData: log insert failed.', [
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
