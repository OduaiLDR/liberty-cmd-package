<?php

namespace Cmd\Reports\Console\Commands\GenerateCancelRequestsAgentReport;

use Cmd\Reports\Services\DBConnector;
use Cmd\Reports\Services\EmailSenderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Cancel Requests Agent Report – both LDR and PLAW.
 *
 * Faithful port of VBA GenerateCancelRequestsAgentReport.
 * Queries Snowflake for cancel request data, groups by agent,
 * checks SQL Server TblEmployees for termination status.
 * Produces Excel workbook with two sheets.
 */
class GenerateCancelRequestsAgentReport extends Command
{
    protected $signature = 'reports:generate-cancel-requests-agent-report
                            {source=both : ldr | plaw | both}';

    protected $description = 'Generate Cancel Requests Agent Report for LDR and/or PLAW.';

    private const SOURCE_CONFIG = [
        'ldr'  => ['display' => 'LDR',          'custom_cancel' => 742098, 'custom_agent' => 742096],
        'plaw' => ['display' => 'Progress Law',  'custom_cancel' => 742100, 'custom_agent' => 742097],
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
        $this->info("[INFO] GenerateCancelRequestsAgentReport – $display");

        // VBA: StartDate = first of previous month, EndDate = last of previous month
        $startDate = date('Y-m-01', strtotime('first day of last month'));
        $endDate   = date('Y-m-t', strtotime($startDate));
        $this->info("[INFO] Period: $startDate → $endDate");

        try {
            $sf  = DBConnector::fromEnvironment($source);
            $sql = $this->initSqlServer($source);
        } catch (\Throwable $e) {
            $this->error("[$display] Connector init: " . $e->getMessage());
            return;
        }

        try {
            $dataRows = $this->fetchCancelData($sf, $cfg, $startDate, $endDate);
            $this->info("[INFO] [$display] Cancel rows: " . count($dataRows));

            // Build agent summary with termination check
            $agentSummary = $this->buildAgentSummary($sql, $dataRows);

            $formatter = new CancelRequestFormatter();
            $file = $formatter->buildWorkbook($dataRows, $agentSummary, $display, $startDate, $endDate);

            if ($file) {
                $this->info("[INFO] [$display] Workbook: {$file['filename']}");
                $this->sendReport($sql, $file, $display, $startDate, $endDate);
                if (file_exists($file['path'])) {
                    @unlink($file['path']);
                }
            }
        } catch (\Throwable $e) {
            $this->error("[$display] Failed: " . $e->getMessage());
            Log::error("GenerateCancelRequestsAgentReport[$display]: failed", ['ex' => $e]);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    private function fetchCancelData(DBConnector $sf, array $cfg, string $start, string $end): array
    {
        $cc = (int)$cfg['custom_cancel'];
        $ca = (int)$cfg['custom_agent'];

        $startTs = strtotime($start);
        $endTs = strtotime($end . ' 23:59:59');

        $sql = "
            WITH PivotedFields AS (
                SELECT 
                    CONTACT_ID,
                    MAX(CASE WHEN CUSTOM_ID = $cc THEN F_DATETIME END) AS CANCEL_REQUEST_DATE,
                    MAX(CASE WHEN CUSTOM_ID = $ca THEN F_SHORTSTRING END) AS AGENT
                FROM CONTACTS_USERFIELDS
                WHERE CUSTOM_ID IN ($cc, $ca)
                GROUP BY CONTACT_ID
            )
            SELECT
                CONCAT('LLG-', c.ID) AS LLG_ID,
                p.CANCEL_REQUEST_DATE,
                p.AGENT,
                c.DROPPED_DATE
            FROM PivotedFields p
            JOIN CONTACTS c ON c.ID = p.CONTACT_ID
            WHERE p.AGENT IS NOT NULL
            ORDER BY p.AGENT ASC, p.CANCEL_REQUEST_DATE ASC
        ";
        $res = $sf->query($sql);
        $rows = $res['data'] ?? [];

        $startTs = strtotime($start);
        $endTs = strtotime($end . ' 23:59:59');

        $filtered = [];
        foreach ($rows as $r) {
            $cd = $r['CANCEL_REQUEST_DATE'] ?? null;
            if (!$cd) continue;
            
            $ts = is_numeric($cd) ? (int)$cd : strtotime($cd);
            if ($ts >= $startTs && $ts <= $endTs) {
                $filtered[] = $r;
            }
        }
        return $filtered;
    }

    /**
     * Group by agent and check SQL Server TblEmployees for termination.
     * Returns array of [agent, count, is_terminated].
     */
    private function buildAgentSummary(DBConnector $sqlConn, array $rows): array
    {
        $counts = [];
        foreach ($rows as $r) {
            $ag = (string)($r['AGENT'] ?? '');
            if ($ag === '') continue;
            $counts[$ag] = ($counts[$ag] ?? 0) + 1;
        }

        $summary = [];
        foreach ($counts as $agent => $count) {
            $safe = str_replace("'", "''", $agent);
            $res  = $sqlConn->querySqlServer(
                "SELECT COUNT(*) AS cnt FROM TblEmployees WHERE Employee_Name='$safe' AND Term_Date IS NOT NULL"
            );
            $row  = ($res['data'] ?? [])[0] ?? null;
            $terminated = $row ? ((int)($row['cnt'] ?? 0) > 0) : false;

            $summary[] = [
                'agent'       => $agent,
                'count'       => $count,
                'terminated'  => $terminated,
            ];
        }

        // VBA: sort terminated (1) ascending then count descending
        usort($summary, function($a, $b) {
            if ($a['terminated'] !== $b['terminated']) {
                return (int)$a['terminated'] <=> (int)$b['terminated'];
            }
            return $b['count'] <=> $a['count'];
        });

        return $summary;
    }

    private function sendReport(DBConnector $sql, array $file, string $display, string $start, string $end): void
    {
        $subject = "Cancel Request Agent Report – $display";
        $body    = "See attached Cancel Request Agent Report for $display from $start to $end.";
        $att     = [
            'name'         => $file['filename'],
            'contentType'  => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'contentBytes' => base64_encode((string)file_get_contents($file['path'])),
        ];

        $email = new EmailSenderService();
        $sent  = $email->sendMailUsingTblReports(
            $sql,
            ['CancelRequestAgentReport', 'Cancel Request Agent Report'],
            [strtoupper($display)],
            $subject, $body, [$att], true
        );

        if (!$sent) {
            // VBA recipients: jude@, adam@, carlos@ (TO); amir@, rama@ (CC) – use fallback until TblReports populated
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
