<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

/** Publishes the Lookback report's existing Sales deductions, never a new formula. */
final class PayrollRetentionLookbackWriter
{
    /**
     * Keep the legacy bonus writer's transaction contract. Correlated runner
     * success is required in addition to this feed marker: a failed feed leaves
     * the completed bonus component in Azure but can never mark payroll ready.
     */
    public static function replaceCalculatedResults(DBConnector $sql, string $source, string $periodStart, string $runId, array $calculatedRows, array $bonusResults): array
    {
        $prepared = self::prepare($sql, $source, $periodStart, $runId, $calculatedRows);
        $result = CommissionResultsWriter::replaceComponent($sql, 'retention', $source, $periodStart, 'Bonus_Commission', $bonusResults);
        self::publish($sql, $prepared);
        return $result;
    }

    public static function runIdFromEnvironment(): string
    {
        $configured = getenv('PAYROLL_COMMISSION_RUN_ID');
        $runId = $configured === false || $configured === '' ? bin2hex(random_bytes(16)) : $configured;
        if (!preg_match('/^[a-f0-9]{32}$/', $runId)) {
            throw new \InvalidArgumentException('PAYROLL_COMMISSION_RUN_ID must be the reserved 32 character lowercase hexadecimal run id.');
        }
        return $runId;
    }

    /** Read-only validation happens before either Azure result component is replaced. */
    public static function prepare(DBConnector $sql, string $source, string $periodStart, string $runId, array $calculatedRows): array
    {
        self::validateScope($source, $periodStart, $runId);
        $rows = [];
        $positive = [];
        foreach ($calculatedRows as $row) {
            $id = preg_replace('/^LLG-/i', '', trim((string) self::value($row, 'ID')));
            if (!preg_match('/^[1-9]\d*$/', $id) || strlen($id) > 50) {
                throw new \UnexpectedValueException('Lookback deduction feed requires a usable canonical client ID.');
            }
            $cid = 'LLG-' . $id;
            if (isset($rows[$cid])) throw new \UnexpectedValueException("Lookback deduction feed has duplicate client {$cid}; resolve the source before processing Sales.");
            $agent = self::cleanName(self::value($row, 'AGENT'));
            $raw = self::value($row, 'AGENT_DEDUCTION');
            $blank = $raw === null || trim((string) $raw) === '';
            if (!$blank && (!is_numeric($raw) || !is_finite((float) $raw) || (float) $raw < 0 || (float) $raw > 9999999999999999.99)) {
                throw new \UnexpectedValueException("Lookback deduction for {$cid} is not a finite nonnegative amount.");
            }
            $deduction = $blank ? null : round((float) $raw, 2);
            $rows[$cid] = ['CID' => $cid, 'Agent' => $agent !== '' ? $agent : null, 'Employee_PK' => null,
                'Agent_Deduction' => $deduction, 'Exclusion_Reason' => $blank ? 'default_rate_no_deduction' : null];
            if ($deduction !== null && $deduction > 0) {
                if ($agent === '' || strlen($agent) > 200) throw new \UnexpectedValueException("Positive Lookback deduction {$cid} has no usable assigned Sales agent.");
                $positive[$cid] = $agent;
            }
        }

        $enrollments = [];
        foreach (array_chunk(array_keys($rows), 500) as $ids) {
            $data = self::read($sql, 'SELECT LLG_ID, Agent FROM dbo.TblEnrollment WHERE LLG_ID IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids);
            foreach ($data as $record) {
                $cid = trim((string) self::value($record, 'LLG_ID'));
                if (isset($rows[$cid])) $enrollments[$cid][] = $record;
            }
        }
        foreach ($enrollments as $cid => $matches) {
            if (count($matches) !== 1) throw new \UnexpectedValueException("Lookback deduction feed has ambiguous TblEnrollment records for {$cid}.");
        }
        foreach ($positive as $cid => $agent) {
            if (count($enrollments[$cid] ?? []) !== 1 || self::nameKey(self::value($enrollments[$cid][0], 'Agent')) !== self::nameKey($agent)) {
                throw new \UnexpectedValueException("Positive Lookback deduction {$cid} requires one matching, unchanged TblEnrollment Sales assignment.");
            }
        }

        $employees = [];
        $agentNames = array_values(array_unique(array_values($positive)));
        foreach (array_chunk($agentNames, 500) as $names) {
            $data = self::read($sql, 'SELECT PK, Employee_Name FROM dbo.TblEmployees WHERE Employee_Name IN (' . implode(',', array_fill(0, count($names), '?')) . ')', $names);
            foreach ($data as $record) $employees[self::nameKey(self::value($record, 'Employee_Name'))][] = $record;
        }
        foreach ($positive as $cid => $agent) {
            $matches = $employees[self::nameKey($agent)] ?? [];
            $pk = count($matches) === 1 ? trim((string) self::value($matches[0], 'PK')) : '';
            if (!preg_match('/^[1-9]\d*$/', $pk) || (float) $pk > 2147483647) {
                throw new \UnexpectedValueException("Positive Lookback deduction {$cid} requires a unique canonical TblEmployees identity for {$agent}.");
            }
            $rows[$cid]['Agent'] = self::cleanName(self::value($matches[0], 'Employee_Name'));
            $rows[$cid]['Employee_PK'] = (int) $pk;
        }
        ksort($rows, SORT_STRING);
        $rows = array_values($rows);
        return self::manifest($source, $periodStart, $runId, $rows);
    }

    /** Atomic source replacement and completion marker; previous source data survives failure. */
    public static function publish(DBConnector $sql, array $prepared): array
    {
        self::validateScope($prepared['source'] ?? '', $prepared['periodStart'] ?? '', $prepared['runId'] ?? '');
        if (!is_array($prepared['rows'] ?? null)
            || self::manifest($prepared['source'], $prepared['periodStart'], $prepared['runId'], $prepared['rows']) !== $prepared) {
            throw new \InvalidArgumentException('Lookback deduction feed manifest changed after identity validation.');
        }
        $pdo = $sql->getSqlServerConnection();
        if ($pdo->inTransaction()) throw new \LogicException('Lookback deduction feed replacement requires its own transaction.');
        self::ensureTables($sql);
        if (!$pdo->beginTransaction()) throw new \RuntimeException('Lookback deduction feed transaction could not begin.');
        try {
            // Serialize replacement even when the first source-period marker has
            // not been inserted yet. The primary key makes this a range lock.
            self::execute($sql, 'SELECT Source FROM dbo.TblPayrollRetentionLookbackRuns WITH (UPDLOCK,HOLDLOCK) WHERE Source=? AND Period_Start=CAST(? AS DATE)', [$prepared['source'], $prepared['periodStart']]);
            self::execute($sql, 'DELETE FROM dbo.TblPayrollRetentionLookbackResults WHERE Source=? AND Period_Start=CAST(? AS DATE)', [$prepared['source'], $prepared['periodStart']]);
            foreach ($prepared['rows'] as $row) {
                self::execute($sql, 'INSERT INTO dbo.TblPayrollRetentionLookbackResults
                    (Source,Period_Start,Run_ID,CID,Agent,Employee_PK,Agent_Deduction,Exclusion_Reason)
                    VALUES(?,CAST(? AS DATE),?,?,?,?,?,?)',
                    [$prepared['source'], $prepared['periodStart'], $prepared['runId'], $row['CID'], $row['Agent'], $row['Employee_PK'], $row['Agent_Deduction'], $row['Exclusion_Reason']]);
            }
            self::execute($sql, 'MERGE dbo.TblPayrollRetentionLookbackRuns WITH (HOLDLOCK) AS t
                USING(SELECT ? AS Source,CAST(? AS DATE) AS Period_Start,? AS Run_ID,? AS Row_Count,
                    ? AS Deduction_Row_Count,? AS Exclusion_Row_Count,? AS Payload_SHA256) AS s
                ON t.Source=s.Source AND t.Period_Start=s.Period_Start
                WHEN MATCHED THEN UPDATE SET Run_ID=s.Run_ID,Row_Count=s.Row_Count,Deduction_Row_Count=s.Deduction_Row_Count,
                    Exclusion_Row_Count=s.Exclusion_Row_Count,Payload_SHA256=s.Payload_SHA256,Completed_At=SYSUTCDATETIME()
                WHEN NOT MATCHED THEN INSERT(Source,Period_Start,Run_ID,Row_Count,Deduction_Row_Count,Exclusion_Row_Count,Payload_SHA256,Completed_At)
                    VALUES(s.Source,s.Period_Start,s.Run_ID,s.Row_Count,s.Deduction_Row_Count,s.Exclusion_Row_Count,s.Payload_SHA256,SYSUTCDATETIME());',
                [$prepared['source'], $prepared['periodStart'], $prepared['runId'], $prepared['rowCount'], $prepared['deductionRowCount'], $prepared['exclusionRowCount'], $prepared['payloadSha256']]);
            if (!$pdo->commit()) throw new \RuntimeException('Commit was not confirmed.');
            return array_diff_key($prepared, ['rows' => true]);
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw new \RuntimeException('Lookback deduction feed replacement failed; do not mark this report ready. ' . $error->getMessage(), 0, $error);
        }
    }

    private static function manifest(string $source, string $periodStart, string $runId, array $rows): array
    {
        $lines = array_map(static fn ($row) => json_encode([$source, $periodStart, $row['CID'], $row['Employee_PK'], $row['Agent'],
            $row['Agent_Deduction'] === null ? null : number_format($row['Agent_Deduction'], 2, '.', ''), $row['Exclusion_Reason']],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $rows);
        return ['source' => $source, 'periodStart' => $periodStart, 'runId' => $runId, 'rows' => $rows, 'rowCount' => count($rows),
            'deductionRowCount' => count(array_filter($rows, static fn ($row) => $row['Agent_Deduction'] !== null)),
            'exclusionRowCount' => count(array_filter($rows, static fn ($row) => $row['Exclusion_Reason'] !== null)),
            'payloadSha256' => hash('sha256', implode("\n", $lines))];
    }

    private static function validateScope(string $source, string $periodStart, string $runId): void
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $periodStart);
        if (!in_array($source, ['ldr', 'plaw'], true) || !$date || $date->format('Y-m-d') !== $periodStart
            || $date->format('d') !== '01' || !preg_match('/^[a-f0-9]{32}$/', $runId)) {
            throw new \InvalidArgumentException('Lookback deduction feed requires a source, first-of-month period, and reserved run id.');
        }
    }

    private static function value(array $row, string $key): mixed { return $row[$key] ?? $row[strtolower($key)] ?? null; }
    private static function cleanName(mixed $name): string { return preg_replace('/\s+/u', ' ', trim((string) $name)) ?? ''; }
    private static function nameKey(mixed $name): string { $name = self::cleanName($name); return function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name); }
    private static function read(DBConnector $sql, string $query, array $params): array
    {
        $result = $sql->querySqlServer($query, $params);
        if (($result['success'] ?? false) !== true || !is_array($result['data'] ?? null)) throw new \RuntimeException('Lookback deduction feed identity lookup is unavailable.');
        return $result['data'];
    }
    private static function execute(DBConnector $sql, string $query, array $params = []): void
    {
        if (($sql->querySqlServer($query, $params)['success'] ?? false) !== true) throw new \RuntimeException('Lookback deduction feed SQL operation was not confirmed.');
    }
    private static function ensureTables(DBConnector $sql): void
    {
        self::execute($sql, "IF OBJECT_ID('dbo.TblPayrollRetentionLookbackResults','U') IS NULL
            CREATE TABLE dbo.TblPayrollRetentionLookbackResults(
                Source NVARCHAR(10) NOT NULL,Period_Start DATE NOT NULL,Run_ID CHAR(32) NOT NULL,CID NVARCHAR(64) NOT NULL,
                Agent NVARCHAR(200) NULL,Employee_PK INT NULL,Agent_Deduction DECIMAL(18,2) NULL,Exclusion_Reason NVARCHAR(80) NULL,
                CONSTRAINT PK_TblPayrollRetentionLookbackResults PRIMARY KEY(Source,Period_Start,CID));
            IF OBJECT_ID('dbo.TblPayrollRetentionLookbackRuns','U') IS NULL
            CREATE TABLE dbo.TblPayrollRetentionLookbackRuns(
                Source NVARCHAR(10) NOT NULL,Period_Start DATE NOT NULL,Run_ID CHAR(32) NOT NULL,Row_Count INT NOT NULL,
                Deduction_Row_Count INT NOT NULL,Exclusion_Row_Count INT NOT NULL,Payload_SHA256 CHAR(64) NOT NULL,Completed_At DATETIME2 NOT NULL,
                CONSTRAINT PK_TblPayrollRetentionLookbackRuns PRIMARY KEY(Source,Period_Start));");
    }
}
