<?php

namespace Cmd\Reports\Console\Commands\GenerateSyncSummary;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PDO;

class GenerateSyncSummary extends Command
{
    protected $signature = 'Generate:sync-summary
        {--test-to= : Override recipients for testing (semicolon or comma separated)}';

    protected $description = 'Generate Sync Summary report showing recent tracked macro executions and upcoming scheduled runs.';

    private ?PDO $sqlConnection = null;

    public function handle(): int
    {
        $this->info('[INFO] Sync Summary: starting.');

        try {
            $this->initializeSqlServerConnection();
            $builder = new StatusBuilder($this->sqlConnection);
            $statusRows = $builder->buildRows();
            $rows = $builder->filterTrackedLogRows($this->loadRecentLogRows(), $statusRows);
        } catch (\Throwable $e) {
            $this->error('Failed to build sync summary: ' . $e->getMessage());
            Log::error('GenerateSyncSummary: build failed', ['exception' => $e]);
            return Command::FAILURE;
        }

        if (empty($rows)) {
            $this->warn('[WARN] No recent tracked execution evidence found.');
        }

        $this->info('[INFO] Found ' . count($rows) . ' recent tracked execution row(s).');
        $this->info('[INFO] Found ' . count($statusRows) . ' tracked report/automation item(s).');

        $formatter = new Formatter();
        $html = $formatter->buildHtmlBody($rows, $statusRows);
        $overrideRecipients = $this->parseRecipientOverride((string) ($this->option('test-to') ?? ''));
        $sent = $formatter->sendReport($html, $overrideRecipients, $this);

        if (!$sent) {
            return Command::FAILURE;
        }

        $this->info('[INFO] Sync Summary: completed.');
        return Command::SUCCESS;
    }

    private function initializeSqlServerConnection(): void
    {
        $config = config('dbConfig.sql_server.sql_server_connection');

        if (!$config) {
            throw new \RuntimeException('SQL Server connection config not found in dbConfig.sql_server.sql_server_connection');
        }

        $this->sqlConnection = new PDO(
            $config['dsn'],
            $config['username'],
            $config['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    }

    /**
     * @return array<int, string>
     */
    private function parseRecipientOverride(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        $parts = preg_split('/[;,]+/', $raw) ?: [];
        $recipients = [];

        foreach ($parts as $part) {
            $email = trim($part);
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $recipients[] = $email;
            }
        }

        return array_values(array_unique($recipients));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadRecentLogRows(): array
    {
        $sql = "
            SELECT TOP 100
                [Macro] AS Macro_Name,
                [Description],
                [Action],
                [Result],
                [Timestamp] AS Last_Run_Date,
                DATENAME(WEEKDAY, [Timestamp]) AS Last_Run_Weekday
            FROM dbo.TblLog
            WHERE [Macro] IS NOT NULL
              AND LTRIM(RTRIM([Macro])) <> ''
            ORDER BY [Timestamp] DESC
        ";

        $stmt = $this->sqlConnection->query($sql);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
