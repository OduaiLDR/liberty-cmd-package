<?php

namespace Cmd\Reports\Console\Commands\GenerateAgentSummaryReport;

use Cmd\Reports\Services\DBConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class GenerateAgentSummaryReport extends Command
{
    protected $signature = 'Generate:agent-summary-report
        {--continuation : Run in continuation mode (single Data Source only)}
        {--start-date= : Period start date (YYYY-MM-DD); defaults to 1st of current month}
        {--end-date= : Period end date (YYYY-MM-DD); defaults to last day of current month}
        {--month-offset=0 : Offset for default period in months (e.g. -1 for previous month). Ignored if --start-date is set.}';

    protected $description = 'Generate Agent Summary report PDF(s) from LDR SQL Server and email them in one consolidated email.';

    public function handle(): int
    {
        $this->info('[INFO] Agent Summary Report: starting.');

        try {
            $sqlConnector = $this->initializeSqlServerConnector();
        } catch (\Throwable $e) {
            $this->error('Failed to initialize SQL Server connector: ' . $e->getMessage());
            Log::error('GenerateAgentSummaryReport: connector init failed', ['exception' => $e]);
            return Command::FAILURE;
        }

        $continuation = (bool) $this->option('continuation');
        [$startDate, $endDate] = $this->resolvePeriod();

        $this->info(sprintf(
            '[INFO] Period: %s to %s (Continuation: %s)',
            $startDate,
            $endDate,
            $continuation ? 'YES' : 'NO'
        ));

        $dataSources = $continuation
            ? ['All Data Sources']
            : array_keys(DataFetcher::DATA_SOURCE_FILTERS);

        $this->info('[INFO] Data sources to process: ' . count($dataSources));

        $fetcher = new DataFetcher();
        $builder = new ReportBuilder();
        $formatter = new Formatter();

        $generatedFiles = [];
        $generatedDataSources = [];

        foreach ($dataSources as $dataSource) {
            try {
                $this->info("[INFO] [{$dataSource}] Fetching metrics...");
                $rows = $fetcher->fetchAgentMetrics($sqlConnector, $startDate, $endDate, $dataSource);
                $this->info(sprintf('[INFO] [%s] Agents: %d', $dataSource, count($rows)));

                if (empty($rows)) {
                    $this->warn("[WARN] [{$dataSource}] No data. Skipping PDF.");
                    continue;
                }

                $this->info("[INFO] [{$dataSource}] Building PDF...");
                $result = $builder->build($rows, $dataSource, $startDate, $endDate, $continuation);
                $this->info("[INFO] [{$dataSource}] PDF written to {$result['path']}");

                $generatedFiles[] = [
                    'name' => $result['filename'],
                    'path' => $result['path'],
                ];
                $generatedDataSources[] = $dataSource;
            } catch (\Throwable $e) {
                $this->error("[{$dataSource}] failed: " . $e->getMessage());
                Log::error('GenerateAgentSummaryReport: data source failed', [
                    'data_source' => $dataSource,
                    'exception' => $e,
                ]);
            }
        }

        if (empty($generatedFiles)) {
            $this->warn('[WARN] No PDFs generated. Nothing to email.');
            return Command::SUCCESS;
        }

        $this->info(sprintf('[INFO] Sending one email with %d attachment%s...', count($generatedFiles), count($generatedFiles) === 1 ? '' : 's'));

        $formatter->sendCombinedReport(
            $sqlConnector,
            $generatedFiles,
            $generatedDataSources,
            $continuation,
            $startDate,
            $endDate,
            $this
        );

        // Cleanup temp files after sending
        foreach ($generatedFiles as $file) {
            if (is_file($file['path'])) {
                @unlink($file['path']);
            }
        }

        $this->info('[SUCCESS] Agent Summary Report run complete.');
        return Command::SUCCESS;
    }

    private function resolvePeriod(): array
    {
        $startOpt = (string) $this->option('start-date');
        $endOpt = (string) $this->option('end-date');

        if ($startOpt !== '' && $endOpt !== '') {
            $this->assertDate($startOpt, 'start-date');
            $this->assertDate($endOpt, 'end-date');
            return [$startOpt, $endOpt];
        }

        $offset = (int) $this->option('month-offset');
        $yesterday = strtotime('-1 day');
        $targetMonthStart = strtotime("{$offset} months", strtotime(date('Y-m-01', $yesterday)));
        $start = date('Y-m-01', $targetMonthStart);
        $end = date('Y-m-t', $targetMonthStart);
        return [$start, $end];
    }

    private function assertDate(string $value, string $label): void
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw new \InvalidArgumentException("Invalid --{$label} (expected YYYY-MM-DD): {$value}");
        }
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
}
