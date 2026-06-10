<?php

namespace Cmd\Reports\Console\Commands\GenerateRetentionCommissionSummary;

use Cmd\Reports\Services\DBConnector;
use Cmd\Reports\Services\EmailSenderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Real-time (current month) Retention Commission Summary.
 *
 * Faithful port of VBA GenerateRetentionCommissionSummary for both LDR and PLAW.
 * Sends an HTML table email – no Excel attachment.
 *
 * LDR:  status_id 332312, CC: tony@, jose@, justin@, jacob@
 * PLAW: status_id 332312, CC: nickj@, melody@, lucas@, jacob@
 */
class GenerateRetentionCommissionSummary extends Command
{
    protected $signature = 'reports:generate-retention-commission-summary
                            {source=both : ldr | plaw | both}';

    protected $description = 'Generate and email the real-time Retention Commission Summary (HTML table).';

    private const TIERS = [
        [15000,  2,  5, 10],
        [30000,  5, 10, 20],
        [60000, 15, 30, 40],
        [PHP_INT_MAX, 20, 40, 60],
    ];

    private const SOURCE_CONFIG = [
        'ldr' => [
            'display'           => 'LDR',
            'custom_agent'      => 742096,
            'custom_date'       => 742101,
            'custom_results'    => 742105,
            'recon_status_id'   => 332312,
            'agents_filter'     => ['JOSE CHOCANA','LAURA BROWN','IVY MORGAN','KEN SMITH','JOHN POZUELOS','MIKE WEXFORD','KEVIN NIXON','ALICE KENNEDY'],
            'send_cc'           => ['tony@libertydebtrelief.com','jose@libertydebtrelief.com','justin@libertydebtrelief.com','jacob@libertydebtrelief.com'],
        ],
        'plaw' => [
            'display'           => 'PLAW',
            'custom_agent'      => 742097,
            'custom_date'       => 742102,
            'custom_results'    => 742106,
            'recon_status_id'   => 332312,
            'agents_filter'     => ['MELODY MARTINEZ','NICK JONES','JUSTIN WILSON','VICENTE GONZALEZ','MARIA LEZANA','THEO CLAYTON','MADELINE MORRIS'],
            'send_cc'           => ['nickj@libertydebtrelief.com','melody@libertydebtrelief.com','lucas@libertydebtrelief.com','jacob@libertydebtrelief.com'],
        ],
    ];

    public function handle(): int
    {
        $arg     = strtolower((string)$this->argument('source'));
        $sources = ($arg === 'both') ? ['ldr', 'plaw'] : [$arg];

        foreach ($sources as $src) {
            if (!isset(self::SOURCE_CONFIG[$src])) {
                $this->error("Unknown source: $src");
                return Command::FAILURE;
            }
            $this->runForSource($src);
        }
        return Command::SUCCESS;
    }

    private function runForSource(string $source): void
    {
        $cfg     = self::SOURCE_CONFIG[$source];
        $display = $cfg['display'];

        $this->info("[INFO] GenerateRetentionCommissionSummary – $display");

        // Current month (VBA: StartDate = DateSerial(Year, Month, 1))
        $startDate = date('Y-m-01');
        $endDate   = date('Y-m-t');
        $today     = date('Y-m-d');
        $this->info("[INFO] Period: $startDate → $today");

        try {
            $sf  = DBConnector::fromEnvironment($source);
            $sql = $this->initSqlServer($source);
        } catch (\Throwable $e) {
            $this->error("[$display] Connector init: " . $e->getMessage());
            return;
        }

        try {
            // STEP 1 – base data
            $rows = $this->fetchBase($sf, $cfg, $startDate, $endDate);

            $ids = array_filter(array_map(fn($r) => (int)($r['ID'] ?? 0), $rows));
            $idList = empty($ids) ? '0' : implode(',', $ids);

            // STEP 2 – reconsideration dates (VBA status 332312, ORDER BY DESC → latest)
            $reconMap = $this->fetchReconsiderationDates($sf, $cfg['recon_status_id'], $idList);

            foreach ($rows as &$row) {
                $id = (string)($row['ID'] ?? '');
                if (!empty($reconMap[$id])) {
                    $row['RECONSIDERATION_DATE'] = $reconMap[$id];
                } else {
                    $dates = array_filter([$row['RETENTION_DATE'] ?? null, $row['DROPPED_DATE'] ?? null]);
                    $row['RECONSIDERATION_DATE'] = $dates ? min($dates) : null;
                }
            }
            unset($row);

            // VBA: remove rows where reconsideration_date = 0 (no genuine reconsideration)
            $rows = array_values(array_filter($rows, fn($r) => !empty($r['RECONSIDERATION_DATE'])));

            // STEP 3 – cleared payments
            $clearedMap = $this->fetchClearedPayments($sf, $idList);
            foreach ($rows as &$row) {
                $recon = $row['RECONSIDERATION_DATE'] ?? null;
                $row['CLEARED_PAYMENTS'] = 0;
                $id = (string)($row['ID'] ?? '');
                if ($recon && !empty($clearedMap[$id])) {
                    foreach ($clearedMap[$id] as $cd) {
                        if ($cd < $recon) $row['CLEARED_PAYMENTS']++;
                    }
                }
            }
            unset($row);

            // STEP 4 – retained dates
            $retainedMap = $this->fetchRetainedDates($sf, $idList);
            foreach ($rows as &$row) {
                $recon = $row['RECONSIDERATION_DATE'] ?? null;
                $row['RETAINED_DATE'] = null;
                $id = (string)($row['ID'] ?? '');
                if ($recon && !empty($retainedMap[$id])) {
                    foreach ($retainedMap[$id] as $rd) {
                        if ($rd >= $recon) { $row['RETAINED_DATE'] = $rd; break; }
                    }
                }
            }
            unset($row);

            // STEP 5 – retention payment + commissions (VBA: G >= 1 AND J <> "")
            foreach ($rows as &$row) {
                $id    = (string)($row['ID'] ?? '');
                $recon = $row['RECONSIDERATION_DATE'] ?? null;
                $retd  = $row['RETAINED_DATE'] ?? null;

                if ($row['CLEARED_PAYMENTS'] >= 1 && $retd) {
                    $fp = $this->fetchFirstPayment($sf, $id, $recon ?? '1900-01-01');
                    $row['RETENTION_PAYMENT_DATE'] = $fp;

                    if ($fp) {
                        [$t1, $t2, $t3] = $this->calcCommission(
                            (float)($row['ENROLLED_DEBT'] ?? 0),
                            (string)($row['RETENTION_AGENT'] ?? '')
                        );
                        $row['RETENTION_COMMISSION_T1'] = $t1;
                        $row['RETENTION_COMMISSION_T2'] = $t2;
                        $row['RETENTION_COMMISSION_T3'] = $t3;
                    }
                }
            }
            unset($row);

            // Build summary per agent
            $summary = $this->buildSummary($rows, $cfg, $startDate, $endDate);

            // Send HTML email
            $this->sendSummaryEmail($sql, $summary, $display, $cfg, $startDate, $today);
            $this->info("[INFO] [$display] Summary email sent.");

        } catch (\Throwable $e) {
            $this->error("[$display] Summary failed: " . $e->getMessage());
            Log::error("GenerateRetentionCommissionSummary[$display]: failed", ['ex' => $e]);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    private function fetchBase(DBConnector $sf, array $cfg, string $start, string $end): array
    {
        $ca = (int)$cfg['custom_agent'];
        $cd = (int)$cfg['custom_date'];
        $cr = (int)$cfg['custom_results'];

        $sql = "
            WITH PivotedFields AS (
                SELECT 
                    CONTACT_ID,
                    MAX(CASE WHEN CUSTOM_ID = $ca THEN F_STRING END) AS RETENTION_AGENT,
                    MAX(CASE WHEN CUSTOM_ID = $cd THEN F_DATE END) AS RETENTION_DATE,
                    MAX(CASE WHEN CUSTOM_ID = $cr THEN F_STRING END) AS IMMEDIATE_RESULTS
                FROM CONTACTS_USERFIELDS
                WHERE CUSTOM_ID IN ($ca, $cd, $cr)
                GROUP BY CONTACT_ID
            )
            SELECT
                c.ID,
                CONCAT(c.FIRSTNAME, ' ', c.LASTNAME) AS CLIENT,
                p.RETENTION_AGENT,
                LEFT(p.RETENTION_DATE, 10) AS RETENTION_DATE,
                p.IMMEDIATE_RESULTS,
                d.ENROLLED_DEBT,
                LEFT(c.DROPPED_DATE, 10) AS DROPPED_DATE
            FROM PivotedFields p
            JOIN CONTACTS c ON c.ID = p.CONTACT_ID
            LEFT JOIN (
                SELECT CONTACT_ID, SUM(ORIGINAL_DEBT_AMOUNT) AS ENROLLED_DEBT
                FROM   DEBTS
                WHERE  ENROLLED = 1 AND _FIVETRAN_DELETED = FALSE
                GROUP BY CONTACT_ID
            ) AS d ON c.ID = d.CONTACT_ID
            WHERE (
                   (p.RETENTION_DATE >= '$start' AND p.RETENTION_DATE <= '$end')
                OR (LEFT(c.DROPPED_DATE, 10) >= '$start' AND LEFT(c.DROPPED_DATE, 10) <= '$end')
            )
              AND p.RETENTION_AGENT IS NOT NULL
            ORDER BY p.RETENTION_AGENT ASC
        ";
        $res = $sf->query($sql);
        return $res['data'] ?? [];
    }

    private function fetchReconsiderationDates(DBConnector $sf, int $statusId, string $idList): array
    {
        // VBA Summary uses ORDER BY DESC → get latest date per contact
        $sql = "
            SELECT cs.CONTACT_ID, LEFT(cs.STAMP, 10) AS RECON_DATE
            FROM   CONTACTS_STATUS AS cs
            WHERE  cs.STATUS_ID = $statusId
              AND  cs.CONTACT_ID IN ($idList)
            ORDER  BY cs.CONTACT_ID ASC, cs.STAMP DESC
        ";
        $res = $sf->query($sql);
        $map = [];
        foreach ($res['data'] ?? [] as $r) {
            $id = (string)$r['CONTACT_ID'];
            if (!isset($map[$id])) {
                $map[$id] = $r['RECON_DATE'];
            }
        }
        return $map;
    }

    private function fetchClearedPayments(DBConnector $sf, string $idList): array
    {
        $sql = "
            SELECT CONTACT_ID, CLEARED_DATE
            FROM   TRANSACTIONS
            WHERE  TRANS_TYPE='D' AND CLEARED_DATE IS NOT NULL AND RETURNED_DATE IS NULL
              AND  CONTACT_ID IN ($idList)
        ";
        $res = $sf->query($sql);
        $map = [];
        foreach ($res['data'] ?? [] as $r) {
            $map[(string)$r['CONTACT_ID']][] = $r['CLEARED_DATE'];
        }
        return $map;
    }

    private function fetchRetainedDates(DBConnector $sf, string $idList): array
    {
        $sql = "
            SELECT cs.CONTACT_ID, LEFT(cs.STAMP, 10) AS RETAINED_DATE
            FROM   CONTACTS_STATUS cs
            LEFT JOIN CONTACTS_LEAD_STATUS cls ON cs.STATUS_ID = cls.ID
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

    private function fetchFirstPayment(DBConnector $sf, string $cid, string $afterDate): ?string
    {
        $id   = (int)$cid;
        $date = str_replace("'", "''", $afterDate);
        $sql = "
            SELECT CLEARED_DATE
            FROM (
                SELECT CLEARED_DATE,
                       ROW_NUMBER() OVER (PARTITION BY CONTACT_ID ORDER BY CLEARED_DATE ASC) AS N
                FROM   TRANSACTIONS
                WHERE  CONTACT_ID=$id AND TRANS_TYPE='D'
                  AND  CLEARED_DATE IS NOT NULL AND RETURNED_DATE IS NULL
                  AND  CLEARED_DATE >= '$date'
            ) WHERE N=1
        ";
        $res = $sf->query($sql);
        $row = ($res['data'] ?? [])[0] ?? null;
        return $row ? ($row['CLEARED_DATE'] ?? null) : null;
    }

    private function calcCommission(float $debt, string $agent): array
    {
        $t1 = $t2 = $t3 = null;
        foreach (self::TIERS as [$max, $a, $b, $c]) {
            if ($debt <= $max) { $t1=$a; $t2=$b; $t3=$c; break; }
        }
        if ($t1 !== null && strtoupper($agent) === 'SYDNEY LEYVA') {
            $t1*=2; $t2*=2; $t3*=2;
        }
        return [$t1, $t2, $t3];
    }

    // ─────────────────────────────────────────────────────────────────────────
    private function buildSummary(array $rows, array $cfg, string $start, string $end): array
    {
        $agentsFilter = $cfg['agents_filter'];
        $byAgent = [];
        foreach ($rows as $row) {
            $ag = strtoupper(trim((string)($row['RETENTION_AGENT'] ?? '')));
            $byAgent[$ag][] = $row;
        }

        $summary = [];
        foreach ($agentsFilter as $agUpper) {
            $agRows = $byAgent[$agUpper] ?? [];

            // VBA B col: COUNTIFS dropped_date in range
            $dropped = 0;
            foreach ($agRows as $r) {
                $d = $r['DROPPED_DATE'] ?? null;
                if ($d && $d >= $start && $d <= $end) $dropped++;
            }

            // VBA C col: COUNTIFS retention_date in range
            $retained = 0;
            foreach ($agRows as $r) {
                $d = $r['RETENTION_DATE'] ?? null;
                if ($d && $d >= $start && $d <= $end) $retained++;
            }

            $pct  = ($dropped + $retained) > 0 ? ($retained / ($dropped + $retained)) : 0;
            $tier = match(true) {
                $pct < 0.20 => 0,
                $pct < 0.35 => 1,
                $pct < 0.50 => 2,
                default     => 3,
            };

            $commission = 0.0;
            if ($tier > 0) {
                $tk = 'RETENTION_COMMISSION_T' . $tier;
                foreach ($agRows as $r) {
                    $pay = $r['RETENTION_PAYMENT_DATE'] ?? null;
                    if ($pay && $pay >= $start && $pay <= $end) {
                        $commission += (float)($r[$tk] ?? 0);
                    }
                }
            }

            $summary[] = [
                'agent'    => ucwords(strtolower($agUpper)),
                'dropped'  => $dropped,
                'retained' => $retained,
                'pct'      => $pct,
                'tier'     => $tier,
                'commission' => $commission,
            ];
        }

        return $summary;
    }

    // ─────────────────────────────────────────────────────────────────────────
    private function sendSummaryEmail(
        DBConnector $sql,
        array $summary,
        string $display,
        array $cfg,
        string $startDate,
        string $today
    ): void {
        $subject = "Retention Summary ($display) - $startDate to $today";

        $body = '<table border="1" cellpadding="4" cellspacing="0" style="border-collapse:collapse;font-family:Calibri,Arial;font-size:13px;">'
              . '<tr style="background:#17853b;color:#fff;text-align:center;">'
              . '<th>Agent</th><th>Dropped</th><th>Retained</th><th>% Retained</th><th>Tier</th>'
              . '</tr>';

        foreach ($summary as $row) {
            $pctFmt = number_format($row['pct'] * 100, 1) . '%';
            $body .= '<tr align="right">'
                   . "<td align='left'>{$row['agent']}</td>"
                   . "<td>{$row['dropped']}</td>"
                   . "<td>{$row['retained']}</td>"
                   . "<td>$pctFmt</td>"
                   . "<td>{$row['tier']}</td>"
                   . '</tr>';
        }
        $body .= '</table>';

        $to  = ['rama@libertydebtrelief.com','candice@libertydebtrelief.com',
                'adrian@libertydebtrelief.com','scarlett@libertydebtrelief.com'];
        $cc  = $cfg['send_cc'];
        $bcc = ['oduai@libertydebtrelief.com'];

        $email = new EmailSenderService();

        $sent = $email->sendMailUsingTblReports(
            $sql,
            ['RetentionCommissionSummary', 'Retention Commission Summary'],
            [strtoupper($display)],
            $subject, $body, [], true
        );

        if (!$sent) {
            // Fallback to oduai@ until TblReports is populated
            $email->sendMailHtml($subject, $body, ['oduai@libertydebtrelief.com'], [], [], []);
        }
    }

    private function initSqlServer(string $source): DBConnector
    {
        $c = DBConnector::fromEnvironment($source);
        $c->initializeSqlServer();
        return $c;
    }
}
