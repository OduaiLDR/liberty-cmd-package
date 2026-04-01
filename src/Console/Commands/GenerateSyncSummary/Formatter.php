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
        $healthyRows = array_values(array_filter($statusRows, static fn(array $row): bool => !(bool) ($row['needs_attention'] ?? false)));

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

        usort($healthyRows, fn(array $left, array $right): int => [$left['type'], $left['name'], $left['scope']] <=> [$right['type'], $right['name'], $right['scope']]);

        $recentRows = array_slice($rows, 0, 12);
        $onTimeCount = count(array_filter($statusRows, static fn(array $row): bool => ($row['status'] ?? '') === 'On Time'));
        $pendingCount = count(array_filter($statusRows, static fn(array $row): bool => ($row['status'] ?? '') === 'Pending'));

        $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; margin: 24px; background: #f5f7fb; color: #111827; }
        .container { max-width: 1180px; margin: 0 auto; background: #ffffff; border: 1px solid #e5e7eb; }
        .header { padding: 24px 28px; border-bottom: 1px solid #e5e7eb; }
        .header h1 { margin: 0; font-size: 22px; }
        .header p { margin: 8px 0 0; color: #6b7280; font-size: 13px; }
        .summary { display: flex; gap: 12px; flex-wrap: wrap; padding: 20px 28px; border-bottom: 1px solid #e5e7eb; }
        .card { min-width: 150px; padding: 12px 14px; background: #f9fafb; border: 1px solid #e5e7eb; }
        .card-label { font-size: 11px; text-transform: uppercase; color: #6b7280; letter-spacing: 0.04em; }
        .card-value { margin-top: 4px; font-size: 24px; font-weight: 700; }
        .card-value.attention { color: #b91c1c; }
        .section { padding: 24px 28px 0; }
        .section h2 { margin: 0 0 10px; font-size: 16px; }
        .section p { margin: 0 0 12px; color: #6b7280; font-size: 13px; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th { text-align: left; background: #f3f4f6; color: #111827; padding: 10px 12px; font-size: 11px; text-transform: uppercase; letter-spacing: 0.04em; border-bottom: 1px solid #e5e7eb; }
        td { padding: 10px 12px; border-bottom: 1px solid #f3f4f6; vertical-align: top; }
        tr.attention td { background: #fff7f7; }
        .status { display: inline-block; padding: 3px 8px; border-radius: 999px; font-size: 11px; font-weight: 700; }
        .status-ok { background: #dcfce7; color: #166534; }
        .status-pending { background: #dbeafe; color: #1d4ed8; }
        .status-attention { background: #fee2e2; color: #991b1b; }
        .status-neutral { background: #f3f4f6; color: #4b5563; }
        .muted { color: #6b7280; }
        .footer { padding: 20px 28px 24px; color: #6b7280; font-size: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Sync Status Summary</h1>
            <p>Generated ' . htmlspecialchars($generatedAt) . '. Inventory comes from TblReports and TblAutomation. Execution evidence is read from TblLog, with Laravel log fallback where available.</p>
        </div>
        <div class="summary">
            <div class="card"><div class="card-label">Tracked Items</div><div class="card-value">' . count($statusRows) . '</div></div>
            <div class="card"><div class="card-label">Needs Attention</div><div class="card-value attention">' . count($attentionRows) . '</div></div>
            <div class="card"><div class="card-label">On Time</div><div class="card-value">' . $onTimeCount . '</div></div>
            <div class="card"><div class="card-label">Pending</div><div class="card-value">' . $pendingCount . '</div></div>
            <div class="card"><div class="card-label">Recent Log Rows</div><div class="card-value">' . count($recentRows) . '</div></div>
        </div>
        <div class="section">
            <h2>Needs Attention</h2>
            <p>Only rows that need review are listed here first.</p>
            ' . $this->renderStatusTable($attentionRows, true) . '
        </div>
        <div class="section">
            <h2>Healthy / Pending</h2>
            <p>Clean rows are separated so the report is easier to scan.</p>
            ' . $this->renderStatusTable($healthyRows, false) . '
        </div>
        <div class="section">
            <h2>Recent Execution Evidence</h2>
            <p>Latest tracked TblLog rows only.</p>
            ' . $this->renderRecentRows($recentRows) . '
        </div>
        <div class="footer">
            Read-only status view only. No database writes are performed by this summary.
        </div>
    </div>
</body>
</html>';

        return $html;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function renderStatusTable(array $rows, bool $showEmptyMessage): string
    {
        if ($rows === []) {
            return $showEmptyMessage
                ? '<p class="muted">No rows need attention.</p>'
                : '<p class="muted">No additional healthy rows.</p>';
        }

        $html = '<table><thead><tr><th>Name</th><th>Scope</th><th>Schedule</th><th>Last Run</th><th>Next Expected</th><th>Status</th><th>Evidence</th></tr></thead><tbody>';

        foreach ($rows as $row) {
            $status = (string) ($row['status'] ?? 'Unknown');
            $statusClass = match ($status) {
                'On Time' => 'status status-ok',
                'Pending', 'Logged' => 'status status-pending',
                'Unscheduled' => 'status status-neutral',
                default => 'status status-attention',
            };

            $html .= '<tr' . (((bool) ($row['needs_attention'] ?? false)) ? ' class="attention"' : '') . '>';
            $html .= '<td><strong>' . htmlspecialchars((string) ($row['name'] ?? '')) . '</strong><br><span class="muted">' . htmlspecialchars((string) ($row['source'] ?? '')) . '</span></td>';
            $html .= '<td>' . htmlspecialchars((string) ($row['scope'] ?? '')) . '</td>';
            $html .= '<td>' . htmlspecialchars((string) ($row['schedule'] ?? 'Not scheduled')) . '</td>';
            $html .= '<td>' . $this->formatDisplayDate((string) ($row['last_run'] ?? '')) . '</td>';
            $html .= '<td>' . htmlspecialchars((string) ($row['next_expected'] ?? '')) . '</td>';
            $html .= '<td><span class="' . $statusClass . '">' . htmlspecialchars($status) . '</span></td>';
            $html .= '<td>' . htmlspecialchars((string) ($row['evidence'] ?? 'No execution log')) . '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';

        return $html;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function renderRecentRows(array $rows): string
    {
        if ($rows === []) {
            return '<p class="muted">No recent tracked TblLog rows found.</p>';
        }

        $html = '<table><thead><tr><th>Macro</th><th>Run</th><th>Result</th></tr></thead><tbody>';

        foreach ($rows as $row) {
            $result = trim((string) ($row['Result'] ?? ''));
            if ($result === '') {
                $result = trim((string) ($row['Action'] ?? ''));
            }
            if (strlen($result) > 80) {
                $result = substr($result, 0, 77) . '...';
            }

            $html .= '<tr>';
            $html .= '<td><strong>' . htmlspecialchars((string) ($row['Macro_Name'] ?? '')) . '</strong></td>';
            $html .= '<td>' . $this->formatDisplayDate((string) ($row['Last_Run_Date'] ?? '')) . '</td>';
            $html .= '<td>' . htmlspecialchars($result) . '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';

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
            return htmlspecialchars($value);
        }

        return htmlspecialchars(date('m/d/Y g:i A', $timestamp));
    }

    /**
     * @param array<int, string> $overrideRecipients
     */
    public function sendReport(string $html, array $overrideRecipients = [], ?Command $console = null): bool
    {
        $email = new EmailSenderService();

        $isTest = !empty($overrideRecipients);
        $subject = ($isTest ? '[TEST] ' : '') . 'Daily Sync & Macro Summary - ' . date('m/d/Y');
        $body = $html;

        $recipients = !empty($overrideRecipients) ? $overrideRecipients : $this->getRecipientsFromTblReports();

        $sent = $email->sendMailHtml($subject, $body, $recipients);

        if ($console) {
            if ($sent) {
                $console->info('[INFO] Sync Summary sent to ' . count($recipients) . ' recipient(s).');
            } else {
                $console->warn('[WARN] Sync Summary send failed.');
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
