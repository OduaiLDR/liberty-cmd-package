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
 * Checks TblEnrollment for data gaps. If a check fails it re-runs ONLY
 * that specific automation once. If the issue persists after the retry
 * it is flagged and an alert email is sent via Microsoft Graph.
 *
 * Email is only sent when at least one check is still failing after retry.
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
        return [
            [
                'label'   => 'Import Missing Enrollments',
                'command' => 'enrollment:import-missing',
                'check'   => "
                    SELECT TOP 50 LLG_ID
                    FROM TblEnrollment
                    WHERE Category IN ('LDR', 'CCS')
                      AND Welcome_Call_Date IS NULL
                      AND Import_Time >= CAST(GETDATE() AS DATE)
                ",
                'reason'  => 'Rows inserted today are missing Welcome_Call_Date',
            ],
            [
                'label'   => 'Sync Enrollment Data (Drop_Name / State)',
                'command' => 'Sync:enrollment-data',
                'check'   => "
                    SELECT TOP 50 LLG_ID
                    FROM TblEnrollment
                    WHERE Category IN ('LDR', 'CCS')
                      AND (Drop_Name IS NULL OR Drop_Name = '')
                      AND Welcome_Call_Date >= DATEADD(day, -90, GETDATE())
                ",
                'reason'  => 'Active enrollments (last 90 days) missing Drop_Name',
            ],
            [
                'label'   => 'Sync Enrollment Status',
                'command' => 'sync:enrollment-status',
                'check'   => "
                    SELECT TOP 50 LLG_ID
                    FROM TblEnrollment
                    WHERE Category IN ('LDR', 'CCS')
                      AND Enrollment_Status IS NULL
                      AND Welcome_Call_Date >= DATEADD(day, -90, GETDATE())
                ",
                'reason'  => 'Active enrollments (last 90 days) missing Enrollment_Status',
            ],
            [
                'label'   => 'Sync Time In Program (Payment_Frequency)',
                'command' => 'sync:time-in-program',
                'check'   => "
                    SELECT TOP 50 LLG_ID
                    FROM TblEnrollment
                    WHERE Category IN ('LDR', 'CCS')
                      AND Payment_Frequency IS NULL
                      AND COALESCE(Enrollment_Status, '') NOT IN ('Cancelled', 'Dropped', 'Cancel')
                      AND Welcome_Call_Date BETWEEN DATEADD(day, -90, GETDATE())
                                               AND DATEADD(day,  -7, GETDATE())
                ",
                'reason'  => 'Active enrollments (7–90 days old) missing Payment_Frequency',
            ],
            [
                'label'   => 'Sync Enrollment Plans',
                'command' => 'enrollment:sync-plans',
                'check'   => "
                    SELECT TOP 50 LLG_ID
                    FROM TblEnrollment
                    WHERE Category IN ('LDR', 'CCS')
                      AND Enrollment_Plan IS NULL
                      AND COALESCE(Enrollment_Status, '') NOT IN ('Cancelled', 'Dropped', 'Cancel')
                      AND Welcome_Call_Date >= DATEADD(day, -90, GETDATE())
                ",
                'reason'  => 'Active enrollments (last 90 days) missing Enrollment_Plan',
            ],
            [
                'label'   => 'Sync Debt Accounts (count + amount)',
                'command' => 'enrollment:update-debts',
                'check'   => "
                    SELECT TOP 50 LLG_ID
                    FROM TblEnrollment
                    WHERE Category IN ('LDR', 'CCS')
                      AND (Enrolled_Debt_Accounts IS NULL OR Debt_Amount IS NULL)
                      AND COALESCE(Enrollment_Status, '') NOT IN ('Cancelled', 'Dropped', 'Cancel')
                      AND Welcome_Call_Date >= DATEADD(day, -90, GETDATE())
                ",
                'reason'  => 'Active enrollments (last 90 days) missing debt count or dollar amount',
            ],
            [
                'label'   => 'Sync First Payment Date',
                'command' => 'sync:first-payment-date',
                'check'   => "
                    SELECT TOP 50 LLG_ID
                    FROM TblEnrollment
                    WHERE Category IN ('LDR', 'CCS')
                      AND First_Payment_Date IS NULL
                      AND COALESCE(Enrollment_Status, '') NOT IN ('Cancelled', 'Dropped', 'Cancel')
                      AND Welcome_Call_Date <= DATEADD(day, -30, GETDATE())
                ",
                'reason'  => 'Active enrollments 30+ days old missing First_Payment_Date',
            ],
            [
                'label'   => 'Sync First Payment Cleared Date',
                'command' => 'sync:first-payment-cleared-date',
                'check'   => "
                    SELECT TOP 50 LLG_ID
                    FROM TblEnrollment
                    WHERE Category IN ('LDR', 'CCS')
                      AND First_Payment_Cleared_Date IS NULL
                      AND COALESCE(Payments, 0) > 0
                ",
                'reason'  => 'Enrollments with cleared payments but missing First_Payment_Cleared_Date',
            ],
            [
                'label'   => 'Sync Last Deposit Date',
                'command' => 'Sync:last-deposit-date',
                'check'   => "
                    SELECT TOP 50 LLG_ID
                    FROM TblEnrollment
                    WHERE Category IN ('LDR', 'CCS')
                      AND Last_Deposit_Date IS NULL
                      AND COALESCE(Payments, 0) > 0
                ",
                'reason'  => 'Enrollments with payments but missing Last_Deposit_Date',
            ],
            [
                'label'   => 'Sync Submitted Date',
                'command' => 'sync:submitted-date',
                'check'   => "
                    SELECT TOP 50 LLG_ID
                    FROM TblEnrollment
                    WHERE Category IN ('LDR', 'CCS')
                      AND Submitted_Date IS NULL
                      AND Welcome_Call_Date BETWEEN DATEADD(day, -90, GETDATE())
                                               AND DATEADD(day,  -7, GETDATE())
                ",
                'reason'  => 'Enrollments (7–90 days old) missing Submitted_Date',
            ],
        ];
    }

    public function handle(): int
    {
        $startedAt = now();
        $skipEmail = (bool) $this->option('no-email');
        $alerts    = [];   // only steps that are STILL broken after retry

        $this->info('[IntegrityCheck] Starting at ' . $startedAt->toDateTimeString());
        Log::info('EnrollmentIntegrityCheck: starting');

        try {
            $sql = DBConnector::fromEnvironment('ldr');
            $sql->initializeSqlServer();
        } catch (\Throwable $e) {
            $this->error('SQL Server connection failed: ' . $e->getMessage());
            Log::error('EnrollmentIntegrityCheck: SQL Server connect failed', ['error' => $e->getMessage()]);
            $this->sendAlert(
                [['label' => 'SQL Server Connection', 'command' => 'n/a', 'reason' => $e->getMessage(), 'count' => 0, 'ids' => []]],
                $startedAt,
                $skipEmail
            );
            return Command::FAILURE;
        }

        foreach ($this->steps() as $step) {
            $label    = $step['label'];
            $command  = $step['command'];
            $checkSql = $step['check'];
            $reason   = $step['reason'];

            $this->line("  checking: {$label}");

            // ── Initial check ─────────────────────────────────────────────
            $ids = $this->runCheck($sql, $checkSql);

            if (empty($ids)) {
                $this->info("    ✓ OK");
                continue;
            }

            // ── Something is off — retry ONLY this automation ─────────────
            $this->warn("    ✗ {$label}: " . count($ids) . " issue(s) — retrying {$command}...");
            Log::warning("EnrollmentIntegrityCheck: check failed, retrying [{$command}]", [
                'count' => count($ids),
                'ids'   => array_slice($ids, 0, 10),
            ]);

            $this->runAutomation($command);

            // ── Re-check after retry ──────────────────────────────────────
            $retryIds = $this->runCheck($sql, $checkSql);

            if (empty($retryIds)) {
                $this->info("    ✓ Resolved after retry");
                Log::info("EnrollmentIntegrityCheck: [{$command}] resolved after retry");
                continue;
            }

            // ── Still broken — flag it ────────────────────────────────────
            $this->error("    ✗ STILL {$count = count($retryIds)} issue(s) after retry — flagging");
            Log::error("EnrollmentIntegrityCheck: [{$command}] still failing after retry", [
                'count' => $count,
                'ids'   => array_slice($retryIds, 0, 20),
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

        $elapsed = now()->diffInSeconds($startedAt);

        if (empty($alerts)) {
            $this->info("\n[IntegrityCheck] All checks passed in {$elapsed}s — no email needed.");
            Log::info('EnrollmentIntegrityCheck: all clear', ['elapsed_s' => $elapsed]);
            return Command::SUCCESS;
        }

        $this->error("\n[IntegrityCheck] " . count($alerts) . " unresolved issue(s) after {$elapsed}s — sending alert...");
        $this->sendAlert($alerts, $startedAt, $skipEmail);

        return Command::FAILURE;
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

    private function sendAlert(array $alerts, \Illuminate\Support\Carbon $startedAt, bool $skipEmail): void
    {
        if ($skipEmail) {
            $this->info('[IntegrityCheck] Email suppressed (--no-email)');
            return;
        }

        $subject = '[ENROLLMENT ALERT] ' . count($alerts) . ' automation(s) failed integrity check — ' . now()->format('Y-m-d H:i');
        $body    = $this->buildHtml($alerts, $startedAt);

        try {
            $sent = (new EmailSenderService())->sendMailHtml($subject, $body, [self::REPORT_TO]);
            $this->info('[IntegrityCheck] Alert email ' . ($sent ? 'sent' : 'FAILED to send') . ' → ' . self::REPORT_TO);
            Log::info('EnrollmentIntegrityCheck: alert email ' . ($sent ? 'sent' : 'failed'), ['to' => self::REPORT_TO]);
        } catch (\Throwable $e) {
            $this->error('Could not send alert email: ' . $e->getMessage());
            Log::error('EnrollmentIntegrityCheck: sendMailHtml threw', ['error' => $e->getMessage()]);
        }
    }

    private function buildHtml(array $alerts, \Illuminate\Support\Carbon $startedAt): string
    {
        $ranAt    = $startedAt->format('F j, Y \a\t g:i A');
        $elapsed  = now()->diffInSeconds($startedAt);
        $duration = $elapsed >= 60 ? round($elapsed / 60, 1) . ' min' : $elapsed . 's';

        $rows = '';
        foreach ($alerts as $a) {
            $sampleHtml = empty($a['ids'])
                ? '<em>none</em>'
                : '<code style="font-size:11px;">' . implode(', ', array_map('htmlspecialchars', $a['ids']))
                  . ($a['count'] > count($a['ids']) ? ' … (' . $a['count'] . ' total)' : '') . '</code>';

            $rows .= "
                <tr>
                    <td style=\"padding:9px 12px;border:1px solid #dee2e6;font-weight:600;white-space:nowrap;\">{$a['label']}</td>
                    <td style=\"padding:9px 12px;border:1px solid #dee2e6;\"><code>{$a['command']}</code></td>
                    <td style=\"padding:9px 12px;border:1px solid #dee2e6;text-align:center;font-weight:bold;color:#dc3545;\">{$a['count']}</td>
                    <td style=\"padding:9px 12px;border:1px solid #dee2e6;font-size:12px;\">{$a['reason']}</td>
                    <td style=\"padding:9px 12px;border:1px solid #dee2e6;font-size:11px;word-break:break-all;\">{$sampleHtml}</td>
                </tr>";
        }

        return "<!DOCTYPE html>
<html>
<body style=\"font-family:Arial,sans-serif;font-size:14px;color:#212529;max-width:960px;margin:0 auto;padding:20px;\">
    <h2 style=\"margin-top:0;color:#dc3545;\">&#x26A0; Enrollment Integrity Alert</h2>
    <p style=\"color:#6c757d;margin-top:-8px;\">Checked: {$ranAt} &nbsp;|&nbsp; Duration: {$duration}</p>
    <div style=\"background:#f8d7da;color:#842029;padding:12px 16px;border-radius:4px;font-size:14px;border:1px solid #f5c2c7;\">
        <strong>" . count($alerts) . " automation(s) still have data issues after an automatic retry.</strong>
        The automations below were re-run once and the problem persists — manual review may be needed.
    </div>
    <table style=\"border-collapse:collapse;width:100%;font-family:Arial,sans-serif;font-size:13px;margin-top:20px;\">
        <thead>
            <tr style=\"background:#343a40;color:#fff;\">
                <th style=\"padding:10px 12px;border:1px solid #dee2e6;text-align:left;\">Automation</th>
                <th style=\"padding:10px 12px;border:1px solid #dee2e6;text-align:left;\">Command</th>
                <th style=\"padding:10px 12px;border:1px solid #dee2e6;text-align:center;\">Issues</th>
                <th style=\"padding:10px 12px;border:1px solid #dee2e6;text-align:left;\">What's Wrong</th>
                <th style=\"padding:10px 12px;border:1px solid #dee2e6;text-align:left;\">Affected LLG_IDs (up to 20)</th>
            </tr>
        </thead>
        <tbody style=\"background:#fff8f8;\">{$rows}</tbody>
    </table>
    <hr style=\"margin-top:32px;\">
    <p style=\"font-size:11px;color:#adb5bd;\">
        Generated by <strong>enrollment:integrity-check</strong> — runs after all scheduled automations complete.<br>
        Each flagged automation was retried once automatically before this alert was sent.
    </p>
</body>
</html>";
    }
}
