<?php

namespace Cmd\Reports\Console\Commands\GenerateRetentionCommissionReport;

use Cmd\Reports\Services\DBConnector;
use Cmd\Reports\Services\EmailSenderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Generates the monthly Retention Commission Report.
 *
 * Supports both LDR and PLAW Snowflake sources.
 * Faithful port of VBA GenerateRetentionCommissionReport macros:
 *   - LDR: CUSTOM_IDs 742096/742101/742105/742098, status_id 377650
 *   - PLAW: CUSTOM_IDs 742097/742102/742106/742100, status_id 377687
 *
 * Date range: first/last day of the PREVIOUS month.
 * Produces Excel workbook (two sheets) – "All" + one per agent.
 * Emails via Microsoft Graph; falls back to oduai@ when TblReports has no recipients.
 */
class GenerateRetentionCommissionReport extends Command
{
    protected $signature = 'reports:generate-retention-commission
                            {source=both : Snowflake source to run – ldr | plaw | both}';

    protected $description = 'Generate the monthly Retention Commission Report for LDR and/or PLAW.';

    /** Commission tiers: [max_debt, t1, t2, t3] */
    private const TIERS = [
        [15000,  2,  5, 10],
        [30000,  5, 10, 20],
        [60000, 15, 30, 40],
        [PHP_INT_MAX, 20, 40, 60],
    ];

    /** Per-source Snowflake custom field IDs and agent lists */
    private const SOURCE_CONFIG = [
        'ldr' => [
            'display'           => 'LDR',
            'custom_agent'      => 742096,
            'custom_date'       => 742101,
            'custom_results'    => 742105,
            'custom_cancel'     => 742098,
            'recon_status_id'   => 377650,
            'agents'            => [
                'Mike Wexford', 'Ivy Morgan', 'Laura Brown', 'Ken Smith',
                'Jose Chocano', 'John Pozuelos', 'Kevin Nixon', 'Alice Kennedy',
            ],
        ],
        'plaw' => [
            'display'           => 'Progress Law',
            'custom_agent'      => 742097,
            'custom_date'       => 742102,
            'custom_results'    => 742106,
            'custom_cancel'     => 742100,
            'recon_status_id'   => 377687,
            'agents'            => [
                'Justin Wilson', 'Melody Martinez', 'Nick Jones',
                'Vicente Gonzalez', 'Maria Lezana', 'Theo Clayton', 'Madeline Morris',
            ],
        ],
    ];

    public function handle(): int
    {
        $arg = strtolower((string) $this->argument('source'));
        $sources = ($arg === 'both') ? ['ldr', 'plaw'] : [$arg];

        foreach ($sources as $src) {
            if (!isset(self::SOURCE_CONFIG[$src])) {
                $this->error("Unknown source: $src. Use ldr, plaw, or both.");
                return Command::FAILURE;
            }
            $this->runForSource($src);
        }

        return Command::SUCCESS;
    }

    // ─────────────────────────────────────────────────────────────────────────
    private function runForSource(string $source): void
    {
        $cfg = self::SOURCE_CONFIG[$source];
        $display = $cfg['display'];

        $this->info("[INFO] GenerateRetentionCommissionReport starting – source=$display");

        // Date range: first/last of previous month (mirrors VBA DateSerial logic)
        $startDate = date('Y-m-01', strtotime('first day of last month'));
        $endDate   = date('Y-m-t', strtotime($startDate));
        $this->info("[INFO] Period: $startDate → $endDate");

        try {
            $sf  = DBConnector::fromEnvironment($source);
            $sql = $this->initSqlServer($source);
        } catch (\Throwable $e) {
            $this->error("[$display] Connector init failed: " . $e->getMessage());
            Log::error("GenerateRetentionCommissionReport[$display]: connector init", ['ex' => $e]);
            return;
        }

        try {
            $this->info("[INFO] [$display] Fetching base data …");
            $rows = $this->buildRows($sf, $cfg, $startDate, $endDate);
            $this->info("[INFO] [$display] " . count($rows) . " rows fetched and enriched.");

            $formatter = new Formatter();

            // ── All-agents workbook ─────────────────────────────────────────
            $allFile = $formatter->buildWorkbook(
                $rows, $display, $startDate, $endDate, $cfg['agents']
            );
            if ($allFile) {
                $this->info("[INFO] [$display] All-agents file: {$allFile['filename']}");
                $this->sendAllReport($sql, $allFile, $display, $startDate, $endDate);
                if (file_exists($allFile['path'])) {
                    @unlink($allFile['path']); // VBA: Kill after send
                }
            }

            // ── Per-agent workbooks (REMOVED: User only wants the combined report) ──

            $this->logTblLog($sql, $display, $startDate, $endDate);
            $this->info("[INFO] [$display] Done.");

        } catch (\Throwable $e) {
            $this->error("[$display] Report failed: " . $e->getMessage());
            Log::error("GenerateRetentionCommissionReport[$display]: failed", ['ex' => $e]);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Data pipeline
    // ─────────────────────────────────────────────────────────────────────────

    private function buildRows(DBConnector $sf, array $cfg, string $startDate, string $endDate): array
    {
        $rows            = $this->fetchBase($sf, $cfg, $startDate, $endDate);
        
        $ids = array_filter(array_map(fn($r) => (int)($r['ID'] ?? 0), $rows));
        $idList = empty($ids) ? '0' : implode(',', $ids);

        $reconMap        = $this->fetchReconsiderationDates($sf, $cfg['recon_status_id'], $idList);
        $clearedMap      = $this->fetchClearedPayments($sf, $idList);
        $retainedMap     = $this->fetchRetainedDates($sf, $idList);

        foreach ($rows as &$row) {
            $id = (string)($row['ID'] ?? '');

            // VBA col H: IFERROR(DATEVALUE(VLOOKUP(...)), MIN(D2,I2))
            if (!empty($reconMap[$id])) {
                $row['RECONSIDERATION_DATE'] = $reconMap[$id];
            } else {
                $dates = array_filter([$row['RETENTION_DATE'] ?? null, $row['DROPPED_DATE'] ?? null]);
                $row['RECONSIDERATION_DATE'] = $dates ? min($dates) : null;
            }

            // VBA col G: COUNTIFS cleared before reconsideration date
            $recon = $row['RECONSIDERATION_DATE'] ?? null;
            $row['CLEARED_PAYMENTS'] = 0;
            if ($recon && !empty($clearedMap[$id])) {
                foreach ($clearedMap[$id] as $cd) {
                    if ($cd < $recon) {
                        $row['CLEARED_PAYMENTS']++;
                    }
                }
            }

            // VBA col J: first enrolled status >= reconsideration date
            $row['RETAINED_DATE'] = null;
            if ($recon && !empty($retainedMap[$id])) {
                foreach ($retainedMap[$id] as $rd) {
                    if ($rd >= $recon) {
                        $row['RETAINED_DATE'] = $rd;
                        break;
                    }
                }
            }
        }
        unset($row);

        // VBA loop: fetch retention payment date + assign commissions
        foreach ($rows as &$row) {
            $id    = (string)($row['ID'] ?? '');
            $recon = $row['RECONSIDERATION_DATE'] ?? null;
            $retd  = $row['RETAINED_DATE'] ?? null;

            // VBA condition: G >= 0 AND H > 0 AND J <> ""
            if ($row['CLEARED_PAYMENTS'] >= 0 && $recon && $retd) {
                $firstPay = $this->fetchFirstPayment($sf, $id, $recon);
                $row['RETENTION_PAYMENT_DATE'] = $firstPay;

                if ($firstPay) {
                    [$t1, $t2, $t3] = $this->calcCommission(
                        (float)($row['ENROLLED_DEBT'] ?? 0),
                        (string)($row['RETENTION_AGENT'] ?? '')
                    );
                    $row['RETENTION_COMMISSION_T1'] = $t1;
                    $row['RETENTION_COMMISSION_T2'] = $t2;
                    $row['RETENTION_COMMISSION_T3'] = $t3;

                    // VBA: if K >= I (payment >= dropped), clear both
                    $dropped = $row['DROPPED_DATE'] ?? null;
                    if ($dropped && $firstPay >= $dropped) {
                        $row['RETENTION_PAYMENT_DATE']   = null;
                        $row['RETENTION_COMMISSION_T1']  = null;
                        $row['RETENTION_COMMISSION_T2']  = null;
                        $row['RETENTION_COMMISSION_T3']  = null;
                    }
                }
            }
        }
        unset($row);

        return $rows;
    }

    // STEP 1 ── base contact data from Snowflake
    private function fetchBase(DBConnector $sf, array $cfg, string $start, string $end): array
    {
        $ca = (int)$cfg['custom_agent'];
        $cd = (int)$cfg['custom_date'];
        $cr = (int)$cfg['custom_results'];
        $cc = (int)$cfg['custom_cancel'];

        $startTs = strtotime($start);
        $endTs = strtotime($end . ' 23:59:59');

        $sql = "
            WITH PivotedFields AS (
                SELECT 
                    CONTACT_ID,
                    MAX(CASE WHEN CUSTOM_ID = $ca THEN F_STRING END) AS RETENTION_AGENT,
                    MAX(CASE WHEN CUSTOM_ID = $cd THEN F_DATE END) AS RETENTION_DATE,
                    MAX(CASE WHEN CUSTOM_ID = $cr THEN F_STRING END) AS IMMEDIATE_RESULTS,
                    MAX(CASE WHEN CUSTOM_ID = $cc THEN F_DATETIME END) AS CANCEL_REQUEST_DATE
                FROM CONTACTS_USERFIELDS
                WHERE CUSTOM_ID IN ($ca, $cd, $cr, $cc)
                GROUP BY CONTACT_ID
            )
            SELECT
                c.ID,
                CONCAT(c.FIRSTNAME, ' ', c.LASTNAME) AS CLIENT,
                p.RETENTION_AGENT,
                LEFT(p.RETENTION_DATE, 10) AS RETENTION_DATE,
                p.IMMEDIATE_RESULTS,
                d.ENROLLED_DEBT,
                c.DROPPED_DATE,
                p.CANCEL_REQUEST_DATE
            FROM PivotedFields p
            JOIN CONTACTS c ON c.ID = p.CONTACT_ID
            LEFT JOIN (
                SELECT CONTACT_ID, SUM(ORIGINAL_DEBT_AMOUNT) AS ENROLLED_DEBT
                FROM   DEBTS
                WHERE  ENROLLED = 1
                  AND  _FIVETRAN_DELETED = FALSE
                GROUP BY CONTACT_ID
            ) AS d ON c.ID = d.CONTACT_ID
            WHERE (
                   (p.RETENTION_DATE >= '$start' AND p.RETENTION_DATE <= '$end')
                OR (p.CANCEL_REQUEST_DATE IS NOT NULL)
            )
              AND p.RETENTION_AGENT IS NOT NULL
            ORDER BY p.RETENTION_AGENT ASC
        ";

        $res = $sf->query($sql);
        return $res['data'] ?? [];
    }

    // STEP 2 ── reconsideration dates (earliest per contact)
    private function fetchReconsiderationDates(DBConnector $sf, int $statusId, string $idList): array
    {
        $sql = "
            SELECT cs.CONTACT_ID, LEFT(cs.STAMP, 10) AS RECON_DATE
            FROM   CONTACTS_STATUS AS cs
            WHERE  cs.STATUS_ID = $statusId
              AND  cs.CONTACT_ID IN ($idList)
            ORDER  BY cs.CONTACT_ID ASC, cs.STAMP ASC
        ";
        $res = $sf->query($sql);
        $map = [];
        foreach ($res['data'] ?? [] as $r) {
            $id = (string)$r['CONTACT_ID'];
            if (!isset($map[$id])) {    // keep earliest (VBA: ORDER BY ASC + VLOOKUP)
                $map[$id] = $r['RECON_DATE'];
            }
        }
        return $map;
    }

    // STEP 3 ── cleared (non-returned) draft transactions
    private function fetchClearedPayments(DBConnector $sf, string $idList): array
    {
        $sql = "
            SELECT CONTACT_ID, CLEARED_DATE
            FROM   TRANSACTIONS
            WHERE  TRANS_TYPE = 'D'
              AND  CONTACT_ID IN ($idList)
              AND  CLEARED_DATE IS NOT NULL
              AND  RETURNED_DATE IS NULL
        ";
        $res = $sf->query($sql);
        $map = [];
        foreach ($res['data'] ?? [] as $r) {
            $map[(string)$r['CONTACT_ID']][] = $r['CLEARED_DATE'];
        }
        return $map;
    }

    // STEP 4 ── first enrolled status (not reconsideration) per contact
    private function fetchRetainedDates(DBConnector $sf, string $idList): array
    {
        $sql = "
            SELECT cs.CONTACT_ID, LEFT(cs.STAMP, 10) AS RETAINED_DATE
            FROM   CONTACTS_STATUS AS cs
            LEFT JOIN CONTACTS_LEAD_STATUS AS cls ON cs.STATUS_ID = cls.ID
            WHERE  UPPER(cls.TITLE) LIKE '%ENROLLED%'
              AND  UPPER(cls.TITLE) NOT LIKE '%RECONSIDERATION%'
              AND  cs.CONTACT_ID IN ($idList)
            ORDER  BY cs.CONTACT_ID ASC, cs.STAMP ASC
        ";
        $res = $sf->query($sql);
        $map = [];
        foreach ($res['data'] ?? [] as $r) {
            $map[(string)$r['CONTACT_ID']][] = $r['RETAINED_DATE'];
        }
        return $map;
    }

    // STEP 5 helper ── first payment for a contact on/after a date
    private function fetchFirstPayment(DBConnector $sf, string $contactId, string $afterDate): ?string
    {
        $cid  = (int)$contactId;
        $date = $this->esc($afterDate);
        $sql = "
            SELECT CLEARED_DATE
            FROM (
                SELECT CLEARED_DATE,
                       ROW_NUMBER() OVER (PARTITION BY CONTACT_ID ORDER BY CLEARED_DATE ASC) AS N
                FROM   TRANSACTIONS
                WHERE  CONTACT_ID = $cid
                  AND  TRANS_TYPE = 'D'
                  AND  CLEARED_DATE IS NOT NULL
                  AND  RETURNED_DATE IS NULL
                  AND  CLEARED_DATE >= '$date'
            )
            WHERE N = 1
        ";
        $res  = $sf->query($sql);
        $row  = ($res['data'] ?? [])[0] ?? null;
        return $row ? ($row['CLEARED_DATE'] ?? null) : null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Commission calculation – mirrors VBA Select Case blocks exactly
    // ─────────────────────────────────────────────────────────────────────────
    private function calcCommission(float $debt, string $agent): array
    {
        $t1 = $t2 = $t3 = null;
        foreach (self::TIERS as [$max, $tier1, $tier2, $tier3]) {
            if ($debt <= $max) {
                $t1 = $tier1; $t2 = $tier2; $t3 = $tier3;
                break;
            }
        }

        // VBA: SYDNEY LEYVA gets 2x commission
        if ($t1 !== null && strtoupper($agent) === 'SYDNEY LEYVA') {
            $t1 *= 2; $t2 *= 2; $t3 *= 2;
        }

        // Commented-out VBA block for ANDRES MORALES preserved here:
        // if (strtoupper($agent) === 'ANDRES MORALES') {
        //     [$t1,$t2,$t3] = match(true) {
        //         $debt<=15000 => [4,   4,  5],
        //         $debt<=30000 => [4.5, 5,  7],
        //         $debt<=60000 => [5,   7,  9],
        //         default      => [7,   9, 11],
        //     };
        // }

        return [$t1, $t2, $t3];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Email helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function sendAllReport(DBConnector $sql, array $file, string $display, string $start, string $end): void
    {
        $subject = "Retention Commission Report - $display - All";
        $body    = "See attached Retention Commission Report - $display - All";
        $att     = $this->buildAttachment($file);

        $email = new EmailSenderService();

        // Try TblReports recipients first; fall back to oduai@ for cross-check
        $sent = $email->sendMailUsingTblReports(
            $sql,
            ['RetentionCommissionReport', 'Retention Commission Report'],
            [strtoupper($display)],
            $subject, $body, [$att], true
        );

        if (!$sent) {
            // Fallback: send to oduai@ until TblReports is populated
            $this->info("[INFO] TblReports had no recipients – using fallback.");
            $email->sendMailHtml(
                $subject, $body,
                ['oduai@libertydebtrelief.com'],   // TO
                [],                                 // CC
                [],                                 // BCC
                [$att]
            );
        }
    }

    private function sendAgentReport(DBConnector $sql, array $file, string $display, string $agent): void
    {
        $subject = "Retention Commission Report - $display - $agent";
        $body    = "See attached Retention Commission Report - $display - $agent";
        $att     = $this->buildAttachment($file);

        $email = new EmailSenderService();

        $sent = $email->sendMailUsingTblReports(
            $sql,
            ['RetentionCommissionReport', 'Retention Commission Report'],
            [strtoupper($display)],
            $subject, $body, [$att], true
        );

        if (!$sent) {
            $email->sendMailHtml(
                $subject, $body,
                ['oduai@libertydebtrelief.com'],
                [], [], [$att]
            );
        }
    }

    private function buildAttachment(array $file): array
    {
        return [
            'name'         => $file['filename'],
            'contentType'  => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'contentBytes' => base64_encode((string)file_get_contents($file['path'])),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function initSqlServer(string $source): DBConnector
    {
        $conn = DBConnector::fromEnvironment($source);
        $conn->initializeSqlServer();
        return $conn;
    }

    private function logTblLog(DBConnector $conn, string $display, string $start, string $end): void
    {
        try {
            $macro  = $this->esc('GenerateRetentionCommissionReport');
            $desc   = $this->esc("Retention Commission Report generated for $display $start to $end");
            $action = 'GENERATE_RETENTION_COMMISSION_REPORT';
            $ts     = $this->esc(date('Y-m-d H:i:s'));

            $sql = <<<SQL
DECLARE @hasPK BIT = CASE WHEN COL_LENGTH('dbo.TblLog','PK') IS NULL THEN 0 ELSE 1 END;
DECLARE @isId  BIT = CASE WHEN COLUMNPROPERTY(OBJECT_ID('dbo.TblLog'),'PK','IsIdentity')=1 THEN 1 ELSE 0 END;
IF @hasPK=1 AND @isId=0
BEGIN
    DECLARE @pk INT = ISNULL((SELECT MAX([PK]) FROM [dbo].[TblLog]),0)+1;
    INSERT INTO [dbo].[TblLog]([PK],[Table_Name],[Macro],[Description],[Action],[Result],[Timestamp])
    VALUES(@pk,'TblRetentionCommission','$macro','$desc','$action','SUCCESS','$ts');
END ELSE BEGIN
    INSERT INTO [dbo].[TblLog]([Table_Name],[Macro],[Description],[Action],[Result],[Timestamp])
    VALUES('TblRetentionCommission','$macro','$desc','$action','SUCCESS','$ts');
END;
SQL;
            $conn->querySqlServer($sql);
        } catch (\Throwable $e) {
            Log::warning('GenerateRetentionCommissionReport: TblLog write failed', ['err' => $e->getMessage()]);
        }
    }

    protected function esc(string $v): string
    {
        return str_replace("'", "''", $v);
    }
}
