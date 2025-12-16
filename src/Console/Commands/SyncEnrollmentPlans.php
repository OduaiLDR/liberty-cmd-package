<?php

namespace Cmd\Reports\Console\Commands;

use Cmd\Reports\Services\DBConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncEnrollmentPlans extends Command
{
    protected $signature = 'enrollment:sync-plans';

    protected $description = 'Sync enrollment plans from Snowflake to SQL Server TblEnrollment for contacts missing Enrollment_Plan';

    public function handle(): int
    {
        $this->info('Enrollment plan sync: starting.');
        Log::info('SyncEnrollmentPlans command started.');

        $connections = ['plaw', 'ldr'];
        $hadException = false;

        foreach ($connections as $connection) {
            $source = strtoupper($connection);

            $this->info("[$source] Starting enrollment plan sync.");
            Log::info('SyncEnrollmentPlans: starting connection.', [
                'connection' => $connection,
                'source' => $source,
            ]);

            try {
                $connector = DBConnector::fromEnvironment($connection);
                $connector->initializeSqlServer();
                $this->ensureLogTable($connector);

                $missingContacts = $this->fetchMissingContactIds($connector);

                if (empty($missingContacts)) {
                    $this->warn("[$source] No contacts missing Enrollment_Plan in last 90 days.");
                    $this->insertLogRow(
                        $connector,
                        $source,
                        'SYNC_ENROLLMENT_PLANS',
                        'SUCCESS',
                        0,
                        0,
                        'No enrollment plans to sync (none missing).'
                    );
                    continue;
                }

                $this->info("[$source] Found " . count($missingContacts) . " contacts missing plans.");

                $plans = $this->fetchPlansFromSnowflake($connector, $missingContacts);

                if (empty($plans)) {
                    $this->warn("[$source] No plans returned from Snowflake for missing contacts.");
                    $this->insertLogRow(
                        $connector,
                        $source,
                        'SYNC_ENROLLMENT_PLANS',
                        'SUCCESS',
                        0,
                        0,
                        'No matching plans found in Snowflake for missing contacts.'
                    );
                    continue;
                }

                $updated = $this->updateEnrollmentPlans($connector, $plans);

                $this->info("[$source] Updated {$updated} enrollment plans.");
                $this->insertLogRow(
                    $connector,
                    $source,
                    'SYNC_ENROLLMENT_PLANS',
                    'SUCCESS',
                    $updated,
                    0,
                    sprintf('Missing contacts: %d. Plans applied: %d.', count($missingContacts), $updated)
                );

                Log::info('SyncEnrollmentPlans: connection finished.', [
                    'connection' => $connection,
                    'source' => $source,
                    'updated' => $updated,
                ]);
            } catch (\Throwable $e) {
                $hadException = true;

                $this->error("Enrollment plan sync failed for connection [{$connection}] ({$source}).");
                $this->error($e->getMessage());

                Log::error('SyncEnrollmentPlans: exception during sync.', [
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
                        'SYNC_ENROLLMENT_PLANS',
                        'FAILED',
                        0,
                        0,
                        $errorMessage
                    );
                } catch (\Throwable $logException) {
                    Log::error('SyncEnrollmentPlans: failed to log to TblLog after exception.', [
                        'connection' => $connection,
                        'source' => $source,
                        'exception' => $logException,
                    ]);
                }
            }
        }

        $this->info('Enrollment plan sync: finished.');
        Log::info('SyncEnrollmentPlans command finished.', [
            'status' => $hadException ? 'FAILURE' : 'SUCCESS',
        ]);

        return $hadException ? Command::FAILURE : Command::SUCCESS;
    }

    protected function fetchMissingContactIds(DBConnector $connector): array
    {
        $sql = <<<SQL
SELECT REPLACE(LLG_ID, 'LLG-', '') AS CONTACT_ID
FROM TblEnrollment
WHERE Enrollment_Plan IS NULL
  AND Welcome_Call_Date >= DATEADD(day, -90, GETDATE())
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
            $contactId = null;
            foreach ($row as $key => $value) {
                if (strcasecmp($key, 'CONTACT_ID') === 0) {
                    $contactId = $value;
                    break;
                }
            }
            if ($contactId !== null && $contactId !== '') {
                $ids[] = (string) $contactId;
            }
        }

        $this->info('Found ' . count($ids) . ' missing-contact IDs from SQL Server.');

        return $ids;
    }

    protected function fetchPlansFromSnowflake(DBConnector $connector, array $contactIds): array
    {
        if (empty($contactIds)) {
            return [];
        }

        $chunks = array_chunk($contactIds, 500);
        $plans = [];

        foreach ($chunks as $chunk) {
            $escapedIds = array_map(function ($id) {
                return "'" . $this->escapeSqlString($id) . "'";
            }, $chunk);
            $inList = implode(', ', $escapedIds);

            $sql = <<<SQL
SELECT ep.CONTACT_ID, ed.TITLE
FROM ENROLLMENT_PLAN AS ep
LEFT JOIN ENROLLMENT_DEFAULTS2 AS ed ON ep.PLAN_ID = ed.id
WHERE ep.CONTACT_ID IN ({$inList})
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
                $title = null;

                foreach ($row as $key => $value) {
                    if (strcasecmp($key, 'CONTACT_ID') === 0) {
                        $cid = (string) $value;
                    } elseif (strcasecmp($key, 'TITLE') === 0) {
                        $title = (string) $value;
                    }
                }

                if ($cid === null || $cid === '' || $title === null || $title === '') {
                    continue;
                }

                $plans[$cid] = $title;
            }
        }

        $this->info('Fetched ' . count($plans) . ' enrollment plans from Snowflake.');

        return $plans;
    }

    protected function updateEnrollmentPlans(DBConnector $connector, array $plans): int
    {
        if (empty($plans)) {
            return 0;
        }

        $batchSize = 500;
        $updates = array_chunk($plans, $batchSize, true);
        $totalUpdated = 0;

        foreach ($updates as $chunk) {
            $cases = [];
            $ids = [];

            foreach ($chunk as $contactId => $title) {
                $llgId = $this->truncateString('LLG-' . $contactId, 100);
                $llgEsc = $this->truncateString($this->escapeSqlString($llgId), 100);

                $planTitle = $this->truncateString((string) $title, 100);
                if ($planTitle === '') {
                    continue;
                }
                $titleEsc = $this->truncateString($this->escapeSqlString($planTitle), 100);

                $cases[] = "WHEN '{$llgEsc}' THEN '{$titleEsc}'";
                $ids[] = "'{$llgEsc}'";
            }

            if (empty($cases) || empty($ids)) {
                continue;
            }

            $caseSql = implode(' ', $cases);
            $idList = implode(', ', $ids);

            $sql = <<<SQL
UPDATE TblEnrollment
SET Enrollment_Plan = CASE LLG_ID {$caseSql} END
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
        $macro = 'SyncEnrollmentPlans';
        $actionLabel = $action !== '' ? strtoupper($action) : 'SYNC_ENROLLMENT_PLANS';

        $description = $this->truncateString(
            sprintf('Sync enrollment plans for %s', $source),
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
                Log::error('SyncEnrollmentPlans: log insert failed.', [
                    'source' => $source,
                    'sql' => $sql,
                    'result' => $result,
                ]);
                return;
            }

            $this->info(sprintf('[%s] Log entry inserted into TblLog.', $source));
        } catch (\Throwable $e) {
            $this->error(sprintf('[%s] Log insert failed: %s', $source, $e->getMessage()));
            Log::error('SyncEnrollmentPlans: log insert failed.', [
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
