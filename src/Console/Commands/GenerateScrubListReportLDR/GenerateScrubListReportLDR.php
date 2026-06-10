<?php

namespace Cmd\Reports\Console\Commands\GenerateScrubListReportLDR;

use Cmd\Reports\Services\DBConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Faithful port of VBA GenerateScrubListReport("LDR").
 * Single Snowflake source (LDR). Keeps the LDR enrollment-plan filter
 * (ed.TITLE LIKE 'LDR%' OR 'LT LDR%') and the Negotiator column.
 * Picks the most recent CONTACT_BALANCES row.
 * Email recipients are hardcoded exactly as the VBA LDR branch (To/CC).
 */
class GenerateScrubListReportLDR extends Command
{
    protected $signature = 'Generate:scrub-list-report-ldr';

    protected $description = 'Generate the LDR Scrub List report from Snowflake and email it.';

    private const SOURCE_ENV = 'ldr';
    private const CATEGORY = 'LDR';

    public function handle(): int
    {
        $this->info('[INFO] LDR Scrub List report: starting.');

        try {
            $snowflake = DBConnector::fromEnvironment(self::SOURCE_ENV);
        } catch (\Throwable $e) {
            $this->error('Failed to initialize LDR Snowflake connector: ' . $e->getMessage());
            Log::error('GenerateScrubListReportLDR: Snowflake init failed', ['exception' => $e]);
            return Command::FAILURE;
        }

        try {
            $sqlConnector = $this->initializeSqlServerConnector();
        } catch (\Throwable $e) {
            $this->error('Failed to initialize SQL Server connector: ' . $e->getMessage());
            Log::error('GenerateScrubListReportLDR: SQL Server init failed', ['exception' => $e]);
            return Command::FAILURE;
        }

        try {
            $rows = $this->buildRows($snowflake);
            $this->info('[INFO] Filtered Scrub List rows: ' . count($rows));

            if (empty($rows)) {
                $this->warn('[WARN] No LDR Scrub List rows. Skipping workbook and email.');
                return Command::SUCCESS;
            }

            $coAppRows = $this->fetchCoApplicants($snowflake);
            $this->info('[INFO] Co-applicant rows: ' . count($coAppRows));

            $formatter = new Formatter();
            $report = $formatter->buildWorkbook($rows, $coAppRows, self::CATEGORY);

            if ($report === null) {
                $this->warn('[WARN] Scrub List report file was not created.');
                return Command::SUCCESS;
            }

            $this->info("[INFO] Report written to {$report['path']}");
            $formatter->sendReport($sqlConnector, $report['path'], $report['filename'], self::CATEGORY, $this);

            if (is_file($report['path'])) {
                @unlink($report['path']);
            }
        } catch (\Throwable $e) {
            $this->error('LDR Scrub List Report failed: ' . $e->getMessage());
            Log::error('GenerateScrubListReportLDR: report failed', ['exception' => $e]);
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Main applicant query + balance/debt/transaction enrichment + VBA drop filter
     * (drop when balance/debt ratio < 0.15 OR projected balance < 0).
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildRows(DBConnector $snowflake): array
    {
        // VBA LDR branch: AND (ed.TITLE LIKE 'LDR%' OR ed.TITLE LIKE 'LT LDR%').
        $sql = "
            SELECT
                ID,
                First_Name,
                Last_Name,
                SSN,
                TO_VARCHAR(DOB, 'MM/DD/YYYY') AS DOB,
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
                  AND (ed.TITLE LIKE 'LDR%' OR ed.TITLE LIKE 'LT LDR%')
            )
            WHERE n = 1
        ";

        $result = $snowflake->query($sql);
        $rows = $result['data'] ?? [];

        if (empty($rows)) {
            return [];
        }

        // VBA sorts column A (ID) ascending after building the sheet.
        usort($rows, static fn ($a, $b) => ((int) ($a['ID'] ?? 0)) <=> ((int) ($b['ID'] ?? 0)));

        $contactIds = array_values(array_filter(array_map(
            static fn ($r) => $r['ID'] ?? null,
            $rows
        ), static fn ($v) => $v !== null && $v !== ''));

        if (empty($contactIds)) {
            return [];
        }

        $idList = implode(',', array_map(static fn ($id) => (int) $id, $contactIds));

        // Most recent balance per contact (VBA VLOOKUP after ORDER BY STAMP DESC = first = newest).
        $balanceData = [];
        $balanceSql = "
            SELECT CONTACT_ID, \"CURRENT\"
            FROM CONTACT_BALANCES
            WHERE STAMP > '" . date('Y-m-d', strtotime('-14 days')) . "'
              AND CONTACT_ID IN ({$idList})
            ORDER BY STAMP DESC
        ";
        foreach ($snowflake->query($balanceSql)['data'] ?? [] as $r) {
            $cid = $r['CONTACT_ID'];
            if (!isset($balanceData[$cid])) {
                $balanceData[$cid] = (float) $r['CURRENT'];
            }
        }

        // Enrolled, unsettled debt total per contact.
        $debtData = [];
        $debtSql = "
            SELECT CONTACT_ID, SUM(ORIGINAL_DEBT_AMOUNT) AS TOTAL_DEBT
            FROM DEBTS
            WHERE ENROLLED = 1
              AND SETTLED = 0
              AND CONTACT_ID IN ({$idList})
            GROUP BY CONTACT_ID
        ";
        foreach ($snowflake->query($debtSql)['data'] ?? [] as $r) {
            $debtData[$r['CONTACT_ID']] = (float) $r['TOTAL_DEBT'];
        }

        // Pending transactions in the window: deposits (D) minus everything else.
        $txData = [];
        $txSql = "
            SELECT CONTACT_ID, TRANS_TYPE, AMOUNT
            FROM TRANSACTIONS
            WHERE ACTIVE = 1
              AND CANCELLED = 0
              AND CLEARED_DATE IS NULL
              AND RETURNED_DATE IS NULL
              AND PROCESS_DATE > '" . date('Y-m-d', strtotime('-1 day')) . "'
              AND PROCESS_DATE <= '" . date('Y-m-d', strtotime('+90 days')) . "'
              AND CONTACT_ID IN ({$idList})
        ";
        foreach ($snowflake->query($txSql)['data'] ?? [] as $r) {
            $cid = $r['CONTACT_ID'];
            if (!isset($txData[$cid])) {
                $txData[$cid] = ['deposits' => 0.0, 'withdrawals' => 0.0];
            }
            if (($r['TRANS_TYPE'] ?? '') === 'D') {
                $txData[$cid]['deposits'] += (float) $r['AMOUNT'];
            } else {
                $txData[$cid]['withdrawals'] += (float) $r['AMOUNT'];
            }
        }

        // VBA: clear row if M (balance/debt) < 0.15 OR P (projected balance) < 0.
        $kept = [];
        foreach ($rows as $row) {
            $cid = $row['ID'];
            $balance = $balanceData[$cid] ?? 0.0;
            $debt = $debtData[$cid] ?? 0.0;
            $ratio = $debt > 0 ? $balance / $debt : 0.0;
            $deposits = $txData[$cid]['deposits'] ?? 0.0;
            $withdrawals = $txData[$cid]['withdrawals'] ?? 0.0;
            $projected = $balance + $deposits - $withdrawals;

            if ($ratio >= 0.15 && $projected >= 0) {
                $kept[] = $row;
            }
        }

        return $kept;
    }

    /**
     * Co-applicant rows (CONTACTS_USERFIELDS CUSTOM_ID 286824), keyed later by CONTACT_ID.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchCoApplicants(DBConnector $snowflake): array
    {
        $sql = "
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
              AND COALESCE(c.FIRSTNAME, '') <> ''
        ";

        return $snowflake->query($sql)['data'] ?? [];
    }

    /**
     * SQL Server connector for reading recipients from dbo.TblReports.
     * DBConnector's constructor needs Snowflake creds, so build from an available
     * env then attach the SQL Server connection (only querySqlServer() is used here).
     */
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
