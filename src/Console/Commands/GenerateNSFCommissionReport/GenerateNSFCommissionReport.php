<?php

namespace Cmd\Reports\Console\Commands\GenerateNSFCommissionReport;

use Cmd\Reports\Services\DBConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class GenerateNSFCommissionReport extends Command
{
    protected $signature = 'reports:generate-nsf-commission-report
                            {source=both : ldr | plaw | both}
                            {period? : Period start date YYYY-MM-01; defaults to first day of last month}
                            {--no-email : Build/save snapshot but do not send email}';

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

            $commissionRows = $this->buildCommissionRows(
                $dataRows,
                $cfg['agents'],
                $sql
            );

            $formatter = new Formatter();
            $file = $formatter->buildWorkbook($dataRows, $commissionRows, $display, $startDate, $endDate);
            $this->info("[INFO] [$display] Workbook: {$file['filename']}");

            $snapshotPath = $this->saveSnapshotCopy($file, $startDate);
            $this->info("[INFO] [$display] Snapshot saved: {$snapshotPath}");
            $this->cleanupOldSnapshots($startDate);

            if ($this->option('no-email')) {
                $this->info("[INFO] [$display] --no-email set; skipping email send.");
            } else {
                $this->sendReport($sql, $file, $display, $startDate, $endDate, $commissionRows);
            }

            if (file_exists($file['path'])) {
                @unlink($file['path']);
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
                TO_VARCHAR(CU4.NSF_RECOUP_DATE, 'YYYY-MM-DD')   AS NSF_RECOUP_DATE,
                TO_VARCHAR(T.CLEARED_DATE, 'YYYY-MM-DD')        AS CLEARED_DATE
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
                       ROW_NUMBER() OVER (PARTITION BY CONTACT_ID ORDER BY PROCESS_DATE DESC) AS RN
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

            if (in_array($agent, self::FLAT_RATE_AGENTS, true)) {
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

    private function sendReport(DBConnector $sql, array $file, string $display, string $start, string $end, array $commissionRows): void
    {
        if (!file_exists($file['path'])) {
            $this->warn("[WARN] [$display] File missing — not sent.");
            return;
        }

        $subject = "NSF Commission Report - $display";

        // Build HTML table body matching VBA
        $body  = '<table border="1">';
        $body .= '<tr><th>Agent</th><th>Assignments</th><th>Actions</th><th>Ratio</th><th>Commission Rate</th><th>Commission</th><th>Location</th></tr>';
        foreach ($commissionRows as $row) {
            if ($row['agent'] === '') continue;
            $ratio      = $row['assignments'] > 0 ? number_format($row['ratio'] * 100, 2) . '%' : '#DIV/0!';
            $rate       = '$' . number_format($row['rate'], 2);
            $commission = '$' . number_format($row['commission'], 2);
            $body .= '<tr align="right">';
            $body .= '<td>' . htmlspecialchars($row['agent'])    . '</td>';
            $body .= '<td>' . $row['assignments']                . '</td>';
            $body .= '<td>' . $row['actions']                    . '</td>';
            $body .= '<td>' . $ratio                             . '</td>';
            $body .= '<td>' . $rate                              . '</td>';
            $body .= '<td>' . $commission                        . '</td>';
            $body .= '<td>' . htmlspecialchars($row['location']) . '</td>';
            $body .= '</tr>';
        }
        $body .= '</table>';

        $attachment = [
            'name'         => $file['filename'],
            'contentType'  => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'contentBytes' => base64_encode((string) file_get_contents($file['path'])),
        ];

        $email  = new \Cmd\Reports\Services\EmailSenderService();
        $testTo = trim((string) env('NSF_REPORT_TEST_TO', ''));

        if ($testTo !== '') {
            // Guard: override all recipients — send only to test address
            $this->info("[INFO] [$display] NSF_REPORT_TEST_TO set — sending only to $testTo");
            $sent = $email->sendMailHtml($subject, $body, [$testTo], [], [], [$attachment]);
        } else {
            $sent = $email->sendMailUsingTblReportsHtml(
                $sql,
                ['NSFCommissionReport', 'NSF Commission Report'],
                [strtoupper($display === 'Progress Law' ? 'PLAW' : 'LDR')],
                $subject,
                $body,
                [$attachment],
                true
            );
        }

        if ($sent) {
            $this->info("[INFO] [$display] NSF Commission Report sent.");
        } else {
            $this->warn("[WARN] [$display] Email not sent.");
            Log::warning("GenerateNSFCommissionReport[$display]: email not sent.");
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
