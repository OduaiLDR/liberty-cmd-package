<?php

namespace Cmd\Reports\Console\Commands\GenerateNSFCommissionReport;

use Cmd\Reports\Services\DBConnector;
use Cmd\Reports\Services\CommissionAgentEmailFiles;
use Cmd\Reports\Services\CommissionResultsWriter;
use Cmd\Reports\Services\CommissionRosterProvider;
use Cmd\Reports\Services\UnassignedCommissionAgents;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class GenerateNSFCommissionReport extends Command
{
    protected $signature = 'reports:generate-nsf-commission-report
                            {source=both : ldr | plaw | both}
                            {period? : Period start date YYYY-MM-01; defaults to first day of last month}
                            {--no-email : Build/save snapshot but do not send email}
                            {--test-recipient= : Send EVERY email (All + agent copies) only to this address}';

    protected $description = 'Generate NSF Commission Report for LDR and/or Progress Law. Runs for the previous calendar month.';

    private const SOURCE_CONFIG = [
        'ldr' => [
            'display'           => 'LDR',
            'custom_agent'      => 742134,
            'custom_nsf_return' => 742148,
            'custom_nsf_action' => 742136,
            'custom_nsf_recoup' => 742146,
            'agents'            => [
                'Bill Mendoza',
                'Gabriel Yol',
                'Harry Gardner',
                'Jose Zuniga',
                'Luna Bradford',
                'Lucas Wright',
                'Samantha Lotz',
                'Timothy Phillips',
                'Katherine Caceres',
            ],
        ],
        'plaw' => [
            'display'           => 'Progress Law',
            'custom_agent'      => 742135,
            'custom_nsf_return' => 742149,
            'custom_nsf_action' => 742137,
            'custom_nsf_recoup' => 742147,
            'agents'            => [
                'Anthony Clark',
                'June Brock',
                'Lucas Wright',
                'Marlon Solorzano',
                'Lilith Bailey',
                'Oaklynn Edwards',
            ],
        ],
    ];

    // Rate = $4.00 regardless of tier
    private const FLAT_RATE_AGENTS = ['Anthony Clark', 'Lucas Wright'];

    // Keep monthly NSF/commission snapshots for current month + previous 5 months.
    private const SNAPSHOT_RETENTION_MONTHS = 6;

    public function handle(): int
    {
        $arg     = strtolower((string) $this->argument('source'));
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
        $this->info("[INFO] GenerateNSFCommissionReport — $display");

        $periodArg = (string) ($this->argument('period') ?? '');
        $startDate = $periodArg !== '' ? date('Y-m-01', strtotime($periodArg)) : date('Y-m-01', strtotime('first day of last month'));
        $endDate   = date('Y-m-t', strtotime($startDate));
        $this->info("[INFO] Period: $startDate → $endDate");

        try {
            $sf  = DBConnector::fromEnvironment($source);
            $sql = $this->initSqlServer($source);
        } catch (\Throwable $e) {
            $this->error("[$display] Connector init: " . $e->getMessage());
            Log::error("GenerateNSFCommissionReport[$display]: connector init failed", ['ex' => $e]);
            return;
        }

        try {
            $dataRows = $this->fetchNSFRows($sf, $cfg, $startDate, $endDate);
            $this->info("[INFO] [$display] NSF rows fetched: " . count($dataRows));

            if (empty($dataRows)) {
                $this->warn("[WARN] [$display] No NSF data for period — skipping.");
                Log::info("GenerateNSFCommissionReport[$display]: no data for $startDate–$endDate");
                return;
            }

            // Enrich each row with its valid_commission flag before passing to formatter
            foreach ($dataRows as &$row) {
                $row['valid_commission'] = $this->isValidCommission($row);
            }
            unset($row);

            // Agent list comes from the roster Rama manages in the Commission Review app.
            // fromRoster() returns null when the roster is empty OR unreachable — the two are worth
            // telling apart, because a broken roster must not be reported as "nobody is missing".
            $rosterAgents = CommissionRosterProvider::fromRoster($sql, 'nsf', $source);
            if ($rosterAgents === null) {
                $this->warn(
                    "[WARN] [$display] The NSF roster in Azure (dbo.TblCommissionRoster) is empty or "
                    . 'unreachable. Falling back to the built-in agent list — this run is NOT roster-driven. '
                    . 'Check that the Commission Review roster is mirroring to Azure.'
                );
                Log::warning("GenerateNSFCommissionReport[$display]: NSF roster unavailable; fell back to the built-in agent list.");
            }
            $agents = $rosterAgents ?? $cfg['agents'];

            // Commission is computed per agent from their row counts, so an agent who is not on the
            // roster has no amount until we compute one. Jacob, 2026-09-04: "If anyone shows on the
            // data sheet with commission, then they should be in the email alerting her that there
            // is someone missing from the roster." Price everyone in the data, then split.
            $dataAgents = array_values(array_unique(array_filter(
                array_map(fn (array $r): string => trim((string) ($r['AGENT'] ?? '')), $dataRows)
            )));
            $pricedRows = $this->buildCommissionRows($dataRows, array_values(array_unique(array_merge($agents, $dataAgents))), $sql);

            $rosterKeys = array_map(
                fn ($n) => strtolower((string) preg_replace('/\s+/', ' ', trim((string) $n))),
                $agents
            );
            $isOnRoster = fn (string $name): bool => in_array(
                strtolower((string) preg_replace('/\s+/', ' ', trim($name))),
                $rosterKeys,
                true
            );

            // The report itself only ever shows roster members.
            $commissionRows = array_values(array_filter(
                $pricedRows,
                fn (array $r): bool => $isOnRoster((string) $r['agent'])
            ));

            $unassigned = UnassignedCommissionAgents::fromTotals(
                array_column(
                    array_values(array_filter($pricedRows, fn (array $r): bool => !$isOnRoster((string) $r['agent']))),
                    'commission',
                    'agent'
                ),
                $rosterAgents
            );
            if ($unassigned !== []) {
                $this->warn(
                    "[WARN] [$display] " . count($unassigned) . ' agent(s) earned NSF commission this period '
                    . 'but are not on the roster: ' . implode(', ', array_column($unassigned, 'agent'))
                );
            }

            // Persist the computed per-agent commission to Azure so the Commission Review app reads
            // the REAL numbers (best-effort; never blocks the report).
            CommissionResultsWriter::persist(
                $sql, 'nsf', $source, $startDate, 'Commission',
                array_map(
                    fn ($r) => ['agent' => $r['agent'], 'amount' => $r['commission']],
                    $commissionRows
                )
            );

            $formatter = new Formatter();
            $allFile = $formatter->buildWorkbook($dataRows, $commissionRows, $display, $startDate, $endDate);
            $this->info("[INFO] [$display] Workbook: {$allFile['filename']}");

            // Per-agent workbooks are no longer built, snapshotted or emailed.
            // Jacob, 2026-09-04: "don't sent the individual ones since we can send from Debt Plete"
            // — Commission Review's Send / Send All delivers each agent their own statement.
            // Only the " - All" snapshot was ever read back: GenerateRetentionManagerCommission's
            // defaultNsfSnapshotPaths() looks for "… - All.xlsx" and nothing else.
            $files = [$allFile];

            $snapshotPath = $this->saveSnapshotCopy($allFile, $startDate);
            $this->info("[INFO] [$display] Snapshot saved: {$snapshotPath}");
            $this->cleanupOldSnapshots($startDate);

            if ($this->option('no-email')) {
                $this->info("[INFO] [$display] --no-email set; skipping email send.");
            } else {
                $this->sendReport($sql, $files, $display, $startDate, $endDate, $unassigned, $rosterAgents === null);
            }

            foreach ($files as $f) {
                if (file_exists($f['path'])) {
                    @unlink($f['path']);
                }
            }
        } catch (\Throwable $e) {
            $this->error("[$display] Failed: " . $e->getMessage());
            Log::error("GenerateNSFCommissionReport[$display]: failed", ['ex' => $e]);
        }
    }

    private function fetchNSFRows(DBConnector $sf, array $cfg, string $startDate, string $endDate): array
    {
        $agentId  = (int) $cfg['custom_agent'];
        $returnId = (int) $cfg['custom_nsf_return'];
        $actionId = (int) $cfg['custom_nsf_action'];
        $recoupId = (int) $cfg['custom_nsf_recoup'];

        $sql = "
            SELECT
                c.ID,
                CU1.AGENT,
                TO_VARCHAR(CU2.NSF_RETURNED_DATE, 'YYYY-MM-DD') AS NSF_RETURNED_DATE,
                CU3.NSF_ACTION,
                TO_VARCHAR(CU4.NSF_RECOUP_DATE, 'YYYY-MM-DD') AS NSF_RECOUP_DATE,
                TO_VARCHAR(CAST(CONVERT_TIMEZONE('America/Los_Angeles', T.CLEARED_DATE) AS DATE), 'YYYY-MM-DD') AS CLEARED_DATE
            FROM CONTACTS c
            LEFT JOIN (
                SELECT CONTACT_ID, F_SHORTSTRING AS AGENT
                FROM CONTACTS_USERFIELDS
                WHERE CUSTOM_ID = $agentId
            ) CU1 ON c.ID = CU1.CONTACT_ID
            LEFT JOIN (
                SELECT CONTACT_ID, F_DATE AS NSF_RETURNED_DATE
                FROM CONTACTS_USERFIELDS
                WHERE CUSTOM_ID = $returnId
            ) CU2 ON c.ID = CU2.CONTACT_ID
            LEFT JOIN (
                SELECT CONTACT_ID, F_STRING AS NSF_ACTION
                FROM CONTACTS_USERFIELDS
                WHERE CUSTOM_ID = $actionId
            ) CU3 ON c.ID = CU3.CONTACT_ID
            LEFT JOIN (
                SELECT CONTACT_ID, F_DATE AS NSF_RECOUP_DATE
                FROM CONTACTS_USERFIELDS
                WHERE CUSTOM_ID = $recoupId
            ) CU4 ON c.ID = CU4.CONTACT_ID
            LEFT JOIN (
                SELECT CONTACT_ID, CLEARED_DATE,
                       ROW_NUMBER() OVER (PARTITION BY CONTACT_ID ORDER BY CONVERT_TIMEZONE('America/Los_Angeles', PROCESS_DATE) DESC) AS RN
                FROM TRANSACTIONS
                WHERE TRANS_TYPE = 'D'
                  AND CLEARED_DATE IS NOT NULL
                  AND RETURNED_DATE IS NULL
            ) T ON c.ID = T.CONTACT_ID
            WHERE CU2.NSF_RETURNED_DATE >= '$startDate'
              AND CU2.NSF_RETURNED_DATE <= '$endDate'
              AND T.RN = 1
            ORDER BY CU1.AGENT, CU2.NSF_RETURNED_DATE
        ";

        $result = $sf->query($sql);
        return $result['data'] ?? [];
    }

    /**
     * Compute commission summary per agent.
     *
     * Mirrors the VBA Commission sheet formulas:
     *   Assignments = rows where AGENT = this agent
     *   Actions     = rows where AGENT = this agent AND NSF_ACTION not empty
     *   Ratio       = Actions / Assignments
     *   Valid       = rows where AGENT = this agent AND valid_commission = true
     *   Rate        = tier lookup (or flat $4 for special agents)
     *   Commission  = Rate * Valid
     */
    private function buildCommissionRows(array $dataRows, array $agents, DBConnector $sqlConn): array
    {
        $rateTable = [
            1 => [1 => 1.50, 2 => 1.75, 3 => 2.00],
            2 => [1 => 2.50, 2 => 2.75, 3 => 3.00],
            3 => [1 => 3.50, 2 => 3.75, 3 => 4.00],
        ];

        // Pre-index rows by agent
        $byAgent = [];
        foreach ($dataRows as $row) {
            $agent = (string) ($row['AGENT'] ?? '');
            if ($agent === '') continue;
            $byAgent[$agent][] = $row;
        }

        // Batch-fetch location from TblEmployees
        $locationMap = [];
        if (!empty($agents)) {
            $inList = implode(',', array_map(
                fn ($a) => "'" . str_replace("'", "''", $a) . "'",
                $agents
            ));
            $empRes = $sqlConn->querySqlServer(
                "SELECT Employee_Name, Location, Company FROM TblEmployees WHERE Employee_Name IN ($inList)"
            );
            foreach ($empRes['data'] ?? [] as $emp) {
                $name = (string) ($emp['Employee_Name'] ?? $emp['employee_name'] ?? '');
                $locationMap[$name] = [
                    'location' => (string) ($emp['Location'] ?? $emp['location'] ?? ''),
                    'company'  => (string) ($emp['Company']  ?? $emp['company']  ?? ''),
                ];
            }
        }

        $rows = [];
        foreach ($agents as $agent) {
            $agentRows   = $byAgent[$agent] ?? [];
            $assignments = count($agentRows);

            $actions = 0;
            $clears  = 0;
            foreach ($agentRows as $r) {
                $action = trim((string) ($r['NSF_ACTION'] ?? ''));
                if ($action !== '') {
                    $actions++;
                }
                if ($this->isValidCommission($r)) {
                    $clears++;
                }
            }

            $ratio = ($assignments > 0) ? ($actions / $assignments) : 0;

            $actionsTier = $this->matchTier($ratio, [0.2, 0.4, 0.6]);
            $clearedTier = $this->matchTier($actions, [1, 51, 101]);

            // Compare case/space-insensitively: the agent list can now come from the managed
            // roster, where a name may differ in casing or spacing from the constant below.
            // A miss here would silently pay the tier rate instead of the flat rate.
            $agentKey = strtolower(preg_replace('/\s+/', ' ', trim($agent)));
            $flatRateKeys = array_map(
                fn ($n) => strtolower(preg_replace('/\s+/', ' ', trim($n))),
                self::FLAT_RATE_AGENTS
            );
            if (in_array($agentKey, $flatRateKeys, true)) {
                $rate = 4.00;
            } else {
                $rate = ($actionsTier > 0 && $clearedTier > 0)
                    ? ($rateTable[$clearedTier][$actionsTier] ?? 0)
                    : 0;
            }

            $rows[] = [
                'agent'        => $agent,
                'assignments'  => $assignments,
                'actions'      => $actions,
                'ratio'        => $ratio,
                'actions_tier' => $actionsTier,
                'cleared_tier' => $clearedTier,
                'rate'         => $rate,
                'clears'       => $clears,
                'commission' => $rate * $clears,
                'location'   => $locationMap[$agent]['location'] ?? '',
                'company'    => $locationMap[$agent]['company']  ?? '',
            ];
        }

        return $rows;
    }

    /**
     * VBA: AND(MONTH(NSF_RETURNED)=MONTH(NSF_RECOUP), CLEARED<=DATE(Y,M+1,5), CLEARED>NSF_RECOUP)
     */
    private function isValidCommission(array $row): bool
    {
        $nsfReturned = (string) ($row['NSF_RETURNED_DATE'] ?? '');
        $nsfRecoup   = (string) ($row['NSF_RECOUP_DATE']   ?? '');
        $cleared     = (string) ($row['CLEARED_DATE']      ?? '');

        if ($nsfReturned === '' || $nsfRecoup === '' || $cleared === '') {
            return false;
        }

        $returnMonth = (int) date('m', strtotime($nsfReturned));
        $recoupMonth = (int) date('m', strtotime($nsfRecoup));

        if ($returnMonth !== $recoupMonth) {
            return false;
        }

        $cutoffDate = date('Y-m-05', strtotime('first day of next month', strtotime($nsfReturned)));

        return $cleared <= $cutoffDate && $cleared > $nsfRecoup;
    }

    /**
     * MATCH type 1: largest value in sorted $thresholds that is <= $value.
     * Returns 1-based index, or 0 if value is below all thresholds.
     */
    private function matchTier($value, array $thresholds): int
    {
        $tier = 0;
        foreach ($thresholds as $i => $threshold) {
            if ($value >= $threshold) {
                $tier = $i + 1;
            }
        }
        return $tier;
    }

    /**
     * Email the All workbook to the report distribution list. Per-agent copies are not sent from
     * here — Commission Review in DebtPlete delivers those.
     *
     * @param array<int,array{filename:string,path:string}>       $files
     * @param array<int,array{agent:string,amount:float}>          $unassigned Earners missing from the roster.
     */
    private function sendReport(
        DBConnector $sql,
        array $files,
        string $display,
        string $start,
        string $end,
        array $unassigned = [],
        bool $rosterUnavailable = false
    ): void {
        $parts = CommissionAgentEmailFiles::partition($files);
        foreach ($parts['missing'] as $missingName) {
            $this->warn("[WARN] [$display] Attachment missing: {$missingName}");
        }
        $allFiles = $parts['all'];
        $agentFiles = $parts['agents'];

        $subject = "NSF Commission Report - $display";

        // Jacob, 2026-09-03: "For the NSF Report, change the email body to be similar to the others,
        // remove the table and just have a message." The per-agent table used to be duplicated here
        // from the attached workbook's Commission sheet, which is where it belongs.
        $body = '<p>See attached NSF Commission Report - ' . htmlspecialchars($display) . '.</p>'
            . UnassignedCommissionAgents::emailBlockHtml($unassigned, $rosterUnavailable, 'NSF roster');

        $email  = new \Cmd\Reports\Services\EmailSenderService();
        // --test-recipient overrides the recipient for this run; NSF_REPORT_TEST_TO
        // is the env-level fallback. Either one redirects ALL mail (All + agents).
        $testTo = trim((string) ($this->option('test-recipient') ?: env('NSF_REPORT_TEST_TO', '')));
        $reportNames = ['NSFCommissionReport', 'NSF Commission Report'];
        $company = [strtoupper($display === 'Progress Law' ? 'PLAW' : 'LDR')];

        if ($allFiles !== []) {
            $attachments = CommissionAgentEmailFiles::toAttachments($allFiles);
            if ($testTo !== '') {
                $this->info("[INFO] [$display] NSF_REPORT_TEST_TO set — sending All only to $testTo");
                $sent = $email->sendMailHtml($subject, $body, [$testTo], [], [], $attachments);
            } else {
                $sent = $email->sendMailUsingTblReportsHtml(
                    $sql,
                    $reportNames,
                    $company,
                    $subject,
                    $body,
                    $attachments,
                    true
                );
            }
            if ($sent) {
                $this->info("[INFO] [$display] All NSF report emailed (" . count($attachments) . " attachment(s)).");
            } else {
                $this->warn("[WARN] [$display] All NSF email not sent.");
                Log::warning("GenerateNSFCommissionReport[$display]: All email not sent.");
            }
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

    private function saveSnapshotCopy(array $file, string $startDate): string
    {
        if (!isset($file['path'], $file['filename']) || !is_file((string) $file['path'])) {
            throw new \RuntimeException('Cannot save NSF commission snapshot because workbook file is missing.');
        }

        $month = date('Y-m', strtotime($startDate));
        $dir = storage_path("app/commission-snapshots/{$month}/nsf");
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $dest = $dir . DIRECTORY_SEPARATOR . (string) $file['filename'];
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

        $items = scandir($path) ?: [];
        foreach ($items as $item) {
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

    private function initSqlServer(string $source): DBConnector
    {
        $c = DBConnector::fromEnvironment($source);
        $c->initializeSqlServer();
        return $c;
    }
}
