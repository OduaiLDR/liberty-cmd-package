<?php

namespace Cmd\Reports\Console\Commands\GeneratePauseHoldReport;

use Cmd\Reports\Services\DBConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class GeneratePauseHoldReport extends Command
{
    protected $signature = 'Generate:pause-hold-report';

    protected $description = 'Generate Pause Hold report for LDR and Progress Law (Snowflake) and email it.';

    private const STATUS_IDS = [
        'LDR' => 378207,
        'PLAW' => 378149,
    ];

    public function handle(): int
    {
        $this->info('[INFO] Pause Hold report: starting.');

        try {
            $snowflakeLdr = DBConnector::fromEnvironment('ldr');
            $snowflakePlaw = DBConnector::fromEnvironment('plaw');
            $sqlConnector = $this->initializeSqlServerConnector();
        } catch (\Throwable $e) {
            $this->error('Failed to initialize connectors: ' . $e->getMessage());
            Log::error('GeneratePauseHoldReport: connector init failed', ['exception' => $e]);
            return Command::FAILURE;
        }

        $formatter = new Formatter();

        // Generate LDR Pause Hold Report
        try {
            $this->info('[INFO] Generating LDR Pause Hold Report (status ID: ' . self::STATUS_IDS['LDR'] . ')...');
            $ldrRows = $this->fetchPauseHoldRows($snowflakeLdr, self::STATUS_IDS['LDR']);
            $this->info('[INFO] LDR Pause Hold rows: ' . count($ldrRows));

            if (empty($ldrRows)) {
                $this->warn('[WARN] No LDR Pause Hold contacts. Skipping workbook and email.');
                Log::info('GeneratePauseHoldReport: no LDR data.');
            } else {
                $ldrResult = $formatter->buildWorkbook($ldrRows, 'LDR');
                $this->info("[INFO] LDR Pause Hold Report written to {$ldrResult['path']}");
                $formatter->sendReport($sqlConnector, $ldrResult['path'], $ldrResult['filename'], 'LDR', $this);
                if (is_file($ldrResult['path'])) {
                    @unlink($ldrResult['path']);
                }
            }
        } catch (\Throwable $e) {
            $this->error('LDR Pause Hold Report failed: ' . $e->getMessage());
            Log::error('GeneratePauseHoldReport: LDR report failed', ['exception' => $e]);
        }

        // Generate PLAW Pause Hold Report
        try {
            $this->info('[INFO] Generating PLAW Pause Hold Report (status ID: ' . self::STATUS_IDS['PLAW'] . ')...');
            $plawRows = $this->fetchPauseHoldRows($snowflakePlaw, self::STATUS_IDS['PLAW']);
            $this->info('[INFO] PLAW Pause Hold rows: ' . count($plawRows));

            if (empty($plawRows)) {
                $this->warn('[WARN] No PLAW Pause Hold contacts. Skipping workbook and email.');
                Log::info('GeneratePauseHoldReport: no PLAW data.');
            } else {
                $plawResult = $formatter->buildWorkbook($plawRows, 'PLAW');
                $this->info("[INFO] PLAW Pause Hold Report written to {$plawResult['path']}");
                $formatter->sendReport($sqlConnector, $plawResult['path'], $plawResult['filename'], 'PLAW', $this);
                if (is_file($plawResult['path'])) {
                    @unlink($plawResult['path']);
                }
            }
        } catch (\Throwable $e) {
            $this->error('PLAW Pause Hold Report failed: ' . $e->getMessage());
            Log::error('GeneratePauseHoldReport: PLAW report failed', ['exception' => $e]);
        }

        return Command::SUCCESS;
    }

    private function fetchPauseHoldRows(DBConnector $snowflake, int $statusId): array
    {
        $sql = "
            SELECT * FROM (
                SELECT
                    CONTACT_ID,
                    TO_VARCHAR(STAMP::date, 'YYYY-MM-DD') AS STATUS_DATE,
                    STATUS_ID,
                    DATEDIFF(DAY, STAMP, CURRENT_DATE) AS DAYS,
                    ROW_NUMBER() OVER (PARTITION BY CONTACT_ID ORDER BY STAMP DESC) AS N
                FROM CONTACTS_STATUS
            )
            WHERE N = 1
              AND STATUS_ID = {$statusId}
            ORDER BY STATUS_DATE ASC
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
