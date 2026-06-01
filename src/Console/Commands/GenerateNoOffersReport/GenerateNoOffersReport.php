<?php

namespace Cmd\Reports\Console\Commands\GenerateNoOffersReport;

use Cmd\Reports\Services\DBConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class GenerateNoOffersReport extends Command
{
    protected $signature = 'Generate:no-offers-report';

    protected $description = 'Generate No Offers report (LT Snowflake) and email it.';

    public function handle(): int
    {
        $this->info('[INFO] No Offers report: starting.');

        try {
            $snowflakeLt = DBConnector::fromEnvironment('lt');
            $sqlConnector = $this->initializeSqlServerConnector();
        } catch (\Throwable $e) {
            $this->error('Failed to initialize connectors: ' . $e->getMessage());
            Log::error('GenerateNoOffersReport: connector init failed', ['exception' => $e]);
            return Command::FAILURE;
        }

        $reportDate = date('Y-m-d', strtotime('-1 day'));
        $this->info("[INFO] No Offers report date: {$reportDate}");

        $formatter = new Formatter();

        try {
            $rows = $this->fetchNoOffersRows($snowflakeLt, $reportDate);
            $this->info('[INFO] No Offers rows: ' . count($rows));

            if (empty($rows)) {
                $this->warn('[WARN] No data for ' . $reportDate . '. Skipping workbook and email.');
                Log::info('GenerateNoOffersReport: no data for report date.', ['report_date' => $reportDate]);
                return Command::SUCCESS;
            }

            $result = $formatter->buildWorkbook($rows, $reportDate);
            $this->info("[INFO] No Offers report written to {$result['path']}");

            $formatter->sendReport($sqlConnector, $result['path'], $result['filename'], $reportDate, $this);

            if (is_file($result['path'])) {
                @unlink($result['path']);
            }
        } catch (\Throwable $e) {
            $this->error('No Offers Report failed: ' . $e->getMessage());
            Log::error('GenerateNoOffersReport: report failed', ['exception' => $e]);
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function fetchNoOffersRows(DBConnector $snowflake, string $reportDate): array
    {
        $statuses = [
            'BK Partner Client',
            'Enrolled (Cancellation Pending EPF Hold)',
            'Enrolled (Reconsideration Pending)',
            'Enrolled Active (CSS)',
            'Enrolled Active (FDR)',
            'Enrolled Active (Saved)',
            'Funded',
            'LDR Enrolled',
            'LDR Enrolled (LUSA-APPROVED)',
            'LDR Enrolled (LUSA-App)',
            'LDR Enrolled (LUSA-DECLINED)',
            'LDR Enrolled (LUSA-FUNDED)',
            'LDR Enrolled (LUSA-PQ)',
            'LDR Enrolled (LUSA-WITHDRAWN)',
            'LDR Enrolled - (Offers Pending - 45 days)',
            'LDR Enrolled - (Offers Pending - 60 Days)',
            'LDR Enrolled - Offers Pending',
            'Lexington Law client',
            'PLAW Enrolled',
            'PLAW Enrolled (LUSA-APPROVED)',
            'PLAW Enrolled (LUSA-App)',
            'PLAW Enrolled (LUSA-DECLINED)',
            'PLAW Enrolled (LUSA-FUNDED)',
            'PLAW Enrolled (LUSA-PQ)',
            'PLAW Enrolled (LUSA-WITHDRAWN)',
            'Paused / Hold',
            'ProLaw Enrolled',
            'ProLaw Enrolled (LUSA-APPROVED)',
            'ProLaw Enrolled (LUSA-DECLINED)',
            'ProLaw Enrolled (LUSA-FUNDED)',
            'ProLaw Enrolled (LUSA-PQ)',
            'ProLaw Enrolled (LUSA-WITHDRAWN)',
            'ProLaw Enrolled (LUSA-app)',
            'AP (FU) 15+ Days',
            'ATC / NAP 10+ Days',
            'Contract Sent',
            'No offers',
            'Rejected',
            'Rejected (Approval Call Set DS)',
            'Rejected (Attempting to Contact DS)',
            'Rejected (Enrolled with Competitor)',
            'Rejected (NQ DS REQUEST)',
            'Rejected (New Accounts)',
            'Rejected (Not Interested DS)',
            'Rejected (Not Qualified DS)',
            'Rejected (Pitched DS)',
            'Approved',
            'Attorney Approved CFLN',
            'Attorney Denied CFLN',
            'Needs Work',
            'Pending Documents',
            'Submitted',
            'Welcome Call Ready',
        ];

        $escapedStatuses = array_map(function ($value) {
            return "'" . $this->esc($value) . "'";
        }, $statuses);
        $statusIn = implode(', ', $escapedStatuses);

        $sql = "
            SELECT *
            FROM (
                SELECT
                    c.FIRSTNAME,
                    c.LASTNAME,
                    c.ADDRESS,
                    c.CITY,
                    c.STATE,
                    c.ZIP,
                    cls.TITLE AS STATUS,
                    ROW_NUMBER() OVER (PARTITION BY cs.CONTACT_ID ORDER BY cs.STAMP DESC) AS N
                FROM CONTACTS AS c
                LEFT JOIN CONTACTS_STATUS AS cs ON c.ID = cs.CONTACT_ID
                LEFT JOIN CONTACTS_LEAD_STATUS AS cls ON cs.STATUS_ID = cls.ID
                LEFT JOIN CONTACTS_ASSIGNED AS ca ON c.ID = ca.CONTACT_ID
                WHERE TO_DATE(LEFT(ca.STAMP, 10)) = '{$this->esc($reportDate)}'
                  AND cls.TITLE IN ({$statusIn})
            )
            WHERE N = 1
            ORDER BY LASTNAME, FIRSTNAME
        ";

        $result = $snowflake->query($sql);
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
