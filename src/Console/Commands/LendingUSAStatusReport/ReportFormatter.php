<?php

namespace Cmd\Reports\Console\Commands\LendingUSAStatusReport;

use Cmd\Reports\Services\DBConnector;
use Cmd\Reports\Services\EmailSenderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ReportFormatter
{
    public function buildHtmlReport(ReportData $data): string
    {
        $report = $data->toArray();
        
        return $this->renderTemplate($report);
    }

    public function sendReport(DBConnector $connector, ReportData $data, ?Command $console = null): bool
    {
        $html = $this->buildHtmlReport($data);
        $subject = $this->buildSubject($data);

        $email = new EmailSenderService();

        // Look up recipients from TblReports
        $sent = $email->sendMailUsingTblReportsHtml(
            $connector,
            ['LendingUSAStatusReport', 'LendingUSA Status Report', 'Lending USA Status Report'],
            [],
            $subject,
            $html,
            []
        );

        if ($console) {
            if ($sent) {
                $console->info('[INFO] LendingUSA status report email sent.');
            } else {
                $console->warn('[WARN] LendingUSA status report not sent (no recipients in TblReports or send failed).');
            }
        } elseif (!$sent) {
            Log::warning('LendingUSAStatusReport: failed to send email (no recipients in TblReports or send failed).');
        }

        return $sent;
    }

    private function buildSubject(ReportData $data): string
    {
        $date = now()->format('M j, Y g:i A');
        $prefix = $data->dryRun ? '[DRY RUN] ' : '';
        return "{$prefix}LendingUSA Status Report - {$data->connection} - {$data->totalChanges} Changes - {$date}";
    }

    private function renderTemplate(array $report): string
    {
        $timestamp = $report['timestamp'] ?? now()->format('F j, Y \a\t g:i A T');
        $connection = $report['connection'] ?? 'ALL';
        $dryRun = $report['dryRun'] ?? false;
        $totalChanges = $report['totalChanges'] ?? 0;
        $totalProcessed = $report['totalProcessed'] ?? 0;
        $crmUpdates = $report['crmUpdates'] ?? 0;
        $noChange = $report['noChange'] ?? 0;
        $errorsCount = $report['errorsCount'] ?? 0;
        
        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LendingUSA Status Sync Report - {$connection}</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; line-height: 1.5; color: #1a1a1a; max-width: 960px; margin: 0 auto; padding: 24px; background-color: #fff; }
        .container { background: #fff; border: 1px solid #e5e5e5; }
        .header { background: #1a1a1a; color: #fff; padding: 24px 32px; }
        .header h1 { margin: 0; font-size: 20px; font-weight: 600; letter-spacing: -0.01em; }
        .header-meta { display: flex; gap: 24px; margin-top: 8px; font-size: 13px; color: #a0a0a0; }
        .header-meta span { display: flex; align-items: center; gap: 6px; }
        .content { padding: 24px 32px; }
        .stats { display: flex; gap: 16px; margin-bottom: 24px; font-size: 13px; color: #525252; flex-wrap: wrap; }
        .stats strong { color: #1a1a1a; }
        .section { margin-top: 28px; }
        .section h2 { font-size: 15px; margin: 0 0 10px; color: #1a1a1a; }
        .section p { margin: 0 0 10px; color: #525252; font-size: 13px; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th { background: #f5f5f5; padding: 10px 12px; text-align: left; font-weight: 600; color: #1a1a1a; font-size: 11px; text-transform: uppercase; letter-spacing: 0.03em; border-bottom: 1px solid #e5e5e5; }
        td { padding: 10px 12px; border-bottom: 1px solid #f0f0f0; vertical-align: middle; }
        tr:hover td { background: #fafafa; }
        .cid { font-family: 'SF Mono', Monaco, 'Courier New', monospace; font-size: 12px; }
        .tag { display: inline-block; padding: 3px 8px; font-size: 11px; font-weight: 500; border-radius: 3px; }
        .tag-before { background: #f5f5f5; color: #525252; }
        .tag-after { background: #dcfce7; color: #166534; }
        .tag-crm { background: #e0e7ff; color: #3730a3; }
        .tag-skip { background: #f5f5f5; color: #9ca3af; font-style: italic; }
        .tag-error { background: #fee2e2; color: #991b1b; }
        .tag-funded { background: #dcfce7; color: #166534; }
        .no-data { color: #a0a0a0; }
        .more-rows { text-align: center; padding: 12px; color: #666; font-size: 12px; background: #fafafa; border-top: 1px solid #f0f0f0; }
        .transition { white-space: nowrap; }
        .muted { color: #737373; }
        .footer { background: #f5f5f5; padding: 16px 32px; font-size: 11px; color: #666; border-top: 1px solid #e5e5e5; }
        .footer p { margin: 4px 0; }
        .dry-run-banner { background: #fef3c7; color: #92400e; padding: 12px 32px; font-size: 13px; font-weight: 500; border-bottom: 1px solid #fcd34d; }
        .error-section { margin-top: 24px; }
        .error-section h3 { color: #dc2626; font-size: 14px; margin: 0 0 8px; }
        .error-row td { color: #dc2626; }
    </style>
</head>
<body>
    <div class="container">
HTML;

        // Dry run banner
        if ($dryRun) {
            $html .= '<div class="dry-run-banner">DRY RUN MODE - No actual changes were made</div>';
        }

        // Header with clear title
        $html .= <<<HTML
        <div class="header">
            <h1>LendingUSA Status Sync Report &mdash; {$connection}</h1>
            <div class="header-meta">
                <span>{$timestamp}</span>
                <span>Changes: <strong>{$totalChanges}</strong></span>
                <span>CRM Updates: <strong>{$crmUpdates}</strong></span>
            </div>
        </div>
        <div class="content">
HTML;

        $html .= <<<HTML
        <div class="stats">
            <span>Processed: <strong>{$totalProcessed}</strong></span>
            <span>Changes: <strong>{$totalChanges}</strong></span>
            <span>CRM Updates: <strong>{$crmUpdates}</strong></span>
            <span>No Change: <strong>{$noChange}</strong></span>
            <span>Errors: <strong>{$errorsCount}</strong></span>
        </div>
HTML;

        if (!empty($report['fundedClients'])) {
            $html .= '<div class="section"><h2>Funded Clients</h2><table><thead><tr><th>Client ID</th><th>Name</th><th>Previous Status</th><th>State</th><th>Negotiator</th></tr></thead><tbody>';
            foreach ($report['fundedClients'] as $client) {
                $cid = htmlspecialchars($client['cid'] ?? '');
                $name = htmlspecialchars($client['name'] ?? '');
                $prevStatus = htmlspecialchars($client['prev_status'] ?? 'N/A');
                $state = htmlspecialchars($client['state'] ?? '-');
                $negotiator = htmlspecialchars($client['negotiator'] ?? 'Pending');
                $html .= "<tr><td class=\"cid\">{$cid}</td><td>{$name}</td><td><span class=\"tag tag-before\">{$prevStatus}</span></td><td>{$state}</td><td>{$negotiator}</td></tr>";
            }
            $html .= '</tbody></table></div>';
        }

        if (!empty($report['declinedClients'])) {
            $html .= '<div class="section"><h2>Declined Clients</h2><table><thead><tr><th>Client ID</th><th>Name</th><th>Transition</th><th>State</th></tr></thead><tbody>';
            foreach ($report['declinedClients'] as $client) {
                $cid = htmlspecialchars($client['cid'] ?? '');
                $name = htmlspecialchars($client['name'] ?? '');
                $prevStatus = htmlspecialchars($client['prev_status'] ?? 'N/A');
                $newStatus = htmlspecialchars($client['new_status'] ?? '');
                $state = htmlspecialchars($client['state'] ?? '-');
                $transition = "<span class=\"transition\"><span class=\"tag tag-before\">{$prevStatus}</span> → <span class=\"tag tag-after\">{$newStatus}</span></span>";
                $html .= "<tr><td class=\"cid\">{$cid}</td><td>{$name}</td><td>{$transition}</td><td>{$state}</td></tr>";
            }
            $html .= '</tbody></table></div>';
        }

        if (!empty($report['newClients'])) {
            $html .= '<div class="section"><h2>New Applications</h2><table><thead><tr><th>Client ID</th><th>Name</th><th>Status</th><th>State</th><th>Plan</th></tr></thead><tbody>';
            foreach ($report['newClients'] as $client) {
                $cid = htmlspecialchars($client['cid'] ?? '');
                $name = htmlspecialchars($client['name'] ?? '');
                $status = htmlspecialchars($client['status'] ?? '');
                $state = htmlspecialchars($client['state'] ?? '-');
                $plan = htmlspecialchars($client['plan'] ?? '-');
                $html .= "<tr><td class=\"cid\">{$cid}</td><td>{$name}</td><td>{$status}</td><td>{$state}</td><td>{$plan}</td></tr>";
            }
            $html .= '</tbody></table></div>';
        }

        if (!empty($report['statusBreakdown'])) {
            $html .= '<div class="section"><h2>Status Distribution</h2><table><thead><tr><th>Status</th><th>Count</th></tr></thead><tbody>';
            foreach ($report['statusBreakdown'] as $status => $count) {
                $html .= '<tr><td>' . htmlspecialchars((string) $status) . '</td><td>' . htmlspecialchars((string) $count) . '</td></tr>';
            }
            $html .= '</tbody></table></div>';
        }

        // All status changes
        if (!empty($report['changedRecords'])) {
            $count = count($report['changedRecords']);
            $html .= '<div class="section"><h2>All Status Changes</h2><p>' . $count . ' records</p><table><thead><tr><th>Client ID</th><th>Name</th><th>State</th><th>Transition</th><th>CRM Update</th></tr></thead><tbody>';
            foreach ($report['changedRecords'] as $rec) {
                $cid = htmlspecialchars($rec['cid'] ?? '');
                $name = htmlspecialchars($rec['name'] ?? '');
                $nameDisplay = $name ?: '<span class="no-data">-</span>';
                $state = htmlspecialchars($rec['state'] ?? '-');
                $oldStatus = htmlspecialchars($rec['old_status'] ?? 'N/A');
                $newStatus = htmlspecialchars($rec['new_status'] ?? '');
                $crmStatus = $rec['crm_status'] ?? '';

                $crmHtml = $this->formatCrmCell($crmStatus);
                $transition = "<span class=\"transition\"><span class=\"tag tag-before\">{$oldStatus}</span> → <span class=\"tag tag-after\">{$newStatus}</span></span>";
                
                $html .= "<tr><td class=\"cid\">{$cid}</td><td>{$nameDisplay}</td><td>{$state}</td><td>{$transition}</td><td>{$crmHtml}</td></tr>";
            }
            $html .= '</tbody></table></div>';
        } else {
            $html .= '<p class="no-data">No status changes detected.</p>';
        }

        // Errors (only if any)
        if (!empty($report['errors'])) {
            $count = count($report['errors']);
            $html .= '<div class="error-section"><h3>Errors (' . $count . ')</h3>';
            $html .= '<table><thead><tr><th>Client ID</th><th>Details</th></tr></thead><tbody>';
            foreach ($report['errors'] as $error) {
                $cid = htmlspecialchars($error['cid'] ?? 'Unknown');
                $message = htmlspecialchars($error['message'] ?? '');
                $html .= "<tr class=\"error-row\"><td class=\"cid\">{$cid}</td><td>{$message}</td></tr>";
            }
            $html .= '</tbody></table></div>';
        }

        // Footer
        $generatedAt = now()->format('Y-m-d H:i:s T');

        $html .= <<<HTML
        </div>
        <div class="footer">
            <p>LendingUSA Status Sync Automation &mdash; {$generatedAt}</p>
        </div>
    </div>
</body>
</html>
HTML;

        return $html;
    }

    private function formatCrmCell(string $crmStatus): string
    {
        if ($crmStatus === '' || $crmStatus === '-') {
            return '<span class="no-data">-</span>';
        }
        if ($crmStatus === '(Error)') {
            return '<span class="tag tag-error">CRM Error</span>';
        }
        if ($crmStatus === '(Not in CRM)') {
            return '<span class="tag tag-skip">Not in CRM</span>';
        }
        if ($crmStatus === '(No mapping)') {
            return '<span class="tag tag-skip">No CRM mapping</span>';
        }
        if ($crmStatus === '(No change)') {
            return '<span class="tag tag-skip">Already current</span>';
        }
        if ($crmStatus === '(Graduated)') {
            return '<span class="tag tag-skip">Graduated</span>';
        }
        if ($crmStatus === '(Cancelled)') {
            return '<span class="tag tag-skip">Cancelled</span>';
        }
        // Actual CRM status update
        return '<span class="tag tag-crm">' . htmlspecialchars($crmStatus) . '</span>';
    }
}
