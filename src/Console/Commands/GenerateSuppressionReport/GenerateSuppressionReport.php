<?php

namespace Cmd\Reports\Console\Commands\GenerateSuppressionReport;

use Cmd\Reports\Services\DBConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class GenerateSuppressionReport extends Command
{
    protected $signature = 'Generate:suppression-report';

    protected $description = 'Generate the Suppression report (CMD_DB SQL Server) and email it.';

    public function handle(): int
    {
        $this->info('[INFO] Suppression report: starting.');

        try {
            $connector = $this->initializeSqlServerConnector();
        } catch (\Throwable $e) {
            $this->error('Failed to initialize SQL Server connector: ' . $e->getMessage());
            Log::error('GenerateSuppressionReport: connector init failed', ['exception' => $e]);
            return Command::FAILURE;
        }

            $start = date('Y-m-01', strtotime('-1 year'));
        $label = date('m/d/Y');

        $this->info("[INFO] Suppression contacts created since: {$start}.");

        try {
            $rows = $this->fetchContacts($connector, $start);
            $this->info('[INFO] Suppression rows: ' . count($rows));

            if (empty($rows)) {
                $this->warn('[WARN] No suppression contacts. Skipping workbook and email.');
                Log::info('GenerateSuppressionReport: no data.', ['start' => $start]);
                return Command::SUCCESS;
            }

            $formatter = new Formatter();
            $result = $formatter->buildWorkbook($rows, $label);
            $this->info("[INFO] Suppression report written to {$result['path']}");

            $formatter->sendReport($connector, $result['path'], $result['filename'], $label, $this);

            if (is_file($result['path'])) {
                @unlink($result['path']);
            }
        } catch (\Throwable $e) {
            $this->error('Suppression Report failed: ' . $e->getMessage());
            Log::error('GenerateSuppressionReport: report failed', ['exception' => $e]);
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Contacts created since the window start, ordered by campaign then client.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchContacts(DBConnector $connector, string $start): array
    {
        $start = $this->esc($start);

        $sql = "
            SELECT
                Campaign   AS DropName,
                Client     AS FullName,
                Address_1  AS Address1,
                City,
                State,
                Zip
            FROM dbo.TblContacts
            WHERE Created_Date >= '{$start}'
            ORDER BY Campaign, Client
        ";

        $result = $connector->querySqlServer($sql);
        return $result['data'] ?? [];
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
