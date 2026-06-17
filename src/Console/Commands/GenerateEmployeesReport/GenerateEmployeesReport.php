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
    protected $signature = 'Generate:employees-report';

    protected $description = 'Generate the Employees Report (active TblEmployees rows) and email it.';

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

        try {
            $rows = $this->fetchEmployees($connector);
            $this->info('[INFO] Active employees: ' . count($rows));

            if (empty($rows)) {
                $this->warn('[WARN] No active employees. Skipping workbook and email.');
                Log::info('GenerateEmployeesReport: no data.');
                return Command::SUCCESS;
            }

            $formatter = new Formatter();
            $result    = $formatter->buildWorkbook($rows, $label);
            $this->info("[INFO] Employees report written to {$result['path']}");

            $formatter->sendReport($connector, $result['path'], $result['filename'], $label, $this);

            if (is_file($result['path'])) {
                @unlink($result['path']);
            }
        } catch (\Throwable $e) {
            $this->error('Employees Report failed: ' . $e->getMessage());
            Log::error('GenerateEmployeesReport: report failed', ['exception' => $e]);
            return Command::FAILURE;
        }

        return Command::SUCCESS;
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
