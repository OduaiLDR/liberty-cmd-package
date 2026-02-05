<?php

namespace Cmd\Reports\Console\Commands;

use Cmd\Reports\Services\DBConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncEnrollmentStatus extends Command
{
    protected $signature = 'sync:enrollment-status';

    protected $description = 'Sync Enrollment_Status, Cancel_Date, and NSF_Date in TblEnrollment from Snowflake CONTACTS_STATUS';

    public function handle(): int
    {
        $this->info('Enrollment status sync: starting.');
        Log::info('SyncEnrollmentStatus command started.');

        $connections = ['plaw', 'ldr'];
        $hadException = false;

        foreach ($connections as $connection) {
            $source = strtoupper($connection);
            $this->info("[$source] Starting enrollment status sync.");
            Log::info('SyncEnrollmentStatus: starting connection.', [
                'connection' => $connection,
                'source' => $source,
            ]);

            try {
                $connector = DBConnector::fromEnvironment($connection);
                $connector->initializeSqlServer();
                $this->ensureLogTable($connector);

                $this->info("[$source] Fetching enrollment rows from SQL Server...");
                $rows = $this->fetchEnrollmentRowsFromSqlServer($connector);

                if (empty($rows)) {
                    $this->warn("[$source] No enrollment rows found in SQL Server.");
                    $this->insertLogRow(
                        $connector,
                        $source,
                        'SYNC_ENROLLMENT_STATUS',
                        'SUCCESS',
                        0,
                        0,
                        'No enrollment rows found.'
                    );
                    continue;
                }

                $contactIds = array_values(array_unique(array_filter(array_map(function (array $row) {
                    return $row['cid'] ?? null;
                }, $rows))));

                if (empty($contactIds)) {
                    $this->warn("[$source] No contact IDs found to sync.");
                    $this->insertLogRow(
                        $connector,
                        $source,
                        'SYNC_ENROLLMENT_STATUS',
                        'SUCCESS',
                        0,
                        0,
                        'No contact IDs found.'
                    );
                    continue;
                }

                $this->info("[$source] Fetching latest statuses from Snowflake...");
                $statusMap = $this->fetchLatestStatusesFromSnowflake($connector, $contactIds);

                if (empty($statusMap)) {
                    $this->warn("[$source] No statuses found in Snowflake.");
                    $this->insertLogRow(
                        $connector,
                        $source,
                        'SYNC_ENROLLMENT_STATUS',
                        'SUCCESS',
                        0,
                        0,
                        'No statuses found to sync.'
                    );
                    continue;
                }

                $this->info("[$source] Applying enrollment status updates to SQL Server...");
                $updated = $this->applyEnrollmentStatusUpdates($connector, $rows, $statusMap, $connection);

                $this->info("[$source] Updated {$updated} enrollment rows.");
                $this->insertLogRow(
                    $connector,
                    $source,
                    'SYNC_ENROLLMENT_STATUS',
                    'SUCCESS',
                    $updated,
                    0,
                    sprintf('Rows fetched: %d. Updated: %d.', count($rows), $updated)
                );

                Log::info('SyncEnrollmentStatus: connection finished.', [
                    'connection' => $connection,
                    'source' => $source,
                    'updated' => $updated,
                ]);
            } catch (\Throwable $e) {
                $hadException = true;

                $this->error("Enrollment status sync failed for connection [{$connection}] ({$source}).");
                $this->error($e->getMessage());

                Log::error('SyncEnrollmentStatus: exception during sync.', [
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
                        'SYNC_ENROLLMENT_STATUS',
                        'FAILED',
                        0,
                        0,
                        $errorMessage
                    );
                } catch (\Throwable $logException) {
                    Log::error('SyncEnrollmentStatus: failed to log to TblLog after exception.', [
                        'connection' => $connection,
                        'source' => $source,
                        'exception' => $logException,
                    ]);
                }
            }
        }

        $this->info('Enrollment status sync: finished.');
        Log::info('SyncEnrollmentStatus command finished.', [
            'status' => $hadException ? 'FAILURE' : 'SUCCESS',
        ]);

        return $hadException ? Command::FAILURE : Command::SUCCESS;
    }

    protected function fetchEnrollmentRowsFromSqlServer(DBConnector $connector): array
    {
        $sql = <<<SQL
SELECT
    REPLACE(LLG_ID, 'LLG-', '') AS CID,
    LLG_ID,
    Enrollment_Status,
    Cancel_Date,
    NSF_Date
FROM TblEnrollment
WHERE Welcome_Call_Date >= '2022-07-01'
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

        $records = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $cid = $this->valueForKey($row, 'CID');
            $llgId = $this->valueForKey($row, 'LLG_ID');

            if ($cid === null || $cid === '') {
                if ($llgId !== null && $llgId !== '') {
                    $cid = preg_replace('/^LLG-?/i', '', $llgId);
                }
            }

            if ($cid === null || $cid === '') {
                continue;
            }

            $records[] = [
                'cid' => $cid,
                'llg_id' => $llgId ?? 'LLG-' . $cid,
                'enrollment_status' => $this->normalizeString($this->valueForKey($row, 'Enrollment_Status')),
                'cancel_date' => $this->normalizeString($this->valueForKey($row, 'Cancel_Date')),
                'nsf_date' => $this->normalizeString($this->valueForKey($row, 'NSF_Date')),
            ];
        }

        $this->info('Found ' . count($records) . ' enrollment rows.');

        return $records;
    }

    protected function fetchLatestStatusesFromSnowflake(DBConnector $connector, array $contactIds): array
    {
        if (empty($contactIds)) {
            return [];
        }

        $statusMap = [];
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
latest AS (
    SELECT
        c.ID AS CONTACT_ID,
        cls.TITLE AS TITLE,
        cs.STAMP AS STAMP,
        ROW_NUMBER() OVER (PARTITION BY cs.CONTACT_ID ORDER BY cs.STAMP DESC) AS rn
    FROM CONTACTS AS c
    LEFT JOIN CONTACTS_STATUS AS cs ON c.ID = cs.CONTACT_ID
    LEFT JOIN CONTACTS_LEAD_STATUS AS cls ON cs.STATUS_ID = cls.ID
    JOIN missing m ON TO_VARCHAR(c.ID) = m.CONTACT_ID_STR
    WHERE cs.CONTACT_ID IS NOT NULL
)
SELECT
    TO_VARCHAR(CONTACT_ID) AS CONTACT_ID,
    TITLE,
    TO_CHAR(STAMP, 'YYYY-MM-DD') AS STAMP
FROM latest
WHERE rn = 1
SQL;

            try {
                $result = $connector->query($sql);
            } catch (\Throwable $e) {
                $this->warn('Snowflake status query failed: ' . $e->getMessage());
                Log::warning('SyncEnrollmentStatus: status query failed.', ['error' => $e->getMessage()]);
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

            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $cid = $this->valueForKey($row, 'CONTACT_ID');
                $title = $this->normalizeString($this->valueForKey($row, 'TITLE'));
                $stamp = $this->normalizeString($this->valueForKey($row, 'STAMP'));

                if ($cid === null || $cid === '' || $title === '') {
                    continue;
                }

                $statusMap[$cid] = [
                    'status' => $title,
                    'stamp' => $stamp,
                ];
            }
        }

        $this->info('Fetched ' . count($statusMap) . ' latest statuses from Snowflake.');

        return $statusMap;
    }

    protected function applyEnrollmentStatusUpdates(DBConnector $connector, array $rows, array $statusMap, string $connection): int
    {
        $updated = 0;

        foreach ($rows as $row) {
            $cid = $row['cid'] ?? '';
            if ($cid === '' || !isset($statusMap[$cid])) {
                continue;
            }

            $newStatus = $this->normalizeString($statusMap[$cid]['status'] ?? '');
            if ($newStatus === '') {
                continue;
            }

            // Map "Approved" to appropriate Enrolled status based on connection
            if ($this->equalsIgnoreCase($newStatus, 'Approved')) {
                if (stripos($connection, 'plaw') !== false) {
                    $newStatus = 'ProLaw Enrolled';
                } else {
                    $newStatus = 'LDR Enrolled';
                }
            }

            $stamp = $this->normalizeString($statusMap[$cid]['stamp'] ?? '');
            $currentStatus = $row['enrollment_status'] ?? '';
            $cancelDate = $row['cancel_date'] ?? '';
            $nsfDate = $row['nsf_date'] ?? '';

            $compareStatus = $currentStatus;
            if ($this->equalsIgnoreCase($newStatus, 'Dropped / Cancelled') && $cancelDate === '') {
                $compareStatus = '';
            } elseif ($this->containsNsf($newStatus) && $nsfDate === '') {
                $compareStatus = '';
            }

            $llgId = $row['llg_id'] ?? 'LLG-' . $cid;
            $llgEsc = $this->escapeSqlString($llgId);
            $statusEsc = $this->escapeSqlString($newStatus);
            $stampEsc = $this->escapeSqlString($stamp);

            $sql = '';

            if ($compareStatus !== $newStatus) {
                if ($this->equalsIgnoreCase($newStatus, 'Dropped / Cancelled')) {
                    $cancelValue = $stamp === '' ? 'NULL' : "'" . $stampEsc . "'";
                    $sql = "UPDATE TblEnrollment SET Enrollment_Status = '{$statusEsc}', Cancel_Date = {$cancelValue}, NSF_Date = NULL WHERE LLG_ID = '{$llgEsc}'";
                } elseif ($this->containsNsf($newStatus)) {
                    $sql = "UPDATE TblEnrollment SET Enrollment_Status = '{$statusEsc}', Cancel_Date = NULL";
                    if ($nsfDate === '') {
                        $nsfValue = $stamp === '' ? 'NULL' : "'" . $stampEsc . "'";
                        $sql .= ", NSF_Date = {$nsfValue}";
                    }
                    $sql .= " WHERE LLG_ID = '{$llgEsc}'";
                } elseif ($this->containsEnrolled($newStatus) && !$this->containsReconsideration($newStatus)) {
                    $sql = "UPDATE TblEnrollment SET Enrollment_Status = '{$statusEsc}', Cancel_Date = NULL, NSF_Date = NULL WHERE LLG_ID = '{$llgEsc}'";
                } else {
                    $sql = "UPDATE TblEnrollment SET Enrollment_Status = '{$statusEsc}' WHERE LLG_ID = '{$llgEsc}'";
                }
            } elseif ($this->endsWithEnrolled($currentStatus) && ($cancelDate !== '' || $nsfDate !== '')) {
                $sql = "UPDATE TblEnrollment SET Enrollment_Status = '{$statusEsc}', Cancel_Date = NULL, NSF_Date = NULL WHERE LLG_ID = '{$llgEsc}'";
            }

            if ($sql === '') {
                continue;
            }

            $result = $connector->querySqlServer($sql);
            if (is_array($result) && isset($result['success']) && $result['success'] === false) {
                $errorMsg = $result['error'] ?? 'Unknown SQL Server error';
                throw new \RuntimeException('Update failed: ' . $errorMsg);
            }

            if (is_array($result)) {
                foreach (['rowCount', 'affected_rows', 'row_count'] as $key) {
                    if (isset($result[$key]) && is_numeric($result[$key])) {
                        $updated += (int) $result[$key];
                        continue 2;
                    }
                }
            }

            $updated += 1;
        }

        return $updated;
    }

    protected function equalsIgnoreCase(string $left, string $right): bool
    {
        return strcasecmp($left, $right) === 0;
    }

    protected function containsNsf(string $value): bool
    {
        return stripos($value, 'NSF') !== false;
    }

    protected function containsEnrolled(string $value): bool
    {
        return stripos($value, 'Enrolled') !== false;
    }

    protected function containsReconsideration(string $value): bool
    {
        return stripos($value, 'Reconsideration') !== false;
    }

    protected function endsWithEnrolled(string $value): bool
    {
        return (bool) preg_match('/\sEnrolled$/i', $value);
    }

    protected function normalizeString(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        return trim($value);
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
        $tableName = 'TblEnrollment';
        $macro = 'SyncEnrollmentStatus';
        $actionLabel = $action !== '' ? strtoupper($action) : 'SYNC_ENROLLMENT_STATUS';

        $description = $this->truncateString(
            sprintf('Sync enrollment status for %s', $source),
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
                Log::error('SyncEnrollmentStatus: log insert failed.', [
                    'source' => $source,
                    'sql' => $sql,
                    'result' => $result,
                ]);
                return;
            }

            $this->info(sprintf('[%s] Log entry inserted into TblLog.', $source));
        } catch (\Throwable $e) {
            $this->error(sprintf('[%s] Log insert failed: %s', $source, $e->getMessage()));
            Log::error('SyncEnrollmentStatus: log insert failed.', [
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
