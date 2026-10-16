<?php

declare(strict_types=1);

namespace Cmd\Reports\Console\Commands\GenerateMissingAssignmentsReport;

use Cmd\Reports\Services\DBConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class GenerateMissingAssignmentsReport extends Command
{
    protected $signature = 'Generate:missing-assignments-report
        {--dry-run : Preview rows only; no workbook, email, or database writes}
        {--no-email : Build and keep the workbook without sending email}';

    protected $description = "Report unassigned clients from the first of the prior Friday's month through that Friday, including Lending Tower IDs.";

    public function handle(): int
    {
        $window = $this->resolveDateWindow();
        $this->info("[INFO] Missing Assignments Report: {$window['start']} through {$window['end']} (inclusive).");

        try {
            $connector = $this->initializeSqlServerConnector();
            $rows = (new ReportData)->fetch($connector, $window['start'], $window['end_exclusive']);
            $this->info('[INFO] Clients requiring assignment: '.count($rows));
            $unresolved = count(array_filter($rows, static fn (array $row): bool => $row['lending_tower_id'] === ''));
            if ($unresolved > 0) {
                $this->warn("[WARN] Lending Tower ID unresolved for {$unresolved} clients; they remain in the report.");
            }

            if ($this->option('dry-run')) {
                $this->table(['Enrollment ID', 'Lending Tower ID', 'Client', 'Status', 'Created Date'], $rows);
                $this->info('[DRY RUN] No workbook, email, or database writes.');

                return Command::SUCCESS;
            }

            $formatter = $this->createFormatter();
            $file = $formatter->buildWorkbook($rows, $window);
            $this->info('[INFO] Workbook: '.$file['path']);
            if ($this->option('no-email')) {
                $this->info('[INFO] Email skipped; workbook kept.');

                return Command::SUCCESS;
            }

            if (!$formatter->sendReport($connector, $file, $window, count($rows), $unresolved)) {
                $this->error('[ERROR] No TblReports recipients or email failed. Workbook kept: '.$file['path']);

                return Command::FAILURE;
            }

            $this->info('[INFO] Missing Assignments Report sent.');
            if (is_file($file['path']) && !@unlink($file['path'])) {
                $this->warn('[WARN] Sent workbook could not be deleted: '.$file['path']);
            }

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Missing Assignments Report failed: '.$e->getMessage());
            Log::error('GenerateMissingAssignmentsReport: failed', ['exception' => $e]);

            return Command::FAILURE;
        }
    }

    /** @return array{start:string,end:string,end_exclusive:string,report_date:string} */
    private function resolveDateWindow(): array
    {
        $today = Carbon::today('America/Los_Angeles');
        $friday = $today->copy()->previous(Carbon::FRIDAY);

        return [
            'start' => $friday->copy()->startOfMonth()->toDateString(),
            'end' => $friday->toDateString(),
            'end_exclusive' => $friday->copy()->addDay()->toDateString(),
            'report_date' => $today->toDateString(),
        ];
    }

    protected function createFormatter(): Formatter
    {
        return new Formatter;
    }

    protected function initializeSqlServerConnector(): DBConnector
    {
        $errors = [];
        foreach (['ldr', 'plaw', 'production', 'sandbox'] as $env) {
            try {
                $connector = DBConnector::fromEnvironment($env);
                $connector->initializeSqlServer();

                return $connector;
            } catch (\Throwable $e) {
                $errors[] = "{$env}: {$e->getMessage()}";
            }
        }

        throw new \RuntimeException('Unable to initialize SQL Server connector. '.implode('; ', $errors));
    }
}
