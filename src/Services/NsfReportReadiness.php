<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

/** Durable source/month evidence. Passing time never promotes an earlier preview. */
final class NsfReportReadiness
{
    public static function timing(string $period, ?\DateTimeImmutable $startedAt = null): array
    {
        $zone = new \DateTimeZone('America/Los_Angeles');
        $month = \DateTimeImmutable::createFromFormat('!Y-m-d', $period, $zone);
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])-01$/D', $period) || !$month || $month->format('Y-m-d') !== $period) {
            throw new \InvalidArgumentException('NSF readiness requires a valid month start.');
        }
        $utc = new \DateTimeZone('UTC');
        $start = ($startedAt ?? new \DateTimeImmutable('now', $utc))->setTimezone($utc);
        $finalAfterPacific = $month->modify('first day of next month')->modify('+5 days');
        $finalAfter = $finalAfterPacific->setTimezone($utc);
        return [
            'period' => $period, 'startedAt' => $start->format('Y-m-d H:i:s.u'),
            'finalAfter' => $finalAfter->format('Y-m-d H:i:s.u'),
            'cutoffPacific' => $finalAfterPacific->modify('-1 second')->format('Y-m-d H:i:s T'),
            'isProvisional' => $start < $finalAfter,
        ];
    }

    /** Must be called while holding CommissionReportRunLock for this source/month. */
    public static function begin(DBConnector $sql, string $source, string $period, ?\DateTimeImmutable $startedAt = null): array
    {
        if (!in_array($source, ['ldr', 'plaw'], true)) throw new \InvalidArgumentException('Invalid NSF readiness source.');
        $run = self::timing($period, $startedAt) + ['source' => $source, 'runId' => bin2hex(random_bytes(16))];
        self::checked($sql, "IF OBJECT_ID(N'dbo.TblNsfCommissionReadiness', N'U') IS NULL
            BEGIN TRY
                CREATE TABLE dbo.TblNsfCommissionReadiness (
                    Source nvarchar(8) NOT NULL, Period_Start date NOT NULL,
                    Status nvarchar(16) NOT NULL, Run_Id char(32) NOT NULL,
                    Started_At datetime2(6) NOT NULL, Completed_At datetime2(6) NULL,
                    Final_After datetime2(6) NOT NULL,
                    CONSTRAINT PK_TblNsfCommissionReadiness PRIMARY KEY (Source, Period_Start),
                    CONSTRAINT CK_TblNsfCommissionReadiness_Status CHECK (Status IN ('running','completed','failed'))
                );
            END TRY
            BEGIN CATCH
                IF ERROR_NUMBER() <> 2714 THROW;
            END CATCH", []);
        self::checked($sql, "MERGE dbo.TblNsfCommissionReadiness WITH (HOLDLOCK) AS t
            USING (SELECT ? AS Source, CAST(? AS date) AS Period_Start) AS s
            ON t.Source=s.Source AND t.Period_Start=s.Period_Start
            WHEN MATCHED THEN UPDATE SET Status='running', Run_Id=?, Started_At=CAST(? AS datetime2(6)), Completed_At=NULL, Final_After=CAST(? AS datetime2(6))
            WHEN NOT MATCHED THEN INSERT (Source,Period_Start,Status,Run_Id,Started_At,Completed_At,Final_After)
                VALUES(s.Source,s.Period_Start,'running',?,CAST(? AS datetime2(6)),NULL,CAST(? AS datetime2(6)));",
            [$source, $period, $run['runId'], $run['startedAt'], $run['finalAfter'], $run['runId'], $run['startedAt'], $run['finalAfter']]);
        self::verify($sql, $run, 'running');
        return $run;
    }

    /** Call only after payable replacement AND verified snapshot publication succeeded. */
    public static function complete(DBConnector $sql, array $run): void
    {
        self::identity($run);
        self::checked($sql, "UPDATE dbo.TblNsfCommissionReadiness
            SET Status='completed', Completed_At=SYSUTCDATETIME()
            WHERE Source=? AND Period_Start=CAST(? AS date) AND Run_Id=? AND Status='running'",
            [$run['source'], $run['period'], $run['runId']]);
        self::verify($sql, $run, 'completed');
    }

    public static function fail(DBConnector $sql, array $run): void
    {
        self::identity($run);
        self::checked($sql, "UPDATE dbo.TblNsfCommissionReadiness
            SET Status='failed', Completed_At=NULL
            WHERE Source=? AND Period_Start=CAST(? AS date) AND Run_Id=?",
            [$run['source'], $run['period'], $run['runId']]);
        self::verify($sql, $run, 'failed');
    }

    private static function identity(array $run): void
    {
        if (!in_array($run['source'] ?? '', ['ldr', 'plaw'], true) || !preg_match('/^[a-f0-9]{32}$/D', $run['runId'] ?? '')) {
            throw new \InvalidArgumentException('Invalid NSF readiness run identity.');
        }
        self::timing($run['period'] ?? '');
    }

    private static function checked(DBConnector $sql, string $query, array $params): array
    {
        $result = $sql->querySqlServer($query, $params);
        if (($result['success'] ?? false) !== true) {
            throw new \RuntimeException('NSF readiness could not be saved or verified; payroll must remain blocked.');
        }
        return $result;
    }

    private static function verify(DBConnector $sql, array $run, string $status): void
    {
        $result = self::checked($sql, 'SELECT Source,Period_Start,Status,Run_Id,Started_At,Completed_At,Final_After FROM dbo.TblNsfCommissionReadiness WHERE Source=? AND Period_Start=CAST(? AS date)', [$run['source'], $run['period']]);
        $rows = $result['data'] ?? [];
        $row = count($rows) === 1 && is_array($rows[0]) ? array_change_key_case($rows[0], CASE_LOWER) : [];
        if (($row['run_id'] ?? null) !== $run['runId'] || ($row['status'] ?? null) !== $status
            || ($status === 'completed' && empty($row['completed_at']))) {
            throw new \RuntimeException('NSF readiness run confirmation failed; payroll must remain blocked.');
        }
    }
}
