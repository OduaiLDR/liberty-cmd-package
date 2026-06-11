<?php

namespace Cmd\Reports\Console\Commands\GenerateSettlementReports;

use Cmd\Reports\Services\DBConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Port of the VBA EmailSettlementReports browser automation.
 *
 * The VBA logged into DebtPayPro and clicked "Email Report" on two accounting
 * reports per portal. The portals have DIFFERENT report sets:
 *
 *   LDR (reports 183874 / 183875):
 *     - "Settlements - Pending Payments"          STATUS 1  (Pending)
 *     - "Settlements - Shipped Uncollected Checks" STATUS 20 (Shipped)
 *
 *   Progress Law (reports 183626 / 183628):
 *     - "Settlements - Uncollected"                STATUS 0,14,20 (Open/Low Balance/Shipped), process date past
 *     - "Settlements - Uncollected Check Shipments" STATUS 20 (Shipped)
 *
 * Rebuilds each from Snowflake (per portal) and emails them. Recipients from
 * dbo.TblReports by Company. All four totals validated to the cent vs the live reports.
 *
 * Lookups: TRANS_TYPE 'S' = Settlement Payment · STATUS 0 Open / 1 Pending / 14 Low Balance / 20 Shipped.
 */
class GenerateSettlementReports extends Command
{
    protected $signature = 'Generate:settlement-reports';

    protected $description = 'Generate the per-portal Settlement reports from Snowflake and email them.';

    /**
     * @var array<int, array{env:string, portal:string, company:string, reports:array<int, array{key:string, title:string}>}>
     */
    private const PORTALS = [
        [
            'env' => 'ldr', 'portal' => 'LDR', 'company' => 'LDR', 'date_fmt' => 'Mon DD YYYY',
            'reports' => [
                ['key' => 'pending', 'title' => 'Settlements - Pending Payments', 'id_prefix' => ''],
                ['key' => 'shipped', 'title' => 'Settlements - Shipped Uncollected Checks', 'id_prefix' => ''],
            ],
        ],
        [
            'env' => 'plaw', 'portal' => 'Progress Law', 'company' => 'Progress Law', 'date_fmt' => 'MM/DD/YYYY',
            'reports' => [
                ['key' => 'uncollected', 'title' => 'Settlements - Uncollected', 'id_prefix' => ''],
                ['key' => 'shipped', 'title' => 'Settlements - Uncollected Check Shipments', 'id_prefix' => 'LLG-'],
            ],
        ],
    ];

    public function handle(): int
    {
        $this->info('[INFO] Settlement reports: starting.');

        try {
            $sqlConnector = $this->initializeSqlServerConnector();
        } catch (\Throwable $e) {
            $this->error('Failed to initialize SQL Server connector: ' . $e->getMessage());
            Log::error('GenerateSettlementReports: SQL Server init failed', ['exception' => $e]);
            return Command::FAILURE;
        }

        foreach (self::PORTALS as $portal) {
            try {
                $this->generateForPortal($sqlConnector, $portal);
            } catch (\Throwable $e) {
                $this->error("{$portal['portal']} settlement reports failed: " . $e->getMessage());
                Log::error('GenerateSettlementReports: portal failed', [
                    'portal' => $portal['portal'],
                    'exception' => $e,
                ]);
                // Continue to the next portal.
            }
        }

        return Command::SUCCESS;
    }

    /**
     * @param  array{env:string, portal:string, company:string, reports:array<int, array{key:string, title:string}>}  $portal
     */
    private function generateForPortal(DBConnector $sqlConnector, array $portal): void
    {
        $this->info("[INFO] Generating {$portal['portal']} settlement reports...");

        $snowflake = DBConnector::fromEnvironment($portal['env']);
        $formatter = new Formatter();
        $attachments = [];

        foreach ($portal['reports'] as $report) {
            $rows = $this->fetch($report['key'], $snowflake, $portal['date_fmt'], $report['id_prefix'] ?? '');
            $this->info("[INFO] {$portal['portal']} - {$report['title']}: " . count($rows) . ' rows');

            if (empty($rows)) {
                continue;
            }

            $filename = "{$portal['portal']} - {$report['title']} - " . date('Y-m-d') . '.xlsx';
            $attachments[] = $formatter->buildWorkbook($rows, $report['key'], "{$portal['portal']} {$report['title']}", $filename);
        }

        if (empty($attachments)) {
            $this->warn("[WARN] No settlement rows for {$portal['portal']}. Skipping email.");
            return;
        }

        $formatter->sendReports($sqlConnector, $attachments, $portal['portal'], $portal['company'], $this);

        foreach ($attachments as $a) {
            if (isset($a['path']) && is_file($a['path'])) {
                @unlink($a['path']);
            }
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetch(string $key, DBConnector $snowflake, string $dateFmt, string $idPrefix): array
    {
        // Contact-id display: optionally prefixed (PLAW Check Shipments shows "LLG-<id>").
        $idExpr = $idPrefix !== ''
            ? "CONCAT('" . str_replace("'", "''", $idPrefix) . "', t.CONTACT_ID)"
            : 't.CONTACT_ID';

        $sql = match ($key) {
            'pending' => $this->pendingSql($dateFmt, $idExpr),
            'shipped' => $this->shippedSql($dateFmt, $idExpr),
            'uncollected' => $this->uncollectedSql($dateFmt, $idExpr),
            default => throw new \InvalidArgumentException("Unknown report key: {$key}"),
        };

        return $snowflake->query($sql)['data'] ?? [];
    }

    /** LDR "Pending Payments": settlement payments still Pending (STATUS 1). */
    private function pendingSql(string $dateFmt, string $idExpr): string
    {
        return "
            SELECT
                {$idExpr}                                 AS CONTACT_ID,
                CONCAT(c.FIRSTNAME, ' ', c.LASTNAME)      AS FULL_NAME,
                TO_VARCHAR(t.PROCESS_DATE, '{$dateFmt}')  AS PROCESS_DATE,
                t.AMOUNT                                  AS AMOUNT,
                s.CREDITOR_NAME                           AS CREDITOR_NAME,
                tp.THIRD_PARTY                            AS DEBT_THIRD_PARTY,
                st.NAME                                   AS STATUS,
                s.REF                                     AS SETT_REF,
                t.TRANSID                                 AS TRANS_ID,
                tt.TITLE                                  AS TRANS_TYPE,
                neg.NEG_NAME                              AS NEGOTIATOR
            FROM TRANSACTIONS t
            LEFT JOIN SETTLEMENTS s            ON s.TRANS_ID = t.ID
            LEFT JOIN CONTACTS c               ON c.ID = t.CONTACT_ID
            LEFT JOIN TRANSACTION_STATUSES st  ON st.ID = t.STATUS
            LEFT JOIN TRANSACTION_TYPES tt     ON tt.TRANS_TYPE = t.TRANS_TYPE
            {$this->negotiatorJoin()}
            {$this->thirdPartyJoin()}
            WHERE t.TRANS_TYPE = 'S'
              AND t.STATUS = 1
              AND t.ACTIVE = 1
            ORDER BY t.PROCESS_DATE
        ";
    }

    /** "Shipped / Uncollected Check Shipments": settlement payments Shipped (STATUS 20). */
    private function shippedSql(string $dateFmt, string $idExpr): string
    {
        return "
            SELECT
                {$idExpr}                                 AS CONTACT_ID,
                CONCAT(c.FIRSTNAME, ' ', c.LASTNAME)      AS FULL_NAME,
                TO_VARCHAR(t.PROCESS_DATE, '{$dateFmt}')  AS PROCESS_DATE,
                t.AMOUNT                                  AS AMOUNT,
                s.REF                                     AS SETT_REF,
                sub.TITLE                                 AS SUB_TYPE,
                st.NAME                                   AS STATUS,
                s.CREDITOR_NAME                           AS CREDITOR_NAME,
                t.ID                                      AS TRANS_ROW_ID,
                t.TRANSID                                 AS TRANS_ID,
                s.CONF                                    AS SETTLEMENT_CONFIRMATION,
                neg.NEG_NAME                              AS NEGOTIATOR
            FROM TRANSACTIONS t
            LEFT JOIN SETTLEMENTS s             ON s.TRANS_ID = t.ID
            LEFT JOIN CONTACTS c                ON c.ID = t.CONTACT_ID
            LEFT JOIN TRANSACTION_STATUSES st   ON st.ID = t.STATUS
            LEFT JOIN TRANSACTION_SUBTYPES sub  ON sub.ID = t.SUB_TYPE
            {$this->negotiatorJoin()}
            WHERE t.TRANS_TYPE = 'S'
              AND t.STATUS = 20
              AND t.ACTIVE = 1
            ORDER BY t.PROCESS_DATE
        ";
    }

    /**
     * Progress Law "Uncollected": settlement payments past-due and uncollected —
     * STATUS Open(0) / Low Balance(14) / Shipped(20), process date before today.
     * Uses "Assigned To" (the contact's assigned user) instead of Negotiator.
     */
    private function uncollectedSql(string $dateFmt, string $idExpr): string
    {
        return "
            SELECT
                CONCAT(c.FIRSTNAME, ' ', c.LASTNAME)      AS FULL_NAME,
                TO_VARCHAR(t.PROCESS_DATE, '{$dateFmt}')  AS PROCESS_DATE,
                st.NAME                                   AS STATUS,
                t.AMOUNT                                  AS AMOUNT,
                tt.TITLE                                  AS TRANS_TYPE,
                {$idExpr}                                 AS CONTACT_ID,
                CONCAT(au.FIRSTNAME, ' ', au.LASTNAME)    AS ASSIGNED_TO,
                s.CREDITOR_NAME                           AS CREDITOR_NAME,
                sub.TITLE                                 AS SUB_TYPE
            FROM TRANSACTIONS t
            LEFT JOIN SETTLEMENTS s             ON s.TRANS_ID = t.ID
            LEFT JOIN CONTACTS c                ON c.ID = t.CONTACT_ID
            LEFT JOIN TRANSACTION_STATUSES st   ON st.ID = t.STATUS
            LEFT JOIN TRANSACTION_TYPES tt      ON tt.TRANS_TYPE = t.TRANS_TYPE
            LEFT JOIN TRANSACTION_SUBTYPES sub  ON sub.ID = t.SUB_TYPE
            LEFT JOIN (
                SELECT CONTACT_ID, USER_ID,
                       ROW_NUMBER() OVER (PARTITION BY CONTACT_ID ORDER BY CONTACT_ID ASC) AS rn
                FROM USERS_ASSIGNMENT
            ) ua ON ua.CONTACT_ID = t.CONTACT_ID AND ua.rn = 1
            LEFT JOIN USERS au ON au.UID = ua.USER_ID
            WHERE t.TRANS_TYPE = 'S'
              AND t.STATUS IN (0, 14, 20)
              AND t.ACTIVE = 1
              AND t.PROCESS_DATE < CURRENT_DATE
            ORDER BY t.PROCESS_DATE
        ";
    }

    /**
     * Negotiator per settlement (CONTACT_ID + Sett Ref). DebtPayPro shows the
     * negotiator tied to the settlement, not the individual transaction's offer —
     * so we pull it from any offer of that settlement that has a real negotiator.
     * Deduped to one row per (contact, ref) so it never multiplies transaction rows.
     */
    private function negotiatorJoin(): string
    {
        return "
            LEFT JOIN (
                SELECT
                    s2.CONTACT_ID,
                    s2.REF,
                    CONCAT(u2.FIRSTNAME, ' ', u2.LASTNAME) AS NEG_NAME,
                    ROW_NUMBER() OVER (PARTITION BY s2.CONTACT_ID, s2.REF ORDER BY o2.CREATED_AT DESC) AS rn
                FROM SETTLEMENTS s2
                JOIN SETTLEMENT_OFFERS o2 ON o2.ID = s2.OFFER_ID
                JOIN USERS u2 ON u2.UID = o2.NEG_ID
                WHERE o2.NEG_ID IS NOT NULL AND o2.NEG_ID <> 0
            ) neg ON neg.CONTACT_ID = t.CONTACT_ID AND neg.REF = s.REF AND neg.rn = 1
        ";
    }

    /**
     * "Debt - Third Party" per settlement (CONTACT_ID + Sett Ref): the debt buyer
     * (DEBTS.DEBT_BUYER) resolved to its CREDITORS.COMPANY name. Deduped per
     * (contact, ref) so it fills consistently and never multiplies transaction rows.
     */
    private function thirdPartyJoin(): string
    {
        return "
            LEFT JOIN (
                SELECT
                    s3.CONTACT_ID,
                    s3.REF,
                    cr.COMPANY AS THIRD_PARTY,
                    ROW_NUMBER() OVER (PARTITION BY s3.CONTACT_ID, s3.REF ORDER BY o3.CREATED_AT DESC) AS rn
                FROM SETTLEMENTS s3
                JOIN SETTLEMENT_OFFERS o3 ON o3.ID = s3.OFFER_ID
                JOIN DEBTS d3 ON d3.ID = o3.DEBT_ID
                JOIN CREDITORS cr ON cr.ID = d3.DEBT_BUYER
                WHERE d3.DEBT_BUYER IS NOT NULL AND d3.DEBT_BUYER <> 0
            ) tp ON tp.CONTACT_ID = t.CONTACT_ID AND tp.REF = s.REF AND tp.rn = 1
        ";
    }

    /**
     * SQL Server connector for reading recipients from dbo.TblReports.
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
