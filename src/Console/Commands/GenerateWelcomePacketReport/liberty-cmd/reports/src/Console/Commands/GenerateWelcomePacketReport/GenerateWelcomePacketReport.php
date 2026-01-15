<?php

namespace Cmd\Reports\Console\Commands\GenerateWelcomePacketReport;

use Cmd\Reports\Services\DBConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class GenerateWelcomePacketReport extends Command
{
    protected $signature = 'Generate:welcome-packet-report';

    protected $description = 'Generate Welcome Packet report (Snowflake) and email it.';

    public function handle(): int
    {
        $this->info('[INFO] Welcome Packet report: starting.');

        $sqlConnector = null;
        try {
            $sqlConnector = $this->initializeSqlServerConnector();
        } catch (\Throwable $e) {
            $this->warn('Recipient lookup failed; TblReports will be skipped. ' . $e->getMessage());
        }

        $startDate = now()->subDays(7);
        $endDate = now()->subDays(1);
        $startDateString = $startDate->format('Y-m-d');
        $endDateString = $endDate->format('Y-m-d');

        $sql = "
            SELECT *
            FROM (
                SELECT
                    CONCAT(c.FIRSTNAME, ' ', c.LASTNAME) AS CLIENT,
                    UPPER(TRIM(ed.TITLE)) AS PLAN,
                    c.ADDRESS,
                    c.ADDRESS2,
                    c.CITY,
                    c.STATE,
                    c.ZIP,
                    CONCAT('LLG-', c.ID) AS LLG_ID,
                    ROW_NUMBER() OVER (
                        PARTITION BY t.CONTACT_ID
                        ORDER BY t.CONTACT_ID ASC, t.CLEARED_DATE ASC
                    ) AS N,
                    TO_VARCHAR(t.CLEARED_DATE::date, 'YYYY-MM-DD') AS CLEARED_DATE
                FROM CONTACTS AS c
                LEFT JOIN ENROLLMENT_PLAN AS ep ON c.ID = ep.CONTACT_ID
                LEFT JOIN ENROLLMENT_DEFAULTS2 AS ed ON ep.PLAN_ID = ed.ID
                LEFT JOIN TRANSACTIONS AS t ON c.ID = t.CONTACT_ID
                WHERE t.TRANS_TYPE = 'D'
                ORDER BY t.CLEARED_DATE ASC
            )
            WHERE N = 1
              AND CLEARED_DATE >= '{$this->esc($startDateString)}'
              AND CLEARED_DATE <= '{$this->esc($endDateString)}'
            ORDER BY UPPER(CLIENT) ASC
        ";

        $formatter = new Formatter();

        // Query LDR Snowflake and build LDR report
        try {
            $ldrSnowflake = DBConnector::fromEnvironment('ldr');
            $ldrResult = $ldrSnowflake->query($sql);
            $ldrRows = $ldrResult['data'] ?? [];
            $this->info('[INFO] Welcome Packet LDR rows: ' . count($ldrRows));
            $ldrReport = $formatter->buildWorkbook($ldrRows, $startDate, $endDate, 'LDR');
            if ($ldrReport !== null) {
                $this->info("[INFO] Report written to {$ldrReport['path']}");
                $formatter->sendReport($sqlConnector, $ldrReport['path'], $ldrReport['filename'], $ldrReport['source'], $ldrReport['startDate'], $ldrReport['endDate'], $this);
            }
        } catch (\Throwable $e) {
            $this->error('LDR Snowflake query failed: ' . $e->getMessage());
            Log::error('GenerateWelcomePacketReport: LDR query failed', ['exception' => $e]);
        }

        // Query PLAW Snowflake and build PLAW report
        try {
            $plawSnowflake = DBConnector::fromEnvironment('plaw');
            $plawResult = $plawSnowflake->query($sql);
            $plawRows = $plawResult['data'] ?? [];
            $this->info('[INFO] Welcome Packet PLAW rows: ' . count($plawRows));
            $plawReport = $formatter->buildWorkbook($plawRows, $startDate, $endDate, 'PLAW');
            if ($plawReport !== null) {
                $this->info("[INFO] Report written to {$plawReport['path']}");
                $formatter->sendReport($sqlConnector, $plawReport['path'], $plawReport['filename'], $plawReport['source'], $plawReport['startDate'], $plawReport['endDate'], $this);
            }
        } catch (\Throwable $e) {
            $this->error('PLAW Snowflake query failed: ' . $e->getMessage());
            Log::error('GenerateWelcomePacketReport: PLAW query failed', ['exception' => $e]);
        }

        return Command::SUCCESS;
    }

    protected function initializeSnowflakeConnector(): DBConnector
    {
        $candidates = ['ldr', 'plaw', 'production', 'sandbox'];
        $errors = [];

        foreach ($candidates as $env) {
            try {
                return DBConnector::fromEnvironment($env);
            } catch (\Throwable $e) {
                $errors[] = "{$env}: {$e->getMessage()}";
            }
        }

        throw new \RuntimeException('Unable to initialize Snowflake connector. Tried: ' . implode('; ', $errors));
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
