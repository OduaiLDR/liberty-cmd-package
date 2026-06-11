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
            'env' => 'ldr', 'portal' => 'LDR', 'company' => 'LDR',
            'reports' => [
                ['key' => 'pending', 'title' => 'Settlements - Pending Payments'],
                ['key' => 'shipped', 'title' => 'Settlements - Shipped Uncollected Checks'],
            ],
        ],
        [
            'env' => 'plaw', 'portal' => 'Progress Law', 'company' => 'Progress Law',
            'reports' => [
                ['key' => 'uncollected', 'title' => 'Settlements - Uncollected'],
                ['key' => 'shipped', 'title' => 'Settlements - Uncollected Check Shipments'],
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
            $rows = $this->fetch($report['key'], $snowflake);
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
    private function fetch(string $key, DBConnector $snowflake): array
    {
        $sql = match ($key) {
            'pending' => $this->pendingSql(),
            'shipped' => $this->shippedSql(),
            'uncollected' => $this->uncollectedSql(),
            default => throw new \InvalidArgumentException("Unknown report key: {$key}"),
        };

        return $snowflake->query($sql)['data'] ?? [];
    }

    /** LDR "Pending Payments": settlement payments still Pending (STATUS 1). */
    private function pendingSql(): string
    {
        return "
            SELECT
                t.CONTACT_ID                              AS CONTACT_ID,
                CONCAT(c.FIRSTNAME, ' ', c.LASTNAME)      AS FULL_NAME,
                TO_VARCHAR(t.PROCESS_DATE, 'Mon DD YYYY') AS PROCESS_DATE,
                t.AMOUNT                                  AS AMOUNT,
                s.CREDITOR_NAME                           AS CREDITOR_NAME,
                s.CREDITOR_NAME                           AS DEBT_THIRD_PARTY,
                st.NAME                                   AS STATUS,
                s.REF                                     AS SETT_REF,
                t.TRANSID                                 AS TRANS_ID,
                tt.TITLE                                  AS TRANS_TYPE,
                CONCAT(u.FIRSTNAME, ' ', u.LASTNAME)      AS NEGOTIATOR
            FROM TRANSACTIONS t
            LEFT JOIN SETTLEMENTS s            ON s.TRANS_ID = t.ID
            LEFT JOIN CONTACTS c               ON c.ID = t.CONTACT_ID
            LEFT JOIN TRANSACTION_STATUSES st  ON st.ID = t.STATUS
            LEFT JOIN TRANSACTION_TYPES tt     ON tt.TRANS_TYPE = t.TRANS_TYPE
            LEFT JOIN SETTLEMENT_OFFERS o      ON o.ID = s.OFFER_ID
            LEFT JOIN USERS u                  ON u.UID = o.NEG_ID
            WHERE t.TRANS_TYPE = 'S'
              AND t.STATUS = 1
              AND t.ACTIVE = 1
            ORDER BY t.PROCESS_DATE
        ";
    }

    /** "Shipped / Uncollected Check Shipments": settlement payments Shipped (STATUS 20). */
    private function shippedSql(): string
    {
        return "
            SELECT
                t.CONTACT_ID                              AS CONTACT_ID,
                CONCAT(c.FIRSTNAME, ' ', c.LASTNAME)      AS FULL_NAME,
                TO_VARCHAR(t.PROCESS_DATE, 'Mon DD YYYY') AS PROCESS_DATE,
                t.AMOUNT                                  AS AMOUNT,
                s.REF                                     AS SETT_REF,
                sub.TITLE                                 AS SUB_TYPE,
                st.NAME                                   AS STATUS,
                s.CREDITOR_NAME                           AS CREDITOR_NAME,
                t.ID                                      AS TRANS_ROW_ID,
                t.TRANSID                                 AS TRANS_ID,
                s.CONF                                    AS SETTLEMENT_CONFIRMATION,
                CONCAT(u.FIRSTNAME, ' ', u.LASTNAME)      AS NEGOTIATOR
            FROM TRANSACTIONS t
            LEFT JOIN SETTLEMENTS s             ON s.TRANS_ID = t.ID
            LEFT JOIN CONTACTS c                ON c.ID = t.CONTACT_ID
            LEFT JOIN TRANSACTION_STATUSES st   ON st.ID = t.STATUS
            LEFT JOIN TRANSACTION_SUBTYPES sub  ON sub.ID = t.SUB_TYPE
            LEFT JOIN SETTLEMENT_OFFERS o       ON o.ID = s.OFFER_ID
            LEFT JOIN USERS u                   ON u.UID = o.NEG_ID
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
    private function uncollectedSql(): string
    {
        return "
            SELECT
                CONCAT(c.FIRSTNAME, ' ', c.LASTNAME)      AS FULL_NAME,
                TO_VARCHAR(t.PROCESS_DATE, 'MM/DD/YYYY')  AS PROCESS_DATE,
                st.NAME                                   AS STATUS,
                t.AMOUNT                                  AS AMOUNT,
                tt.TITLE                                  AS TRANS_TYPE,
                t.CONTACT_ID                              AS CONTACT_ID,
                (
                    SELECT CONCAT(au.FIRSTNAME, ' ', au.LASTNAME)
                    FROM USERS_ASSIGNMENT ua
                    JOIN USERS au ON au.UID = ua.USER_ID
                    WHERE ua.CONTACT_ID = t.CONTACT_ID
                    LIMIT 1
                )                                         AS ASSIGNED_TO,
                s.CREDITOR_NAME                           AS CREDITOR_NAME,
                sub.TITLE                                 AS SUB_TYPE
            FROM TRANSACTIONS t
            LEFT JOIN SETTLEMENTS s             ON s.TRANS_ID = t.ID
            LEFT JOIN CONTACTS c                ON c.ID = t.CONTACT_ID
            LEFT JOIN TRANSACTION_STATUSES st   ON st.ID = t.STATUS
            LEFT JOIN TRANSACTION_TYPES tt      ON tt.TRANS_TYPE = t.TRANS_TYPE
            LEFT JOIN TRANSACTION_SUBTYPES sub  ON sub.ID = t.SUB_TYPE
            WHERE t.TRANS_TYPE = 'S'
              AND t.STATUS IN (0, 14, 20)
              AND t.ACTIVE = 1
              AND t.PROCESS_DATE < CURRENT_DATE
            ORDER BY t.PROCESS_DATE
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
