<?php

namespace Cmd\Reports\Console\Commands\GenerateNSFCommissionReport;

use Cmd\Reports\Services\DBConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class GenerateNSFCommissionReport extends Command
{
    protected $signature = 'reports:generate-nsf-commission-report
                            {source=both : ldr | plaw | both}';

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

        $startDate = date('Y-m-01', strtotime('first day of last month'));
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

            $this->sendReport($sql, $file, $display, $startDate, $endDate);

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
            ) T ON c.ID = T.CONTACT_ID AND T.RN = 1
            WHERE CU2.NSF_RETURNED_DATE >= '$startDate'
              AND CU2.NSF_RETURNED_DATE <= '$endDate'
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
                "SELECT Employee_Name, Location FROM TblEmployees WHERE Employee_Name IN ($inList)"
            );
            foreach ($empRes['data'] ?? [] as $emp) {
                $name = (string) ($emp['Employee_Name'] ?? $emp['employee_name'] ?? '');
                $locationMap[$name] = (string) ($emp['Location'] ?? $emp['location'] ?? '');
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

            if (in_array($agent, self::FLAT_RATE_AGENTS, true)) {
                $rate = 4.00;
            } else {
                $actionsTier = $this->matchTier($ratio, [0.2, 0.4, 0.6]);
                $clearedTier = $this->matchTier($clears, [1, 51, 101]);
                $rate = ($actionsTier > 0 && $clearedTier > 0)
                    ? ($rateTable[$clearedTier][$actionsTier] ?? 0)
                    : 0;
            }

            $rows[] = [
                'agent'       => $agent,
                'assignments' => $assignments,
                'actions'     => $actions,
                'ratio'       => $ratio,
                'rate'        => $rate,
                'clears'      => $clears,
                'commission'  => $rate * $clears,
                'location'    => $locationMap[$agent] ?? '',
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

    private function sendReport(DBConnector $sql, array $file, string $display, string $start, string $end): void
    {
        if (!file_exists($file['path'])) {
            $this->warn("[WARN] [$display] File missing — not sent.");
            return;
        }

        $subject = "NSF Commission Report - $display";
        $period  = date('F Y', strtotime($start));
        $body    = "Please see the attached NSF Commission Report for $display — $period.";

        $attachment = [
            'name'         => $file['filename'],
            'contentType'  => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'contentBytes' => base64_encode((string) file_get_contents($file['path'])),
        ];

        $email = new \Cmd\Reports\Services\EmailSenderService();
        $sent  = $email->sendMailUsingTblReports(
            $sql,
            ['NSFCommissionReport', 'NSF Commission Report'],
            [strtoupper($display === 'Progress Law' ? 'PLAW' : 'LDR')],
            $subject,
            $body,
            [$attachment],
            true
        );

        if ($sent) {
            $this->info("[INFO] [$display] NSF Commission Report sent.");
        } else {
            $this->warn("[WARN] [$display] sendMailUsingTblReports returned false — report not sent.");
            Log::warning("GenerateNSFCommissionReport[$display]: email not sent.");
        }
    }

    private function initSqlServer(string $source): DBConnector
    {
        $c = DBConnector::fromEnvironment($source);
        $c->initializeSqlServer();
        return $c;
    }
}
