<?php

namespace Cmd\Reports\Console\Commands\GenerateRetentionBonusCommission;

use Cmd\Reports\Services\DBConnector;
use Cmd\Reports\Services\EmailSenderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Retention Bonus Commission Report – both LDR and PLAW.
 *
 * Faithful port of VBA GenerateRetentionBonusCommission.
 * Queries Snowflake for retained clients in the previous month,
 * cross-references SQL Server TblEnrollment for payment/agent data,
 * checks TblSalesAgentViolations for deductions.
 *
 * LDR:  CUSTOM_IDs 742096/742101/742105, recon status_id 377650
 * PLAW: CUSTOM_IDs 742097/742102/742106, recon status_id 377687
 */
class GenerateRetentionBonusCommission extends Command
{
    protected $signature = 'reports:generate-retention-bonus-commission 
                            {source? : Brand to generate report for (ldr, plaw, or both)}
                            {--month= : Specific month to run for, e.g. 2026-04}';

    protected $description = 'Generate Retention Bonus Commission report for LDR and/or PLAW.';

    private const SOURCE_CONFIG = [
        'ldr' => [
            'display'           => 'LDR',
            'custom_agent'      => 742096,
            'custom_date'       => 742101,
            'custom_results'    => 742105,
            'recon_status_id'   => 377650,
        ],
        'plaw' => [
            'display'           => 'Progress Law',
            'custom_agent'      => 742097,
            'custom_date'       => 742102,
            'custom_results'    => 742106,
            'recon_status_id'   => 377687,
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
        $cfg = self::SOURCE_CONFIG[$source];
        $display = $cfg['display'];
        $this->info("[INFO] GenerateRetentionBonusCommission – $display");

        $targetMonth = $this->option('month');
        if ($targetMonth) {
            $startDate = date('Y-m-01', strtotime($targetMonth . '-01'));
            $endDate   = date('Y-m-t', strtotime($targetMonth . '-01'));
        } else {
            // VBA logic: Previous month
            $startDate = date('Y-m-d', strtotime('first day of last month'));
            $endDate   = date('Y-m-d', strtotime('last day of last month'));
        }
        $this->info("[INFO] Period: $startDate → $endDate");

        try {
            $sf  = DBConnector::fromEnvironment($source);
            $sql = $this->initSqlServer($source);
        } catch (\Throwable $e) {
            $this->error("[$display] Connector init: " . $e->getMessage());
            return;
        }

        try {
            // STEP 1 – base data (only Retained contacts within date range)
            $rows = $this->fetchBase($sf, $cfg, $startDate, $endDate);
            $this->info("[INFO] [$display] Base rows: " . count($rows));

            $ids = array_filter(array_map(fn($r) => (int)($r['ID'] ?? 0), $rows));
            $idList = empty($ids) ? '0' : implode(',', $ids);

            // STEP 2 – reconsideration dates
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

            // STEP 3 – retained dates
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

            // STEP 4 – SQL Server enrollment data per contact
            foreach ($rows as &$row) {
                $id   = (string)($row['ID'] ?? '');
                $llg  = "LLG-$id";
                $safe = str_replace("'", "''", $llg);
                $res  = $sql->querySqlServer(
                    "SELECT First_Payment_Cleared_Date, NULL AS filler, Payments, Agent, Commission_Rate
                     FROM TblEnrollment WHERE LLG_ID='$safe'"
                );
                $en = ($res['data'] ?? [])[0] ?? null;
                $row['FIRST_PAYMENT_CLEARED_DATE'] = $en ? ($en['First_Payment_Cleared_Date'] ?? null) : null;
                $row['PAYMENTS']                   = $en ? (int)($en['Payments']                ?? 0)  : 0;
                $row['AGENT']                      = $en ? ($en['Agent']                        ?? null) : null;
                $row['COMMISSION_RATE']             = $en ? (float)($en['Commission_Rate']      ?? 0)  : 0;
            }
            unset($row);

            // VBA: remove rows with Payments < 2
            $rows = array_values(array_filter($rows, fn($r) => (int)($r['PAYMENTS'] ?? 0) >= 2));

            // STEP 5 – calculate cutoff (first payment + 3 months) and filter
            foreach ($rows as &$row) {
                $fp = $row['FIRST_PAYMENT_CLEARED_DATE'] ?? null;
                if ($fp && strtotime($fp) !== false) {
                    $row['CUTOFF'] = date('Y-m-d', strtotime('+3 months', strtotime($fp)));
                } else {
                    $row['CUTOFF'] = null;
                }
            }
            unset($row);

            // Format dates to Y-m-d so string comparisons work correctly
            foreach ($rows as &$row) {
                foreach (['RETENTION_DATE', 'DROPPED_DATE'] as $dtField) {
                    $val = $row[$dtField] ?? null;
                    if ($val) {
                        if (is_numeric($val)) {
                            // Snowflake returns DATE fields as days since epoch if they aren't formatted strings
                            $ts = $val < 100000 ? (int)$val * 86400 : (int)$val;
                        } else {
                            $ts = strtotime($val);
                        }
                        if ($ts) {
                            $row[$dtField] = date('Y-m-d', $ts);
                        }
                    }
                }
            }
            unset($row);

            // VBA filter conditions
            $rows = array_values(array_filter($rows, function($row) use ($endDate) {
                $retenDate = $row['RETENTION_DATE'] ?? null;
                $dropped   = $row['DROPPED_DATE']   ?? null;
                $cutoff    = $row['CUTOFF']          ?? null;

                // If retention_date > cutoff → remove
                if ($retenDate && $cutoff && $retenDate > $cutoff) return false;
                // If dropped and dropped <= cutoff → remove
                if ($dropped && $cutoff && $dropped !== '' && $dropped <= $cutoff) return false;
                // If cutoff > endDate → remove
                if ($cutoff && $cutoff > $endDate) return false;
                // If payments < 3 → remove
                if ((int)($row['PAYMENTS'] ?? 0) < 3) return false;

                return true;
            }));
            $this->info("[INFO] [$display] Eligible rows after filtering: " . count($rows));

            // STEP 6 – commission and violation deductions
            foreach ($rows as &$row) {
                $id   = (string)($row['ID'] ?? '');
                $debt = (float)($row['ENROLLED_DEBT'] ?? 0);
                $rate = (float)($row['COMMISSION_RATE'] ?? 0);

                if ($rate == 0) {
                    // Default rate: 0.38%
                    $row['COMMISSION_RATE']    = 0.38;
                    $row['VIOLATIONS']         = 0;
                    $row['RETENTION_COMMISSION'] = round($debt * 0.38 / 100 / 2, 2);
                    $row['AGENT_DEDUCTION']    = null;
                } else {
                    // Check violations
                    $safe = str_replace("'", "''", $id);
                    $res  = $sql->querySqlServer(
                        "SELECT ISNULL(SUM(Points),0) AS pts FROM TblSalesAgentViolations WHERE CID='$safe'"
                    );
                    $pts = (float)(($res['data'] ?? [])[0]['pts'] ?? 0);
                    $violations = min($pts / 10, 1.0);
                    $row['VIOLATIONS'] = $violations;

                    $base             = round($debt * $rate / 100 / 2, 2);
                    $adjusted         = round($debt * $rate / 100 * (1 - $violations), 2);
                    $row['RETENTION_COMMISSION'] = $base;
                    $row['AGENT_DEDUCTION']      = min($adjusted, $base);
                }
            }
            unset($row);

            // Build and send workbook
            $formatter = new BonusFormatter();
            $file = $formatter->buildWorkbook($rows, $display, $startDate, $endDate);

            if ($file) {
                $this->info("[INFO] [$display] Workbook: {$file['filename']}");
                $this->sendReport($sql, $file, $display);
                if (file_exists($file['path'])) {
                    @unlink($file['path']);
                }
            }

        } catch (\Throwable $e) {
            $this->error("[$display] Failed: " . $e->getMessage());
            Log::error("GenerateRetentionBonusCommission[$display]: failed", ['ex' => $e]);
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
                CONCAT(c.FIRSTNAME,' ',c.LASTNAME)  AS CLIENT,
                p.RETENTION_AGENT,
                p.RETENTION_DATE,
                p.IMMEDIATE_RESULTS,
                d.ENROLLED_DEBT,
                c.DROPPED_DATE
            FROM PivotedFields p
            JOIN CONTACTS c ON c.ID = p.CONTACT_ID
            LEFT JOIN (
                SELECT CONTACT_ID, SUM(ORIGINAL_DEBT_AMOUNT) AS ENROLLED_DEBT
                FROM DEBTS WHERE ENROLLED=1 AND _FIVETRAN_DELETED=FALSE GROUP BY CONTACT_ID
            ) d ON c.ID=d.CONTACT_ID
            WHERE p.IMMEDIATE_RESULTS = 'Retained'
              AND p.RETENTION_AGENT IS NOT NULL
            ORDER BY p.RETENTION_AGENT ASC
        ";
        $res = $sf->query($sql);
        $rows = $res['data'] ?? [];

        $startTs = strtotime($start);
        $endTs = strtotime($end . ' 23:59:59');

        $filtered = [];
        foreach ($rows as $r) {
            $rd = $r['RETENTION_DATE'] ?? null;
            if (!$rd) continue;
            
            $ts = is_numeric($rd) ? (int)$rd * 86400 : strtotime($rd);
            if ($ts >= $startTs && $ts <= $endTs) {
                $filtered[] = $r;
            }
        }
        return $filtered;
    }

    private function fetchReconsiderationDates(DBConnector $sf, int $statusId, string $idList): array
    {
        $sql = "
            SELECT cs.CONTACT_ID, LEFT(cs.STAMP,10) AS RECON_DATE
            FROM CONTACTS_STATUS cs WHERE cs.STATUS_ID=$statusId
             AND cs.CONTACT_ID IN ($idList)
            ORDER BY cs.CONTACT_ID ASC, cs.STAMP ASC
        ";
        $res = $sf->query($sql);
        $map = [];
        foreach ($res['data'] ?? [] as $r) {
            $id = (string)$r['CONTACT_ID'];
            if (!isset($map[$id])) $map[$id] = $r['RECON_DATE'];
        }
        return $map;
    }

    private function fetchRetainedDates(DBConnector $sf, string $idList): array
    {
        $sql = "
            SELECT cs.CONTACT_ID, LEFT(cs.STAMP,10) AS RETAINED_DATE
            FROM CONTACTS_STATUS cs
            LEFT JOIN CONTACTS_LEAD_STATUS cls ON cs.STATUS_ID=cls.ID
            WHERE UPPER(cls.TITLE) LIKE '%ENROLLED%' AND UPPER(cls.TITLE) NOT LIKE '%RECONSIDERATION%'
             AND cs.CONTACT_ID IN ($idList)
            ORDER BY cs.CONTACT_ID ASC, cs.STAMP ASC
        ";
        $res = $sf->query($sql);
        $map = [];
        foreach ($res['data'] ?? [] as $r) {
            $map[(string)$r['CONTACT_ID']][] = $r['RETAINED_DATE'];
        }
        return $map;
    }

    private function sendReport(DBConnector $sql, array $file, string $display): void
    {
        $subject = "Retention Bonus Commission - $display";
        $body    = "See attached Retention Bonus Commission - $display.";
        $att     = [
            'name'         => $file['filename'],
            'contentType'  => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'contentBytes' => base64_encode((string)file_get_contents($file['path'])),
        ];

        $email = new EmailSenderService();
        $sent  = $email->sendMailUsingTblReports(
            $sql,
            ['RetentionBonusCommission', 'Retention Bonus Commission'],
            [strtoupper($display)],
            $subject, $body, [$att], true
        );

        if (!$sent) {
            // VBA SendTo: jacob@, rama@ → use fallback until TblReports populated
            $email->sendMailHtml($subject, $body, ['oduai@libertydebtrelief.com'], [], [], [$att]);
        }
    }

    private function initSqlServer(string $source): DBConnector
    {
        $c = DBConnector::fromEnvironment($source);
        $c->initializeSqlServer();
        return $c;
    }
}
