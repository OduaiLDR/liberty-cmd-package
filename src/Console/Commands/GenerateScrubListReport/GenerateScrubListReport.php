<?php

namespace Cmd\Reports\Console\Commands\GenerateScrubListReport;

use Cmd\Reports\Services\DBConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class GenerateScrubListReport extends Command
{
    protected $signature = 'Generate:scrub-list-report';

    protected $description = 'Generate Scrub List reports for LDR and PLAW (Snowflake) and email them.';

    public function handle(): int
    {
        $this->info("[INFO] Scrub List report: starting.");

        try {
            $snowflakeLdr = $this->initializeSnowflakeConnector('ldr');
        } catch (\Throwable $e) {
            $this->error('Failed to initialize LDR Snowflake connector: ' . $e->getMessage());
            Log::error('GenerateScrubListReport: LDR Snowflake init failed', ['exception' => $e]);
            return Command::FAILURE;
        }

        try {
            $snowflakePlaw = $this->initializeSnowflakeConnector('plaw');
        } catch (\Throwable $e) {
            $this->error('Failed to initialize PLAW Snowflake connector: ' . $e->getMessage());
            Log::error('GenerateScrubListReport: PLAW Snowflake init failed', ['exception' => $e]);
            return Command::FAILURE;
        }

        try {
            $sqlConnector = $this->initializeSqlServerConnector();
        } catch (\Throwable $e) {
            $this->error('Failed to initialize SQL Server connector: ' . $e->getMessage());
            Log::error('GenerateScrubListReport: SQL Server init failed', ['exception' => $e]);
            return Command::FAILURE;
        }

        $reportDate = date('Y-m-d');

        // Generate LDR report
        $this->info('[INFO] Generating LDR Scrub List Report...');
        $this->generateReport($snowflakeLdr, $sqlConnector, 'LDR', $reportDate);

        // Generate Paramount Law report (uses LDR database)
        $this->info('[INFO] Generating Paramount Law Scrub List Report...');
        $this->generateReport($snowflakeLdr, $sqlConnector, 'Paramount', $reportDate);

        // Generate Progress Law report
        $this->info('[INFO] Generating Progress Law Scrub List Report...');
        $this->generateReport($snowflakePlaw, $sqlConnector, 'PLAW', $reportDate);

        return Command::SUCCESS;
    }

    private function generateReport(DBConnector $snowflake, DBConnector $sqlConnector, string $source, string $reportDate): void
    {

        // Build plan filter based on source
        $planFilter = '';
        if ($source === 'LDR') {
            $planFilter = "AND (ed.TITLE LIKE 'LDR%' OR ed.TITLE LIKE 'LT LDR%')";
        } elseif ($source === 'Paramount') {
            // Paramount Law uses PLAW enrollment plans in LDR database
            $planFilter = "AND ed.TITLE LIKE 'PLAW%'";
        }
        // Progress Law: No filter - gets all enrolled, non-graduated contacts from PLAW database

        // Main query for enrolled clients
        $sql = "
            SELECT
                ID,
                First_Name,
                Last_Name,
                SSN,
                TO_VARCHAR(DOB, 'MM/DD/YYYY') AS DOB,
                NULL AS Co_First_Name,
                NULL AS Co_Last_Name,
                NULL AS Co_SSN,
                NULL AS Co_DOB,
                Negotiator
            FROM (
                SELECT
                    c.ID,
                    c.FIRSTNAME AS First_Name,
                    c.LASTNAME AS Last_Name,
                    c.SSN,
                    c.DOB,
                    CONCAT(u.FIRSTNAME, ' ', u.LASTNAME) AS Negotiator,
                    ROW_NUMBER() OVER (PARTITION BY c.ID ORDER BY c.ID ASC) AS n
                FROM CONTACTS AS c
                LEFT JOIN USERS_ASSIGNMENT AS ua ON c.ID = ua.CONTACT_ID
                LEFT JOIN USERS AS u ON ua.USER_ID = u.UID
                LEFT JOIN ENROLLMENT_PLAN AS ep ON c.ID = ep.CONTACT_ID
                LEFT JOIN ENROLLMENT_DEFAULTS2 AS ed ON ep.PLAN_ID = ed.ID
                WHERE c.ENROLLED = 1
                  AND c.GRADUATED = 0
                  {$planFilter}
            )
            WHERE n = 1
        ";

        $result = $snowflake->query($sql);
        $rows = $result['data'] ?? [];
        $this->info('[INFO] Scrub List rows: ' . count($rows));

        // Sort by Last Name then First Name
        usort($rows, function($a, $b) {
            $lastNameCompare = strcasecmp($a['LAST_NAME'] ?? '', $b['LAST_NAME'] ?? '');
            if ($lastNameCompare !== 0) {
                return $lastNameCompare;
            }
            return strcasecmp($a['FIRST_NAME'] ?? '', $b['FIRST_NAME'] ?? '');
        });

        // Get co-applicant data - matches VBA query exactly
        $coAppSql = "
            SELECT
                cu.CONTACT_ID,
                c.ID,
                c.FIRSTNAME,
                c.LASTNAME,
                c.SSN,
                TO_VARCHAR(c.DOB, 'MM/DD/YYYY') AS DOB
            FROM CONTACTS AS c
            LEFT JOIN CONTACTS_USERFIELDS AS cu ON cu.F_INT = c.ID
            WHERE cu.CUSTOM_ID = 286824
              AND COALESCE(FIRSTNAME, '') <> ''
        ";

        $coAppResult = $snowflake->query($coAppSql);
        $coAppRows = $coAppResult['data'] ?? [];
        $this->info('[INFO] Co-applicant rows: ' . count($coAppRows));

        // Get balance data
        $contactIds = array_column($rows, 'ID');
        $balanceData = [];
        $debtData = [];
        $transactionData = [];

        if (!empty($contactIds)) {
            $contactIdList = implode(',', $contactIds);

            // Get current balances
            $balanceSql = "
                SELECT CONTACT_ID, \"CURRENT\"
                FROM CONTACT_BALANCES
                WHERE STAMP > '" . date('Y-m-d', strtotime('-14 days')) . "'
                  AND CONTACT_ID IN ({$contactIdList})
                ORDER BY STAMP DESC
            ";
            $balanceResult = $snowflake->query($balanceSql);
            foreach ($balanceResult['data'] ?? [] as $row) {
                $balanceData[$row['CONTACT_ID']] = $row['CURRENT'];
            }

            // Get enrolled debt
            $debtSql = "
                SELECT CONTACT_ID, SUM(ORIGINAL_DEBT_AMOUNT) AS TOTAL_DEBT
                FROM DEBTS
                WHERE ENROLLED = 1
                  AND SETTLED = 0
                  AND CONTACT_ID IN ({$contactIdList})
                GROUP BY CONTACT_ID
            ";
            $debtResult = $snowflake->query($debtSql);
            foreach ($debtResult['data'] ?? [] as $row) {
                $debtData[$row['CONTACT_ID']] = $row['TOTAL_DEBT'];
            }

            // Get transaction data
            $transSql = "
                SELECT CONTACT_ID, TRANS_TYPE, AMOUNT
                FROM TRANSACTIONS
                WHERE ACTIVE = 1
                  AND CANCELLED = 0
                  AND CLEARED_DATE IS NULL
                  AND RETURNED_DATE IS NULL
                  AND PROCESS_DATE > '" . date('Y-m-d', strtotime('-1 day')) . "'
                  AND PROCESS_DATE <= '" . date('Y-m-d', strtotime('+90 days')) . "'
                  AND CONTACT_ID IN ({$contactIdList})
            ";
            $transResult = $snowflake->query($transSql);
            foreach ($transResult['data'] ?? [] as $row) {
                $contactId = $row['CONTACT_ID'];
                if (!isset($transactionData[$contactId])) {
                    $transactionData[$contactId] = ['deposits' => 0, 'withdrawals' => 0];
                }
                if ($row['TRANS_TYPE'] === 'D') {
                    $transactionData[$contactId]['deposits'] += $row['AMOUNT'];
                } else {
                    $transactionData[$contactId]['withdrawals'] += $row['AMOUNT'];
                }
            }
        }

        // Filter rows based on balance criteria
        $filteredRows = [];
        foreach ($rows as $row) {
            $contactId = $row['ID'];
            $balance = $balanceData[$contactId] ?? 0;
            $debt = $debtData[$contactId] ?? 0;
            $ratio = $debt > 0 ? $balance / $debt : 0;
            $deposits = $transactionData[$contactId]['deposits'] ?? 0;
            $withdrawals = $transactionData[$contactId]['withdrawals'] ?? 0;
            $projectedBalance = $balance + $deposits - $withdrawals;

            // Only include if ratio >= 15% and projected balance >= 0
            if ($ratio >= 0.15 && $projectedBalance >= 0) {
                $filteredRows[] = $row;
            }
        }

        $this->info('[INFO] Filtered Scrub List rows: ' . count($filteredRows));

        $formatter = new Formatter();
        $report = $formatter->buildWorkbook($filteredRows, $coAppRows, $source, $reportDate);

        if ($report !== null) {
            $this->info("[INFO] Report written to {$report['path']}");
            $formatter->sendReport($sqlConnector, $report['path'], $report['filename'], $reportDate, $source, $this);
        } else {
            $this->warn('[WARN] Scrub List report file was not created.');
        }
    }

    protected function initializeSnowflakeConnector(string $env): DBConnector
    {
        try {
            return DBConnector::fromEnvironment($env);
        } catch (\Throwable $e) {
            throw new \RuntimeException("Unable to initialize Snowflake connector for {$env}: {$e->getMessage()}");
        }
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
