<?php

namespace Cmd\Reports\Console\Commands;

use Cmd\Reports\Services\DBConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncEnrollmentPlans extends Command
{
    protected $signature = 'enrollment:sync-plans {--diagnose : Show detailed diagnostics for contacts that cannot be matched}';

    protected $desription = 'Sync enrollment plans from Snowflake to SQL Server TblEnrollment for contacts missing Enrollment_Plan';

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
                $this->assertSqlServerConnection($connector, $source);

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

                $plans = $this->fetchPlansFromSnowflake($connector, $missingContacts, $connection);

                // Log contacts that couldn't be matched in Snowflake
                $unmatchedIds = array_diff($missingContacts, array_keys($plans));
                if (!empty($unmatchedIds)) {
                    $this->warn("[$source] " . count($unmatchedIds) . " contacts could NOT be matched in Snowflake:");
                    foreach ($unmatchedIds as $uid) {
                        $this->warn("  - LLG-{$uid}");
                    }
                    Log::warning('SyncEnrollmentPlans: unmatched contacts.', [
                        'source' => $source,
                        'count' => count($unmatchedIds),
                        'contact_ids' => array_values($unmatchedIds),
                    ]);

                    if ($this->option('diagnose')) {
                        $this->diagnoseUnmatched($connector, $unmatchedIds, $source);
                    }
                }

                if (empty($plans)) {
                    $this->warn("[$source] No plans returned from Snowflake for missing contacts.");
                    $this->insertLogRow(
                        $connector,
                        $source,
                        'SYNC_ENROLLMENT_PLANS',
                        'SUCCESS',
                        0,
                        0,
                        sprintf('No matching plans found in Snowflake. %d contacts unmatched.', count($unmatchedIds))
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
SELECT LTRIM(RTRIM(REPLACE(LLG_ID, 'LLG-', ''))) AS CONTACT_ID
FROM TblEnrollment
WHERE Enrollment_Plan IS NULL
  AND Welcome_Call_Date >= DATEADD(day, -90, GETDATE())
SQL;

        $result = $connector->querySqlServer($sql);
        $rows = $this->extractSqlServerRows($result, 'fetching contacts missing Enrollment_Plan');

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

    protected function getCompanyPrefix(string $connection): string
    {
        return match ($connection) {
            'ldr' => 'LDR',
            'plaw' => 'Progress Law',
            default => strtoupper($connection),
        };
    }

    protected function fetchPlansFromSnowflake(DBConnector $connector, array $contactIds, string $connection = ''): array
    {
        if (empty($contactIds)) {
            return [];
        }

        $companyPrefix = $this->getCompanyPrefix($connection);
        $chunks = array_chunk($contactIds, 500);
        $plans = [];

        foreach ($chunks as $chunk) {
            $escapedIds = array_map(function ($id) {
                return "'" . $this->escapeSqlString($id) . "'";
            }, $chunk);
            $inList = implode(', ', $escapedIds);

            $prefixEsc = $this->escapeSqlString($companyPrefix);
            $sql = <<<SQL
SELECT TRIM(TO_VARCHAR(ep.CONTACT_ID)) AS CONTACT_ID,
       COALESCE(NULLIF(TRIM(ed.TITLE), ''), '{$prefixEsc} Plan ' || ep.PLAN_ID) AS TITLE
FROM ENROLLMENT_PLAN AS ep
LEFT JOIN ENROLLMENT_DEFAULTS2 AS ed ON ep.PLAN_ID = ed.id
WHERE TRIM(TO_VARCHAR(ep.CONTACT_ID)) IN ({$inList})
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
                        $cid = trim((string) $value);
                    } elseif (strcasecmp($key, 'TITLE') === 0) {
                        $title = trim((string) $value);
                    }
                }

                if ($cid === null || $cid === '') {
                    continue;
                }

                // If we still have no title after COALESCE, skip but log it
                if ($title === null || $title === '') {
                    Log::warning('SyncEnrollmentPlans: contact in ENROLLMENT_PLAN but no title resolvable.', [
                        'contact_id' => $cid,
                    ]);
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
            $this->assertSqlServerResult($result, 'updating TblEnrollment Enrollment_Plan values');

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
                $errorMsg = $this->formatSqlServerError($result['error'] ?? 'Unknown SQL Server error');
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

    protected function assertSqlServerConnection(DBConnector $connector, string $source): void
    {
        $result = $connector->testSqlServerConnection();
        if (($result['success'] ?? false) !== true) {
            $error = $this->formatSqlServerError($result['error'] ?? 'Unknown SQL Server error');
            throw new \RuntimeException("[{$source}] SQL Server connection failed: {$error}");
        }
    }

    protected function extractSqlServerRows(array $result, string $context): array
    {
        $this->assertSqlServerResult($result, $context);

        if (isset($result['data']) && is_array($result['data'])) {
            return $result['data'];
        }

        return array_is_list($result) ? $result : [];
    }

    protected function assertSqlServerResult(array $result, string $context): void
    {
        if (($result['success'] ?? true) === false) {
            $error = $this->formatSqlServerError($result['error'] ?? 'Unknown SQL Server error');
            throw new \RuntimeException("SQL Server query failed while {$context}: {$error}");
        }
    }

    protected function formatSqlServerError(string $error): string
    {
        if (stripos($error, 'could not find driver') !== false) {
            return $error . '. The PHP runtime running this command is missing the required PDO driver, usually pdo_sqlsrv.';
        }

        return $error;
    }

    protected function diagnoseUnmatched(DBConnector $connector, array $unmatchedIds, string $source): void
    {
        $this->info("[$source] Running diagnostics for unmatched contacts...");

        // Check which contacts exist in ENROLLMENT_PLAN at all
        $chunks = array_chunk($unmatchedIds, 500);
        foreach ($chunks as $chunk) {
            $escapedIds = array_map(fn($id) => "'" . $this->escapeSqlString($id) . "'", $chunk);
            $inList = implode(', ', $escapedIds);

            // Check if they exist in ENROLLMENT_PLAN
            $sql = "SELECT ep.CONTACT_ID, ep.PLAN_ID, ed.TITLE
                    FROM ENROLLMENT_PLAN AS ep
                    LEFT JOIN ENROLLMENT_DEFAULTS2 AS ed ON ep.PLAN_ID = ed.id
                    WHERE ep.CONTACT_ID IN ({$inList})";

            $result = $connector->query($sql);
            $rows = [];
            if (is_array($result)) {
                $rows = $result['data'] ?? (array_is_list($result) ? $result : []);
            }

            $foundInEp = [];
            foreach ($rows as $row) {
                $cid = null;
                $planId = null;
                $title = null;
                foreach ($row as $key => $value) {
                    if (strcasecmp($key, 'CONTACT_ID') === 0) $cid = $value;
                    if (strcasecmp($key, 'PLAN_ID') === 0) $planId = $value;
                    if (strcasecmp($key, 'TITLE') === 0) $title = $value;
                }
                if ($cid !== null) {
                    $foundInEp[$cid] = ['plan_id' => $planId, 'title' => $title];
                }
            }

            foreach ($chunk as $contactId) {
                if (!isset($foundInEp[$contactId])) {
                    $this->error("  LLG-{$contactId}: NOT FOUND in Snowflake ENROLLMENT_PLAN table");
                } else {
                    $info = $foundInEp[$contactId];
                    if ($info['plan_id'] === null) {
                        $this->error("  LLG-{$contactId}: In ENROLLMENT_PLAN but PLAN_ID is NULL");
                    } elseif ($info['title'] === null || $info['title'] === '') {
                        $this->error("  LLG-{$contactId}: PLAN_ID={$info['plan_id']} but no matching TITLE in ENROLLMENT_DEFAULTS2");
                    } else {
                        $this->warn("  LLG-{$contactId}: Has PLAN_ID={$info['plan_id']}, TITLE='{$info['title']}' (should have matched - possible data issue)");
                    }
                }
            }
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
