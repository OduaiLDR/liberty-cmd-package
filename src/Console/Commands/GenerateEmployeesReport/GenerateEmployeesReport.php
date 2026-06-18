<?php

namespace Cmd\Reports\Console\Commands\GenerateEmployeesReport;

use Cmd\Reports\Services\DBConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Generate the Employees Report (active employees from CommissionDatabase.dbo.TblEmployees)
 * and email it as an .xlsx attachment.
 *
 * Columns: Employee_Name, Access_Level, Location, Company
 * Filter:  Term_Date IS NULL (active employees only)
 * Sort:    Location, Company, Employee_Name — NULLs last in each tier
 * NULLs:   rendered as blank cells, highlighted yellow in the workbook
 */
class GenerateEmployeesReport extends Command
{
    protected $signature = 'Generate:employees-report
        {--force : Send even when no Location/Company values are missing}';

    protected $description = 'Generate the Employees Report (active TblEmployees rows) and email it.';

    /** Marker file path: prevents double-sending on a single calendar day. */
    private const SENT_MARKER_PATH = 'employees_report_last_sent.txt';

    public function handle(): int
    {
        $this->info('[INFO] Employees Report: starting.');

        try {
            $connector = $this->initializeSqlServerConnector();
        } catch (\Throwable $e) {
            $this->error('Failed to initialize SQL Server connector: ' . $e->getMessage());
            Log::error('GenerateEmployeesReport: connector init failed', ['exception' => $e]);
            return Command::FAILURE;
        }

        $label = date('m/d/Y');

        $force = (bool) $this->option('force');

        try {
            $rows = $this->fetchEmployees($connector);
            $this->info('[INFO] Active employees: ' . count($rows));

            if (empty($rows)) {
                $this->warn('[WARN] No active employees. Skipping workbook and email.');
                Log::info('GenerateEmployeesReport: no data.');
                return Command::SUCCESS;
            }

            [$missingLocations, $missingCompanies] = $this->countMissing($rows);
            $this->info("[INFO] Missing Locations: {$missingLocations}, Missing Companies: {$missingCompanies}");

            // Send-decision: --force always sends; otherwise send only when there is missing data.
            if (!$force && ($missingLocations + $missingCompanies) === 0) {
                $this->info('[INFO] No missing data and --force not set. Skipping email.');
                Log::info('GenerateEmployeesReport: skipped (no missing data, --force not set).');
                return Command::SUCCESS;
            }

            // De-dup guard: skip if already sent today (per marker file).
            if ($this->wasSentToday()) {
                $this->info('[INFO] Already sent today (per marker). Skipping.');
                Log::info('GenerateEmployeesReport: skipped (already sent today).');
                return Command::SUCCESS;
            }

            $formatter = new Formatter();
            $result    = $formatter->buildWorkbook($rows, $label);
            $this->info("[INFO] Employees report written to {$result['path']}");

            $sent = $formatter->sendReport(
                $connector,
                $result['path'],
                $result['filename'],
                $label,
                $missingLocations,
                $missingCompanies,
                $this
            );

            if (is_file($result['path'])) {
                @unlink($result['path']);
            }

            if ($sent) {
                $this->markSentToday();
            }
        } catch (\Throwable $e) {
            $this->error('Employees Report failed: ' . $e->getMessage());
            Log::error('GenerateEmployeesReport: report failed', ['exception' => $e]);
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Count rows whose Location / Company is null or trimmed-empty.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{0:int, 1:int}  [missingLocations, missingCompanies]
     */
    private function countMissing(array $rows): array
    {
        $missingLocations = 0;
        $missingCompanies = 0;

        foreach ($rows as $row) {
            if ($this->isBlank($row['Location'] ?? null)) {
                $missingLocations++;
            }
            if ($this->isBlank($row['Company'] ?? null)) {
                $missingCompanies++;
            }
        }

        return [$missingLocations, $missingCompanies];
    }

    private function isBlank($value): bool
    {
        return $value === null || trim((string) $value) === '';
    }

    private function wasSentToday(): bool
    {
        $path = storage_path('app/' . self::SENT_MARKER_PATH);
        if (!is_file($path)) {
            return false;
        }
        $contents = trim((string) @file_get_contents($path));
        return $contents === date('Y-m-d');
    }

    private function markSentToday(): void
    {
        $path = storage_path('app/' . self::SENT_MARKER_PATH);
        @file_put_contents($path, date('Y-m-d'));
    }

    /**
     * Active employees (Term_Date IS NULL), sorted by Location, Company, Employee_Name.
     * NULLs sort LAST in each tier (CASE WHEN ... IS NULL THEN 1 ELSE 0 END).
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchEmployees(DBConnector $connector): array
    {
        $sql = "
            SELECT
                Employee_Name,
                Access_Level,
                Location,
                Company
            FROM dbo.TblEmployees
            WHERE Term_Date IS NULL
            ORDER BY
                CASE WHEN Location      IS NULL OR Location      = '' THEN 1 ELSE 0 END, Location,
                CASE WHEN Company       IS NULL OR Company       = '' THEN 1 ELSE 0 END, Company,
                CASE WHEN Employee_Name IS NULL OR Employee_Name = '' THEN 1 ELSE 0 END, Employee_Name
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
}
