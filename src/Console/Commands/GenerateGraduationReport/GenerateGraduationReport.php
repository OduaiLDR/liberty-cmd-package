<?php

namespace Cmd\Reports\Console\Commands\GenerateGraduationReport;

use Cmd\Reports\Services\DBConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class GenerateGraduationReport extends Command
{
    protected $signature = 'Generate:graduation-report';

    protected $description = 'Generate monthly Graduation report (CMD_DB SQL Server) and email it.';

    public function handle(): int
    {
        $this->info('[INFO] Graduation report: starting.');

        try {
            $sqlConnector = $this->initializeSqlServerConnector();
        } catch (\Throwable $e) {
            $this->error('Failed to initialize SQL Server connector: ' . $e->getMessage());
            Log::error('GenerateGraduationReport: connector init failed', ['exception' => $e]);
            return Command::FAILURE;
        }

        // Last calendar month range (the report always covers the previous month).
        $start = date('Y-m-01', strtotime('first day of last month'));
        $end = date('Y-m-t', strtotime('last day of last month'));
        $label = date('F Y', strtotime('first day of last month'));

        $this->info("[INFO] Graduation period: {$start} to {$end} ({$label}).");

        try {
            $grads = $this->fetchGraduates($sqlConnector, $start, $end);
            $this->info('[INFO] Graduated clients: ' . count($grads));

            if (empty($grads)) {
                $this->warn('[WARN] No graduated clients for ' . $label . '. Skipping workbook and email.');
                Log::info('GenerateGraduationReport: no data for period.', ['start' => $start, 'end' => $end]);
                return Command::SUCCESS;
            }

            $llgIds = array_values(array_filter(array_map(
                static fn ($row) => (string) ($row['LLG_ID'] ?? ''),
                $grads
            )));

            $debts = $this->fetchDebts($sqlConnector, $llgIds);
            $this->info('[INFO] Debt rows: ' . count($debts));

            $formatter = new Formatter();
            $result = $formatter->buildWorkbook($grads, $debts, $label);
            $this->info("[INFO] Graduation report written to {$result['path']}");

            $formatter->sendReport($sqlConnector, $result['path'], $result['filename'], $label, $this);

            if (is_file($result['path'])) {
                @unlink($result['path']);
            }
        } catch (\Throwable $e) {
            $this->error('Graduation Report failed: ' . $e->getMessage());
            Log::error('GenerateGraduationReport: report failed', ['exception' => $e]);
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Graduated clients for the period. Deduped by LLG_ID; requires an enrollment
     * match (mirrors the legacy VBA VLOOKUP-into-Enrollments drop-on-#N/A filter).
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchGraduates(DBConnector $connector, string $start, string $end): array
    {
        $start = $this->esc($start);
        $end = $this->esc($end);

        $sql = "
            SELECT
                p.LLG_ID,
                MAX(p.Client) AS Client,
                MAX(p.Completetion_Date) AS Grad_Date,
                MAX(e.Enrollment_Plan) AS Enrollment_Plan
            FROM dbo.TblProgramCompletions p
            INNER JOIN dbo.TblEnrollment e ON e.LLG_ID = p.LLG_ID
            WHERE p.Completetion_Date >= '{$start}'
              AND p.Completetion_Date <= '{$end}'
            GROUP BY p.LLG_ID
            ORDER BY Grad_Date ASC
        ";

        $result = $connector->querySqlServer($sql);
        return $result['data'] ?? [];
    }

    /**
     * Debt/settlement detail for the graduated clients.
     *
     * @param  array<int, string>  $llgIds
     * @return array<int, array<string, mixed>>
     */
    private function fetchDebts(DBConnector $connector, array $llgIds): array
    {
        if (empty($llgIds)) {
            return [];
        }

        $quoted = implode(', ', array_map(fn (string $id) => "'" . $this->esc($id) . "'", $llgIds));

        $sql = "
            SELECT
                LLG_ID,
                Creditor,
                Debt_Buyer,
                Settlement_ID,
                Account_Number,
                Original_Debt_Amount,
                Current_Amount,
                Settlement_Amount,
                Settlement_Date
            FROM dbo.TblSettlementsNGF
            WHERE LLG_ID IN ({$quoted})
        ";

        $result = $connector->querySqlServer($sql);
        return $result['data'] ?? [];
    }

    protected function initializeSqlServerConnector(): DBConnector
    {
        // DBConnector's constructor requires Snowflake creds, so build it from an
        // available env, then attach the SQL Server connection. Only querySqlServer()
        // is used by this report; the Snowflake side is never queried.
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
