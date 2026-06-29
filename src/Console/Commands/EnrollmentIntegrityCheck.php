<?php

namespace Cmd\Reports\Console\Commands;

use Cmd\Reports\Services\DBConnector;
use Cmd\Reports\Services\EmailSenderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * Enrollment Integrity Check
 *
 * Runs all 10 enrollment automation commands in sequence, cross-checks TblEnrollment
 * for suspicious nulls or gaps after each one, retries once if issues are found,
 * then sends an HTML report email via Microsoft Graph.
 *
 * Artisan: enrollment:integrity-check
 */
class EnrollmentIntegrityCheck extends Command
{
    protected $signature = 'enrollment:integrity-check
                            {--skip-automations : Skip running automations — only run checks and email}
                            {--no-email         : Run checks but do not send email}';

    protected $description = 'Run all enrollment sync automations, cross-check data integrity, retry failures, email report via Graph API';

    private const REPORT_TO = 'oduai@libertydebtrelief.com';

    /** Each entry: label, command, check SQL (returns suspicious LLG_IDs), reason */
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
                'reason'  => 'Rows inserted today missing Welcome_Call_Date — import may have failed',
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
                'reason'  => 'Active enrollments (last 90 days) still missing Drop_Name after sync',
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
                'reason'  => 'Active enrollments (last 90 days) still missing Enrollment_Status',
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
                'reason'  => 'Active enrollments (7-90 days) still missing Payment_Frequency',
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
                'reason'  => 'Active enrollments (last 90 days) still missing Enrollment_Plan',
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
                'reason'  => 'Active enrollments (last 90 days) still missing debt data',
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
                'reason'  => 'Active enrollments 30+ days old still missing First_Payment_Date',
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
                'reason'  => 'Enrollments with cleared payments but still missing First_Payment_Cleared_Date',
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
                'reason'  => 'Enrollments with payments but still missing Last_Deposit_Date',
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
                'reason'  => 'Enrollments (7-90 days) still missing Submitted_Date',
            ],
        ];
    }

    public function handle(): int
    {
        $startedAt   = now();
        $skipAuto    = (bool) $this->option('skip-automations');
        $skipEmail   = (bool) $this->option('no-email');
        $overallOk   = true;
        $results     = [];

        $this->info('[EnrollmentIntegrityCheck] Starting at ' . $startedAt->toDateTimeString());
        Log::info('EnrollmentIntegrityCheck: starting', ['skip_automations' => $skipAuto]);

        try {
            $sql = DBConnector::fromEnvironment('ldr');
            $sql->initializeSqlServer();
        } catch (\Throwable $e) {
            $this->error('Cannot connect to SQL Server: ' . $e->getMessage());
            Log::error('EnrollmentIntegrityCheck: SQL Server connect failed', ['error' => $e->getMessage()]);
            $this->maybeSendEmail(false, [], $startedAt, 'SQL Server connection failed: ' . $e->getMessage(), $skipEmail);
            return Command::FAILURE;
        }

        foreach ($this->steps() as $step) {
            $label   = $step['label'];
            $command = $step['command'];
            $checkSql = $step['check'];
            $reason  = $step['reason'];

            $this->info("\n" . str_repeat('-', 60));
            $this->info("▶  {$label}");

            $result = [
                'label'         => $label,
                'command'       => $command,
                'reason'        => $reason,
                'status'        => 'OK',          // OK | FIXED | ALERT | SKIPPED | ERROR
                'pre_count'     => 0,
                'post_count'    => 0,
                'sample_ids'    => [],
                'automation_ok' => true,
                'error'         => null,
            ];

            // ── Run pre-check (before automation) ────────────────────────
            $preIds = $this->runCheck($sql, $checkSql);
            $result['pre_count'] = count($preIds);

            if ($result['pre_count'] > 0) {
                $this->warn("   Pre-check: {$result['pre_count']} suspicious row(s) found");
            } else {
                $this->info("   Pre-check: clean");
            }

            // ── Run automation ────────────────────────────────────────────
            if (!$skipAuto) {
                $exitCode = $this->runAutomation($command);
                $result['automation_ok'] = ($exitCode === 0);

                if (!$result['automation_ok']) {
                    $this->warn("   Automation exited with code {$exitCode}");
                }
            }

            // ── Post-check ────────────────────────────────────────────────
            $postIds = $this->runCheck($sql, $checkSql);
            $result['post_count'] = count($postIds);
            $result['sample_ids'] = array_slice($postIds, 0, 20);

            if ($result['post_count'] === 0) {
                $result['status'] = ($result['pre_count'] > 0) ? 'FIXED' : 'OK';
                $this->info("   Post-check: ✓ clean");
            } else {
                // Issues remain — retry automation once
                $this->warn("   Post-check: {$result['post_count']} issue(s) — retrying automation...");

                if (!$skipAuto) {
                    $retryCode = $this->runAutomation($command);
                    $result['automation_ok'] = ($retryCode === 0);
                }

                $retryIds = $this->runCheck($sql, $checkSql);
                $result['post_count']  = count($retryIds);
                $result['sample_ids']  = array_slice($retryIds, 0, 20);

                if ($result['post_count'] === 0) {
                    $result['status'] = 'FIXED';
                    $this->info("   Retry: ✓ resolved after retry");
                } else {
                    $result['status'] = 'ALERT';
                    $overallOk = false;
                    $this->error("   Retry: ✗ still {$result['post_count']} issue(s) after retry");
                    Log::warning("EnrollmentIntegrityCheck: persistent issue in [{$label}]", [
                        'count'      => $result['post_count'],
                        'sample_ids' => $result['sample_ids'],
                        'reason'     => $reason,
                    ]);
                }
            }

            $results[] = $result;
        }

        $elapsed = now()->diffInSeconds($startedAt);
        $this->info("\n" . str_repeat('=', 60));
        $this->info('[EnrollmentIntegrityCheck] Completed in ' . $elapsed . 's — overall: ' . ($overallOk ? 'OK ✓' : 'ISSUES FOUND ✗'));
        Log::info('EnrollmentIntegrityCheck: completed', ['overall_ok' => $overallOk, 'elapsed_s' => $elapsed]);

        $this->maybeSendEmail($overallOk, $results, $startedAt, null, $skipEmail);

        return $overallOk ? Command::SUCCESS : Command::FAILURE;
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
            Log::error('EnrollmentIntegrityCheck: check query failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    private function runAutomation(string $command): int
    {
        try {
            return Artisan::call($command);
        } catch (\Throwable $e) {
            Log::error("EnrollmentIntegrityCheck: automation [{$command}] threw exception", [
                'error' => $e->getMessage(),
            ]);
            return 1;
        }
    }

    private function maybeSendEmail(
        bool $overallOk,
        array $results,
        \Illuminate\Support\Carbon $startedAt,
        ?string $fatalError,
        bool $skipEmail
    ): void {
        if ($skipEmail) {
            $this->info('[EnrollmentIntegrityCheck] Email skipped (--no-email)');
            return;
        }

        $subject = $fatalError
            ? '[ENROLLMENT INTEGRITY] ✗ FATAL ERROR — ' . now()->format('Y-m-d H:i')
            : '[ENROLLMENT INTEGRITY] ' . ($overallOk ? '✓ All Clear' : '✗ Issues Found') . ' — ' . now()->format('Y-m-d H:i');

        $body = $this->buildHtmlEmail($overallOk, $results, $startedAt, $fatalError);

        try {
            $mailer  = new EmailSenderService();
            $sent    = $mailer->sendMailHtml($subject, $body, [self::REPORT_TO]);
            $outcome = $sent ? 'sent' : 'failed';
            $this->info("[EnrollmentIntegrityCheck] Email {$outcome} → " . self::REPORT_TO);
            Log::info("EnrollmentIntegrityCheck: email {$outcome}", ['to' => self::REPORT_TO]);
        } catch (\Throwable $e) {
            $this->error('Failed to send email: ' . $e->getMessage());
            Log::error('EnrollmentIntegrityCheck: email send failed', ['error' => $e->getMessage()]);
        }
    }

    private function buildHtmlEmail(
        bool $overallOk,
        array $results,
        \Illuminate\Support\Carbon $startedAt,
        ?string $fatalError
    ): string {
        $elapsed  = now()->diffInSeconds($startedAt);
        $ranAt    = $startedAt->format('F j, Y \a\t g:i A');
        $duration = $elapsed >= 60
            ? round($elapsed / 60, 1) . ' min'
            : $elapsed . 's';

        $banner = $fatalError
            ? '<div style="background:#dc3545;color:#fff;padding:12px 16px;border-radius:4px;font-size:15px;font-weight:bold;">✗ FATAL — Could not run checks: ' . htmlspecialchars($fatalError) . '</div>'
            : ($overallOk
                ? '<div style="background:#198754;color:#fff;padding:12px 16px;border-radius:4px;font-size:15px;font-weight:bold;">✓ All enrollment data checks passed</div>'
                : '<div style="background:#dc3545;color:#fff;padding:12px 16px;border-radius:4px;font-size:15px;font-weight:bold;">✗ One or more enrollment integrity checks still have issues after retry</div>');

        $rows = '';
        foreach ($results as $r) {
            [$bg, $icon] = match ($r['status']) {
                'OK'     => ['#d1e7dd', '✓'],
                'FIXED'  => ['#fff3cd', '⚡'],
                'ALERT'  => ['#f8d7da', '✗'],
                'ERROR'  => ['#f8d7da', '⚠'],
                default  => ['#e2e3e5', '—'],
            };
            $sampleHtml = empty($r['sample_ids'])
                ? '<em>none</em>'
                : '<code style="font-size:11px;">' . implode(', ', array_map('htmlspecialchars', $r['sample_ids'])) . (count($r['sample_ids']) < $r['post_count'] ? ', …' : '') . '</code>';

            $rows .= "
                <tr style=\"background:{$bg};\">
                    <td style=\"padding:8px 12px;border:1px solid #dee2e6;white-space:nowrap;\">{$icon} {$r['label']}</td>
                    <td style=\"padding:8px 12px;border:1px solid #dee2e6;white-space:nowrap;\"><code>{$r['command']}</code></td>
                    <td style=\"padding:8px 12px;border:1px solid #dee2e6;text-align:center;\">{$r['post_count']}</td>
                    <td style=\"padding:8px 12px;border:1px solid #dee2e6;font-size:12px;\">{$r['reason']}</td>
                    <td style=\"padding:8px 12px;border:1px solid #dee2e6;font-size:11px;word-break:break-all;\">{$sampleHtml}</td>
                </tr>";
        }

        $tableHtml = empty($results) ? '<p><em>No steps were run.</em></p>' : "
            <table style=\"border-collapse:collapse;width:100%;font-family:Arial,sans-serif;font-size:13px;margin-top:16px;\">
                <thead>
                    <tr style=\"background:#343a40;color:#fff;\">
                        <th style=\"padding:10px 12px;border:1px solid #dee2e6;text-align:left;\">Automation</th>
                        <th style=\"padding:10px 12px;border:1px solid #dee2e6;text-align:left;\">Command</th>
                        <th style=\"padding:10px 12px;border:1px solid #dee2e6;text-align:center;\">Issues</th>
                        <th style=\"padding:10px 12px;border:1px solid #dee2e6;text-align:left;\">What It Checks</th>
                        <th style=\"padding:10px 12px;border:1px solid #dee2e6;text-align:left;\">Sample LLG_IDs</th>
                    </tr>
                </thead>
                <tbody>{$rows}</tbody>
            </table>";

        return "
<!DOCTYPE html>
<html>
<body style=\"font-family:Arial,sans-serif;font-size:14px;color:#212529;max-width:960px;margin:0 auto;padding:20px;\">
    <h2 style=\"margin-top:0;\">Enrollment Integrity Report</h2>
    <p style=\"color:#6c757d;margin-top:-8px;\">Run: {$ranAt} &nbsp;|&nbsp; Duration: {$duration}</p>
    {$banner}
    {$tableHtml}
    <hr style=\"margin-top:32px;\">
    <p style=\"font-size:11px;color:#adb5bd;\">
        Generated by <strong>enrollment:integrity-check</strong> — cmd-runner<br>
        Showing up to 20 sample IDs per check. Counts may be higher (TOP 50 sampled from SQL Server).
    </p>
</body>
</html>";
    }
}
