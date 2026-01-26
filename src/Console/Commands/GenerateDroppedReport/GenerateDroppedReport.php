<?php

namespace Cmd\Reports\Console\Commands\GenerateDroppedReport;

use Cmd\Reports\Services\DBConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class GenerateDroppedReport extends Command
{
    protected $signature = 'Generate:dropped-report {--source=LDR : Source (LDR or PLAW)}';

    protected $description = 'Generate Dropped report (Snowflake) and email it.';

    private string $source;

    public function handle(): int
    {
        $this->source = strtoupper($this->option('source') ?: 'LDR');
        $this->info("[INFO] Dropped report: starting for {$this->source}.");

        try {
            $snowflake = $this->initializeSnowflakeConnector();
        } catch (\Throwable $e) {
            $this->error('Failed to initialize Snowflake connector: ' . $e->getMessage());
            Log::error('GenerateDroppedReport: Snowflake init failed', ['exception' => $e]);
            return Command::FAILURE;
        }

        try {
            $sqlConnector = $this->initializeSqlServerConnector();
        } catch (\Throwable $e) {
            $this->error('Failed to initialize SQL Server connector: ' . $e->getMessage());
            Log::error('GenerateDroppedReport: SQL Server init failed', ['exception' => $e]);
            return Command::FAILURE;
        }

        $reportDate = date('Y-m-d', strtotime('-1 day'));

        // Fetch dropped clients from yesterday
        $sql = "
            SELECT
                c.ID,
                CONCAT(c.FIRSTNAME, ' ', c.LASTNAME) AS CLIENT,
                TO_VARCHAR(c.ENROLLED_DATE::date, 'YYYY-MM-DD') AS ENROLLED_DATE,
                TO_VARCHAR(c.DROPPED_DATE::date, 'YYYY-MM-DD') AS DROPPED_DATE,
                DATEDIFF(day, c.ENROLLED_DATE, c.DROPPED_DATE) AS DAYS_ENROLLED,
                ed.TITLE,
                d.ENROLLED_DEBT,
                cr.TITLE AS DROPPED_REASON,
                cls.TITLE AS STATUS
            FROM CONTACTS AS c
            LEFT JOIN ENROLLMENT_PLAN AS ep ON c.ID = ep.CONTACT_ID
            LEFT JOIN ENROLLMENT_DEFAULTS2 AS ed ON ep.PLAN_ID = ed.ID
            LEFT JOIN (
                SELECT CONTACT_ID, SUM(ORIGINAL_DEBT_AMOUNT) AS ENROLLED_DEBT
                FROM DEBTS
                WHERE ENROLLED = 1
                  AND _FIVETRAN_DELETED = FALSE
                GROUP BY CONTACT_ID
            ) AS d ON c.ID = d.CONTACT_ID
            LEFT JOIN CANCELLATION_REASONS AS cr ON c.DROPPED_REASON = cr.ID
            LEFT JOIN CONTACTS_LEAD_STATUS AS cls ON c.LEADSTATUS = cls.ID
            WHERE c.DROPPED_DATE = '{$this->esc($reportDate)}'
        ";

        $result = $snowflake->query($sql);
        $rows = $result['data'] ?? [];
        $this->info('[INFO] Dropped clients rows: ' . count($rows));

        if (empty($rows)) {
            $this->info('[INFO] No dropped clients for ' . $reportDate);
            return Command::SUCCESS;
        }

        $formatter = new Formatter();
        $report = $formatter->buildWorkbook($rows, $this->source, $reportDate);

        if ($report !== null) {
            $this->info("[INFO] Report written to {$report['path']}");
            $formatter->sendReport($sqlConnector, $report['path'], $report['filename'], $reportDate, $this->source, $this);
        } else {
            $this->warn('[WARN] Dropped report file was not created.');
        }

        return Command::SUCCESS;
    }

    protected function initializeSnowflakeConnector(): DBConnector
    {
        $candidates = ['ldr', 'plaw', 'production', 'sandbox'];
        $errors = [];

        foreach ($candidates as $env) {
            try {
                return DBConnector::fromEnvironment($env);
            } catch (\Throwable $e) {
                $errors[] = "{$env}: {$e->getMessage()}";
            }
        }

        throw new \RuntimeException('Unable to initialize Snowflake connector. Tried: ' . implode('; ', $errors));
    }

    protected function initializeSqlServerConnector(): DBConnector
    {
        $candidates = ['ldr', 'plaw', 'production', 'sandbox'];
        $errors = [];

        foreach ($candidates as $env) {
            try {
                $connector = DBConnector::fromEnvironment($env);
                $connector->initializeSqlServer();
                return $connector;
            } catch (\Throwable $e) {
                $errors[] = "{$env}: {$e->getMessage()}";
            }
        }

        throw new \RuntimeException('Unable to initialize SQL Server connector. Tried: ' . implode('; ', $errors));
    }

    protected function esc(string $value): string
    {
        return str_replace("'", "''", $value);
    }
}
