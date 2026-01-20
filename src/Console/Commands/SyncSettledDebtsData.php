<?php

namespace Cmd\Reports\Console\Commands;

use Cmd\Reports\Services\DBConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncSettledDebtsData extends Command
{
    protected $signature = 'sync:settled-debts-data';

    protected $description = 'Sync settled debt data into TblNegotiatorDebts from Snowflake DEBTS for enrolled contacts';

    public function handle(): int
    {
        $this->info('Settled debts sync: starting.');
        Log::info('SyncSettledDebtsData command started.');

        $connections = ['plaw', 'ldr'];
        $hadException = false;

        foreach ($connections as $connection) {
            $source = strtoupper($connection);
            $this->info("[$source] Starting settled debts sync.");
            Log::info('SyncSettledDebtsData: starting connection.', [
                'connection' => $connection,
                'source' => $source,
            ]);

            try {
                $connector = DBConnector::fromEnvironment($connection);
                $connector->initializeSqlServer();
                $this->ensureLogTable($connector);

                $this->info("[$source] Fetching settled debts from Snowflake...");
                $rows = $this->fetchSettledDebtsFromSnowflake($connector);

                $this->info("[$source] Deleting existing negotiator debts for source...");
                $deleted = $this->deleteNegotiatorDebtsBySource($connector, $source);

                if (empty($rows)) {
                    $this->warn("[$source] No settled debts found in Snowflake.");
                    $this->insertLogRow(
                        $connector,
                        $source,
                        'SYNC_SETTLED_DEBTS_DATA',
                        'SUCCESS',
                        0,
                        $deleted,
                        'No settled debts found to insert.'
                    );
                    continue;
                }

                $this->info("[$source] Inserting negotiator debts to SQL Server...");
                $inserted = $this->insertNegotiatorDebts($connector, $rows, $source);

                $this->info("[$source] Inserted {$inserted} negotiator debt rows.");
                $this->insertLogRow(
                    $connector,
                    $source,
                    'SYNC_SETTLED_DEBTS_DATA',
                    'SUCCESS',
                    $inserted,
                    $deleted,
                    sprintf('Rows fetched: %d. Inserted: %d.', count($rows), $inserted)
                );

                Log::info('SyncSettledDebtsData: connection finished.', [
                    'connection' => $connection,
                    'source' => $source,
                    'deleted' => $deleted,
                    'inserted' => $inserted,
                ]);
            } catch (\Throwable $e) {
                $hadException = true;

                $this->error("Settled debts sync failed for connection [{$connection}] ({$source}).");
                $this->error($e->getMessage());

                Log::error('SyncSettledDebtsData: exception during sync.', [
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
                        'SYNC_SETTLED_DEBTS_DATA',
                        'FAILED',
                        0,
                        0,
                        $errorMessage
                    );
                } catch (\Throwable $logException) {
                    Log::error('SyncSettledDebtsData: failed to log to TblLog after exception.', [
                        'connection' => $connection,
                        'source' => $source,
                        'exception' => $logException,
                    ]);
                }
            }
        }

        $this->info('Settled debts sync: finished.');
        Log::info('SyncSettledDebtsData command finished.', [
            'status' => $hadException ? 'FAILURE' : 'SUCCESS',
        ]);

        return $hadException ? Command::FAILURE : Command::SUCCESS;
    }

    protected function fetchSettledDebtsFromSnowflake(DBConnector $connector): array
    {
        $records = [];
        $lastId = 0;
        $limit = 50000;

        while (true) {
            $sql = <<<SQL
SELECT
    d.ID AS DEBT_ID,
    TO_CHAR(d.SETTLEMENT_DATE, 'YYYY-MM-DD HH24:MI:SS') AS SETTLEMENT_DATE,
    TO_CHAR(d.LAST_PAYMENT, 'YYYY-MM-DD HH24:MI:SS') AS LAST_PAYMENT,
    d.ACCOUNT_NUM,
    d.DEBT_BUYER_ACCOUNT,
    d.HAS_SUMMONS,
    cf.PRE_LIT
FROM DEBTS AS d
LEFT JOIN CONTACTS AS c ON c.ID = d.CONTACT_ID
LEFT JOIN (
    SELECT
        OBJ_ID AS DEBT_ID,
        F_INT AS PRE_LIT
    FROM CUSTOMFIELD_DATA
    WHERE CUSTOM_ID = 9255
      AND OBJ_TYPE = 'debts'
      AND _FIVETRAN_DELETED = FALSE
) AS cf ON d.ID = cf.DEBT_ID
WHERE d.ENROLLED = 1
  AND c.ENROLLED_DATE IS NOT NULL
  AND d.ID > {$lastId}
ORDER BY d.ID ASC
LIMIT {$limit}
SQL;

            try {
                $result = $connector->query($sql);
            } catch (\Throwable $e) {
                $this->warn('Snowflake query failed: ' . $e->getMessage());
                Log::warning('SyncSettledDebtsData: Snowflake query failed.', ['error' => $e->getMessage()]);
                throw $e;
            }

            if (is_array($result)) {
                if (isset($result['success']) && $result['success'] === false) {
                    Log::warning('SyncSettledDebtsData: Snowflake query success=false.', ['result' => $result]);
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

            if (empty($rows)) {
                break;
            }

            $maxId = $lastId;

            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $debtId = $this->valueForKey($row, 'DEBT_ID');
                if ($debtId === null || $debtId === '') {
                    continue;
                }

                $debtIdInt = (int) $debtId;
                if ($debtIdInt > $maxId) {
                    $maxId = $debtIdInt;
                }

                $settlementDate = $this->normalizeOptionalDate($this->valueForKey($row, 'SETTLEMENT_DATE'));
                $lastPayment = $this->normalizeLastPaymentDate($this->valueForKey($row, 'LAST_PAYMENT'));

                $accountNum = $this->valueForKey($row, 'DEBT_BUYER_ACCOUNT');
                if ($accountNum === null || trim($accountNum) === '') {
                    $accountNum = $this->valueForKey($row, 'ACCOUNT_NUM');
                }

                $hasSummons = $this->boolToString($this->valueForKey($row, 'HAS_SUMMONS'));
                $preLit = $this->boolToString($this->valueForKey($row, 'PRE_LIT'));

                $records[] = [
                    'debt_id' => $debtId,
                    'settlement_date' => $settlementDate,
                    'last_payment_date' => $lastPayment,
                    'account_number' => $accountNum ?? '',
                    'has_summons' => $hasSummons,
                    'pre_lit' => $preLit,
                ];
            }

            if ($maxId <= $lastId) {
                $this->warn('Snowflake pagination made no progress; stopping to avoid a loop.');
                Log::warning('SyncSettledDebtsData: pagination stalled.', ['last_id' => $lastId]);
                break;
            }

            $lastId = $maxId;
        }

        $this->info('Fetched ' . count($records) . ' settled debt rows from Snowflake.');

        return $records;
    }

    protected function deleteNegotiatorDebtsBySource(DBConnector $connector, string $source): int
    {
        $sourceEsc = $this->escapeSqlString($source);
        
        // Delete current source + legacy ProLaw + DP_ variants
        if (strtoupper($source) === 'PLAW') {
            $sql = "DELETE FROM TblNegotiatorDebts WHERE Source IN ('PLAW', 'ProLaw', 'DP_PLAW')";
        } else {
            $sql = "DELETE FROM TblNegotiatorDebts WHERE Source IN ('LDR', 'DP_LDR')";
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

    protected function insertNegotiatorDebts(DBConnector $connector, array $rows, string $source): int
    {
        if (empty($rows)) {
            return 0;
        }

        $fields = "Debt_ID, Settlement_Date, Last_Payment_Date, Account_Number, Has_Summons, Pre_Lit, Source";
        $inserted = 0;
        $batchSize = 1000;

        for ($i = 0; $i < count($rows); $i += $batchSize) {
            $batch = array_slice($rows, $i, $batchSize);
            $values = '';

            foreach ($batch as $row) {
                $values .= ',(';
                $values .= "'" . $this->escapeSqlString((string) $row['debt_id']) . "'";

                if ($row['settlement_date'] === null || $row['settlement_date'] === '') {
                    $values .= ", NULL";
                } else {
                    $values .= ", '" . $this->escapeSqlString((string) $row['settlement_date']) . "'";
                }

                if ($row['last_payment_date'] === null || $row['last_payment_date'] === '') {
                    $values .= ", NULL";
                } else {
                    $values .= ", '" . $this->escapeSqlString((string) $row['last_payment_date']) . "'";
                }

                $values .= ", '" . $this->escapeSqlString((string) $row['account_number']) . "'";
                $values .= ", '" . $this->escapeSqlString((string) $row['has_summons']) . "'";
                $values .= ", '" . $this->escapeSqlString((string) $row['pre_lit']) . "'";
                $values .= ", '" . $this->escapeSqlString($source) . "'";
                $values .= ')';
            }

            $sql = "INSERT INTO TblNegotiatorDebts ({$fields}) VALUES " . ltrim($values, ',');
            $result = $connector->querySqlServer($sql);
            if (is_array($result) && isset($result['success']) && $result['success'] === false) {
                $errorMsg = $result['error'] ?? 'Unknown SQL Server error';
                Log::error('SyncSettledDebtsData: insert failed.', [
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

    protected function boolToString($value): string
    {
        if (is_bool($value)) {
            return $value ? 'True' : 'False';
        }

        if ($value === null) {
            return 'False';
        }

        $string = strtolower(trim((string) $value));
        if ($string === '' || $string === '0' || $string === 'false' || $string === 'f' || $string === 'no' || $string === 'n') {
            return 'False';
        }

        if ($string === '1' || $string === 'true' || $string === 't' || $string === 'yes' || $string === 'y') {
            return 'True';
        }

        if (is_numeric($string)) {
            return ((float) $string) != 0.0 ? 'True' : 'False';
        }

        return 'False';
    }

    protected function normalizeLastPaymentDate($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $string = trim((string) $value);
        if ($string === '') {
            return null;
        }

        if (!$this->looksLikeDate($string)) {
            return null;
        }

        $year = (int) substr($string, 0, 4);
        if ($year < 2000) {
            return null;
        }

        return $string;
    }

    protected function normalizeOptionalDate($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $string = trim((string) $value);
        if ($string === '') {
            return null;
        }

        return $string;
    }

    protected function looksLikeDate(string $value): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}(?:\s+\d{2}:\d{2}:\d{2})?$/', $value);
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
        $tableName = 'TblNegotiatorDebts';
        $macro = 'SyncSettledDebtsData';
        $actionLabel = $action !== '' ? strtoupper($action) : 'SYNC_SETTLED_DEBTS_DATA';

        $description = $this->truncateString(
            sprintf('Sync negotiator debts for %s', $source),
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
                Log::error('SyncSettledDebtsData: log insert failed.', [
                    'source' => $source,
                    'sql' => $sql,
                    'result' => $result,
                ]);
                return;
            }

            $this->info(sprintf('[%s] Log entry inserted into TblLog.', $source));
        } catch (\Throwable $e) {
            $this->error(sprintf('[%s] Log insert failed: %s', $source, $e->getMessage()));
            Log::error('SyncSettledDebtsData: log insert failed.', [
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
