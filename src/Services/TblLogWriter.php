<?php

namespace Cmd\Reports\Services;

use Illuminate\Support\Facades\Log;

class TblLogWriter
{
    /**
     * @return array{success:bool,error?:string}
     */
    public function logAutomation(
        DBConnector $connector,
        string $tableName,
        string $macro,
        string $description,
        string $action,
        string $status,
        int $recordsProcessed = 0,
        int $recordsDeleted = 0,
        string $details = ''
    ): array {
        $tableName = $this->truncateString($tableName, 50);
        $macro = $this->truncateString($macro, 50);
        $description = $this->truncateString($description, 255);
        $action = strtoupper(trim($action));
        $action = $this->truncateString($action !== '' ? $action : 'AUTOMATION', 255);
        $status = strtoupper(trim($status));
        $status = $status !== '' ? $status : 'SUCCESS';

        $timestamp = now()->format('Y-m-d H:i:s');
        $resultSummary = $this->truncateString(
            sprintf('S=%s A=%s P=%d D=%d', $status, $action, $recordsProcessed, $recordsDeleted),
            50
        );

        $sql = sprintf(
            <<<'SQL'
DECLARE @hasPK BIT = CASE WHEN COL_LENGTH('dbo.TblLog', 'PK') IS NULL THEN 0 ELSE 1 END;
DECLARE @isIdentity BIT = CASE WHEN COLUMNPROPERTY(OBJECT_ID('dbo.TblLog'), 'PK', 'IsIdentity') = 1 THEN 1 ELSE 0 END;

IF @hasPK = 1 AND @isIdentity = 0
BEGIN
    DECLARE @nextPK INT = ISNULL((SELECT MAX([PK]) FROM [dbo].[TblLog]), 0) + 1;
    INSERT INTO [dbo].[TblLog] ([PK], [Table_Name], [Macro], [Description], [Action], [Result], [Timestamp])
    VALUES (@nextPK, '%s', '%s', '%s', '%s', '%s', '%s');
END
ELSE
BEGIN
    INSERT INTO [dbo].[TblLog] ([Table_Name], [Macro], [Description], [Action], [Result], [Timestamp])
    VALUES ('%s', '%s', '%s', '%s', '%s', '%s');
END;
SQL,
            $this->escapeSqlString($tableName),
            $this->escapeSqlString($macro),
            $this->escapeSqlString($description),
            $this->escapeSqlString($action),
            $this->escapeSqlString($resultSummary),
            $this->escapeSqlString($timestamp),
            $this->escapeSqlString($tableName),
            $this->escapeSqlString($macro),
            $this->escapeSqlString($description),
            $this->escapeSqlString($action),
            $this->escapeSqlString($resultSummary),
            $this->escapeSqlString($timestamp)
        );

        try {
            $result = $connector->querySqlServer($sql);
            if (is_array($result) && isset($result['success']) && $result['success'] === false) {
                $error = $this->formatSqlServerError($result['error'] ?? 'Unknown SQL Server error');
                Log::error('TblLogWriter: log insert failed.', [
                    'table' => $tableName,
                    'macro' => $macro,
                    'action' => $action,
                    'status' => $status,
                    'details' => $this->truncateString($details, 900),
                    'error' => $error,
                ]);

                return ['success' => false, 'error' => $error];
            }

            Log::info('TblLogWriter: log insert OK.', [
                'table' => $tableName,
                'macro' => $macro,
                'action' => $action,
                'status' => $status,
                'processed' => $recordsProcessed,
                'deleted' => $recordsDeleted,
                'details' => $this->truncateString($details, 900),
            ]);

            return ['success' => true];
        } catch (\Throwable $e) {
            Log::error('TblLogWriter: log insert exception.', [
                'table' => $tableName,
                'macro' => $macro,
                'action' => $action,
                'status' => $status,
                'details' => $this->truncateString($details, 900),
                'exception' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function escapeSqlString(string $value): string
    {
        return str_replace("'", "''", $value);
    }

    private function truncateString(string $value, int $maxLength): string
    {
        return mb_strlen($value) <= $maxLength ? $value : mb_substr($value, 0, $maxLength);
    }

    private function formatSqlServerError(mixed $error): string
    {
        if (is_string($error)) {
            return $error;
        }

        if (is_array($error)) {
            $encoded = json_encode($error, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            return $encoded !== false ? $encoded : 'Unknown SQL Server error';
        }

        return 'Unknown SQL Server error';
    }
}
