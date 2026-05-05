<?php

namespace Cmd\Reports\Console\Commands\GenerateCompanyStatsReport;

use Cmd\Reports\Services\DBConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class GenerateCompanyStatsReport extends Command
{
    protected $signature = 'Generate:company-stats-report';

    protected $description = 'Generate company stats report (Snowflake) and email it.';

    public function handle(): int
    {
        $this->info('[INFO] Company stats report: starting.');

        try {
            $snowflakeLdr = $this->initializeSnowflakeConnector('ldr');
            $snowflakePlaw = $this->initializeSnowflakeConnector('plaw');
            $sqlServer = $this->initializeSqlServerConnector();
        } catch (\Throwable $e) {
            $this->error('Failed to initialize connectors: ' . $e->getMessage());
            Log::error('GenerateCompanyStatsReport: connector init failed', ['exception' => $e]);
            return Command::FAILURE;
        }

        $yearStart = date('Y-01-01');
        $monthsSoFar = max((int) date('n') - 1, 1);
        $formatter = new Formatter();

        // LDR report
        $this->info('[INFO] Generating LDR report...');
        $ldrStats = [
            'LDR' => $this->buildStatsForFilter($snowflakeLdr, $this->buildClientFilterLdr(), $yearStart, $monthsSoFar),
        ];
        $ldrBody = $formatter->buildHtmlBody($ldrStats);
        $formatter->sendLdrReport('Company Stats Report - LDR', $ldrBody, $sqlServer, $this);

        // Paramount Law report
        $this->info('[INFO] Generating Paramount Law report...');
        $paramountStats = [
            'Paramount Law' => $this->buildStatsForFilter($snowflakeLdr, $this->buildClientFilterParamount(), $yearStart, $monthsSoFar),
        ];
        $paramountBody = $formatter->buildHtmlBody($paramountStats);
        $formatter->sendLdrReport('Company Stats Report - Paramount Law', $paramountBody, $sqlServer, $this);

        // Progress Law report (uses PLAW Snowflake connection)
        $this->info('[INFO] Generating Progress Law report...');
        $progressStats = [
            'Progress Law' => $this->buildStatsForFilter($snowflakePlaw, $this->buildClientFilterProgress(), $yearStart, $monthsSoFar),
        ];
        $progressBody = $formatter->buildHtmlBody($progressStats);
        $formatter->sendProgressReport($progressBody, $sqlServer, $this);

        return Command::SUCCESS;
    }

    private function buildStatsForFilter(DBConnector $snowflake, string $clientFilter, string $yearStart, int $monthsSoFar): array
    {
        $stats = [];

        $total = $this->snowflakeScalar($snowflake, "
            SELECT COUNT(*)
            FROM CONTACTS
            WHERE ENROLLED = 1
              AND {$clientFilter}
        ");
        $ytd = $this->snowflakeScalar($snowflake, "
            SELECT COUNT(*)
            FROM CONTACTS
            WHERE ENROLLED = 1
              AND {$clientFilter}
              AND ENROLLED_DATE >= '{$this->esc($yearStart)}'
        ");
        $this->addStat($stats, 'Enrolled Clients', $total, $ytd, 'count');

        $total = $this->snowflakeScalar($snowflake, "
            SELECT COUNT(*)
            FROM CONTACTS
            WHERE {$clientFilter}
              AND ID IN (
                  SELECT CONTACT_ID
                  FROM DEBTS
                  WHERE SETTLED = 1
              )
        ");
        $ytd = $this->snowflakeScalar($snowflake, "
            SELECT COUNT(*)
            FROM CONTACTS
            WHERE {$clientFilter}
              AND ID IN (
                  SELECT CONTACT_ID
                  FROM DEBTS
                  WHERE SETTLED = 1
                    AND SETTLEMENT_DATE >= '{$this->esc($yearStart)}'
              )
        ");
        $this->addStat($stats, 'Clients With Settlements', $total, $ytd, 'count');

        $total = $this->snowflakeScalar($snowflake, "
            SELECT SUM(ORIGINAL_DEBT_AMOUNT)
            FROM DEBTS
            WHERE ENROLLED = 1
              AND _FIVETRAN_DELETED = FALSE
              AND SETTLED = 0
              AND CONTACT_ID IN (SELECT ID FROM CONTACTS WHERE ENROLLED = 1 OR GRADUATED = 1)
              AND CONTACT_ID IN (SELECT ID FROM CONTACTS WHERE {$clientFilter})
        ");
        $ytd = $this->snowflakeScalar($snowflake, "
            SELECT SUM(ORIGINAL_DEBT_AMOUNT)
            FROM DEBTS
            WHERE ENROLLED = 1
              AND _FIVETRAN_DELETED = FALSE
              AND SETTLED = 0
              AND CONTACT_ID IN (SELECT ID FROM CONTACTS WHERE ENROLLED = 1 OR GRADUATED = 1)
              AND CONTACT_ID IN (
                  SELECT ID FROM CONTACTS
                  WHERE {$clientFilter}
                    AND ENROLLED_DATE >= '{$this->esc($yearStart)}'
              )
        ");
        $this->addStat($stats, 'Debt Under Management', $total, $ytd, 'money');

        $total = $this->snowflakeScalar($snowflake, "
            SELECT SUM(ORIGINAL_DEBT_AMOUNT)
            FROM DEBTS
            WHERE ENROLLED = 1
              AND _FIVETRAN_DELETED = FALSE
              AND COALESCE(SETTLEMENT_DATE, '2018-01-01') >= '2019-01-01'
              AND CONTACT_ID IN (SELECT ID FROM CONTACTS WHERE ENROLLED = 1 OR GRADUATED = 1)
              AND CONTACT_ID IN (SELECT ID FROM CONTACTS WHERE {$clientFilter})
        ");
        $ytd = $this->snowflakeScalar($snowflake, "
            SELECT SUM(ORIGINAL_DEBT_AMOUNT)
            FROM DEBTS
            WHERE ENROLLED = 1
              AND _FIVETRAN_DELETED = FALSE
              AND COALESCE(SETTLEMENT_DATE, '2018-01-01') >= '{$this->esc($yearStart)}'
              AND CONTACT_ID IN (SELECT ID FROM CONTACTS WHERE ENROLLED = 1 OR GRADUATED = 1)
              AND CONTACT_ID IN (SELECT ID FROM CONTACTS WHERE {$clientFilter})
        ");
        $this->addStat($stats, 'Debt Settled', $total, $ytd, 'money');

        $total = $this->snowflakeScalar($snowflake, "
            SELECT COUNT(*)
            FROM DEBTS
            WHERE ENROLLED = 1
              AND _FIVETRAN_DELETED = FALSE
              AND COALESCE(SETTLEMENT_DATE, '2018-01-01') >= '2019-01-01'
              AND CONTACT_ID IN (SELECT ID FROM CONTACTS WHERE {$clientFilter})
        ");
        $ytd = $this->snowflakeScalar($snowflake, "
            SELECT COUNT(*)
            FROM DEBTS
            WHERE ENROLLED = 1
              AND _FIVETRAN_DELETED = FALSE
              AND COALESCE(SETTLEMENT_DATE, '2018-01-01') >= '{$this->esc($yearStart)}'
              AND CONTACT_ID IN (SELECT ID FROM CONTACTS WHERE {$clientFilter})
        ");
        $this->addStat($stats, 'Accounts Settled', $total, $ytd, 'count');

        $total = $this->snowflakeScalar($snowflake, "
            SELECT AVG(SETTLEMENT_MONTHS)
            FROM (
                SELECT DATEDIFF(MONTH, ENROLLED_DATE, SETTLEMENT_DATE) AS SETTLEMENT_MONTHS,
                       SETTLEMENT_DATE,
                       ROW_NUMBER() OVER (PARTITION BY CONTACT_ID ORDER BY SETTLEMENT_DATE ASC) AS N
                FROM DEBTS
                WHERE ENROLLED = 1
                  AND _FIVETRAN_DELETED = FALSE
                  AND COALESCE(SETTLEMENT_DATE, '2018-01-01') >= '2019-01-01'
                  AND CONTACT_ID IN (SELECT ID FROM CONTACTS WHERE ENROLLED = 1 OR GRADUATED = 1)
                  AND CONTACT_ID IN (SELECT ID FROM CONTACTS WHERE {$clientFilter})
            )
            WHERE N = 1
              AND SETTLEMENT_MONTHS IS NOT NULL
              AND SETTLEMENT_DATE >= '2019-01-01'
        ");
        $ytd = $this->snowflakeScalar($snowflake, "
            SELECT AVG(SETTLEMENT_MONTHS)
            FROM (
                SELECT DATEDIFF(MONTH, ENROLLED_DATE, SETTLEMENT_DATE) AS SETTLEMENT_MONTHS,
                       SETTLEMENT_DATE,
                       ROW_NUMBER() OVER (PARTITION BY CONTACT_ID ORDER BY SETTLEMENT_DATE ASC) AS N
                FROM DEBTS
                WHERE ENROLLED = 1
                  AND _FIVETRAN_DELETED = FALSE
                  AND COALESCE(SETTLEMENT_DATE, '2018-01-01') >= '2019-01-01'
                  AND CONTACT_ID IN (SELECT ID FROM CONTACTS WHERE ENROLLED = 1 OR GRADUATED = 1)
                  AND CONTACT_ID IN (SELECT ID FROM CONTACTS WHERE {$clientFilter})
            )
            WHERE N = 1
              AND SETTLEMENT_MONTHS IS NOT NULL
              AND SETTLEMENT_DATE >= '{$this->esc($yearStart)}'
        ");
        $this->addStat($stats, 'Months To Settlement', $total, $ytd, 'decimal:2');

        $total = $this->snowflakeScalar($snowflake, "
            SELECT AVG(ORIGINAL_DEBT_AMOUNT_SUM)
            FROM (
                SELECT SUM(ORIGINAL_DEBT_AMOUNT) AS ORIGINAL_DEBT_AMOUNT_SUM
                FROM DEBTS
                WHERE ENROLLED = 1
                  AND _FIVETRAN_DELETED = FALSE
                  AND COALESCE(SETTLEMENT_DATE, '2018-01-01') >= '2019-01-01'
                  AND CONTACT_ID IN (SELECT ID FROM CONTACTS WHERE ENROLLED = 1 OR GRADUATED = 1)
                  AND CONTACT_ID IN (SELECT ID FROM CONTACTS WHERE {$clientFilter})
                GROUP BY YEAR(SETTLEMENT_DATE), MONTH(SETTLEMENT_DATE)
            )
        ");
        $ytd = $this->snowflakeScalar($snowflake, "
            SELECT AVG(ORIGINAL_DEBT_AMOUNT_SUM)
            FROM (
                SELECT SUM(ORIGINAL_DEBT_AMOUNT) AS ORIGINAL_DEBT_AMOUNT_SUM
                FROM DEBTS
                WHERE ENROLLED = 1
                  AND _FIVETRAN_DELETED = FALSE
                  AND COALESCE(SETTLEMENT_DATE, '2018-01-01') >= '{$this->esc($yearStart)}'
                  AND CONTACT_ID IN (SELECT ID FROM CONTACTS WHERE ENROLLED = 1 OR GRADUATED = 1)
                  AND CONTACT_ID IN (SELECT ID FROM CONTACTS WHERE {$clientFilter})
                GROUP BY YEAR(SETTLEMENT_DATE), MONTH(SETTLEMENT_DATE)
            )
        ");
        $this->addStat($stats, 'Average Debt Settled Per Month', $total, $ytd, 'money');

        $total = $this->snowflakeScalar($snowflake, "
            SELECT AVG(ACCOUNTS)
            FROM (
                SELECT COUNT(*) AS ACCOUNTS
                FROM DEBTS
                WHERE ENROLLED = 1
                  AND _FIVETRAN_DELETED = FALSE
                  AND COALESCE(SETTLEMENT_DATE, '2018-01-01') >= '2019-01-01'
                  AND CONTACT_ID IN (SELECT ID FROM CONTACTS WHERE {$clientFilter})
                GROUP BY YEAR(SETTLEMENT_DATE), MONTH(SETTLEMENT_DATE)
            )
        ");
        $ytdSum = $this->snowflakeScalar($snowflake, "
            SELECT SUM(ACCOUNTS)
            FROM (
                SELECT COUNT(*) AS ACCOUNTS
                FROM DEBTS
                WHERE ENROLLED = 1
                  AND _FIVETRAN_DELETED = FALSE
                  AND COALESCE(SETTLEMENT_DATE, '2018-01-01') >= '{$this->esc($yearStart)}'
                  AND CONTACT_ID IN (SELECT ID FROM CONTACTS WHERE {$clientFilter})
                GROUP BY YEAR(SETTLEMENT_DATE), MONTH(SETTLEMENT_DATE)
            )
        ");
        $ytd = $monthsSoFar > 0 ? ($ytdSum / $monthsSoFar) : 0;
        $this->addStat($stats, 'Average Accounts Settled Per Month', $total, $ytd, 'decimal:2');

        return $stats;
    }

    private function addStat(array &$stats, string $label, $total, $ytd, string $format): void
    {
        $stats[] = [
            'label' => $label,
            'total' => $total,
            'ytd' => $ytd,
            'format' => $format,
        ];
    }

    private function buildClientFilterLdr(): string
    {
        return "ID NOT IN (
            SELECT CONTACT_ID
            FROM ENROLLMENT_PLAN AS p
            LEFT JOIN ENROLLMENT_DEFAULTS2 AS e ON p.PLAN_ID = e.ID
            WHERE e.TITLE LIKE 'PLAW%' OR e.TITLE LIKE '%Paramount%' OR e.TITLE LIKE '%Progress%'
        )";
    }

    private function buildClientFilterParamount(): string
    {
        return "ID IN (
            SELECT CONTACT_ID
            FROM ENROLLMENT_PLAN AS p
            LEFT JOIN ENROLLMENT_DEFAULTS2 AS e ON p.PLAN_ID = e.ID
            WHERE e.TITLE LIKE 'PLAW%'
        )";
    }

    private function buildClientFilterProgress(): string
    {
        return "ID IN (
            SELECT CONTACT_ID
            FROM ENROLLMENT_PLAN AS p
            LEFT JOIN ENROLLMENT_DEFAULTS2 AS e ON p.PLAN_ID = e.ID
            WHERE e.TITLE LIKE '%Progress%'
        )";
    }

    private function snowflakeScalar(DBConnector $connector, string $sql)
    {
        $result = $connector->query($sql);
        $rows = $result['data'] ?? [];
        $row = $rows[0] ?? [];
        return (float) (reset($row) ?? 0);
    }


    private function initializeSnowflakeConnector(?string $preferredEnv = null): DBConnector
    {
        $candidates = $preferredEnv !== null
            ? [$preferredEnv]
            : ['ldr', 'plaw', 'lt'];
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

    private function initializeSqlServerConnector(): DBConnector
    {
        $connector = DBConnector::fromEnvironment('ldr');
        $connector->initializeSqlServer('ldr');
        return $connector;
    }

    private function esc(string $value): string
    {
        return str_replace("'", "''", $value);
    }
}
