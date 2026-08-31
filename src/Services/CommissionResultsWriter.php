<?php

namespace Cmd\Reports\Services;

use Illuminate\Support\Facades\Log;

/**
 * Persists the report generators' computed per-agent commission to Azure SQL
 * (CommissionDatabase.dbo.TblCommissionReviewResults) so the Commission Review app can read the
 * REAL numbers. Best-effort: any failure (missing table / DDL rights / connection) is logged and
 * NEVER interrupts report generation or emailing.
 *
 * Table contract — must match CMD_PAYROLL_REVIEW's fetchCommissionResultsByAgent():
 *   TblCommissionReviewResults(Report_Type, Source, Period_Start, Agent, Commission, Bonus_Commission, Updated_At)
 *   PK (Report_Type, Source, Period_Start, Agent)
 *
 * Components: NSF writes Commission. Retention writes Commission (retention-commission report) and
 * Bonus_Commission (retention-bonus-commission report) — each call upserts only its own column so
 * the sibling report's value is preserved.
 */
class CommissionResultsWriter
{
    private const TABLE = 'TblCommissionReviewResults';
    private const COLUMNS = ['Commission', 'Bonus_Commission'];

    /**
     * @param DBConnector $sql         A SQL Server-connected DBConnector.
     * @param string      $reportType  'nsf' | 'retention'
     * @param string      $source      'ldr' | 'plaw'
     * @param string      $periodStart 'Y-m-01' (first day of the report month)
     * @param string      $column      'Commission' | 'Bonus_Commission'
     * @param array       $rows        [['agent' => string, 'amount' => float], ...]
     */
    public static function persist(DBConnector $sql, string $reportType, string $source, string $periodStart, string $column, array $rows): void
    {
        if (!in_array($column, self::COLUMNS, true)) {
            Log::warning("CommissionResultsWriter: unknown column '{$column}' — skipped.");
            return;
        }

        $reportType = strtolower(trim($reportType));
        $source     = strtolower(trim($source));

        try {
            self::ensureTable($sql);

            $written = 0;
            foreach ($rows as $row) {
                $agent = trim((string) ($row['agent'] ?? ''));
                if ($agent === '') {
                    continue;
                }
                $amount = round((float) ($row['amount'] ?? 0), 2);

                // Upsert only this component; the sibling generator owns the other column.
                $merge = 'MERGE dbo.' . self::TABLE . ' AS t
                    USING (SELECT ? AS Report_Type, ? AS Source, CAST(? AS DATE) AS Period_Start, ? AS Agent, CAST(? AS DECIMAL(12,2)) AS Amount) AS s
                    ON t.Report_Type = s.Report_Type AND t.Source = s.Source AND t.Period_Start = s.Period_Start AND t.Agent = s.Agent
                    WHEN MATCHED THEN UPDATE SET t.' . $column . ' = s.Amount, t.Updated_At = GETDATE()
                    WHEN NOT MATCHED THEN INSERT (Report_Type, Source, Period_Start, Agent, ' . $column . ', Updated_At)
                        VALUES (s.Report_Type, s.Source, s.Period_Start, s.Agent, s.Amount, GETDATE());';

                $res = $sql->querySqlServer($merge, [$reportType, $source, $periodStart, $agent, $amount]);
                if (($res['success'] ?? false) === true) {
                    $written++;
                } else {
                    Log::warning("CommissionResultsWriter: upsert failed for agent '{$agent}'", ['error' => $res['error'] ?? '']);
                }
            }

            Log::info("CommissionResultsWriter: {$reportType}/{$source} {$periodStart} {$column} — {$written}/" . count($rows) . ' rows written to Azure.');
        } catch (\Throwable $e) {
            Log::warning('CommissionResultsWriter: persist skipped', ['ex' => $e->getMessage()]);
        }
    }

    /**
     * Reset one computed component before a report re-run. This lets the
     * generator clear a formerly qualifying agent without overwriting the
     * sibling component stored on the same result row.
     */
    public static function resetColumn(DBConnector $sql, string $reportType, string $source, string $periodStart, string $column): void
    {
        if (!in_array($column, self::COLUMNS, true)) {
            throw new \InvalidArgumentException("Unsupported commission result column: {$column}");
        }

        try {
            self::ensureTable($sql);
            $sql->querySqlServer(
                'UPDATE dbo.' . self::TABLE . ' SET ' . $column . ' = 0, Updated_At = GETDATE()'
                . ' WHERE Report_Type = ? AND Source = ? AND Period_Start = CAST(? AS DATE)',
                [strtolower(trim($reportType)), strtolower(trim($source)), $periodStart]
            );
        } catch (\Throwable $e) {
            Log::warning("CommissionResultsWriter: {$reportType}/{$source} {$periodStart} {$column} reset skipped", [
                'ex' => $e->getMessage(),
            ]);
        }
    }

    private static function ensureTable(DBConnector $sql): void
    {
        $ddl = "IF NOT EXISTS (SELECT 1 FROM sys.tables WHERE name = '" . self::TABLE . "')
            CREATE TABLE dbo." . self::TABLE . " (
                Report_Type      NVARCHAR(20)  NOT NULL,
                Source           NVARCHAR(10)  NOT NULL,
                Period_Start     DATE          NOT NULL,
                Agent            NVARCHAR(200) NOT NULL,
                Commission       DECIMAL(12,2) NOT NULL DEFAULT 0,
                Bonus_Commission DECIMAL(12,2) NOT NULL DEFAULT 0,
                Updated_At       DATETIME      NOT NULL DEFAULT GETDATE(),
                CONSTRAINT PK_" . self::TABLE . " PRIMARY KEY (Report_Type, Source, Period_Start, Agent)
            );";
        $sql->querySqlServer($ddl);
    }
}
