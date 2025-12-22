<?php

namespace Cmd\Reports\Console\Commands;

use Cmd\Reports\Services\DBConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncTimeInProgram extends Command
{
    protected $signature = 'sync:time-in-program';

    protected $description = 'Sync Program_Length and Payment_Frequency in TblEnrollment from Snowflake enrollment plan data (last 7 days)';

    public function handle(): int
    {
        $this->info('Time in program sync: starting.');
        Log::info('SyncTimeInProgram command started.');

        $connections = ['plaw', 'ldr'];
        $hadException = false;

        foreach ($connections as $connection) {
            $source = strtoupper($connection);
            $this->info("[$source] Starting time in program sync.");
            Log::info('SyncTimeInProgram: starting connection.', [
                'connection' => $connection,
                'source' => $source,
            ]);

            try {
                $connector = DBConnector::fromEnvironment($connection);
                $connector->initializeSqlServer();
                $this->ensureLogTable($connector);

                $this->info("[$source] Fetching time in program data from Snowflake...");
                $rows = $this->fetchTimeInProgramFromSnowflake($connector);

                if (empty($rows)) {
                    $this->warn("[$source] No time in program rows found in Snowflake.");
                    $this->insertLogRow(
                        $connector,
                        $source,
                        'SYNC_TIME_IN_PROGRAM',
                        'SUCCESS',
                        0,
                        0,
                        'No rows found to sync.'
                    );
                    continue;
                }

                $this->info("[$source] Applying time in program updates to SQL Server...");
                $updated = $this->updateTimeInProgram($connector, $rows);

                $this->info("[$source] Updated {$updated} time in program rows.");
                $this->insertLogRow(
                    $connector,
                    $source,
                    'SYNC_TIME_IN_PROGRAM',
                    'SUCCESS',
                    $updated,
                    0,
                    sprintf('Rows fetched: %d. Updated: %d.', count($rows), $updated)
                );

                Log::info('SyncTimeInProgram: connection finished.', [
                    'connection' => $connection,
                    'source' => $source,
                    'updated' => $updated,
                ]);
            } catch (\Throwable $e) {
                $hadException = true;

                $this->error("Time in program sync failed for connection [{$connection}] ({$source}).");
                $this->error($e->getMessage());

                Log::error('SyncTimeInProgram: exception during sync.', [
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
                        'SYNC_TIME_IN_PROGRAM',
                        'FAILED',
                        0,
                        0,
                        $errorMessage
                    );
                } catch (\Throwable $logException) {
                    Log::error('SyncTimeInProgram: failed to log to TblLog after exception.', [
                        'connection' => $connection,
                        'source' => $source,
                        'exception' => $logException,
                    ]);
                }
            }
        }

        $this->info('Time in program sync: finished.');
        Log::info('SyncTimeInProgram command finished.', [
            'status' => $hadException ? 'FAILURE' : 'SUCCESS',
        ]);

        return $hadException ? Command::FAILURE : Command::SUCCESS;
    }

    protected function fetchTimeInProgramFromSnowflake(DBConnector $connector): array
    {
        $sql = <<<SQL
SELECT c.ID AS CONTACT_ID,
       p.TIME_IN_PROGRAM,
       p.FREQUENCY
FROM CONTACTS AS c
LEFT JOIN ENROLLMENT_PLAN AS p ON c.ID = p.CONTACT_ID
LEFT JOIN ENROLLMENT_DEFAULTS2 AS ed ON p.PLAN_ID = ed.ID
WHERE c.CREATED >= '2021-07-01'
  AND c.DEL = FALSE
  AND c.ISCOAPP = 0
  AND c.ENROLLED_DATE >= DATEADD(day, -7, CURRENT_DATE)
SQL;

        try {
            $result = $connector->query($sql);
        } catch (\Throwable $e) {
            $this->warn('Snowflake query failed: ' . $e->getMessage());
            Log::warning('SyncTimeInProgram: Snowflake query failed.', ['error' => $e->getMessage()]);
            $result = [];
        }

        if (is_array($result)) {
            if (isset($result['success']) && $result['success'] === false) {
                Log::warning('SyncTimeInProgram: Snowflake query success=false.', ['result' => $result]);
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

        $records = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $cid = null;
            $timeInProgram = null;
            $frequency = null;

            foreach ($row as $key => $value) {
                if (strcasecmp($key, 'CONTACT_ID') === 0) {
                    $cid = (string) $value;
                } elseif (strcasecmp($key, 'TIME_IN_PROGRAM') === 0) {
                    $timeInProgram = $value;
                } elseif (strcasecmp($key, 'FREQUENCY') === 0) {
                    $frequency = (string) $value;
                }
            }

            if ($cid === null || $cid === '') {
                continue;
            }

            $llgId = $this->truncateString('LLG-' . $cid, 100);
            $records[$llgId] = [
                'program_length' => $timeInProgram,
                'payment_frequency' => $this->mapFrequency($frequency),
            ];
        }

        $this->info('Fetched ' . count($records) . ' time in program rows from Snowflake.');

        return $records;
    }

    protected function updateTimeInProgram(DBConnector $connector, array $records): int
    {
        if (empty($records)) {
            return 0;
        }

        $totalUpdated = 0;
        $batchSize = 500;
        $batches = array_chunk($records, $batchSize, true);

        foreach ($batches as $chunk) {
            $casesLength = [];
            $casesFrequency = [];
            $ids = [];

            foreach ($chunk as $llgId => $data) {
                $llgEsc = $this->truncateString($this->escapeSqlString($llgId), 100);
                $programLength = $data['program_length'];
                $programLengthSql = 'NULL';
                if ($programLength !== null && $programLength !== '' && is_numeric($programLength)) {
                    $programLengthSql = (string) $programLength;
                }
                $frequencyEsc = $this->truncateString($this->escapeSqlString($data['payment_frequency']), 50);

                $casesLength[] = "WHEN '{$llgEsc}' THEN {$programLengthSql}";
                $casesFrequency[] = "WHEN '{$llgEsc}' THEN '{$frequencyEsc}'";
                $ids[] = "'{$llgEsc}'";
            }

            if (empty($casesLength) || empty($ids)) {
                continue;
            }

            $caseLengthSql = implode(' ', $casesLength);
            $caseFrequencySql = implode(' ', $casesFrequency);
            $idList = implode(', ', $ids);

            $sql = <<<SQL
UPDATE TblEnrollment
SET
    Program_Length = CASE LLG_ID {$caseLengthSql} END,
    Payment_Frequency = CASE LLG_ID {$caseFrequencySql} END
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

    protected function mapFrequency(?string $frequency): string
    {
        switch ($frequency) {
            case 'BW':
                return 'Bi-Weekly';
            case 'M':
                return 'Monthly';
            case 'SM':
                return 'Semi-Monthly';
            case 'W':
                return 'Weekly';
            default:
                return 'Monthly';
        }
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
        $macro = 'SyncTimeInProgram';
        $actionLabel = $action !== '' ? strtoupper($action) : 'SYNC_TIME_IN_PROGRAM';

        $description = $this->truncateString(
            sprintf('Sync time in program for %s', $source),
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
                Log::error('SyncTimeInProgram: log insert failed.', [
                    'source' => $source,
                    'sql' => $sql,
                    'result' => $result,
                ]);
                return;
            }

            $this->info(sprintf('[%s] Log entry inserted into TblLog.', $source));
        } catch (\Throwable $e) {
            $this->error(sprintf('[%s] Log insert failed: %s', $source, $e->getMessage()));
            Log::error('SyncTimeInProgram: log insert failed.', [
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
