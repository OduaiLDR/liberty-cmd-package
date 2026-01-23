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

        // Query TblLog for all report executions from today
        $sql = "
            SELECT 
                [Macro] AS Report_Name,
                SUBSTRING([Description], CHARINDEX('for ', [Description]) + 4, LEN([Description])) AS Company,
                'Daily' AS Schedule,
                [Timestamp] AS Last_Run_Date,
                DATENAME(WEEKDAY, [Timestamp]) AS Last_Run_Weekday
            FROM dbo.TblLog
            WHERE [Table_Name] = 'TblReports'
              AND CAST([Timestamp] AS DATE) = CAST(GETDATE() AS DATE)
              AND [Macro] <> 'ReportSummary'
            ORDER BY [Timestamp] DESC, [Macro]
        ";

        try {
            $stmt = $this->sqlConnection->query($sql);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            $this->error('Failed to query TblReports: ' . $e->getMessage());
            Log::error('GenerateReportSummary: Query failed', ['exception' => $e]);
            return Command::FAILURE;
        }

        if (empty($rows)) {
            $this->warn('[WARN] No reports found in TblReports.');
            return Command::SUCCESS;
        }

        $this->info('[INFO] Found ' . count($rows) . ' reports in TblReports.');

        // Build HTML table
        $formatter = new Formatter();
        $html = $formatter->buildHtmlBody($rows);

        // Send email
        $sent = $formatter->sendReport($html, $this);

        // Update Last_Run_Date if email was sent successfully
        if ($sent) {
            $this->updateLastRunDate();
        }

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

    private function updateLastRunDate(): void
    {
        try {
            $sql = "
                UPDATE dbo.TblReports
                SET Last_Run_Date = GETDATE()
                WHERE Report_Name IN ('ReportSummary', 'Report Summary')
            ";
            $this->sqlConnection->exec($sql);
        } catch (\Throwable $e) {
            Log::warning('GenerateReportSummary: Failed to update Last_Run_Date', ['error' => $e->getMessage()]);
        }
    }
}
