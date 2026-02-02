<?php

namespace Cmd\Reports\Console\Commands\GenerateDroppedReport;

use Cmd\Reports\Services\DBConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class GenerateDroppedReport extends Command
{
    protected $signature = 'Generate:dropped-report';

    protected $description = 'Generate Dropped reports for both LDR and Progress Law (Snowflake) and email them.';

    public function handle(): int
    {
        $this->info("[INFO] Dropped report: starting for both LDR and Progress Law.");

        try {
            $snowflakeLdr = DBConnector::fromEnvironment('ldr');
            $snowflakePlaw = DBConnector::fromEnvironment('plaw');
            $sqlConnector = $this->initializeSqlServerConnector();
        } catch (\Throwable $e) {
            $this->error('Failed to initialize connectors: ' . $e->getMessage());
            Log::error('GenerateDroppedReport: connector init failed', ['exception' => $e]);
            return Command::FAILURE;
        }

        // Determine date range based on day of week
        $dayOfWeek = date('N'); // 1=Monday, 7=Sunday
        $isMonday = ($dayOfWeek == 1);
        
        if ($isMonday) {
            // Monday: Check Friday to Sunday (last 3 days)
            $startDate = date('Y-m-d', strtotime('-3 days'));
            $endDate = date('Y-m-d', strtotime('-1 day'));
            $dateRange = date('m/d/Y', strtotime($startDate)) . ' - ' . date('m/d/Y', strtotime($endDate));
        } else {
            // Tuesday-Friday: Check previous day only
            $startDate = date('Y-m-d', strtotime('-1 day'));
            $endDate = $startDate;
            $dateRange = date('m/d/Y', strtotime($startDate));
        }
        
        $this->info("[INFO] Checking dropped clients for date range: {$dateRange}");
        $formatter = new Formatter();

        // Generate Progress Law Dropped Report
        try {
            $this->info('[INFO] Generating Progress Law Dropped Report...');
            $plawRows = $this->fetchDroppedClients($snowflakePlaw, $startDate, $endDate);
            $this->info('[INFO] Progress Law dropped clients rows: ' . count($plawRows));
            
            $plawReport = $formatter->buildWorkbook($plawRows, 'Progress Law', $dateRange, $isMonday);
            if ($plawReport !== null) {
                $this->info("[INFO] Progress Law report written to {$plawReport['path']}");
                $formatter->sendReport($sqlConnector, $plawReport['path'], $plawReport['filename'], $dateRange, 'PLAW', $this, $isMonday);
            } else {
                $this->warn('[WARN] Progress Law dropped report file was not created.');
            }
        } catch (\Throwable $e) {
            $this->error('Progress Law Dropped Report failed: ' . $e->getMessage());
            Log::error('GenerateDroppedReport: Progress Law report failed', ['exception' => $e]);
        }

        // Generate LDR Dropped Report
        try {
            $this->info('[INFO] Generating LDR Dropped Report...');
            $ldrRows = $this->fetchDroppedClients($snowflakeLdr, $startDate, $endDate);
            $this->info('[INFO] LDR dropped clients rows: ' . count($ldrRows));
            
            $ldrReport = $formatter->buildWorkbook($ldrRows, 'LDR', $dateRange, $isMonday);
            if ($ldrReport !== null) {
                $this->info("[INFO] LDR report written to {$ldrReport['path']}");
                $formatter->sendReport($sqlConnector, $ldrReport['path'], $ldrReport['filename'], $dateRange, 'LDR', $this, $isMonday);
            } else {
                $this->warn('[WARN] LDR dropped report file was not created.');
            }
        } catch (\Throwable $e) {
            $this->error('LDR Dropped Report failed: ' . $e->getMessage());
            Log::error('GenerateDroppedReport: LDR report failed', ['exception' => $e]);
        }

        return Command::SUCCESS;
    }

    private function fetchDroppedClients(DBConnector $snowflake, string $startDate, string $endDate): array
    {
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
            WHERE c.DROPPED_DATE >= '{$this->esc($startDate)}'
              AND c.DROPPED_DATE <= '{$this->esc($endDate)}'
        ";

        $result = $snowflake->query($sql);
        return $result['data'] ?? [];
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
