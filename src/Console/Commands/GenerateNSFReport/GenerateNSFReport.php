<?php

namespace Cmd\Reports\Console\Commands\GenerateNSFReport;

use Cmd\Reports\Services\DBConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class GenerateNSFReport extends Command
{
    protected $signature = 'Generate:nsf-report';

    protected $description = 'Generate NSF report for LDR and Progress Law (Snowflake) and email it.';

    public function handle(): int
    {
        $this->info('[INFO] NSF report: starting.');

        try {
            $snowflakeLdr = DBConnector::fromEnvironment('ldr');
            $snowflakePlaw = DBConnector::fromEnvironment('plaw');
            $sqlConnector = $this->initializeSqlServerConnector();
        } catch (\Throwable $e) {
            $this->error('Failed to initialize connectors: ' . $e->getMessage());
            Log::error('GenerateNSFReport: connector init failed', ['exception' => $e]);
            return Command::FAILURE;
        }

        $formatter = new Formatter();

        // Generate LDR NSF Report
        try {
            $this->info('[INFO] Generating LDR NSF Report...');
            $ldrRows = $this->fetchNSFRows($snowflakeLdr);
            $this->info('[INFO] LDR NSF rows: ' . count($ldrRows));

            if (empty($ldrRows)) {
                $this->warn('[WARN] No LDR NSF data. Skipping workbook and email.');
                Log::info('GenerateNSFReport: no LDR data.');
            } else {
                $ldrResult = $formatter->buildWorkbook($ldrRows, 'LDR');
                $this->info("[INFO] LDR NSF Report written to {$ldrResult['path']}");
                $formatter->sendReport($sqlConnector, $ldrResult['path'], $ldrResult['filename'], 'LDR', $this);
                if (is_file($ldrResult['path'])) {
                    @unlink($ldrResult['path']);
                }
            }
        } catch (\Throwable $e) {
            $this->error('LDR NSF Report failed: ' . $e->getMessage());
            Log::error('GenerateNSFReport: LDR report failed', ['exception' => $e]);
        }

        // Generate PLAW NSF Report
        try {
            $this->info('[INFO] Generating PLAW NSF Report...');
            $plawRows = $this->fetchNSFRows($snowflakePlaw);
            $this->info('[INFO] PLAW NSF rows: ' . count($plawRows));

            if (empty($plawRows)) {
                $this->warn('[WARN] No PLAW NSF data. Skipping workbook and email.');
                Log::info('GenerateNSFReport: no PLAW data.');
            } else {
                $plawResult = $formatter->buildWorkbook($plawRows, 'PLAW');
                $this->info("[INFO] PLAW NSF Report written to {$plawResult['path']}");
                $formatter->sendReport($sqlConnector, $plawResult['path'], $plawResult['filename'], 'PLAW', $this);
                if (is_file($plawResult['path'])) {
                    @unlink($plawResult['path']);
                }
            }
        } catch (\Throwable $e) {
            $this->error('PLAW NSF Report failed: ' . $e->getMessage());
            Log::error('GenerateNSFReport: PLAW report failed', ['exception' => $e]);
        }

        return Command::SUCCESS;
    }

    private function fetchNSFRows(DBConnector $snowflake): array
    {
        $sql = "
            SELECT * FROM (
                SELECT
                    c.ID,
                    CONCAT(c.FIRSTNAME, ' ', c.LASTNAME) AS CONTACT,
                    TO_VARCHAR(c.ENROLLED_DATE::date, 'YYYY-MM-DD') AS ENROLLED_DATE,
                    d.ENROLLED_DEBT,
                    cls.TITLE AS STATUS,
                    TO_VARCHAR(s.STAMP::date, 'YYYY-MM-DD') AS STATUS_DATE,
                    DATEDIFF(DAY, s.STAMP, CURRENT_DATE) AS DAYS,
                    c.PHONE  AS PHONE_1,
                    c.PHONE2 AS PHONE_2,
                    c.PHONE3 AS PHONE_3,
                    c.PHONE4 AS PHONE_4,
                    ROW_NUMBER() OVER (PARTITION BY c.ID ORDER BY s.STAMP DESC) AS N
                FROM CONTACTS c
                LEFT JOIN CONTACTS_STATUS s ON c.ID = s.CONTACT_ID
                LEFT JOIN CONTACTS_LEAD_STATUS cls ON s.STATUS_ID = cls.ID
                LEFT JOIN (
                    SELECT CONTACT_ID, SUM(ORIGINAL_DEBT_AMOUNT) AS ENROLLED_DEBT
                    FROM DEBTS
                    WHERE ENROLLED = 1 AND _FIVETRAN_DELETED = FALSE
                    GROUP BY CONTACT_ID
                ) d ON c.ID = d.CONTACT_ID
                WHERE c.DEL = 0
                  AND c.ENROLLED = 1
                  AND COALESCE(c.FIRSTNAME, '') <> ''
                  AND s.STAMP IS NOT NULL
                  AND c.ID IN (
                      SELECT c2.ID
                      FROM CONTACTS c2
                      LEFT JOIN CONTACTS_STATUS s2 ON c2.ID = s2.CONTACT_ID
                      LEFT JOIN CONTACTS_LEAD_STATUS cls2 ON s2.STATUS_ID = cls2.ID
                      WHERE cls2.TITLE LIKE '%NSF%'
                  )
            )
            WHERE N = 1
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
