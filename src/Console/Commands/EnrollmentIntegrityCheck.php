<?php

namespace Cmd\Reports\Console\Commands;

use Cmd\Reports\Services\DBConnector;
use Cmd\Reports\Services\EmailSenderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * Enrollment Integrity Check — watchdog, not a runner.
 *
 * Runs AFTER all automations have already executed on their schedule.
 * Each step has a severity:
 *
 *   'alert'  — automation is broken or data needs manual intervention.
 *              Failures are emailed and cause a non-zero exit.
 *              Examples: duplicate LLG_IDs, sync exceptions.
 *
 *   'info'   — persistent gap means the SOURCE data doesn't exist yet
 *              (e.g. no campaign assigned, no enrollment plan in CRM).
 *              The sync ran and retried — it's not broken, the data just
 *              isn't there. Logged and shown in output; NOT emailed.
 *
 * Artisan: enrollment:integrity-check
 */
class EnrollmentIntegrityCheck extends Command
{
    protected $signature = 'enrollment:integrity-check
                            {--no-email : Run checks and retries but suppress the alert email}';

    protected $description = 'Watchdog: checks enrollment data integrity after automations run, retries specific failing automation, emails alert if unresolved';

    private const REPORT_TO = 'oduai@libertydebtrelief.com';

    private function steps(): array
    {
        // severity = 'alert' → email + failure exit if unresolved after retry
        // severity = 'info'  → log only; gap means source data doesn't exist, not a broken sync
        return [
            [
                'label'    => 'Import Missing Enrollments',
                'command'  => 'enrollment:import-missing',
                'severity' => 'info',
                'check'    => "
                    SELECT TOP 50 LLG_ID
                    FROM TblEnrollment
                    WHERE Category IN ('LDR', 'CCS')
                      AND Welcome_Call_Date IS NULL
                      AND Import_Time >= CAST(GETDATE() AS DATE)
                ",
                'reason'   => 'Rows inserted today are missing Welcome_Call_Date — likely not yet set in CRM',
            ],
            [
                'label'    => 'Sync Enrollment Data (Drop_Name / State)',
                'command'  => 'Sync:enrollment-data',
                'severity' => 'info',
                'check'    => "
                    SELECT TOP 50 LLG_ID
                    FROM TblEnrollment
                    WHERE Category IN ('LDR', 'CCS')
                      AND (Drop_Name IS NULL OR Drop_Name = '')
                      AND Welcome_Call_Date >= DATEADD(day, -90, GETDATE())
                ",
                'reason'   => 'Active enrollments (last 90 days) missing Drop_Name — contact was enrolled without a campaign/mailer assignment in CRM',
            ],
            [
                'label'    => 'Sync Enrollment Status',
                'command'  => 'sync:enrollment-status',
                'severity' => 'info',
                'check'    => "
                    SELECT TOP 50 LLG_ID
                    FROM TblEnrollment
                    WHERE Category IN ('LDR', 'CCS')
                      AND Enrollment_Status IS NULL
                      AND Welcome_Call_Date >= DATEADD(day, -90, GETDATE())
                ",
                'reason'   => 'Active enrollments (last 90 days) missing Enrollment_Status — status not yet set in Snowflake',
            ],
            [
                'label'    => 'Sync Time In Program (Payment_Frequency)',
                'command'  => 'sync:time-in-program',
                'severity' => 'info',
                'check'    => "
                    SELECT TOP 50 LLG_ID
                    FROM TblEnrollment
                    WHERE Category IN ('LDR', 'CCS')
                      AND Payment_Frequency IS NULL
                      AND COALESCE(Enrollment_Status, '') NOT IN ('Cancelled', 'Dropped', 'Cancel')
                      AND Welcome_Call_Date BETWEEN DATEADD(day, -90, GETDATE())
                                               AND DATEADD(day,  -7, GETDATE())
                ",
                'reason'   => 'Active enrollments (7–90 days old) missing Payment_Frequency — enrollment plan has no payment schedule set in Snowflake',
            ],
            [
                'label'    => 'Sync Enrollment Plans',
                'command'  => 'enrollment:sync-plans',
                'severity' => 'info',
                'check'    => "
                    SELECT TOP 50 LLG_ID
                    FROM TblEnrollment
                    WHERE Category IN ('LDR', 'CCS')
                      AND Enrollment_Plan IS NULL
                      AND COALESCE(Enrollment_Status, '') NOT IN ('Cancelled', 'Dropped', 'Cancel')
                      AND Welcome_Call_Date >= DATEADD(day, -90, GETDATE())
                ",
                'reason'   => 'Active enrollments (last 90 days) missing Enrollment_Plan — no plan linked in Snowflake ENROLLMENT_DEFAULTS2',
            ],
            [
                'label'    => 'Sync Debt Accounts (count + amount)',
                'command'  => 'enrollment:update-debts',
                'severity' => 'info',
                'check'    => "
                    SELECT TOP 50 LLG_ID
                    FROM TblEnrollment
                    WHERE Category IN ('LDR', 'CCS')
                      AND (Enrolled_Debt_Accounts IS NULL OR Debt_Amount IS NULL)
                      AND COALESCE(Enrollment_Status, '') NOT IN ('Cancelled', 'Dropped', 'Cancel')
                      AND Welcome_Call_Date >= DATEADD(day, -90, GETDATE())
                ",
                'reason'   => 'Active enrollments (last 90 days) missing debt count or amount — no enrolled debts in Snowflake yet',
            ],
            [
                'label'    => 'Sync First Payment Date',
                'command'  => 'sync:first-payment-date',
                'severity' => 'info',
                'check'    => "
                    SELECT TOP 50 LLG_ID
                    FROM TblEnrollment
                    WHERE Category IN ('LDR', 'CCS')
                      AND First_Payment_Date IS NULL
                      AND COALESCE(Payments, 0) > 0
                      AND COALESCE(Enrollment_Status, '') NOT LIKE '%Cancelled%'
                      AND COALESCE(Enrollment_Status, '') NOT LIKE '%Dropped%'
                      AND Welcome_Call_Date <= DATEADD(day, -30, GETDATE())
                ",
                'reason'   => 'Enrollments 30+ days old with payments but no First_Payment_Date — transactions may be in the other Snowflake instance or not yet cleared',
            ],
            [
                'label'    => 'Sync First Payment Cleared Date',
                'command'  => 'sync:first-payment-cleared-date',
                'severity' => 'info',
                'check'    => "
                    SELECT TOP 50 LLG_ID
                    FROM TblEnrollment
                    WHERE Category IN ('LDR', 'CCS')
                      AND First_Payment_Cleared_Date IS NULL
                      AND COALESCE(Payments, 0) > 0
                      AND First_Payment_Date IS NOT NULL
                      AND First_Payment_Date <= GETDATE()
                ",
                'reason'   => 'First_Payment_Date has passed but no cleared date found — payment may still be pending in Snowflake',
            ],
            [
                'label'    => 'Sync Last Deposit Date',
                'command'  => 'Sync:last-deposit-date',
                'severity' => 'info',
                'check'    => "
                    SELECT TOP 50 LLG_ID
                    FROM TblEnrollment
                    WHERE Category IN ('LDR', 'CCS')
                      AND Last_Deposit_Date IS NULL
                      AND First_Payment_Cleared_Date IS NOT NULL
                      AND COALESCE(Payments, 0) > 0
                ",
                'reason'   => 'Cleared payments exist but Last_Deposit_Date is missing — transaction not found in Snowflake',
            ],
            [
                'label'    => 'Sync Submitted Date',
                'command'  => 'sync:submitted-date',
                'severity' => 'info',
                'check'    => "
                    SELECT TOP 50 LLG_ID
                    FROM TblEnrollment
                    WHERE Category IN ('LDR', 'CCS')
                      AND Submitted_Date IS NULL
                      AND Welcome_Call_Date BETWEEN DATEADD(day, -90, GETDATE())
                                               AND DATEADD(day,  -7, GETDATE())
                ",
                'reason'   => 'Enrollments (7–90 days old) missing Submitted_Date — status not yet reached in Snowflake',
            ],
        ];
    }

    public function handle(): int
    {
        $startedAt = now();
        $skipEmail = (bool) $this->option('no-email');
        $alerts    = [];
        $steps     = $this->steps();
        $total     = count($steps);

        $this->log('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->log("ENROLLMENT INTEGRITY CHECK — {$startedAt->format('Y-m-d H:i:s')}");
        $this->log("Checks to run: {$total}");
        $this->log('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        try {
            $this->log('Connecting to SQL Server...');
            $sql = DBConnector::fromEnvironment('ldr');
            $sql->initializeSqlServer();
            $this->log('Connected.');
        } catch (\Throwable $e) {
            $this->log('ERROR: SQL Server connection failed — ' . $e->getMessage(), 'error');
            $this->sendAlert(
                [['label' => 'SQL Server Connection', 'command' => 'n/a', 'reason' => $e->getMessage(), 'count' => 0, 'ids' => []]],
                [],
                $startedAt,
                $skipEmail
            );
            return Command::FAILURE;
        }

        $dataGaps = [];   // severity=info: syncs ran fine, source data just doesn't exist

        foreach ($steps as $i => $step) {
            $label    = $step['label'];
            $command  = $step['command'];
            $checkSql = $step['check'];
            $reason   = $step['reason'];
            $severity = $step['severity'] ?? 'alert';
            $num      = $i + 1;

            $this->log("─────────────────────────────────────────────────────────");
            $this->log("[{$num}/{$total}] {$label}");
            $this->log("  command : {$command}");
            $this->log("  checking: {$reason}");

            // ── Initial check ─────────────────────────────────────────────
            $t0  = microtime(true);
            $ids = $this->runCheck($sql, $checkSql);
            $ms  = round((microtime(true) - $t0) * 1000);

            if (empty($ids)) {
                $this->log("  result  : ✓ CLEAN  ({$ms}ms)");
                continue;
            }

            // ── Something is off — retry ──────────────────────────────────
            $found = count($ids);
            $this->log("  result  : ✗ {$found} gap(s) found ({$ms}ms)", 'warn');
            $this->log("  sample  : " . implode(', ', array_slice($ids, 0, 5)) . ($found > 5 ? ' …' : ''));
            $this->log("  action  : re-running [{$command}]...", 'warn');

            Log::warning("EnrollmentIntegrityCheck: check failed [{$label}]", [
                'count'  => $found,
                'sample' => array_slice($ids, 0, 10),
            ]);

            $t1 = microtime(true);
            $this->runAutomation($command);
            $retryMs = round((microtime(true) - $t1) * 1000);
            $this->log("  retried : completed in {$retryMs}ms — re-checking...");

            $retryIds = $this->runCheck($sql, $checkSql);

            if (empty($retryIds)) {
                $this->log("  result  : ✓ RESOLVED after retry", 'info');
                Log::info("EnrollmentIntegrityCheck: [{$label}] resolved after retry");
                continue;
            }

            $count = count($retryIds);

            if ($severity === 'info') {
                // Sync ran and retried — gap means data doesn't exist in source, not a broken automation.
                $this->log("  result  : ℹ {$count} still missing after retry — data gap in source (not an error)", 'line');
                Log::info("EnrollmentIntegrityCheck: [{$label}] data gap after retry — source data missing", [
                    'count'  => $count,
                    'sample' => array_slice($retryIds, 0, 20),
                    'reason' => $reason,
                ]);
                $dataGaps[] = [
                    'label'   => $label,
                    'command' => $command,
                    'reason'  => $reason,
                    'count'   => $count,
                    'ids'     => array_slice($retryIds, 0, 20),
                ];
            } else {
                $this->log("  result  : ✗ STILL {$count} issue(s) after retry — FLAGGED", 'error');
                Log::error("EnrollmentIntegrityCheck: [{$label}] still failing after retry", [
                    'count'  => $count,
                    'sample' => array_slice($retryIds, 0, 20),
                    'reason' => $reason,
                ]);
                $alerts[] = [
                    'label'   => $label,
                    'command' => $command,
                    'reason'  => $reason,
                    'count'   => $count,
                    'ids'     => array_slice($retryIds, 0, 20),
                ];
            }
        }

        // ── Duplicate LLG_ID check (no retry — needs manual fix) ─────────────
        $this->log('─────────────────────────────────────────────────────────');
        $this->log('[DUP] Checking for duplicate LLG_IDs in TblEnrollment...');

        $dupIds = $this->runCheck($sql, "
            SELECT TOP 50 LLG_ID
            FROM TblEnrollment
            WHERE Category IN ('LDR', 'CCS')
            GROUP BY LLG_ID
            HAVING COUNT(*) > 1
        ");

        if (empty($dupIds)) {
            $this->log('[DUP] ✓ No duplicates found');
        } else {
            $dupCount = count($dupIds);
            $this->log("[DUP] ✗ {$dupCount} LLG_ID(s) have duplicate rows — FLAGGED (manual fix required)", 'error');
            Log::error('EnrollmentIntegrityCheck: duplicate LLG_IDs detected', [
                'count'  => $dupCount,
                'sample' => array_slice($dupIds, 0, 20),
            ]);
            $alerts[] = [
                'label'   => 'Duplicate LLG_IDs',
                'command' => 'manual',
                'reason'  => 'Same LLG_ID appears more than once in TblEnrollment — no automation can fix this, manual DELETE required',
                'count'   => $dupCount,
                'ids'     => array_slice($dupIds, 0, 20),
            ];
        }

        $elapsed = round(now()->diffInSeconds($startedAt));
        $this->log('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        // Log data gaps summary (informational — no email)
        if (!empty($dataGaps)) {
            $gapCount = count($dataGaps);
            $this->log("ℹ {$gapCount} data gap(s) noted (source data missing — automation is fine, no action needed):", 'line');
            foreach ($dataGaps as $g) {
                $this->log("  • {$g['label']}: {$g['count']} record(s) — {$g['reason']}", 'line');
            }
        }

        if (empty($alerts)) {
            $this->log("✓ NO ALERTS — elapsed: {$elapsed}s — no email sent." . (!empty($dataGaps) ? " ({$gapCount} data gap(s) logged above.)" : ''));
            $this->log('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
            Log::info('EnrollmentIntegrityCheck: all clear', ['elapsed_s' => $elapsed, 'data_gaps' => count($dataGaps)]);
            return Command::SUCCESS;
        }

        $flagged = count($alerts);
        $this->log("{$flagged} ALERT(S) require attention after {$elapsed}s — sending alert email...", 'error');
        $this->log('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->sendAlert($alerts, $dataGaps, $startedAt, $skipEmail);

        return Command::FAILURE;
    }

    private function log(string $message, string $level = 'line'): void
    {
        $ts      = now()->format('H:i:s');
        $full    = "[{$ts}] {$message}";
        $logPath = storage_path('logs/enrollment-integrity.log');

        // Write to dedicated log file
        @file_put_contents($logPath, $full . PHP_EOL, FILE_APPEND | LOCK_EX);

        // Write to console with colour
        match ($level) {
            'error' => $this->error($full),
            'warn'  => $this->warn($full),
            'info'  => $this->info($full),
            default => $this->line($full),
        };

        Log::channel('single')->{$level === 'warn' ? 'warning' : ($level === 'line' ? 'info' : $level)}($message);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function runCheck(DBConnector $sql, string $checkSql): array
    {
        try {
            $result = $sql->querySqlServer($checkSql);
            $rows   = is_array($result)
                ? ($result['data'] ?? (array_is_list($result) ? $result : []))
                : [];
            return array_values(array_filter(array_column($rows, 'LLG_ID')));
        } catch (\Throwable $e) {
            Log::error('EnrollmentIntegrityCheck: check query threw exception', ['error' => $e->getMessage()]);
            return [];
        }
    }

    private function runAutomation(string $command): void
    {
        try {
            Artisan::call($command);
        } catch (\Throwable $e) {
            Log::error("EnrollmentIntegrityCheck: retry of [{$command}] threw exception", [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function sendAlert(array $alerts, array $dataGaps, \Illuminate\Support\Carbon $startedAt, bool $skipEmail): void
    {
        if ($skipEmail) {
            $this->info('[IntegrityCheck] Email suppressed (--no-email)');
            return;
        }

        $subject = '[ENROLLMENT ALERT] ' . count($alerts) . ' issue(s) need attention — ' . now()->format('Y-m-d H:i');
        $body    = $this->buildHtml($alerts, $dataGaps, $startedAt);

        try {
            $sent = (new EmailSenderService())->sendMailHtml($subject, $body, [self::REPORT_TO]);
            $this->info('[IntegrityCheck] Alert email ' . ($sent ? 'sent' : 'FAILED to send') . ' → ' . self::REPORT_TO);
            Log::info('EnrollmentIntegrityCheck: alert email ' . ($sent ? 'sent' : 'failed'), ['to' => self::REPORT_TO]);
        } catch (\Throwable $e) {
            $this->error('Could not send alert email: ' . $e->getMessage());
            Log::error('EnrollmentIntegrityCheck: sendMailHtml threw', ['error' => $e->getMessage()]);
        }
    }

    private function buildHtml(array $alerts, array $dataGaps, \Illuminate\Support\Carbon $startedAt): string
    {
        $ranAt    = $startedAt->format('F j, Y \a\t g:i A');
        $elapsed  = now()->diffInSeconds($startedAt);
        $duration = $elapsed >= 60 ? round($elapsed / 60, 1) . ' min' : $elapsed . 's';

        // ── ALERTS section (red — needs action) ──────────────────────────────
        $alertRows = '';
        foreach ($alerts as $a) {
            $isManual   = ($a['command'] === 'manual');
            $cmdDisplay = $isManual
                ? '<span style="color:#842029;font-weight:bold;">&#x26A0; MANUAL FIX REQUIRED</span>'
                : '<code>' . htmlspecialchars($a['command']) . '</code>';
            $rowBg = $isManual ? '#fff3cd' : '#fff8f8';

            $sampleHtml = empty($a['ids'])
                ? '<em>none</em>'
                : '<code style="font-size:11px;">' . implode(', ', array_map('htmlspecialchars', $a['ids']))
                  . ($a['count'] > count($a['ids']) ? ' … (' . $a['count'] . ' total)' : '') . '</code>';

            $alertRows .= "
                <tr style=\"background:{$rowBg};\">
                    <td style=\"padding:9px 12px;border:1px solid #dee2e6;font-weight:600;white-space:nowrap;\">{$a['label']}</td>
                    <td style=\"padding:9px 12px;border:1px solid #dee2e6;\">{$cmdDisplay}</td>
                    <td style=\"padding:9px 12px;border:1px solid #dee2e6;text-align:center;font-weight:bold;color:#dc3545;\">{$a['count']}</td>
                    <td style=\"padding:9px 12px;border:1px solid #dee2e6;font-size:12px;\">{$a['reason']}</td>
                    <td style=\"padding:9px 12px;border:1px solid #dee2e6;font-size:11px;word-break:break-all;\">{$sampleHtml}</td>
                </tr>";
        }

        $alertsTable = "
    <div style=\"background:#f8d7da;color:#842029;padding:12px 16px;border-radius:4px;font-size:14px;border:1px solid #f5c2c7;margin-bottom:12px;\">
        <strong>" . count($alerts) . " alert(s) need your attention.</strong>
        These automations were retried once and the issue persists — action required.
    </div>
    <table style=\"border-collapse:collapse;width:100%;font-family:Arial,sans-serif;font-size:13px;margin-bottom:32px;\">
        <thead>
            <tr style=\"background:#842029;color:#fff;\">
                <th style=\"padding:10px 12px;border:1px solid #c9a0a4;text-align:left;\">Automation</th>
                <th style=\"padding:10px 12px;border:1px solid #c9a0a4;text-align:left;\">Command</th>
                <th style=\"padding:10px 12px;border:1px solid #c9a0a4;text-align:center;\">Count</th>
                <th style=\"padding:10px 12px;border:1px solid #c9a0a4;text-align:left;\">Why It's Flagged</th>
                <th style=\"padding:10px 12px;border:1px solid #c9a0a4;text-align:left;\">Affected LLG_IDs (up to 20)</th>
            </tr>
        </thead>
        <tbody>{$alertRows}</tbody>
    </table>";

        // ── DATA GAPS section (grey/blue — informational, no action needed) ──
        $gapsSection = '';
        if (!empty($dataGaps)) {
            $gapRows = '';
            foreach ($dataGaps as $g) {
                $sampleHtml = empty($g['ids'])
                    ? '<em>none</em>'
                    : '<code style="font-size:11px;">' . implode(', ', array_map('htmlspecialchars', $g['ids']))
                      . ($g['count'] > count($g['ids']) ? ' … (' . $g['count'] . ' total)' : '') . '</code>';

                $gapRows .= "
                <tr style=\"background:#f8f9fa;\">
                    <td style=\"padding:9px 12px;border:1px solid #dee2e6;font-weight:600;white-space:nowrap;\">{$g['label']}</td>
                    <td style=\"padding:9px 12px;border:1px solid #dee2e6;text-align:center;color:#6c757d;\">{$g['count']}</td>
                    <td style=\"padding:9px 12px;border:1px solid #dee2e6;font-size:12px;color:#495057;\">{$g['reason']}</td>
                    <td style=\"padding:9px 12px;border:1px solid #dee2e6;font-size:11px;word-break:break-all;\">{$sampleHtml}</td>
                </tr>";
            }

            $gapsSection = "
    <h3 style=\"color:#495057;font-size:14px;margin-bottom:6px;\">&#x2139; Data Gaps &mdash; informational only, no action needed</h3>
    <div style=\"background:#e7f1ff;color:#084298;padding:10px 16px;border-radius:4px;font-size:13px;border:1px solid #b6d4fe;margin-bottom:12px;\">
        These syncs ran and retried successfully. Records are missing because the source data
        doesn't exist yet in the CRM or Snowflake &mdash; <strong>the automation is working correctly.</strong>
        These will resolve automatically once the data is added upstream.
    </div>
    <table style=\"border-collapse:collapse;width:100%;font-family:Arial,sans-serif;font-size:13px;margin-bottom:32px;\">
        <thead>
            <tr style=\"background:#495057;color:#fff;\">
                <th style=\"padding:10px 12px;border:1px solid #dee2e6;text-align:left;\">Sync</th>
                <th style=\"padding:10px 12px;border:1px solid #dee2e6;text-align:center;\">Count</th>
                <th style=\"padding:10px 12px;border:1px solid #dee2e6;text-align:left;\">Why Data Is Missing</th>
                <th style=\"padding:10px 12px;border:1px solid #dee2e6;text-align:left;\">Sample LLG_IDs</th>
            </tr>
        </thead>
        <tbody>{$gapRows}</tbody>
    </table>";
        }

        return "<!DOCTYPE html>
<html>
<body style=\"font-family:Arial,sans-serif;font-size:14px;color:#212529;max-width:980px;margin:0 auto;padding:20px;\">
    <h2 style=\"margin-top:0;color:#dc3545;\">&#x26A0; Enrollment Integrity Alert</h2>
    <p style=\"color:#6c757d;margin-top:-8px;\">Checked: {$ranAt} &nbsp;|&nbsp; Duration: {$duration}</p>
    {$alertsTable}
    {$gapsSection}
    <hr style=\"margin-top:16px;\">
    <p style=\"font-size:11px;color:#adb5bd;\">
        Generated by <strong>enrollment:integrity-check</strong> &mdash; runs after all scheduled automations complete.<br>
        Each check was retried once before this alert was sent.
        <strong>Alerts = broken or needs manual fix.</strong>
        <strong>Data Gaps = automation is fine, source data missing.</strong>
    </p>
</body>
</html>";
    }
}
