<?php

namespace Cmd\Reports\Console\Commands\GenerateLookbackSummaryReport;

use Cmd\Reports\Services\DBConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class GenerateLookbackSummaryReport extends Command
{
    protected $signature = 'Generate:lookback-summary-report';

    protected $description = 'Generate Lookback Summary report (SQL Server) and email it using the Liberty CMD pattern.';

    public function handle(): int
    {
        $reportDate = date('Y-m-d');
        $this->info('[INFO] Lookback Summary report: initializing SQL Server connection...');

        try {
            $sqlConnector = $this->initializeSqlServerConnector();
        } catch (\Throwable $e) {
            $this->error('Failed to initialize SQL Server connector: ' . $e->getMessage());
            Log::error('GenerateLookbackSummaryReport: SQL init failed', ['exception' => $e]);
            return Command::FAILURE;
        }

        try {
            $rows = $this->fetchLookbackSummaryRows($sqlConnector);
            $rows = $this->injectLatestTrancheRow($sqlConnector, $rows);
        } catch (\Throwable $e) {
            $this->error('Failed to build Lookback Summary dataset: ' . $e->getMessage());
            Log::error('GenerateLookbackSummaryReport: data query failed', ['exception' => $e]);
            return Command::FAILURE;
        }

        if (empty($rows)) {
            $this->warn('[WARN] No Lookback Summary rows were returned.');
        } else {
            $this->info('[INFO] Lookback Summary rows: ' . count($rows));
        }

        $formatter = new Formatter();
        $subject = 'Lookback Summary Report - ' . date('m/d/Y');
        $body = $formatter->buildEmailBody($rows, $reportDate);

        $sent = $formatter->sendEmail($sqlConnector, $subject, $body, $this);

        if (!$sent) {
            $this->warn('[WARN] Lookback Summary email failed to send via TblReports and fallback recipients.');
        }

        return Command::SUCCESS;
    }

    /**
     * Fetch Lookback Summary rows from SQL Server (mirrors VBA query logic).
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchLookbackSummaryRows(DBConnector $connector): array
    {
        $sql = "
            SELECT
                d.Tranche,
                d.Payment_Date,
                COUNT(*) AS Total_Cancels,
                SUM(e1.Sold_Debt) * 0.08 AS Total_Lookback,
                SUM(e2.Sold_Debt) * 0.08 AS Pending_Lookback,
                SUM(e3.Sold_Debt) * 0.08 AS Completed_Lookback
            FROM TblEnrollment AS e1
            LEFT JOIN TblDebtTrancheSales AS d ON e1.Tranche = d.Tranche
            LEFT JOIN TblEnrollment AS e2 ON e1.LLG_ID = e2.LLG_ID AND e2.Lookback_Date IS NULL
            LEFT JOIN TblEnrollment AS e3 ON e1.LLG_ID = e3.LLG_ID AND e3.Lookback_Date IS NOT NULL
            WHERE e1.Debt_Sold_To = 'NGF'
              AND e1.Cancel_Date IS NOT NULL
              AND e1.Cancel_Date >= DATEADD(day, 3, d.Payment_Date)
              AND e1.Cancel_Date <= DATEADD(day, 57, d.Payment_Date)
            GROUP BY d.Tranche, d.Payment_Date
            ORDER BY d.Tranche DESC
        ";

        $result = $connector->querySqlServer($sql);

        return $result['data'] ?? [];
    }

    /**
     * Replicates VBA behavior by inserting the newest tranche (if not already present).
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function injectLatestTrancheRow(DBConnector $connector, array $rows): array
    {
        $latest = $connector->querySqlServer("
            SELECT TOP 1 Tranche, Payment_Date
            FROM TblDebtTrancheSales
            WHERE Tranche IS NOT NULL
            ORDER BY Tranche DESC
        ");

        $latestRow = $latest['data'][0] ?? null;
        if (!$latestRow) {
            return $rows;
        }

        $latestTranche = (int) ($latestRow['Tranche'] ?? 0);
        $currentTopTranche = isset($rows[0]['Tranche']) ? (int) $rows[0]['Tranche'] : null;

        if ($currentTopTranche === null || $latestTranche > $currentTopTranche) {
            array_unshift($rows, [
                'Tranche' => $latestTranche,
                'Payment_Date' => $latestRow['Payment_Date'] ?? null,
                'Total_Cancels' => 0,
                'Total_Lookback' => 0,
                'Pending_Lookback' => 0,
                'Completed_Lookback' => 0,
            ]);
        }

        return $rows;
    }

    private function initializeSqlServerConnector(): DBConnector
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

    private function esc(string $value): string
    {
        return str_replace("'", "''", $value);
    }
}
