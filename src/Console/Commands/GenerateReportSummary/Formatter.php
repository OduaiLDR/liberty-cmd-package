<?php

namespace Cmd\Reports\Console\Commands\GenerateReportSummary;

use Cmd\Reports\Services\EmailSenderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PDO;

class Formatter
{
    /**
     * @param array<int, array<string, mixed>> $rows
     */
    public function buildHtmlBody(array $rows): string
    {
        $reports = array_values(array_filter($rows, fn(array $row): bool => ($row['type'] ?? '') === 'Report'));
        $automations = array_values(array_filter($rows, fn(array $row): bool => ($row['type'] ?? '') === 'Automation'));
        $attentionRows = array_values(array_filter($rows, fn(array $row): bool => (bool) ($row['needs_attention'] ?? false)));
        $healthyReports = array_values(array_filter($reports, fn(array $row): bool => !(bool) ($row['needs_attention'] ?? false)));
        $healthyAutomations = array_values(array_filter($automations, fn(array $row): bool => !(bool) ($row['needs_attention'] ?? false)));
        $overdueCount = count($attentionRows);
        $onTimeCount = count(array_filter($rows, fn(array $row): bool => ($row['status'] ?? '') === 'On Time'));
        $pendingCount = count(array_filter($rows, fn(array $row): bool => ($row['status'] ?? '') === 'Pending'));
        $generatedAt = now()->format('F j, Y g:i A T');

        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Automation Run Summary</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; line-height: 1.5; color: #1a1a1a; max-width: 1180px; margin: 0 auto; padding: 24px; background: #fafafa; }
        .container { background: #fff; border: 1px solid #e5e5e5; }
        .header { background: #111827; color: #fff; padding: 24px 32px; }
        .header h1 { margin: 0; font-size: 22px; }
        .header p { margin: 8px 0 0; color: #d1d5db; font-size: 13px; }
        .summary { display: flex; gap: 16px; flex-wrap: wrap; padding: 20px 32px; border-bottom: 1px solid #e5e5e5; }
        .summary-item { background: #f9fafb; border: 1px solid #e5e7eb; padding: 10px 14px; min-width: 160px; }
        .summary-label { color: #6b7280; font-size: 11px; text-transform: uppercase; letter-spacing: 0.04em; }
        .summary-value { color: #111827; font-size: 22px; font-weight: 700; }
        .summary-value.red { color: #b91c1c; }
        .content { padding: 24px 32px; }
        h2 { margin: 0 0 12px; font-size: 16px; color: #111827; }
        .section { margin-top: 28px; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th { background: #f3f4f6; color: #111827; text-align: left; padding: 10px 12px; font-size: 11px; text-transform: uppercase; letter-spacing: 0.04em; border-bottom: 1px solid #e5e7eb; }
        td { padding: 10px 12px; border-bottom: 1px solid #f3f4f6; vertical-align: top; }
        .muted { color: #6b7280; }
        .status { display: inline-block; border-radius: 4px; padding: 3px 8px; font-size: 11px; font-weight: 600; }
        .status-ok { background: #dcfce7; color: #166534; }
        .status-pending { background: #dbeafe; color: #1d4ed8; }
        .status-unscheduled { background: #f3f4f6; color: #6b7280; }
        .status-overdue { background: #fee2e2; color: #991b1b; }
        tr.overdue td { background: #fff1f2; }
        .footer { padding: 16px 32px; border-top: 1px solid #e5e5e5; background: #f9fafb; color: #6b7280; font-size: 12px; }
        .empty { color: #9ca3af; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Automation Run Summary</h1>
            <p>Inventory sourced from TblReports and TblAutomation. Generated {$generatedAt}.</p>
        </div>
        <div class="summary">
            <div class="summary-item">
                <div class="summary-label">Total Items</div>
                <div class="summary-value">{$this->escape((string) count($rows))}</div>
            </div>
            <div class="summary-item">
                <div class="summary-label">Reports</div>
                <div class="summary-value">{$this->escape((string) count($reports))}</div>
            </div>
            <div class="summary-item">
                <div class="summary-label">Automations</div>
                <div class="summary-value">{$this->escape((string) count($automations))}</div>
            </div>
            <div class="summary-item">
                <div class="summary-label">Overdue</div>
                <div class="summary-value{$this->cssClass($overdueCount > 0, ' red', '')}">{$this->escape((string) $overdueCount)}</div>
            </div>
            <div class="summary-item">
                <div class="summary-label">On Time</div>
                <div class="summary-value">{$this->escape((string) $onTimeCount)}</div>
            </div>
            <div class="summary-item">
                <div class="summary-label">Pending</div>
                <div class="summary-value">{$this->escape((string) $pendingCount)}</div>
            </div>
        </div>
        <div class="content">
HTML;

        $html .= $this->renderSection('Needs Attention', $attentionRows);
        $html .= $this->renderSection('Healthy Reports', $healthyReports);
        $html .= $this->renderSection('Healthy Automations', $healthyAutomations);

        $html .= <<<HTML
        </div>
        <div class="footer">
            This report is read-only. It compares configured inventory from TblReports and TblAutomation against available execution evidence.
        </div>
    </div>
</body>
</html>
HTML;

        return $html;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, string> $overrideRecipients
     */
    public function sendReport(string $html, array $overrideRecipients = [], ?Command $console = null): bool
    {
        $email = new EmailSenderService();
        $isTest = !empty($overrideRecipients);
        $subjectPrefix = $isTest ? '[TEST] ' : '';
        $subject = $subjectPrefix . 'Automation Run Summary - ' . now()->format('m/d/Y');

        $recipients = !empty($overrideRecipients) ? $overrideRecipients : $this->getRecipientsFromTblReports();

        if (empty($recipients)) {
            if ($console) {
                $console->warn('[WARN] Report Summary not sent (no recipients found).');
            }
            Log::warning('GenerateReportSummary: no recipients found.');
            return false;
        }

        $sent = $email->sendMailHtml($subject, $html, $recipients);

        if ($console) {
            if ($sent) {
                $console->info('[INFO] Report Summary sent to ' . count($recipients) . ' recipient(s).');
            } else {
                $console->warn('[WARN] Report Summary send failed.');
            }
        } elseif (!$sent) {
            Log::warning('GenerateReportSummary: failed to send email.');
        }

        return $sent;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function renderSection(string $title, array $rows): string
    {
        if (empty($rows)) {
            return '<div class="section"><h2>' . $this->escape($title) . '</h2><p class="empty">No entries found.</p></div>';
        }

        $html = '<div class="section"><h2>' . $this->escape($title) . '</h2>';
        $html .= '<table><thead><tr><th>Name</th><th>Scope</th><th>Schedule</th><th>Last Run</th><th>Next Expected</th><th>Status</th><th>Evidence</th></tr></thead><tbody>';

        foreach ($rows as $row) {
            $isOverdue = (bool) ($row['needs_attention'] ?? false);
            $status = (string) ($row['status'] ?? 'Unknown');
            $statusClass = match ($status) {
                'Missed', 'Failed Last Run', 'No Run Evidence', 'Schedule Mismatch', 'Other Scope Logged', 'Log Gap' => 'status status-overdue',
                'Pending' => 'status status-pending',
                'Unscheduled', 'Logged', 'Scheduled' => 'status status-unscheduled',
                default => 'status status-ok',
            };

            $lastRun = $row['last_run'] ?? null;
            $lastRunDisplay = $lastRun ? $this->formatDisplayDate((string) $lastRun) : '<span class="muted">No log</span>';
            $nextExpected = $row['next_expected'] ?? '';
            $scope = (string) ($row['scope'] ?? '');

            $html .= '<tr' . ($isOverdue ? ' class="overdue"' : '') . '>';
            $html .= '<td><strong>' . $this->escape((string) ($row['name'] ?? '')) . '</strong><br><span class="muted">' . $this->escape((string) ($row['source'] ?? '')) . '</span></td>';
            $html .= '<td>' . $this->escape($scope) . '</td>';
            $html .= '<td>' . $this->escape((string) ($row['schedule'] ?? '')) . '</td>';
            $html .= '<td>' . $lastRunDisplay . '</td>';
            $html .= '<td>' . $this->escape((string) $nextExpected) . '</td>';
            $html .= '<td><span class="' . $statusClass . '">' . $this->escape($status) . '</span></td>';
            $html .= '<td>' . $this->escape((string) ($row['evidence'] ?? 'No execution log')) . '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table></div>';

        return $html;
    }

    /**
     * @return array<int, string>
     */
    private function getRecipientsFromTblReports(): array
    {
        try {
            $config = config('dbConfig.sql_server.sql_server_connection');
            if (!$config) {
                return [];
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
                WHERE Report_Name IN ('ReportSummary', 'Report Summary')
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

            return array_values(array_unique($emails));
        } catch (\Throwable $e) {
            Log::error('GenerateReportSummary: Failed to get recipients from TblReports', ['error' => $e->getMessage()]);
            return [];
        }
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function cssClass(bool $condition, string $trueClass, string $falseClass): string
    {
        return $condition ? $trueClass : $falseClass;
    }

    private function formatDisplayDate(string $value): string
    {
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return $this->escape($value);
        }

        return $this->escape(date('m/d/Y g:i A', $timestamp));
    }
}
