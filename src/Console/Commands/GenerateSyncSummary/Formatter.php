<?php

namespace Cmd\Reports\Console\Commands\GenerateSyncSummary;

use Cmd\Reports\Services\EmailSenderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PDO;

class Formatter
{
    public function buildHtmlBody(array $rows, array $statusRows = []): string
    {
        $generatedAt = now()->format('F j, Y g:i A T');

        $attentionRows = array_values(array_filter($statusRows, static fn(array $row): bool => (bool) ($row['needs_attention'] ?? false)));
        $healthyAutomations = array_values(array_filter($statusRows, static fn(array $row): bool => !(bool) ($row['needs_attention'] ?? false) && ($row['type'] ?? '') === 'Automation'));
        $healthyReports = array_values(array_filter($statusRows, static fn(array $row): bool => !(bool) ($row['needs_attention'] ?? false) && ($row['type'] ?? '') === 'Report'));

        $priority = [
            'Failed Last Run' => 0,
            'Missed' => 1,
            'No Run Evidence' => 2,
            'Other Scope Logged' => 3,
            'Schedule Mismatch' => 4,
            'Unscheduled' => 5,
        ];

        usort($attentionRows, function (array $left, array $right) use ($priority): int {
            $leftPriority = $priority[$left['status'] ?? ''] ?? 99;
            $rightPriority = $priority[$right['status'] ?? ''] ?? 99;

            return [$leftPriority, $left['name'], $left['scope']] <=> [$rightPriority, $right['name'], $right['scope']];
        });

        usort($healthyAutomations, fn(array $left, array $right): int => [$left['name'], $left['scope']] <=> [$right['name'], $right['scope']]);
        usort($healthyReports, fn(array $left, array $right): int => [$left['name'], $left['scope']] <=> [$right['name'], $right['scope']]);

        $recentRows = array_slice($rows, 0, 12);
        $totalCount = count($statusRows);
        $automationCount = count(array_filter($statusRows, static fn(array $row): bool => ($row['type'] ?? '') === 'Automation'));
        $reportCount = count(array_filter($statusRows, static fn(array $row): bool => ($row['type'] ?? '') === 'Report'));
        $attentionCount = count($attentionRows);
        $onTimeCount = count(array_filter($statusRows, static fn(array $row): bool => in_array(($row['status'] ?? ''), ['On Time', 'Table Active'], true)));
        $pendingCount = count(array_filter($statusRows, static fn(array $row): bool => ($row['status'] ?? '') === 'Pending'));

        $attentionClass = $attentionCount > 0 ? ' red' : '';

        $html = '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Helvetica, Arial, sans-serif; line-height: 1.5; color: #1a1a1a; max-width: 1180px; margin: 0 auto; padding: 24px; background: #fafafa; }
        .container { background: #fff; border: 1px solid #e5e5e5; }
        .header { background: #111827; color: #fff; padding: 24px 32px; }
        .header h1 { margin: 0; font-size: 22px; font-weight: 700; }
        .header p { margin: 8px 0 0; color: #d1d5db; font-size: 13px; }
        .summary { display: flex; gap: 14px; flex-wrap: wrap; padding: 20px 32px; border-bottom: 1px solid #e5e5e5; }
        .card { min-width: 140px; padding: 10px 14px; background: #f9fafb; border: 1px solid #e5e7eb; }
        .card-label { font-size: 11px; text-transform: uppercase; color: #6b7280; letter-spacing: 0.04em; }
        .card-value { margin-top: 2px; font-size: 22px; font-weight: 700; color: #111827; }
        .card-value.red { color: #b91c1c; }
        .content { padding: 0 32px 24px; }
        .section { margin-top: 28px; }
        .section h2 { margin: 0 0 10px; font-size: 16px; color: #111827; }
        .section p { margin: 0 0 12px; color: #6b7280; font-size: 13px; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th { background: #f3f4f6; color: #111827; text-align: left; padding: 10px 12px; font-size: 11px; text-transform: uppercase; letter-spacing: 0.04em; border-bottom: 1px solid #e5e7eb; }
        td { padding: 10px 12px; border-bottom: 1px solid #f3f4f6; vertical-align: top; }
        tr.attention td { background: #fff1f2; }
        .status { display: inline-block; border-radius: 4px; padding: 3px 8px; font-size: 11px; font-weight: 600; }
        .status-ok { background: #dcfce7; color: #166534; }
        .status-pending { background: #dbeafe; color: #1d4ed8; }
        .status-attention { background: #fee2e2; color: #991b1b; }
        .status-neutral { background: #f3f4f6; color: #6b7280; }
        .status-table { background: #d1fae5; color: #065f46; }
        .muted { color: #6b7280; }
        .empty { color: #9ca3af; font-size: 13px; }
        .footer { padding: 16px 32px; border-top: 1px solid #e5e5e5; background: #f9fafb; color: #6b7280; font-size: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Automation &amp; Sync Summary</h1>
            <p>Inventory from TblReports and TblAutomation. Evidence from TblLog, table activity, and Laravel scheduler. Generated ' . $this->escape($generatedAt) . '.</p>
        </div>
        <div class="summary">
            <div class="card"><div class="card-label">Total Items</div><div class="card-value">' . $totalCount . '</div></div>
            <div class="card"><div class="card-label">Automations</div><div class="card-value">' . $automationCount . '</div></div>
            <div class="card"><div class="card-label">Reports</div><div class="card-value">' . $reportCount . '</div></div>
            <div class="card"><div class="card-label">Needs Attention</div><div class="card-value' . $attentionClass . '">' . $attentionCount . '</div></div>
            <div class="card"><div class="card-label">On Time</div><div class="card-value">' . $onTimeCount . '</div></div>
            <div class="card"><div class="card-label">Pending</div><div class="card-value">' . $pendingCount . '</div></div>
        </div>
        <div class="content">';

        $html .= $this->renderSection('Needs Attention', $attentionRows, true);
        $html .= $this->renderSection('Healthy Automations', $healthyAutomations, false);
        $html .= $this->renderSection('Healthy Reports', $healthyReports, false);
        $html .= $this->renderRecentSection($recentRows);

        $html .= '
        </div>
        <div class="footer">
            Read-only status view. No database writes are performed by this summary.
        </div>
    </div>
</body>
</html>';

        return $html;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function renderSection(string $title, array $rows, bool $isAttention): string
    {
        $html = '<div class="section"><h2>' . $this->escape($title) . '</h2>';

        if ($rows === []) {
            $label = $isAttention ? 'No items need attention.' : 'No entries.';
            $html .= '<p class="empty">' . $label . '</p></div>';
            return $html;
        }

        $html .= '<table><thead><tr><th>Name</th><th>Scope</th><th>Schedule</th><th>Last Run</th><th>Next Expected</th><th>Status</th><th>Evidence</th></tr></thead><tbody>';

        foreach ($rows as $row) {
            $status = (string) ($row['status'] ?? 'Unknown');
            $statusClass = match ($status) {
                'On Time' => 'status status-ok',
                'Table Active' => 'status status-table',
                'Pending', 'Logged' => 'status status-pending',
                'Unscheduled', 'Scheduled' => 'status status-neutral',
                default => 'status status-attention',
            };

            $rowClass = ((bool) ($row['needs_attention'] ?? false)) ? ' class="attention"' : '';
            $lastRun = $row['last_run'] ?? null;
            $lastRunDisplay = $lastRun ? $this->formatDisplayDate((string) $lastRun) : '<span class="muted">No log</span>';

            $html .= '<tr' . $rowClass . '>';
            $html .= '<td><strong>' . $this->escape((string) ($row['name'] ?? '')) . '</strong><br><span class="muted">' . $this->escape((string) ($row['source'] ?? '')) . '</span></td>';
            $html .= '<td>' . $this->escape((string) ($row['scope'] ?? '')) . '</td>';
            $html .= '<td>' . $this->escape((string) ($row['schedule'] ?? 'Not scheduled')) . '</td>';
            $html .= '<td>' . $lastRunDisplay . '</td>';
            $html .= '<td>' . $this->escape((string) ($row['next_expected'] ?? '')) . '</td>';
            $html .= '<td><span class="' . $statusClass . '">' . $this->escape($status) . '</span></td>';
            $html .= '<td>' . $this->escape((string) ($row['evidence'] ?? 'No execution log')) . '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table></div>';

        return $html;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function renderRecentSection(array $rows): string
    {
        $html = '<div class="section"><h2>Recent Execution Evidence</h2>';

        if ($rows === []) {
            $html .= '<p class="empty">No recent tracked TblLog rows found.</p></div>';
            return $html;
        }

        $html .= '<p class="muted">Latest tracked TblLog rows.</p>';
        $html .= '<table><thead><tr><th>Macro</th><th>Run</th><th>Result</th></tr></thead><tbody>';

        foreach ($rows as $row) {
            $result = trim((string) ($row['Result'] ?? ''));
            if ($result === '') {
                $result = trim((string) ($row['Action'] ?? ''));
            }
            if (strlen($result) > 80) {
                $result = substr($result, 0, 77) . '...';
            }

            $html .= '<tr>';
            $html .= '<td><strong>' . $this->escape((string) ($row['Macro_Name'] ?? '')) . '</strong></td>';
            $html .= '<td>' . $this->formatDisplayDate((string) ($row['Last_Run_Date'] ?? '')) . '</td>';
            $html .= '<td>' . $this->escape($result) . '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table></div>';

        return $html;
    }

    private function formatDisplayDate(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '<span class="muted">No log</span>';
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return $this->escape($value);
        }

        return $this->escape(date('m/d/Y g:i A', $timestamp));
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * @param array<int, string> $overrideRecipients
     */
    public function sendReport(string $html, array $overrideRecipients = [], ?Command $console = null): bool
    {
        $email = new EmailSenderService();

        $isTest = !empty($overrideRecipients);
        $subject = ($isTest ? '[TEST] ' : '') . 'Automation & Sync Summary - ' . date('m/d/Y');
        $body = $html;

        $recipients = !empty($overrideRecipients) ? $overrideRecipients : $this->getRecipientsFromTblReports();

        $sent = $email->sendMailHtml($subject, $body, $recipients);

        if ($console) {
            if ($sent) {
                $console->info('[INFO] Summary sent to ' . count($recipients) . ' recipient(s).');
            } else {
                $console->warn('[WARN] Summary send failed.');
            }
        } elseif (!$sent) {
            Log::warning('GenerateSyncSummary: failed to send email.');
        }

        return $sent;
    }

    /**
     * @return array<int, string>
     */
    private function getRecipientsFromTblReports(): array
    {
        try {
            $config = config('dbConfig.sql_server.sql_server_connection');
            if (!$config) {
                return $this->fallbackRecipients();
            }

            $pdo = new PDO(
                $config['dsn'],
                $config['username'],
                $config['password'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );

            $sql = "
                SELECT Send_To, Send_CC, Send_BCC
                FROM dbo.TblReports
                WHERE Report_Name IN ('SyncSummary', 'Sync Summary', 'ReportSummary', 'Report Summary')
            ";

            $stmt = $pdo->query($sql);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $emails = [];
            foreach ($rows as $row) {
                foreach (['Send_To', 'Send_CC', 'Send_BCC'] as $column) {
                    $value = $row[$column] ?? '';
                    if (!$value) {
                        continue;
                    }

                    $parts = preg_split('/[;,]+/', $value) ?: [];
                    foreach ($parts as $email) {
                        $email = trim($email);
                        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                            $emails[] = $email;
                        }
                    }
                }
            }

            $emails = array_values(array_unique($emails));

            return empty($emails) ? $this->fallbackRecipients() : $emails;
        } catch (\Throwable $e) {
            Log::warning('GenerateSyncSummary: failed to load TblReports recipients.', ['error' => $e->getMessage()]);

            return $this->fallbackRecipients();
        }
    }

    /**
     * @return array<int, string>
     */
    private function fallbackRecipients(): array
    {
        return [
            'oduai@libertydebtrelief.com',
            'jacob@libertydebtrelief.com',
            'ahmed@libertydebtrelief.com',
        ];
    }
}
