<?php

namespace Cmd\Reports\Console\Commands\GenerateConsumerAffairsSettlementReport;

use Cmd\Reports\Services\DBConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class GenerateConsumerAffairsSettlementReport extends Command
{
    protected $signature = 'Generate:consumer-affairs-settlement-report';

    protected $description = 'Generate Consumer Affairs settlement report (Snowflake + SQL Server) and email it.';

    public function handle(): int
    {
        $this->info('[INFO] Consumer Affairs Settlement report: starting.');

        try {
            $sqlConnector = $this->initializeSqlServerConnector();
        } catch (\Throwable $e) {
            $this->error('Failed to initialize SQL Server connector: ' . $e->getMessage());
            Log::error('GenerateConsumerAffairsSettlementReport: SQL Server init failed', ['exception' => $e]);
            return Command::FAILURE;
        }

        try {
            $snowflake = $this->initializeSnowflakeConnector();
        } catch (\Throwable $e) {
            $this->error('Failed to initialize Snowflake connector: ' . $e->getMessage());
            Log::error('GenerateConsumerAffairsSettlementReport: Snowflake init failed', ['exception' => $e]);
            return Command::FAILURE;
        }

        $startDate = date('Y-m-01', strtotime('first day of last month'));
        $endDate = date('Y-m-t', strtotime('last day of last month'));

        $poorRatings = $this->loadPoorRatings($sqlConnector);

        $sql = "
            SELECT *
            FROM (
                SELECT
                    c.PHONE,
                    c.PHONE2,
                    c.PHONE3,
                    c.PHONE4,
                    c.EMAIL,
                    c.FIRSTNAME,
                    c.LASTNAME,
                    c.CITY,
                    c.STATE,
                    c.ID,
                    '(800) 756-8447' AS CUSTOMER_SERVICE,
                    c.ENROLLED_DATE,
                    'Liberty Debt Relief' AS COMPANY_INFO,
                    'Debt Settlement' AS ADDITIONAL_INFO,
                    NULL AS PRODUCT_INFO,
                    c.ENROLLED_DATE AS ENROLLMENT_DATE,
                    d.SETTLEMENTS,
                    cls.TITLE AS ENROLLMENT_STATUS,
                    ROW_NUMBER() OVER (PARTITION BY cs.CONTACT_ID ORDER BY cs.STAMP DESC) AS N
                FROM CONTACTS AS c
                LEFT JOIN (
                    SELECT CONTACT_ID, COUNT(*) AS SETTLEMENTS
                    FROM DEBTS
                    WHERE ENROLLED = 1
                      AND SETTLED = 1
                      AND SETTLEMENT_DATE >= '{$this->esc($startDate)}'
                      AND SETTLEMENT_DATE <= '{$this->esc($endDate)}'
                    GROUP BY CONTACT_ID
                ) AS d ON c.ID = d.CONTACT_ID
                LEFT JOIN ENROLLMENT_PLAN AS ep ON c.ID = ep.CONTACT_ID
                LEFT JOIN ENROLLMENT_DEFAULTS2 AS ed ON ep.PLAN_ID = ed.ID
                LEFT JOIN CONTACTS_STATUS AS cs ON c.ID = cs.CONTACT_ID
                LEFT JOIN CONTACTS_LEAD_STATUS AS cls ON cs.STATUS_ID = cls.ID
                WHERE c.ENROLLED = 1
                  AND c.ID NOT IN (
                      SELECT CONTACT_ID
                      FROM DEBTS
                      WHERE ENROLLED = 1
                        AND SETTLED = 0
                        AND HAS_SUMMONS = 1
                  )
                  AND ed.TITLE LIKE '%LDR%'
            )
            WHERE N = 1
            ORDER BY SETTLEMENTS DESC, ENROLLMENT_DATE DESC
        ";

        $result = $snowflake->query($sql);
        $rows = $result['data'] ?? [];

        $formatter = new Formatter();
        $report = $formatter->buildWorkbook($rows, $poorRatings);
        $this->info('[INFO] CSV written to: ' . $report['csvPath']);
        $this->info('[INFO] IDs written to: ' . $report['idsPath']);
        $formatter->sendReport($sqlConnector, $report, $this);

        return Command::SUCCESS;
    }

    protected function loadPoorRatings(DBConnector $connector): array
    {
        $result = $connector->querySqlServer('SELECT LLG_ID FROM TblPoorRatings');
        $rows = $result['data'] ?? [];
        $set = [];

        foreach ($rows as $row) {
            $llg = (string) ($row['LLG_ID'] ?? '');
            if ($llg === '') {
                continue;
            }
            $id = str_replace('LLG-', '', $llg);
            if ($id !== '') {
                $set[$id] = true;
            }
        }

        return $set;
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

    protected function esc(string $value): string
    {
        return str_replace("'", "''", $value);
    }
}
