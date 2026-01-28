<?php

namespace Cmd\Reports\Console\Commands\GenerateReportSummary;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PDO;

class GenerateReportSummary extends Command
{
    protected $signature = 'Generate:report-summary';

    protected $description = 'Generate a daily summary of all reports from TblReports and email it.';

    private ?PDO $sqlConnection = null;

    public function handle(): int
    {
        $this->info('[INFO] Report Summary: starting.');

        try {
            $this->initializeSqlServerConnection();
        } catch (\Throwable $e) {
            $this->error('Failed to initialize SQL Server connection: ' . $e->getMessage());
            Log::error('GenerateReportSummary: SQL Server init failed', ['exception' => $e]);
            return Command::FAILURE;
        }

        // Query ALL reports from TblReports and TblAutomation, then get last run from TblLog
        $sql = "
            WITH AllReports AS (
                -- Get all reports from TblReports
                SELECT 
                    LTRIM(RTRIM(Report_Name)) AS Report_Name,
                    LTRIM(RTRIM(Company)) AS Company,
                    LTRIM(RTRIM(Schedule)) AS Schedule,
                    'Report' AS Type
                FROM dbo.TblReports
                WHERE Report_Name IS NOT NULL 
                  AND Report_Name <> ''
                  AND Report_Name NOT IN ('ReportSummary', 'Report Summary')
                
                UNION ALL
                
                -- Get all automations from TblAutomation
                SELECT 
                    LTRIM(RTRIM(Automation_Name)) AS Report_Name,
                    CASE 
                        WHEN LTRIM(RTRIM(Automation_Name)) LIKE '%PLAW%' OR LTRIM(RTRIM(Automation_Name)) LIKE '%Progress%' THEN 'PLAW'
                        WHEN LTRIM(RTRIM(Automation_Name)) LIKE '%LDR%' THEN 'LDR'
                        ELSE 'All'
                    END AS Company,
                    LTRIM(RTRIM(Schedule)) AS Schedule,
                    'Automation' AS Type
                FROM dbo.TblAutomation
                WHERE Automation_Name IS NOT NULL 
                  AND Automation_Name <> ''
            ),
            LastRuns AS (
                -- Get the most recent run for each report/automation
                SELECT 
                    LTRIM(RTRIM([Macro])) AS Report_Name,
                    MAX([Timestamp]) AS Last_Run_Date
                FROM dbo.TblLog
                WHERE [Table_Name] IN ('TblReports', 'TblAutomation')
                  AND [Macro] IS NOT NULL
                  AND [Macro] <> ''
                GROUP BY LTRIM(RTRIM([Macro]))
            )
            SELECT 
                ar.Report_Name,
                ar.Company,
                ar.Schedule,
                ar.Type,
                lr.Last_Run_Date,
                CASE 
                    WHEN lr.Last_Run_Date IS NOT NULL 
                    THEN DATENAME(WEEKDAY, lr.Last_Run_Date)
                    ELSE NULL
                END AS Last_Run_Weekday
            FROM AllReports ar
            LEFT JOIN LastRuns lr ON ar.Report_Name = lr.Report_Name
            ORDER BY ar.Type, ar.Report_Name
        ";

        try {
            $stmt = $this->sqlConnection->query($sql);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            $this->error('Failed to query TblReports/TblAutomation: ' . $e->getMessage());
            Log::error('GenerateReportSummary: Query failed', ['exception' => $e, 'sql' => $sql]);
            return Command::FAILURE;
        }

        if (empty($rows)) {
            $this->warn('[WARN] No reports found in TblReports or TblAutomation.');
            return Command::SUCCESS;
        }

        $this->info('[INFO] Found ' . count($rows) . ' reports/automations.');

        // Build HTML table
        $formatter = new Formatter();
        $html = $formatter->buildHtmlBody($rows);

        // Send email
        $sent = $formatter->sendReport($html, $this);

        $this->info('[INFO] Report Summary: completed.');
        return Command::SUCCESS;
    }

    private function initializeSqlServerConnection(): void
    {
        // Use dbConfig from package which reads from env variables
        $config = config('dbConfig.sql_server.sql_server_connection');
        
        if (!$config) {
            throw new \RuntimeException('SQL Server connection config not found in dbConfig.sql_server.sql_server_connection');
        }

        $dsn = $config['dsn'];
        $username = $config['username'];
        $password = $config['password'];

        $this->sqlConnection = new PDO(
            $dsn,
            $username,
            $password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    }
}
