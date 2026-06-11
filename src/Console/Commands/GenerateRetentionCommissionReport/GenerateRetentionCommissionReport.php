<?php

declare(strict_types=1);

namespace Cmd\Reports\Console\Commands\GenerateRetentionCommissionReport;

use Cmd\Reports\Services\DBConnector;
use Cmd\Reports\Services\EmailSenderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Shared\Date as XlDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Retention Commission Report (not the bonus/percentage report).
 *
 * Produces two sheets per source:
 *   1. "Retention Commission Report" – one row per retained contact with T1/T2/T3 flat-dollar commissions
 *   2. "Commission Summary"          – one row per retention agent with tier and total commission
 *
 * Sends one workbook for LDR and one for PLAW, both to oduai only.
 *
 * LDR:  custom_agent=742096, custom_date=742101, custom_results=742105,
 *        recon_status_id=377650, cancel_request_custom=742098
 * PLAW: custom_agent=742097, custom_date=742102, custom_results=742106,
 *        recon_status_id=377687, cancel_request_custom=742100
 */
class GenerateRetentionCommissionReport extends Command
{
    protected $signature = 'reports:retention-commission
                            {source=both : ldr | plaw | both}';

    protected $description = 'Generate Retention Commission Report (sends to oduai only for testing).';

    private const SOURCE_CONFIG = [
        'ldr' => [
            'display'               => 'LDR',
            'custom_agent'          => 742096,
            'custom_date'           => 742101,
            'custom_results'        => 742105,
            'recon_status_id'       => 377650,
            'cancel_request_custom' => 742098,
            'has_t4'                => true,
            'agents' => [
                'Alice Kennedy', 'Gracia Rivera', 'John Pozuelos', 'Jose Melgar',
                'Ken Smith', 'Marco Gonzalez', 'Mike Wexford', 'Rick Mills',
                'Javier Deras', 'Andrea Mendoza',
            ],
        ],
        'plaw' => [
            'display'               => 'Progress Law',
            'custom_agent'          => 742097,
            'custom_date'           => 742102,
            'custom_results'        => 742106,
            'recon_status_id'       => 377687,
            'cancel_request_custom' => 742100,
            'has_t4'                => false,
            'agents' => [
                'Alexander Malone', 'Andrea Galves', 'Edgar Gonzalez', 'Maria Lezana',
                'Melody Martinez', 'Nick Jones', 'Theo Clayton', 'Tony Walker',
                'Vicente Gonzalez', 'Alfred Brown',
            ],
        ],
    ];

    // Tier flat-dollar amounts by enrolled_debt bracket
    private const TIERS = [
        ['max' => 15000,       't1' => 2,  't2' => 5,  't3' => 10, 't4' => 40],
        ['max' => 30000,       't1' => 5,  't2' => 10, 't3' => 20, 't4' => 60],
        ['max' => 60000,       't1' => 15, 't2' => 30, 't3' => 40, 't4' => 80],
        ['max' => 100000,      't1' => 20, 't2' => 40, 't3' => 60, 't4' => 100],
        ['max' => PHP_INT_MAX, 't1' => 20, 't2' => 40, 't3' => 60, 't4' => 150],
    ];

    public function handle(): int
    {
        $arg     = strtolower((string) $this->argument('source'));
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

    private function runForSource(string $source): void
    {
        $cfg     = self::SOURCE_CONFIG[$source];
        $display = $cfg['display'];
        $this->info("[INFO] GenerateRetentionCommissionReport – $display");

        $startDate = date('Y-m-01', strtotime('first day of last month'));
        $endDate   = date('Y-m-t', strtotime($startDate));
        $this->info("[INFO] Period: $startDate → $endDate");

        try {
            $sf  = DBConnector::fromEnvironment($source);
            $sql = $this->initSqlServer($source);
        } catch (\Throwable $e) {
            $this->error("[$display] Connector init failed: " . $e->getMessage());
            return;
        }

        try {
            // ── STEP 1: base rows (no date filter — VBA doesn't filter by date on initial query)
            $rows = $this->fetchBase($sf, $cfg);
            $this->info("[INFO] [$display] Base rows: " . count($rows));

            if (empty($rows)) {
                $this->warn("[$display] No rows found.");
                return;
            }

            $ids    = array_filter(array_map(fn ($r) => (int) $this->col($r, 'ID', 0), $rows));
            $idList = empty($ids) ? '0' : implode(',', $ids);

            // ── STEP 2: reconsideration dates → column H (RECONSIDERATION_DATE)
            $reconMap = $this->fetchReconsiderationDates($sf, (int) $cfg['recon_status_id'], $idList);
            foreach ($rows as &$row) {
                $id = (string) $this->col($row, 'ID', '');
                if (!empty($reconMap[$id])) {
                    $row['RECONSIDERATION_DATE'] = $reconMap[$id];
                } else {
                    // VBA fallback: MIN(D, I) = MIN(RETENTION_DATE, DROPPED_DATE)
                    $dates = array_filter([
                        $this->toDate($this->col($row, 'RETENTION_DATE')),
                        $this->toDate($this->col($row, 'DROPPED_DATE')),
                    ]);
                    $row['RECONSIDERATION_DATE'] = $dates ? min($dates) : null;
                }
            }
            unset($row);

            // ── STEP 3: batch-fetch all cleared transaction dates (used for both
            //   cleared-payment count and first-payment-after-recon in step 5)
            $allTxMap = $this->fetchFirstClearedPerContact($sf, $idList);

            foreach ($rows as &$row) {
                $id    = (string) $this->col($row, 'ID', '');
                $recon = $this->toDate($row['RECONSIDERATION_DATE'] ?? null);
                $count = 0;
                if ($recon && !empty($allTxMap[$id])) {
                    foreach ($allTxMap[$id] as $d) {
                        if ($d < $recon) {
                            $count++;
                        }
                    }
                }
                $row['CLEARED_PAYMENTS'] = $count;
            }
            unset($row);

            // ── STEP 4: first enrolled-status date >= reconsideration → column J (RETAINED_DATE)
            $retainedMap = $this->fetchRetainedDates($sf, $idList);
            foreach ($rows as &$row) {
                $recon            = $this->toDate($row['RECONSIDERATION_DATE'] ?? null);
                $row['RETAINED_DATE'] = null;
                $id = (string) $this->col($row, 'ID', '');
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

            // ── STEP 5: reuse the already-fetched transaction map from step 3

            foreach ($rows as &$row) {
                $row['RETENTION_PAYMENT_DATE'] = null;
                $row['T1'] = null;
                $row['T2'] = null;
                $row['T3'] = null;

                $recon    = $this->toDate($row['RECONSIDERATION_DATE'] ?? null);
                $retained = $this->toDate($row['RETAINED_DATE'] ?? null);

                if ($recon === null || $retained === null) {
                    continue;
                }

                $id      = (string) $this->col($row, 'ID', '');
                // Find first cleared date >= recon date from the pre-fetched map
                $firstTx = null;
                foreach ($allTxMap[$id] ?? [] as $txDate) {
                    if ($txDate >= $recon) {
                        $firstTx = $txDate;
                        break;
                    }
                }

                if ($firstTx === null) {
                    continue;
                }

                // VBA: if payment_date >= dropped_date → nullify
                $dropped = $this->toDate($this->col($row, 'DROPPED_DATE'));
                if ($dropped !== null && $firstTx >= $dropped) {
                    continue;
                }

                $row['RETENTION_PAYMENT_DATE'] = $firstTx;

                $debt  = (float) $this->col($row, 'ENROLLED_DEBT', 0);
                $agent = strtoupper((string) $this->col($row, 'RETENTION_AGENT', ''));
                $multi = ($agent === 'SYDNEY LEYVA') ? 2 : 1;
                $tier  = $this->tierAmounts($debt);

                $row['T1'] = $tier['t1'] * $multi;
                $row['T2'] = $tier['t2'] * $multi;
                $row['T3'] = $tier['t3'] * $multi;
                $row['T4'] = $tier['t4'] * $multi;
            }
            unset($row);

            $this->info("[INFO] [$display] Rows after processing: " . count($rows));

            // ── STEP 6: fetch agent locations from SQL Server
            $locationMap = $this->fetchLocationMap($sql, $cfg['agents']);

            // ── STEP 7: build workbook with both sheets
            $file = $this->buildWorkbook($rows, $cfg, $display, $startDate, $endDate, $locationMap);

            if ($file) {
                $this->info("[INFO] [$display] Workbook built: {$file['filename']}");
                $this->sendReport($file, $display);
                if (file_exists($file['path'])) {
                    @unlink($file['path']);
                }
            } else {
                $this->error("[$display] Workbook generation failed.");
            }

        } catch (\Throwable $e) {
            $this->error("[$display] Failed: " . $e->getMessage());
            Log::error("GenerateRetentionCommissionReportCommand[$display] failed", [
                'exception' => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]);
        }
    }

    // ─── Data fetchers ────────────────────────────────────────────────────────

    /** @param array<string,mixed> $cfg */
    private function fetchBase(DBConnector $sf, array $cfg): array
    {
        $ca = (int) $cfg['custom_agent'];
        $cd = (int) $cfg['custom_date'];
        $cr = (int) $cfg['custom_results'];
        $cc = (int) $cfg['cancel_request_custom'];

        $sql = "
            WITH PivotedFields AS (
                SELECT
                    CONTACT_ID,
                    MAX(CASE WHEN CUSTOM_ID = $ca THEN F_STRING END) AS RETENTION_AGENT,
                    MAX(CASE WHEN CUSTOM_ID = $cd THEN F_DATE   END) AS RETENTION_DATE,
                    MAX(CASE WHEN CUSTOM_ID = $cr THEN F_STRING END) AS IMMEDIATE_RESULTS,
                    MAX(CASE WHEN CUSTOM_ID = $cc THEN TO_DATE(F_DATETIME) END) AS CANCEL_REQUEST_DATE
                FROM CONTACTS_USERFIELDS
                WHERE CUSTOM_ID IN ($ca, $cd, $cr, $cc)
                GROUP BY CONTACT_ID
            )
            SELECT
                c.ID,
                CONCAT(c.FIRSTNAME,' ',c.LASTNAME)                     AS CLIENT,
                p.RETENTION_AGENT,
                LEFT(p.RETENTION_DATE, 10)                             AS RETENTION_DATE,
                p.IMMEDIATE_RESULTS,
                d.ENROLLED_DEBT,
                LEFT(c.DROPPED_DATE, 10)                               AS DROPPED_DATE,
                TO_VARCHAR(p.CANCEL_REQUEST_DATE, 'YYYY-MM-DD')        AS CANCEL_REQUEST_DATE
            FROM PivotedFields p
            JOIN CONTACTS c ON c.ID = p.CONTACT_ID
            LEFT JOIN (
                SELECT CONTACT_ID, SUM(ORIGINAL_DEBT_AMOUNT) AS ENROLLED_DEBT
                FROM DEBTS
                WHERE ENROLLED=1 AND _FIVETRAN_DELETED=FALSE
                GROUP BY CONTACT_ID
            ) d ON c.ID = d.CONTACT_ID
            WHERE p.RETENTION_AGENT IS NOT NULL
              AND p.IMMEDIATE_RESULTS IS NOT NULL
            ORDER BY p.RETENTION_AGENT ASC
        ";

        return $sf->query($sql)['data'] ?? [];
    }

    private function fetchReconsiderationDates(DBConnector $sf, int $statusId, string $idList): array
    {
        $sql = "
            SELECT cs.CONTACT_ID, LEFT(cs.STAMP,10) AS RECON_DATE
            FROM CONTACTS_STATUS cs
            WHERE cs.STATUS_ID = $statusId
              AND cs.CONTACT_ID IN ($idList)
            ORDER BY cs.CONTACT_ID ASC, cs.STAMP ASC
        ";
        $map = [];
        foreach ($sf->query($sql)['data'] ?? [] as $r) {
            $id = (string) $r['CONTACT_ID'];
            if (!isset($map[$id])) {
                $map[$id] = $r['RECON_DATE'];
            }
        }
        return $map;
    }

    /** Returns map of contact_id → array of enrolled-status dates (sorted asc) */
    private function fetchRetainedDates(DBConnector $sf, string $idList): array
    {
        $sql = "
            SELECT cs.CONTACT_ID, LEFT(cs.STAMP,10) AS RETAINED_DATE
            FROM CONTACTS_STATUS cs
            LEFT JOIN CONTACTS_LEAD_STATUS cls ON cs.STATUS_ID = cls.ID
            WHERE UPPER(cls.TITLE) LIKE '%ENROLLED%'
              AND UPPER(cls.TITLE) NOT LIKE '%RECONSIDERATION%'
              AND cs.CONTACT_ID IN ($idList)
            ORDER BY cs.CONTACT_ID ASC, cs.STAMP ASC
        ";
        $map = [];
        foreach ($sf->query($sql)['data'] ?? [] as $r) {
            $map[(string) $r['CONTACT_ID']][] = substr((string) $r['RETAINED_DATE'], 0, 10);
        }
        return $map;
    }

    /**
     * Fetch all cleared transaction dates per contact in one query.
     * Returns map of contact_id → array of cleared dates sorted ASC.
     * PHP then finds the first date >= recon date per row.
     */
    private function fetchFirstClearedPerContact(DBConnector $sf, string $idList): array
    {
        $sql = "
            SELECT CONTACT_ID, LEFT(CLEARED_DATE,10) AS CLEARED_DATE
            FROM TRANSACTIONS
            WHERE TRANS_TYPE = 'D'
              AND CLEARED_DATE IS NOT NULL
              AND RETURNED_DATE IS NULL
              AND CONTACT_ID IN ($idList)
            ORDER BY CONTACT_ID ASC, CLEARED_DATE ASC
        ";
        $map = [];
        foreach ($sf->query($sql)['data'] ?? [] as $r) {
            $map[(string) $r['CONTACT_ID']][] = substr((string) $r['CLEARED_DATE'], 0, 10);
        }
        return $map;
    }

    // ─── Workbook builder ─────────────────────────────────────────────────────

    /**
     * @param array<int,array<string,mixed>> $rows
     * @param array<string,mixed> $cfg
     * @param array<string,string> $locationMap
     * @return array{filename:string,path:string}|null
     */
    private function buildWorkbook(array $rows, array $cfg, string $display, string $startDate, string $endDate, array $locationMap = []): ?array
    {
        try {
            $sp = new Spreadsheet();

            // ── Sheet 1: Retention Commission Report
            $sheet1 = $sp->getActiveSheet();
            $sheet1->setTitle('Retention Commission Report');
            $sheet1->setShowGridlines(false);

            $hasT4 = (bool) ($cfg['has_t4'] ?? false);
            $headers1 = [
                'ID', 'Client', 'Retention Agent', 'Retention Date', 'Immediate Results',
                'Enrolled Debt', 'Cleared Payments', 'Reconsideration Date', 'Dropped Date',
                'Retained Date', 'Retention Payment Date',
                'Retention Commission T1', 'Retention Commission T2', 'Retention Commission T3',
            ];
            if ($hasT4) {
                $headers1[] = 'Retention Commission T4';
            }
            $headers1[] = 'Cancel Request Date';
            $lastDataCol = $hasT4 ? 'P' : 'O';
            $cancelCol   = $hasT4 ? 'P' : 'O';

            foreach ($headers1 as $i => $h) {
                $sheet1->setCellValue(chr(65 + $i) . '1', $h);
            }
            $this->headerStyle($sheet1, "A1:{$lastDataCol}1");

            $r = 2;
            foreach ($rows as $row) {
                $sheet1->setCellValue("A$r", $this->col($row, 'ID', ''));
                $sheet1->setCellValue("B$r", $this->col($row, 'CLIENT', ''));
                $sheet1->setCellValue("C$r", $this->col($row, 'RETENTION_AGENT', ''));
                $this->setDate($sheet1, "D$r", $this->col($row, 'RETENTION_DATE'));
                $sheet1->setCellValue("E$r", $this->col($row, 'IMMEDIATE_RESULTS', ''));
                $sheet1->setCellValue("F$r", (float) $this->col($row, 'ENROLLED_DEBT', 0));
                $sheet1->setCellValue("G$r", (int)   $this->col($row, 'CLEARED_PAYMENTS', 0));
                $this->setDate($sheet1, "H$r", $this->col($row, 'RECONSIDERATION_DATE'));
                $this->setDate($sheet1, "I$r", $this->col($row, 'DROPPED_DATE'));
                $this->setDate($sheet1, "J$r", $this->col($row, 'RETAINED_DATE'));
                $this->setDate($sheet1, "K$r", $this->col($row, 'RETENTION_PAYMENT_DATE'));
                $sheet1->setCellValue("L$r", $row['T1'] ?? '');
                $sheet1->setCellValue("M$r", $row['T2'] ?? '');
                $sheet1->setCellValue("N$r", $row['T3'] ?? '');
                if ($hasT4) {
                    $sheet1->setCellValue("O$r", $row['T4'] ?? '');
                    $this->setDate($sheet1, "P$r", $this->col($row, 'CANCEL_REQUEST_DATE'));
                } else {
                    $this->setDate($sheet1, "O$r", $this->col($row, 'CANCEL_REQUEST_DATE'));
                }
                $r++;
            }

            $last1 = max($r - 1, 1);
            $dateCols = ['D', 'H', 'I', 'J', 'K', $cancelCol];
            foreach ($dateCols as $c) {
                $sheet1->getStyle("{$c}2:{$c}{$last1}")->getNumberFormat()->setFormatCode('mm/dd/yyyy');
            }
            $sheet1->getStyle("F2:F{$last1}")->getNumberFormat()->setFormatCode('$#,##0');
            $tierRange = $hasT4 ? "L2:O{$last1}" : "L2:N{$last1}";
            $sheet1->getStyle($tierRange)->getNumberFormat()->setFormatCode('$#,##0');
            if ($last1 > 1) {
                $sheet1->getStyle("A1:{$lastDataCol}{$last1}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
            }
            foreach (range('A', $lastDataCol) as $c) {
                $sheet1->getColumnDimension($c)->setAutoSize(true);
            }
            $sheet1->getStyle("A1:{$lastDataCol}{$last1}")->getFont()->setName('Calibri')->setSize(9);
            $sheet1->freezePane('A2');
            $sheet1->setSelectedCells('A1');

            // ── Sheet 2: Commission Summary
            $sp->createSheet();
            $sheet2 = $sp->getSheet(1);
            $sheet2->setTitle('Commission Summary');
            $sheet2->setShowGridlines(false);

            $sumHeaders = ['Retention Agent', 'Assigned', 'Retained', '% Retained', 'Tier', 'Commission', 'Location'];
            foreach ($sumHeaders as $i => $h) {
                $sheet2->setCellValue(chr(65 + $i) . '1', $h);
            }
            $this->headerStyle($sheet2, 'A1:G1');

            $agents      = $cfg['agents'];
            $summaryRows = $this->buildSummary($rows, $agents, $startDate, $endDate, $locationMap);

            $r2 = 2;
            foreach ($summaryRows as $agentName => $sum) {
                $sheet2->setCellValue("A$r2", $agentName);
                $sheet2->setCellValue("B$r2", $sum['assigned']);
                $sheet2->setCellValue("C$r2", $sum['retained']);
                $sheet2->setCellValue("D$r2", $sum['pct_retained']);
                $sheet2->setCellValue("E$r2", $sum['tier']);
                $sheet2->setCellValue("F$r2", $sum['commission']);
                $sheet2->setCellValue("G$r2", $sum['location'] ?? '');
                $r2++;
            }

            $last2 = max($r2 - 1, 1);
            $sheet2->getStyle("D2:D{$last2}")->getNumberFormat()->setFormatCode('0%');
            $sheet2->getStyle("F2:F{$last2}")->getNumberFormat()->setFormatCode('$#,##0');
            if ($last2 > 1) {
                $sheet2->getStyle("A1:G{$last2}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
            }
            foreach (range('A', 'G') as $c) {
                $sheet2->getColumnDimension($c)->setAutoSize(true);
            }
            $sheet2->getStyle("A1:G{$last2}")->getFont()->setName('Calibri')->setSize(9);
            $sheet2->freezePane('A2');
            $sheet2->setSelectedCells('A1');

            $sp->setActiveSheetIndex(0);

            $filename = "Retention Commission ({$display}) - All.xlsx";
            $path     = storage_path("app/{$filename}");
            (new Xlsx($sp))->save($path);

            return ['filename' => $filename, 'path' => $path];

        } catch (\Throwable $e) {
            Log::error('GenerateRetentionCommissionReportCommand::buildWorkbook failed', ['err' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Replicates the Commission Summary sheet formulas in PHP.
     *
     * Assigned  = rows where cancel_request_date falls in period
     * Retained  = rows where retention_date falls in period
     * Tier      = 0 if pct<20%, 1 if <35%, 2 if <50%, 3 otherwise
     * Commission= sum of T{tier} for rows where retention_payment_date falls in period
     *
     * @param  array<int,array<string,mixed>> $rows
     * @param  string[] $agents
     * @param  array<string,string> $locationMap
     * @return array<string,array{assigned:int,retained:int,pct_retained:float,tier:int,commission:float,location:string}>
     */
    private function buildSummary(array $rows, array $agents, string $startDate, string $endDate, array $locationMap = []): array
    {
        $summary = [];

        foreach ($agents as $agentName) {
            $agentUpper = strtoupper($agentName);

            $assigned   = 0;
            $retained   = 0;
            $commission = 0.0;

            foreach ($rows as $row) {
                $rowAgent = strtoupper((string) $this->col($row, 'RETENTION_AGENT', ''));
                if ($rowAgent !== $agentUpper) {
                    continue;
                }

                // Assigned: cancel_request_date falls in period
                $cancelDate = $this->toDate($this->col($row, 'CANCEL_REQUEST_DATE'));
                if ($cancelDate && $cancelDate >= $startDate && $cancelDate <= $endDate) {
                    $assigned++;
                }

                // Retained: retention_date falls in period
                $retentionDate = $this->toDate($this->col($row, 'RETENTION_DATE'));
                if ($retentionDate && $retentionDate >= $startDate && $retentionDate <= $endDate) {
                    $retained++;
                }
            }

            $pct  = ($assigned > 0) ? ($retained / $assigned) : 0.0;
            $tier = match (true) {
                $pct < 0.20 => 0,
                $pct < 0.35 => 1,
                $pct < 0.50 => 2,
                $pct < 0.65 => 3,
                default     => 4,
            };

            // Sum commission using the agent's tier column for rows where payment landed in period
            $tierCol = $tier > 0 ? "T$tier" : null;
            foreach ($rows as $row) {
                $rowAgent = strtoupper((string) $this->col($row, 'RETENTION_AGENT', ''));
                if ($rowAgent !== $agentUpper) {
                    continue;
                }
                $payDate = $this->toDate($this->col($row, 'RETENTION_PAYMENT_DATE'));
                if ($payDate && $payDate >= $startDate && $payDate <= $endDate && $tierCol !== null) {
                    $commission += (float) $this->col($row, $tierCol, 0);
                }
            }

            $summary[$agentName] = [
                'assigned'    => $assigned,
                'retained'    => $retained,
                'pct_retained'=> $pct,
                'tier'        => $tier,
                'commission'  => $commission,
                'location'    => $locationMap[strtoupper($agentName)] ?? '',
            ];
        }

        return $summary;
    }

    // ─── Email ────────────────────────────────────────────────────────────────

    private function sendReport(array $file, string $display): void
    {
        $subject = "Retention Commission Report - $display";
        $body    = "See attached Retention Commission Report - $display.";
        $att     = [
            'name'         => $file['filename'],
            'contentType'  => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'contentBytes' => base64_encode((string) file_get_contents($file['path'])),
        ];

        $sql   = $this->initSqlServer('ldr');
        $email = new EmailSenderService();
        $sent  = $email->sendMailUsingTblReports(
            $sql,
            ['RetentionCommissionReport', 'Retention Commission Report'],
            [strtoupper($display)],
            $subject,
            $body,
            [$att],
            true
        );

        if (!$sent) {
            $email->sendMailHtml($subject, $body, ['oduai@libertydebtrelief.com'], [], [], [$att]);
        }

        $this->info("[INFO] [$display] Email sent.");
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /** @return array{t1:int,t2:int,t3:int,t4:int} */
    private function tierAmounts(float $debt): array
    {
        foreach (self::TIERS as $bracket) {
            if ($debt <= $bracket['max']) {
                return ['t1' => $bracket['t1'], 't2' => $bracket['t2'], 't3' => $bracket['t3'], 't4' => $bracket['t4']];
            }
        }
        return ['t1' => 20, 't2' => 40, 't3' => 60, 't4' => 60];
    }

    private function col(array $row, string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $row)) {
            return $row[$key];
        }
        $lower = strtolower($key);
        foreach ($row as $k => $v) {
            if (strtolower((string) $k) === $lower) {
                return $v;
            }
        }
        return $default;
    }

    private function toDate(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            $ts = (int) $value;
            return $ts > 0 ? date('Y-m-d', $ts) : null;
        }
        $ts = strtotime((string) $value);
        return $ts === false ? null : date('Y-m-d', $ts);
    }

    private function headerStyle(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font'      => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF17853B']],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        ]);
    }

    private function setDate(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, string $cell, mixed $val): void
    {
        $d = $this->toDate($val);
        if ($d !== null) {
            $sheet->setCellValue($cell, XlDate::PHPToExcel(strtotime($d)));
            $sheet->getStyle($cell)->getNumberFormat()->setFormatCode('mm/dd/yyyy');
        }
    }

    private function initSqlServer(string $source): DBConnector
    {
        $c = DBConnector::fromEnvironment($source);
        $c->initializeSqlServer();
        return $c;
    }

    /**
     * Fetch Location from TblEmployees keyed by UPPER(Employee_Name).
     *
     * @param  string[] $agents
     * @return array<string,string>
     */
    private function fetchLocationMap(DBConnector $sql, array $agents): array
    {
        if (empty($agents)) {
            return [];
        }
        $list = implode(',', array_map(fn ($a) => "'" . str_replace("'", "''", $a) . "'", $agents));
        $res  = $sql->querySqlServer(
            "SELECT Employee_Name, Location FROM TblEmployees WHERE Employee_Name IN ($list)"
        );
        $map = [];
        foreach ($res['data'] ?? [] as $row) {
            $name = strtoupper((string) ($row['Employee_Name'] ?? $row['employee_name'] ?? ''));
            $map[$name] = (string) ($row['Location'] ?? $row['location'] ?? '');
        }
        return $map;
    }
}
