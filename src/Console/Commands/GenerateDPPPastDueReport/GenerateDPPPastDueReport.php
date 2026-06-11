<?php

namespace Cmd\Reports\Console\Commands\GenerateDPPPastDueReport;

use Cmd\Reports\Services\DBConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class GenerateDPPPastDueReport extends Command
{
    protected $signature = 'Generate:dpp-past-due-report';

    protected $description = 'Generate DPP Past Due report (DPG transactions older than 45 days) for LDR and Progress Law (Snowflake) and email it.';

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

        // LDR
        try {
            $this->info('[INFO] Generating LDR DPP Past Due Report...');
            $ldrRows = $this->fetchPastDueRows($snowflakeLdr);
            $this->info('[INFO] LDR rows: ' . count($ldrRows));

            $ldrResult = $formatter->buildWorkbook($ldrRows, 'LDR');
            $this->info("[INFO] LDR DPP Past Due Report written to {$ldrResult['path']}");

            $formatter->sendReport($sqlConnector, $ldrResult['path'], $ldrResult['filename'], 'LDR', count($ldrRows), $this);

            if (is_file($ldrResult['path'])) {
                @unlink($ldrResult['path']);
            }
        } catch (\Throwable $e) {
            $this->error('LDR DPP Past Due Report failed: ' . $e->getMessage());
            Log::error('GenerateDPPPastDueReport: LDR failed', ['exception' => $e]);
        }

        // PLAW
        try {
            $this->info('[INFO] Generating PLAW DPP Past Due Report...');
            $plawRows = $this->fetchPastDueRows($snowflakePlaw);
            $this->info('[INFO] PLAW rows: ' . count($plawRows));

            $plawResult = $formatter->buildWorkbook($plawRows, 'PLAW');
            $this->info("[INFO] PLAW DPP Past Due Report written to {$plawResult['path']}");

            $formatter->sendReport($sqlConnector, $plawResult['path'], $plawResult['filename'], 'PLAW', count($plawRows), $this);

            if (is_file($plawResult['path'])) {
                @unlink($plawResult['path']);
            }
        } catch (\Throwable $e) {
            $this->error('PLAW DPP Past Due Report failed: ' . $e->getMessage());
            Log::error('GenerateDPPPastDueReport: PLAW failed', ['exception' => $e]);
        }

        return Command::SUCCESS;
    }

    private function fetchPastDueRows(DBConnector $snowflake): array
    {
        $cutoffDate = date('Y-m-d', strtotime('-' . self::PAST_DUE_DAYS . ' days'));

        $sql = "
            SELECT
                CONTACT_ID,
                AMOUNT,
                TO_VARCHAR(PROCESS_DATE::date, 'YYYY-MM-DD') AS PROCESS_DATE,
                TRANS_TYPE,
                ID
            FROM TRANSACTIONS
            WHERE TRANS_TYPE IN ('DPG')
              AND PROCESS_DATE <= '{$cutoffDate}'
              AND CLEARED_DATE IS NULL
              AND RETURNED_DATE IS NULL
              AND ACTIVE = 1
              AND CANCELLED = 0
              AND AMOUNT > 0
            ORDER BY PROCESS_DATE ASC
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
}
