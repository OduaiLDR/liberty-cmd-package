<?php

namespace Cmd\Reports\Console\Commands\GenerateReportSummary;

use Cmd\Reports\Console\Commands\GenerateSyncSummary\StatusBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PDO;

class GenerateReportSummary extends Command
{
    protected $signature = 'Generate:report-summary
        {--test-to= : Override recipients for testing (semicolon or comma separated)}';

    protected $description = 'Generate a summary of tracked reports and automations, including schedule and last run.';

    private ?PDO $sqlConnection = null;

    public function handle(): int
    {
        $this->info('[INFO] Report Summary: starting.');

        try {
            $this->initializeSqlServerConnection();
            $rows = (new StatusBuilder($this->sqlConnection))->buildRows();
        } catch (\Throwable $e) {
            $this->error('Failed to build report summary: ' . $e->getMessage());
            Log::error('GenerateReportSummary: build failed', ['exception' => $e]);
            return Command::FAILURE;
        }

        if (empty($rows)) {
            $this->warn('[WARN] No tracked reports or automations were found.');
            return Command::SUCCESS;
        }

        $this->info('[INFO] Found ' . count($rows) . ' tracked report/automation item(s).');

        $formatter = new Formatter();
        $html = $formatter->buildHtmlBody($rows);
        $overrideRecipients = $this->parseRecipientOverride((string) ($this->option('test-to') ?? ''));
        $sent = $formatter->sendReport($html, $overrideRecipients, $this);

        if (!$sent) {
            return Command::FAILURE;
        }

        $this->info('[INFO] Report Summary: completed.');
        return Command::SUCCESS;
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
}
