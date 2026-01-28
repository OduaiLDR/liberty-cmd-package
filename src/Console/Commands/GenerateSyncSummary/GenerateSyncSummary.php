<?php

namespace Cmd\Reports\Console\Commands\GenerateSyncSummary;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PDO;

class GenerateSyncSummary extends Command
{
    protected $signature = 'Generate:sync-summary';

    protected $description = 'Generate Sync Summary report showing recent macro executions and upcoming scheduled syncs.';

    private ?PDO $sqlConnection = null;

    public function handle(): int
    {
        $this->info('[INFO] Sync Summary: starting.');

        try {
            $this->initializeSqlServerConnection();
        } catch (\Throwable $e) {
            $this->error('Failed to initialize SQL Server connection: ' . $e->getMessage());
            Log::error('GenerateSyncSummary: SQL Server init failed', ['exception' => $e]);
            return Command::FAILURE;
        }

        // Query TblLog for all macro executions from today
        $sql = "
            SELECT 
                [Macro] AS Macro_Name,
                [Description],
                [Action],
                [Result],
                [Timestamp] AS Last_Run_Date,
                DATENAME(WEEKDAY, [Timestamp]) AS Last_Run_Weekday
            FROM dbo.TblLog
            WHERE CAST([Timestamp] AS DATE) = CAST(GETDATE() AS DATE)
            ORDER BY [Timestamp] DESC
        ";

        try {
            $stmt = $this->sqlConnection->query($sql);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            $this->error('Failed to query TblLog: ' . $e->getMessage());
            Log::error('GenerateSyncSummary: Query failed', ['exception' => $e]);
            return Command::FAILURE;
        }

        if (empty($rows)) {
            $this->warn('[WARN] No macro executions found in TblLog for today.');
            return Command::SUCCESS;
        }

        $this->info('[INFO] Found ' . count($rows) . ' macro executions in TblLog.');

        // Build HTML email
        $formatter = new Formatter();
        $html = $formatter->buildHtmlBody($rows);

        // Send email
        $formatter->sendReport($html, $this);

        $this->info('[INFO] Sync Summary: completed.');
        return Command::SUCCESS;
    }

    private function initializeSqlServerConnection(): void
    {
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
