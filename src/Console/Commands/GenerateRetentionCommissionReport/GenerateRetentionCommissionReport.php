<?php

declare(strict_types=1);

namespace Cmd\Reports\Console\Commands\GenerateRetentionCommissionReport;

use Cmd\Reports\Services\DBConnector;
use Cmd\Reports\Services\CommissionAgentEmailFiles;
use Cmd\Reports\Services\CommissionCompanyMatch;
use Cmd\Reports\Services\CommissionResultsWriter;
use Cmd\Reports\Services\CommissionRosterProvider;
use Cmd\Reports\Services\EmailSenderService;
use Cmd\Reports\Services\RetentionCommissionTierStore;
use Cmd\Reports\Services\UnassignedCommissionAgents;
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
 * Emails one workbook per source (LDR, PLAW) containing the full dataset
 * (" - All"), plus one workbook per configured agent filtered to their data
 * (" - <Agent Name>"), all attached in the same email.
 *
 * LDR:  custom_agent=742096, custom_date=742101, custom_results=742105,
 *        recon_status_id=377650, cancel_request_custom=742098
 * PLAW: custom_agent=742097, custom_date=742102, custom_results=742106,
 *        recon_status_id=377687, cancel_request_custom=742100
 */
class GenerateRetentionCommissionReport extends Command
{
    protected $signature = 'reports:retention-commission
                            {source=both : ldr | plaw | both}
                            {period? : Period start date YYYY-MM-01; defaults to first day of last month}
                            {--no-email : Build/save snapshot but do not send email}
                            {--test-recipient= : Send EVERY email (All + agent copies) only to this address}';

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
            // Summary agents from Jacob's July 2026 correct All workbook (Commission Summary).
            'agents' => [
                'Alice Kennedy', 'Andrea Mendoza', 'Gracia Rivera', 'Javier Deras',
                'John Pozuelos', 'Ken Smith', 'Mike Wexford', 'Wendy Kazem',
            ],
            // VBA does not exclude anyone from the detail sheet.
            'excluded_agents' => [],
        ],
        'plaw' => [
            'display'               => 'Progress Law',
            'custom_agent'          => 742097,
            'custom_date'           => 742102,
            'custom_results'        => 742106,
            'recon_status_id'       => 377687,
            'cancel_request_custom' => 742100,
            // Keep T4 (Jacob request) even though older PLAW VBA had tiers 0-3 only.
            'has_t4'                => true,
            // Summary agents from Jacob's July 2026 correct All workbook (Commission Summary).
            'agents' => [
                'Alexander Malone', 'Andrea Galvez', 'Edgar Gonzalez', 'Maria Lezana',
                'Melody Martinez', 'Theo Clayton',
            ],
            'excluded_agents' => [],
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
        ini_set('memory_limit', '1024M');

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

    /** Per-agent commission summary from the most recent buildWorkbook(), for the Azure results write. */
    private array $lastSummaryRows = [];

    private function runForSource(string $source): void
    {
        // Reset per source: handle() runs LDR then PLAW on the SAME instance, so a stale value
        // here would persist one source's commission under the other source's key.
        $this->lastSummaryRows = [];

        $cfg     = self::SOURCE_CONFIG[$source];
        $display = $cfg['display'];
        $this->info("[INFO] GenerateRetentionCommissionReport – $display");

        $periodArg = (string) ($this->argument('period') ?? '');
        $startDate = $periodArg !== '' ? date('Y-m-01', strtotime($periodArg)) : date('Y-m-01', strtotime('first day of last month'));
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

            // Normalize known misspellings so Summary matches the configured agent list.
            foreach ($rows as &$row) {
                $agent = strtoupper((string) $this->col($row, 'RETENTION_AGENT', ''));
                if ($agent === 'ANDREA MENDOZE') {
                    $row['RETENTION_AGENT'] = 'ANDREA MENDOZA';
                } elseif ($agent === 'ANDREA GALVES') {
                    // VBA list typo "Galves"; CRM / Summary use Galvez.
                    $row['RETENTION_AGENT'] = 'ANDREA GALVEZ';
                }
            }
            unset($row);

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
                        // Compare on calendar date (VBA used LEFT(CLEARED_DATE,10)).
                        $txDay = $this->toDate($d);
                        if ($txDay !== null && $txDay < $recon) {
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
                // Find first cleared datetime with calendar date >= recon (VBA LEFT stamp).
                $firstTx = null;
                foreach ($allTxMap[$id] ?? [] as $txDate) {
                    $txDay = $this->toDate($txDate);
                    if ($txDay !== null && $txDay >= $recon) {
                        $firstTx = $txDate;
                        break;
                    }
                }

                if ($firstTx === null) {
                    continue;
                }

                // VBA: if payment_date >= dropped_date → nullify
                $dropped = $this->toDate($this->col($row, 'DROPPED_DATE'));
                $payDay  = $this->toDate($firstTx);
                if ($dropped !== null && $payDay !== null && $payDay >= $dropped) {
                    continue;
                }

                $row['RETENTION_PAYMENT_DATE'] = $firstTx;

                $debt  = (float) $this->col($row, 'ENROLLED_DEBT', 0);
                $agent = strtoupper((string) $this->col($row, 'RETENTION_AGENT', ''));
                // PLAW VBA only: Sydney Leyva doubles T1-T3 (no T4 in that VBA). Keep T4 undoubled.
                $multi = ($source === 'plaw' && $agent === 'SYDNEY LEYVA') ? 2 : 1;
                $tier  = $this->tierAmounts($debt);

                $row['T1'] = $tier['t1'] * $multi;
                $row['T2'] = $tier['t2'] * $multi;
                $row['T3'] = $tier['t3'] * $multi;
                $row['T4'] = $tier['t4'];
            }
            unset($row);

            $this->info("[INFO] [$display] Rows after processing: " . count($rows));

            // This source job must include every actual retention agent. Aurora
            // Payroll Review performs the authoritative roster eligibility check;
            // a hard-coded list here would hide valid people before that check.
            $agentsByKey = [];
            foreach ($rows as $row) {
                $agent = trim((string) $this->col($row, 'RETENTION_AGENT', ''));
                $key = strtolower((string) preg_replace('/\s+/', ' ', $agent));
                if ($key !== '' && !isset($agentsByKey[$key])) {
                    $agentsByKey[$key] = $agent;
                }
            }
            if ($agentsByKey !== []) {
                $cfg['agents'] = array_values($agentsByKey);
                sort($cfg['agents'], SORT_STRING | SORT_FLAG_CASE);
            }

            // The roster decides the summary; the data-derived list above stays as the fallback for
            // when the roster is empty or unreachable, so a broken mirror degrades to the previous
            // behaviour rather than to an empty report.
            $rosterAgents = CommissionRosterProvider::fromRoster($sql, 'retention', $source);
            if ($rosterAgents === null) {
                $this->warn(
                    "[WARN] [$display] The retention roster in Azure (dbo.TblCommissionRoster) is empty "
                    . 'or unreachable. Falling back to the CRM agent names — this run is NOT roster-driven.'
                );
                Log::warning("GenerateRetentionCommissionReport[$display]: retention roster unavailable.");
            } else {
                $this->info("[INFO] [$display] Roster agents: " . count($rosterAgents));
            }

            // ── STEP 6: fetch agent location/company from SQL Server.
            // Roster members with no activity still need their company checked, so look up both sets.
            $locationMap = $this->fetchLocationMap(
                $sql,
                $rosterAgents === null
                    ? $cfg['agents']
                    : array_values(array_unique(array_merge($cfg['agents'], $rosterAgents)))
            );

            // Payroll policy: a retained account is paid at the tier earned in
            // its retained month, even when the payment clears in a later month.
            // Keep an explicit environment override for rollback, but make the
            // correct business rule the safe default for scheduled runs too.
            $useRetainedMonthTier = filter_var(env('RETENTION_TIER_BY_RETAINED_MONTH', true), FILTER_VALIDATE_BOOLEAN);
            $tierSnapshotMap = RetentionCommissionTierStore::fetchMap(
                $sql,
                $source,
                $this->retentionPeriodStarts($rows)
            );

            // ── STEP 7: build workbook with both sheets
            $file = $this->buildWorkbook(
                $rows,
                $cfg,
                $display,
                $startDate,
                $endDate,
                $locationMap,
                null,
                $tierSnapshotMap,
                $useRetainedMonthTier,
                true,
                $rosterAgents,
                $source
            );

            // Anyone with commission this period who is not on the roster. lastSummaryRows is set
            // inside buildWorkbook and covers every agent found in the data, so it can price the
            // unassigned as well as the paid.
            $unassigned = UnassignedCommissionAgents::fromTotals(
                array_map(
                    fn ($sum) => (float) ($sum['commission'] ?? 0),
                    $this->lastSummaryRows
                ),
                $rosterAgents
            );
            if ($unassigned !== []) {
                $this->warn(
                    "[WARN] [$display] " . count($unassigned) . ' agent(s) earned retention commission this '
                    . 'period but are not on the roster: ' . implode(', ', array_column($unassigned, 'agent'))
                );
            }

            // Persist the computed per-agent retention commission to Azure for the Commission Review
            // app (best-effort; never blocks the report). $lastSummaryRows is set inside buildWorkbook.
            // Reset Commission first (same pattern as bonus) so agents who drop off this run
            // do not keep a stale amount that makes Commission Review / Payroll disagree with the XLSX.
            $retResults = [];
            foreach ($this->lastSummaryRows as $agentName => $sum) {
                $retResults[] = ['agent' => (string) $agentName, 'amount' => $sum['commission'] ?? 0];
            }
            CommissionResultsWriter::resetColumn($sql, 'retention', $source, $startDate, 'Commission');
            CommissionResultsWriter::persist($sql, 'retention', $source, $startDate, 'Commission', $retResults);
            RetentionCommissionTierStore::persist(
                $sql,
                $source,
                $startDate,
                RetentionCommissionTierStore::rowsFromSummary($this->lastSummaryRows)
            );

            if ($file) {
                $this->info("[INFO] [$display] Workbook built: {$file['filename']}");

                $agentNames = [];
                foreach ($rows as $row) {
                    $agent = trim((string) $this->col($row, 'RETENTION_AGENT', ''));
                    if ($agent !== '') {
                        $agentNames[] = $agent;
                    }
                }
                $agentNames = array_values(array_unique($agentNames));
                sort($agentNames, SORT_STRING | SORT_FLAG_CASE);

                // Per-agent workbooks are no longer built, snapshotted or emailed.
                // Jacob, 2026-09-04: "don't sent the individual ones since we can send from Debt
                // Plete" — Commission Review's Send / Send All delivers each agent their own
                // statement, so this was a second, competing delivery path.
                $files = [$file];

                $snapshotPath = $this->saveSnapshotCopy($file, $display, $startDate);
                $this->info("[INFO] [$display] Snapshot saved: {$snapshotPath}");
                $this->cleanupOldSnapshots($startDate);

                if ($this->option('no-email')) {
                    $this->info("[INFO] [$display] --no-email set; skipping email send.");
                } else {
                    $this->sendReport($files, $display, $unassigned, $rosterAgents === null);
                }
                foreach ($files as $f) {
                    if (file_exists($f['path'])) {
                        @unlink($f['path']);
                    }
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


        $excludedAgents = $cfg['excluded_agents'] ?? [];
        $excludeSql = '';
        if (!empty($excludedAgents)) {
            $excludedListUpper = implode(',', array_map(
                fn ($a) => "'" . str_replace("'", "''", strtoupper((string) $a)) . "'",
                $excludedAgents
            ));
            $excludeSql = "AND UPPER(cu1.F_STRING) NOT IN ($excludedListUpper)";
        }

        $sql = "
            SELECT
                c.ID,
                CONCAT(c.FIRSTNAME,' ',c.LASTNAME)                     AS CLIENT,
                cu1.F_STRING                                           AS RETENTION_AGENT,
                LEFT(cu2.F_DATE, 10) AS RETENTION_DATE,
                cu3.F_STRING                                           AS IMMEDIATE_RESULTS,
                d.ENROLLED_DEBT,
                LEFT(c.DROPPED_DATE, 10)                               AS DROPPED_DATE,
                -- Keep full datetime like VBA (Excel COUNTIFS vs DateSerial end = midnight).
                TO_VARCHAR(cu4.F_DATETIME)                             AS CANCEL_REQUEST_DATE
            FROM CONTACTS c
            LEFT JOIN CONTACTS_USERFIELDS cu1
                   ON cu1.CONTACT_ID = c.ID AND cu1.CUSTOM_ID = $ca
            LEFT JOIN (
                SELECT CONTACT_ID, F_DATE
                FROM CONTACTS_USERFIELDS
                WHERE CUSTOM_ID = $cd
            ) cu2 ON c.ID = cu2.CONTACT_ID
            LEFT JOIN CONTACTS_USERFIELDS cu3
                   ON cu3.CONTACT_ID = c.ID AND cu3.CUSTOM_ID = $cr
            LEFT JOIN CONTACTS_USERFIELDS cu4
                   ON cu4.CONTACT_ID = c.ID AND cu4.CUSTOM_ID = $cc
            LEFT JOIN (
                SELECT CONTACT_ID, SUM(ORIGINAL_DEBT_AMOUNT) AS ENROLLED_DEBT
                FROM DEBTS
                WHERE ENROLLED=1 AND _FIVETRAN_DELETED=FALSE
                GROUP BY CONTACT_ID
            ) d ON c.ID = d.CONTACT_ID
            WHERE cu1.CONTACT_ID IS NOT NULL
              AND cu3.CONTACT_ID IS NOT NULL
              AND cu4.CONTACT_ID IS NOT NULL
              $excludeSql
            ORDER BY cu1.F_STRING ASC
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
            SELECT CONTACT_ID, TO_VARCHAR(CLEARED_DATE) AS CLEARED_DATE
            FROM TRANSACTIONS
            WHERE TRANS_TYPE = 'D'
              AND CLEARED_DATE IS NOT NULL
              AND RETURNED_DATE IS NULL
              AND CONTACT_ID IN ($idList)
            ORDER BY CONTACT_ID ASC, CLEARED_DATE ASC
        ";
        $map = [];
        foreach ($sf->query($sql)['data'] ?? [] as $r) {
            $map[(string) $r['CONTACT_ID']][] = (string) $r['CLEARED_DATE'];
        }
        return $map;
    }

    // ─── Workbook builder ─────────────────────────────────────────────────────

    /**
     * @param array<int,array<string,mixed>> $rows
     * @param array<string,mixed> $cfg
     * @param array<string,array{location:string,company:string}> $locationMap
     * @param array<string,int> $tierSnapshotMap
     * @param string|null $agentFilter When set, filename uses the agent name
     * @return array{filename:string,path:string}|null
     */
    private function buildWorkbook(
        array $rows,
        array $cfg,
        string $display,
        string $startDate,
        string $endDate,
        array $locationMap = [],
        ?string $agentFilter = null,
        array $tierSnapshotMap = [],
        bool $useRetainedMonthTier = false,
        bool $logShadowCompare = false,
        ?array $rosterAgents = null,
        string $sourceCode = ''
    ): ?array
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
                // Keep full datetime like VBA (Excel COUNTIFS vs DateSerial end = midnight).
                $this->setDateTime($sheet1, "K$r", $this->col($row, 'RETENTION_PAYMENT_DATE'));
                $sheet1->setCellValue("L$r", $row['T1'] ?? '');
                $sheet1->setCellValue("M$r", $row['T2'] ?? '');
                $sheet1->setCellValue("N$r", $row['T3'] ?? '');
                if ($hasT4) {
                    $sheet1->setCellValue("O$r", $row['T4'] ?? '');
                    $this->setDateTime($sheet1, "P$r", $this->col($row, 'CANCEL_REQUEST_DATE'));
                } else {
                    $this->setDateTime($sheet1, "O$r", $this->col($row, 'CANCEL_REQUEST_DATE'));
                }
                $r++;
            }

            $last1 = max($r - 1, 1);
            // Date-only fields (VBA formats D,H,I). Cancel/payment keep datetime for period math.
            foreach (['D', 'H', 'I', 'J'] as $c) {
                $sheet1->getStyle("{$c}2:{$c}{$last1}")->getNumberFormat()->setFormatCode('mm/dd/yyyy');
            }
            foreach (['K', $cancelCol] as $c) {
                $sheet1->getStyle("{$c}2:{$c}{$last1}")->getNumberFormat()->setFormatCode('mm/dd/yyyy hh:mm:ss');
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

            $sumHeaders = ['Retention Agent', 'Assigned', 'Retained', '% Retained', 'Tier', 'Commission', 'Location', 'Company'];
            foreach ($sumHeaders as $i => $h) {
                $sheet2->setCellValue(chr(65 + $i) . '1', $h);
            }
            $this->headerStyle($sheet2, 'A1:H1');

            $agents      = $agentFilter !== null ? [$agentFilter] : $cfg['agents'];
            $summaryRows = $this->buildSummary(
                $rows,
                $agents,
                $startDate,
                $endDate,
                $locationMap,
                $hasT4,
                $tierSnapshotMap,
                $useRetainedMonthTier,
                $logShadowCompare
            );
            $this->lastSummaryRows = $summaryRows;

            // The roster decides who is on the paid list; everyone else with commission this period
            // is listed separately below it (Jacob, 2026-09-03). With no roster we keep the previous
            // behaviour of listing every agent found in the data, rather than emitting a blank sheet.
            $onRoster = static fn (string $name): bool => $rosterAgents === null
                || CommissionRosterProvider::isOnRoster($rosterAgents, $name);

            $paid = [];
            $unassignedRows = [];
            foreach ($summaryRows as $agentName => $sum) {
                if ($onRoster((string) $agentName)) {
                    $paid[(string) $agentName] = $sum;
                } elseif (round((float) ($sum['commission'] ?? 0), 2) > 0) {
                    $unassignedRows[(string) $agentName] = $sum;
                }
            }
            // A per-agent copy is filtered to one person; never hide them from their own workbook.
            if ($agentFilter !== null && $paid === [] && $unassignedRows !== []) {
                $paid = $unassignedRows;
                $unassignedRows = [];
            }

            // $brand is the report's own source for paid rows, and '' for the unassigned block —
            // those are already called out under their own heading, so a second red flag adds
            // nothing.
            $writeSummaryRow = function ($agentName, array $sum, int $row, string $brand = '') use ($sheet2): int {
                $sheet2->setCellValue("A$row", $agentName);
                $sheet2->setCellValue("B$row", $sum['assigned']);
                $sheet2->setCellValue("C$row", $sum['retained']);
                $sheet2->setCellValue("D$row", $sum['pct_retained']);
                $sheet2->setCellValue("E$row", $sum['tier']);
                $sheet2->setCellValue("F$row", $sum['commission']);
                $sheet2->setCellValue("G$row", $sum['location']);
                $sheet2->setCellValue("H$row", $sum['company']);

                $company  = trim((string) ($sum['company'] ?? ''));
                $location = trim((string) ($sum['location'] ?? ''));

                // Judged against THIS REPORT's brand, so an agent rostered to "both" is still
                // flagged on the report whose company they disagree with — that is where Jacob's
                // own examples live (Katherine Caceres, Lucas Wright). Judging it against the
                // agent's own roster source was tried on 2026-09-04 and reverted: it silenced them.
                if ($brand !== '' && CommissionCompanyMatch::mismatches($brand, $company)) {
                    // Jacob: "Add a red highlight if the company does not match."
                    $sheet2->getStyle("A$row:H$row")->getFill()
                        ->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFF0000');
                    $sheet2->getStyle("A$row:H$row")->getFont()->getColor()->setARGB('FFFFFFFF');
                    $sheet2->getStyle("A$row:H$row")->getFont()->setBold(true);
                } elseif ($company === '' || $location === '') {
                    // Without a company they cannot appear on the Commission Review page.
                    $sheet2->getStyle("A$row:H$row")->getFill()
                        ->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFC7CE');
                    $sheet2->getStyle("A$row:H$row")->getFont()->getColor()->setARGB('FF9C0006');
                }

                return $row + 1;
            };

            $r2 = 2;
            foreach ($paid as $agentName => $sum) {
                $r2 = $writeSummaryRow($agentName, $sum, $r2, $sourceCode);
            }
            if ($unassignedRows !== []) {
                $r2++;
                $sheet2->setCellValue("A$r2", 'Unassigned Agents');
                $sheet2->getStyle("A$r2")->getFont()->setBold(true);
                $sheet2->setCellValue("B$r2", 'Not on the retention roster');
                $sheet2->getStyle("B$r2")->getFont()->getColor()->setARGB('FF9C0006');
                $r2++;
                foreach ($unassignedRows as $agentName => $sum) {
                    $r2 = $writeSummaryRow($agentName, $sum, $r2);
                }
            }

            $last2 = max($r2 - 1, 1);
            $sheet2->getStyle("D2:D{$last2}")->getNumberFormat()->setFormatCode('0%');
            $sheet2->getStyle("F2:F{$last2}")->getNumberFormat()->setFormatCode('$#,##0');
            if ($last2 > 1) {
                $sheet2->getStyle("A1:H{$last2}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
            }
            foreach (range('A', 'H') as $c) {
                $sheet2->getColumnDimension($c)->setAutoSize(true);
            }
            $sheet2->getStyle("A1:H{$last2}")->getFont()->setName('Calibri')->setSize(9);
            $sheet2->freezePane('A2');
            $sheet2->setSelectedCells('A1');

            $sp->setActiveSheetIndex(0);

            $suffix   = $agentFilter !== null ? $this->safeFilenamePart($agentFilter) : 'All';
            $filename = "Retention Commission ({$display}) - {$suffix}.xlsx";
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
     * Assigned   = rows where cancel_request_date falls in period
     * Retained   = rows where retention_date falls in period
     * Tier       = 4 if has_t4 AND pct >= 70% AND retained >= 50
     *              0 if pct<20%, 1 if <35%, 2 if <50%, 3 otherwise
     * Commission = sum of T{tier} for rows where retention_payment_date falls in period
     *              (tier from retained-month snapshot when RETENTION_TIER_BY_RETAINED_MONTH=true)
     *
     * @param  array<int,array<string,mixed>> $rows
     * @param  string[] $agents
     * @param  array<string,array{location:string,company:string}> $locationMap
     * @param  array<string,int> $tierSnapshotMap
     * @return array<string,array{assigned:int,retained:int,pct_retained:float,tier:int,commission:float,location:string,company:string}>
     */
    private function buildSummary(
        array $rows,
        array $agents,
        string $startDate,
        string $endDate,
        array $locationMap = [],
        bool $hasT4 = false,
        array $tierSnapshotMap = [],
        bool $useRetainedMonthTier = false,
        bool $logShadowCompare = false
    ): array
    {
        $summary = [];
        $shadowDiffs = 0;
        $missingWarned = [];

        foreach ($agents as $agentName) {
            $agentUpper = strtoupper($agentName);

            $assigned   = 0;
            $retained   = 0;
            $commissionOld = 0.0;
            $commissionNew = 0.0;

            foreach ($rows as $row) {
                $rowAgent = strtoupper((string) $this->col($row, 'RETENTION_AGENT', ''));
                if ($rowAgent !== $agentUpper) {
                    continue;
                }

                // Assigned: cancel datetime in VBA COUNTIFS window (see inExcelPeriod).
                if ($this->inExcelPeriod($this->col($row, 'CANCEL_REQUEST_DATE'), $startDate, $endDate, true)) {
                    $assigned++;
                }

                // Retained: retention date (date-only in Excel) inclusive through endDate.
                if ($this->inExcelPeriod($this->col($row, 'RETENTION_DATE'), $startDate, $endDate, false)) {
                    $retained++;
                }
            }

            $pct  = ($assigned > 0) ? ($retained / $assigned) : 0.0;
            $tier = $this->resolveTier($pct, $retained, $hasT4);

            foreach ($rows as $row) {
                $rowAgent = strtoupper((string) $this->col($row, 'RETENTION_AGENT', ''));
                if ($rowAgent !== $agentUpper) {
                    continue;
                }
                if (!$this->inExcelPeriod($this->col($row, 'RETENTION_PAYMENT_DATE'), $startDate, $endDate, true)) {
                    continue;
                }

                $commissionOld += $this->amountForTier($row, $tier);

                $retainedPeriod = RetentionCommissionTierStore::periodStartFromDate(
                    (string) ($this->col($row, 'RETENTION_DATE') ?? '')
                );
                $snapshotTier = null;
                if ($retainedPeriod !== null) {
                    $key = RetentionCommissionTierStore::tierMapKey($retainedPeriod, $agentName);
                    if (array_key_exists($key, $tierSnapshotMap)) {
                        $snapshotTier = $tierSnapshotMap[$key];
                    } elseif (
                        $retainedPeriod !== $startDate
                        && ($useRetainedMonthTier || $logShadowCompare)
                        && !isset($missingWarned[$key])
                    ) {
                        $missingWarned[$key] = true;
                        Log::warning('RetentionCommissionTierStore: missing tier snapshot; using current-month tier', [
                            'agent' => $agentName,
                            'retained_period' => $retainedPeriod,
                        ]);
                    }
                }
                $payTier = RetentionCommissionTierStore::resolveTierForPayment($tier, $snapshotTier);
                $commissionNew += $this->amountForTier($row, $payTier);
            }

            $commission = $useRetainedMonthTier ? $commissionNew : $commissionOld;
            if ($logShadowCompare && abs($commissionOld - $commissionNew) >= 0.005) {
                $shadowDiffs++;
                Log::info('Retention commission shadow compare', [
                    'agent' => $agentName,
                    'old' => round($commissionOld, 2),
                    'new' => round($commissionNew, 2),
                    'delta' => round($commissionNew - $commissionOld, 2),
                ]);
            }

            $summary[$agentName] = [
                'assigned'    => $assigned,
                'retained'    => $retained,
                'pct_retained'=> $pct,
                'tier'        => $tier,
                'commission'  => $commission,
                'location'    => $locationMap[$agentUpper]['location'] ?? '',
                'company'     => $locationMap[$agentUpper]['company'] ?? '',
            ];
        }

        if ($logShadowCompare && $shadowDiffs === 0) {
            Log::info('Retention commission shadow compare: no agent diffs');
        }

        uksort($summary, function (string $a, string $b) use ($summary): int {
            return [
                $summary[$a]['location'],
                $summary[$a]['company'],
                $a,
            ] <=> [
                $summary[$b]['location'],
                $summary[$b]['company'],
                $b,
            ];
        });

        return $summary;
    }

    /**
     * @param  array<string,mixed> $row
     */
    private function amountForTier(array $row, int $tier): float
    {
        if ($tier <= 0) {
            return 0.0;
        }

        return (float) $this->col($row, 'T' . $tier, 0);
    }

    /**
     * @param  array<int,array<string,mixed>> $rows
     * @return list<string>
     */
    private function retentionPeriodStarts(array $rows): array
    {
        $months = [];
        foreach ($rows as $row) {
            $period = RetentionCommissionTierStore::periodStartFromDate(
                (string) ($this->col($row, 'RETENTION_DATE') ?? '')
            );
            if ($period !== null) {
                $months[$period] = true;
            }
        }

        return array_keys($months);
    }

    /**
     * Map retention % to commission tier.
     *
     * Replicates the VBA formula:
     *   =IF(AND(D2>=.7,C2>=50),4,IF(D2<0.2,0,IF(D2<0.35,1,IF(D2<0.5,2,3))))
     *
     * - Tier 4:  pct >= 70%  AND  retained count >= 50
     * - Else:    <20%  -> 0
     *            <35%  -> 1
     *            <50%  -> 2
     *            else  -> 3
     */
    private function resolveTier(float $pct, int $retained, bool $hasT4): int
    {
        if ($hasT4 && $pct >= 0.70 && $retained >= 50) {
            return 4;
        }
        return match (true) {
            $pct < 0.20 => 0,
            $pct < 0.35 => 1,
            $pct < 0.50 => 2,
            default     => 3,
        };
    }

    // ─── Email ────────────────────────────────────────────────────────────────

    /**
     * Email the All workbook to the report distribution list. Per-agent copies are not sent from
     * here — Commission Review in DebtPlete delivers those.
     *
     * @param array<int,array{filename:string,path:string}>      $files
     * @param array<int,array{agent:string,amount:float}>         $unassigned Earners missing from the roster.
     */
    private function sendReport(array $files, string $display, array $unassigned = [], bool $rosterUnavailable = false): void
    {
        $sql   = $this->initSqlServer('ldr');
        $email = new EmailSenderService();
        $reportNames = ['RetentionCommissionReport', 'Retention Commission Report'];
        $baseSubject = "Retention Commission Report - $display";
        // HTML on both paths — --test-recipient sends HTML and the real send used plain text, so a
        // padded text block looked right to the list and collapsed onto one line in the test copy.
        $baseBody    = '<p>See attached Retention Commission Report - ' . htmlspecialchars($display) . '.</p>'
            . UnassignedCommissionAgents::emailBlockHtml($unassigned, $rosterUnavailable, 'retention roster');

        // --test-recipient: redirect EVERY email for this run to one address.
        $testTo = trim((string) ($this->option('test-recipient') ?: ''));

        $parts = CommissionAgentEmailFiles::partition($files);
        foreach ($parts['missing'] as $missingName) {
            $this->warn("[WARN] [$display] Attachment missing: {$missingName}");
        }
        $allFiles = $parts['all'];
        $agentFiles = $parts['agents'];

        if ($allFiles !== []) {
            $attachments = CommissionAgentEmailFiles::toAttachments($allFiles);
            if ($testTo !== '') {
                $this->info("[INFO] [$display] --test-recipient set — All report only to $testTo");
                $email->sendMailHtml($baseSubject, $baseBody, [$testTo], [], [], $attachments);
            } else {
                $sent = $email->sendMailUsingTblReportsHtml(
                    $sql,
                    $reportNames,
                    [strtoupper($display)],
                    $baseSubject,
                    $baseBody,
                    $attachments,
                    true
                );
                if (!$sent) {
                    $email->sendMailHtml($baseSubject, $baseBody, ['oduai@libertydebtrelief.com'], [], [], $attachments);
                }
            }
            $this->info("[INFO] [$display] All report emailed (" . count($attachments) . " attachment(s)).");
        } else {
            $this->warn("[WARN] [$display] No All workbook to email.");
        }

        // Per-agent copies are not emailed from here any more — Jacob, 2026-09-04: "don't sent the
        // individual ones since we can send from Debt Plete". $agentFiles is expected to be empty;
        // warn rather than send if a caller ever passes one, so a partial revert cannot quietly
        // resume the old behaviour.
        if ($agentFiles !== []) {
            $this->warn(
                "[WARN] [$display] " . count($agentFiles) . ' per-agent workbook(s) were built but not '
                . 'emailed. Agent statements are sent from Commission Review in DebtPlete.'
            );
        }
    }

    // ─── Snapshot retention ───────────────────────────────────────────────────

    private const SNAPSHOT_RETENTION_MONTHS = 6;

    private function saveSnapshotCopy(array $file, string $display, string $startDate): string
    {
        if (!isset($file['path'], $file['filename']) || !is_file((string) $file['path'])) {
            throw new \RuntimeException('Cannot save retention commission snapshot because workbook file is missing.');
        }

        $month = date('Y-m', strtotime($startDate));
        $period = date('m-Y', strtotime($startDate));
        $source = strtoupper($display) === 'PROGRESS LAW' ? 'Progress Law' : 'LDR';
        $dir = storage_path("app/commission-snapshots/{$month}/retention");
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        // Stable name so retention-manager-commission can find the All workbook.
        // Per-agent files keep their generated filename; only the All snapshot uses this path.
        $filename = (string) $file['filename'];
        $isAll = str_ends_with($filename, ' - All.xlsx') || str_contains($filename, ' - All.');
        $destName = $isAll
            ? "Retention Commission Report - {$source} - {$period}.xlsx"
            : $filename;
        $dest = $dir . DIRECTORY_SEPARATOR . $destName;
        copy((string) $file['path'], $dest); // overwrite same month/source if rerun

        return $dest;
    }

    private function cleanupOldSnapshots(string $currentStartDate): void
    {
        $root = storage_path('app/commission-snapshots');
        if (!is_dir($root)) {
            return;
        }

        $cutoff = (new \DateTimeImmutable(date('Y-m-01', strtotime($currentStartDate))))
            ->modify('-' . (self::SNAPSHOT_RETENTION_MONTHS - 1) . ' months')
            ->format('Y-m');

        foreach (scandir($root) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || !preg_match('/^\d{4}-\d{2}$/', $entry)) {
                continue;
            }
            if ($entry < $cutoff) {
                $path = $root . DIRECTORY_SEPARATOR . $entry;
                $this->deleteDirectory($path);
                $this->info("[INFO] Deleted old commission snapshot folder: {$path}");
            }
        }
    }

    private function deleteDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $child = $path . DIRECTORY_SEPARATOR . $item;
            if (is_dir($child)) {
                $this->deleteDirectory($child);
            } else {
                @unlink($child);
            }
        }
        @rmdir($path);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /** Strip characters that are illegal in filenames (Windows/Linux). */
    private function safeFilenamePart(string $name): string
    {
        return trim((string) preg_replace('/[\/\\\\:*?"<>|]/', '_', $name), " \t\n\r\0\x0B.");
    }

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

    /**
     * VBA Excel COUNTIFS vs DateSerial(start)/DateSerial(end).
     * DateTime fields (cancel / payment): include only timestamps from start 00:00:00 through end 00:00:00.
     * That matches Excel's end bound at midnight, so most last-day times are excluded.
     * Date-only fields (retention date): full calendar day inclusive through endDate.
     */
    private function inExcelPeriod(mixed $value, string $startDate, string $endDate, bool $dateTimeField): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        $startTs = strtotime($startDate . ' 00:00:00');
        $endTs   = strtotime($endDate . ' 00:00:00');
        if ($startTs === false || $endTs === false) {
            return false;
        }

        if ($dateTimeField) {
            $ts = $value instanceof \DateTimeInterface
                ? $value->getTimestamp()
                : strtotime((string) $value);
            if ($ts === false) {
                return false;
            }

            return $ts >= $startTs && $ts <= $endTs;
        }

        $d = $this->toDate($value);

        return $d !== null && $d >= $startDate && $d <= $endDate;
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

    /** Write full datetime (VBA dumps F_DATETIME / CLEARED_DATE with time). */
    private function setDateTime(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, string $cell, mixed $val): void
    {
        if ($val === null || $val === '') {
            return;
        }
        $ts = $val instanceof \DateTimeInterface
            ? $val->getTimestamp()
            : strtotime((string) $val);
        if ($ts === false) {
            return;
        }
        $sheet->setCellValue($cell, XlDate::PHPToExcel($ts));
        $sheet->getStyle($cell)->getNumberFormat()->setFormatCode('mm/dd/yyyy hh:mm:ss');
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
            "SELECT Employee_Name, Location, Company FROM TblEmployees WHERE Employee_Name IN ($list)"
        );
        $map = [];
        foreach ($res['data'] ?? [] as $row) {
            $name = strtoupper((string) ($row['Employee_Name'] ?? $row['employee_name'] ?? ''));
            $map[$name] = [
                'location' => (string) ($row['Location'] ?? $row['location'] ?? ''),
                'company' => (string) ($row['Company'] ?? $row['company'] ?? ''),
            ];
        }
        return $map;
    }
}
