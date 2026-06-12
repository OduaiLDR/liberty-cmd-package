<?php

namespace Cmd\Reports\Console\Commands\GenerateDPPPastDueReport;

use Cmd\Reports\Services\DBConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class GenerateDPPPastDueReport extends Command
{
    protected $signature = 'Generate:dpp-past-due-report';

    protected $description = 'Generate DPP Past Due report (PF and C transactions older than 45 days), split into Active/Graduated/Dropped sheets, for LDR and Progress Law (Snowflake).';

    private const PAST_DUE_DAYS = 45;

    public function handle(): int
    {
        $this->info('[INFO] DPP Past Due report: starting.');

        try {
            $snowflakeLdr = DBConnector::fromEnvironment('ldr');
            $snowflakePlaw = DBConnector::fromEnvironment('plaw');
            $sqlConnector = $this->initializeSqlServerConnector();
        } catch (\Throwable $e) {
            $this->error('Failed to initialize connectors: ' . $e->getMessage());
            Log::error('GenerateDPPPastDueReport: connector init failed', ['exception' => $e]);
            return Command::FAILURE;
        }

        $formatter = new Formatter();

        foreach (['LDR' => $snowflakeLdr, 'PLAW' => $snowflakePlaw] as $company => $snowflake) {
            try {
                $this->info("[INFO] Generating {$company} DPP Past Due Report...");
                $rows = $this->fetchPastDueRows($snowflake);
                $partitioned = $this->partitionByStatus($rows);

                $this->info(sprintf(
                    '[INFO] %s rows: Active=%d, Graduated=%d, Dropped=%d (total=%d)',
                    $company,
                    count($partitioned['Active']),
                    count($partitioned['Graduated']),
                    count($partitioned['Dropped']),
                    count($rows)
                ));

                $result = $formatter->buildWorkbook($partitioned, $company);
                $this->info("[INFO] {$company} workbook written to {$result['path']}");

                $formatter->sendReport(
                    $sqlConnector,
                    $result['path'],
                    $result['filename'],
                    $company,
                    $partitioned,
                    $this
                );

                if (is_file($result['path'])) {
                    @unlink($result['path']);
                }
            } catch (\Throwable $e) {
                $this->error("{$company} DPP Past Due Report failed: " . $e->getMessage());
                Log::error('GenerateDPPPastDueReport: failed', ['company' => $company, 'exception' => $e]);
            }
        }

        return Command::SUCCESS;
    }

    private function fetchPastDueRows(DBConnector $snowflake): array
    {
        $cutoffDate = date('Y-m-d', strtotime('-' . self::PAST_DUE_DAYS . ' days'));

        $sql = "
            SELECT
                t.CONTACT_ID,
                CONCAT(COALESCE(c.FIRSTNAME, ''), ' ', COALESCE(c.LASTNAME, '')) AS CONTACT_NAME,
                t.AMOUNT,
                TO_VARCHAR(t.PROCESS_DATE::date, 'YYYY-MM-DD') AS PROCESS_DATE,
                t.TRANS_TYPE,
                CASE
                    WHEN c.DROPPED = 1 THEN 'Dropped'
                    WHEN c.GRADUATED = 1 THEN 'Graduated'
                    WHEN c.ENROLLED = 1 THEN 'Active'
                    ELSE 'Other'
                END AS STATUS_CATEGORY
            FROM TRANSACTIONS t
            LEFT JOIN CONTACTS c ON t.CONTACT_ID = c.ID
            WHERE t.TRANS_TYPE IN ('PF', 'C')
              AND t.PROCESS_DATE <= '{$cutoffDate}'
              AND t.CLEARED_DATE IS NULL
              AND t.RETURNED_DATE IS NULL
              AND t.ACTIVE = 1
              AND t.CANCELLED = 0
              AND t.AMOUNT > 0
              AND (c.DROPPED = 1 OR c.GRADUATED = 1 OR c.ENROLLED = 1)
            ORDER BY t.PROCESS_DATE ASC
        ";

        $result = $snowflake->query($sql);
        return $result['data'] ?? [];
    }

    private function partitionByStatus(array $rows): array
    {
        $partitioned = [
            'Active' => [],
            'Graduated' => [],
            'Dropped' => [],
        ];

        foreach ($rows as $row) {
            $category = $row['STATUS_CATEGORY'] ?? null;
            if (isset($partitioned[$category])) {
                $partitioned[$category][] = $row;
            }
        }

        return $partitioned;
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
