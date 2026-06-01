<?php

namespace Cmd\Reports\Console\Commands\ProgramCompletions;

use Cmd\Reports\Services\DBConnector;
use Cmd\Reports\Services\EmailSenderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ReportFormatter
{
    public function buildHtmlReport(ReportData $data): string
    {
        return $this->renderTemplate($data->toArray());
    }

    public function sendReport(DBConnector $connector, ReportData $data, ?Command $console = null): bool
    {
        $html = $this->buildHtmlReport($data);
        $subject = $this->buildSubject($data);

        $email = new EmailSenderService();

        $sent = $email->sendMailUsingTblReportsHtml(
            $connector,
            ['ProgramCompletionsReport', 'Program Completions Report', 'Graduation Report'],
            [],
            $subject,
            $html,
            []
        );

        if ($console) {
            if ($sent) {
                $console->info('  OK Program Completions report email sent.');
            } else {
                $console->warn('  WARN Program Completions report not sent (no recipients in TblReports or send failed).');
            }
        } elseif (!$sent) {
            Log::warning('ProgramCompletionsReport: failed to send email (no recipients in TblReports or send failed).');
        }

        return $sent;
    }

    private function buildSubject(ReportData $data): string
    {
        $date = now()->format('M j, Y g:i A');
        $prefix = $data->dryRun ? '[DRY RUN] ' : '';

        return "{$prefix}Program Completions Report - {$data->connection} - {$data->totalGraduated} Graduated - {$date}";
    }

    private function renderTemplate(array $report): string
    {
        $timestamp = $report['timestamp'] ?? now()->format('F j, Y \\a\\t g:i A T');
        $connection = $report['connection'] ?? 'ALL';
        $dryRun = (bool) ($report['dryRun'] ?? false);
        $totalProcessed = (int) ($report['totalProcessed'] ?? 0);
        $totalGraduated = (int) ($report['totalGraduated'] ?? 0);
        $crmUpdates = (int) ($report['crmUpdates'] ?? 0);
        $notesCreated = (int) ($report['notesCreated'] ?? 0);
        $errorsCount = (int) ($report['errorsCount'] ?? 0);

        $reportTitle = 'Program Completions Report';
        $bannerText = 'DRY RUN MODE - No actual changes were made';
        $modeText = $dryRun ? 'DRY RUN MODE - No changes were made' : 'LIVE MODE - Changes applied to production';
        $generatedAt = now()->format('Y-m-d H:i:s T');
        $errorColor = $errorsCount > 0 ? '#dc2626' : '#166534';

        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$reportTitle} - {$connection}</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; line-height: 1.5; color: #1a1a1a; max-width: 960px; margin: 0 auto; padding: 24px; background-color: #fff; }
        .container { background: #fff; border: 1px solid #e5e5e5; }
        .header { background: #166534; color: #fff; padding: 24px 32px; }
        .header h1 { margin: 0; font-size: 20px; font-weight: 600; letter-spacing: -0.01em; }
        .header-meta { display: flex; gap: 24px; margin-top: 8px; font-size: 13px; color: #dcfce7; }
        .content { padding: 24px 32px; }
        .section { margin-top: 28px; }
        .section h2 { font-size: 15px; margin: 0 0 10px; color: #1a1a1a; }
        .section p { margin: 0 0 10px; color: #525252; font-size: 13px; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th { background: #f5f5f5; padding: 10px 12px; text-align: left; font-weight: 600; color: #1a1a1a; font-size: 11px; text-transform: uppercase; letter-spacing: 0.03em; border-bottom: 1px solid #e5e5e5; }
        td { padding: 10px 12px; border-bottom: 1px solid #f0f0f0; vertical-align: middle; }
        tr:hover td { background: #fafafa; }
        .cid { font-family: 'SF Mono', Monaco, 'Courier New', monospace; font-size: 12px; }
        .tag { display: inline-block; padding: 3px 8px; font-size: 11px; font-weight: 500; border-radius: 3px; }
        .tag-success { background: #dcfce7; color: #166534; }
        .tag-error { background: #fee2e2; color: #991b1b; }
        .tag-preview { background: #fef3c7; color: #92400e; }
        .tag-skip { background: #f5f5f5; color: #9ca3af; font-style: italic; }
        .no-data { color: #a0a0a0; }
        .currency, .percent { font-family: 'SF Mono', Monaco, 'Courier New', monospace; font-size: 12px; text-align: right; }
        .footer { background: #f5f5f5; padding: 16px 32px; font-size: 11px; color: #666; border-top: 1px solid #e5e5e5; }
        .footer p { margin: 4px 0; }
        .dry-run-banner { background: #fef3c7; color: #92400e; padding: 12px 32px; font-size: 13px; font-weight: 500; border-bottom: 1px solid #fcd34d; }
        .error-section { margin-top: 24px; }
        .error-section h3 { color: #dc2626; font-size: 14px; margin: 0 0 8px; }
        .error-row td { color: #dc2626; }
        .summary-box { background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 6px; padding: 16px; margin-bottom: 20px; }
        .summary-box h3 { margin: 0 0 12px; font-size: 14px; color: #166534; }
        .summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; }
        .summary-item { display: flex; justify-content: space-between; align-items: center; }
        .summary-label { color: #525252; font-size: 13px; }
        .summary-value { font-weight: 600; color: #1a1a1a; font-size: 15px; }
    </style>
</head>
<body>
    <div class="container">
HTML;

        if ($dryRun) {
            $html .= '<div class="dry-run-banner">' . htmlspecialchars($bannerText) . '</div>';
        }

        $html .= <<<HTML
        <div class="header">
            <h1>{$reportTitle} - {$connection}</h1>
            <div class="header-meta">
                <span>{$timestamp}</span>
                <span>Graduated: <strong>{$totalGraduated}</strong></span>
                <span>CRM Updates: <strong>{$crmUpdates}</strong></span>
            </div>
        </div>
        <div class="content">
        <div class="summary-box">
            <h3>Summary</h3>
            <div class="summary-grid">
                <div class="summary-item">
                    <span class="summary-label">Processed:</span>
                    <span class="summary-value">{$totalProcessed}</span>
                </div>
                <div class="summary-item">
                    <span class="summary-label">Graduated:</span>
                    <span class="summary-value">{$totalGraduated}</span>
                </div>
                <div class="summary-item">
                    <span class="summary-label">CRM Updates:</span>
                    <span class="summary-value">{$crmUpdates}</span>
                </div>
                <div class="summary-item">
                    <span class="summary-label">Notes Created:</span>
                    <span class="summary-value">{$notesCreated}</span>
                </div>
                <div class="summary-item">
                    <span class="summary-label">Errors:</span>
                    <span class="summary-value" style="color: {$errorColor};">{$errorsCount}</span>
                </div>
            </div>
        </div>
HTML;

        if (!empty($report['completedClients'])) {
            $count = count($report['completedClients']);
            $html .= '<div class="section"><h2>Graduated Clients</h2><p>' . $count . ' clients marked as graduated</p><table><thead><tr><th>Client ID</th><th>Client Name</th><th>Original Debt</th><th>Debt Settled</th><th>Settlement %</th><th>CRM Status</th><th>Note</th></tr></thead><tbody>';

            foreach ($report['completedClients'] as $client) {
                $cid = htmlspecialchars($client['llg_id'] ?? '');
                $name = htmlspecialchars($client['client_name'] ?? '');
                $originalDebt = htmlspecialchars($client['original_debt'] ?? '$0');
                $settledDebt = htmlspecialchars($client['settled_debt'] ?? '$0');
                $settlementPct = htmlspecialchars($client['settlement_pct'] ?? '0%');
                $crmTag = $this->renderStatusTag((string) ($client['crm_status'] ?? 'Skipped'));
                $noteTag = $this->renderStatusTag((string) ($client['note_status'] ?? 'Skipped'));

                $html .= "<tr><td class=\"cid\">{$cid}</td><td>{$name}</td><td class=\"currency\">{$originalDebt}</td><td class=\"currency\">{$settledDebt}</td><td class=\"percent\">{$settlementPct}</td><td>{$crmTag}</td><td>{$noteTag}</td></tr>";
            }
            $html .= '</tbody></table></div>';
        } else {
            $html .= '<div class="section"><h2>Graduated Clients</h2><p class="no-data">No program completions were processed in this run.</p></div>';
        }

        if (!empty($report['errors'])) {
            $count = count($report['errors']);
            $html .= '<div class="error-section"><h3>Errors (' . $count . ')</h3>';
            $html .= '<table><thead><tr><th>Client ID</th><th>Details</th></tr></thead><tbody>';
            foreach ($report['errors'] as $error) {
                $cid = htmlspecialchars($error['llg_id'] ?? 'Unknown');
                $message = htmlspecialchars($error['message'] ?? '');
                $html .= "<tr class=\"error-row\"><td class=\"cid\">{$cid}</td><td>{$message}</td></tr>";
            }
            $html .= '</tbody></table></div>';
        }

        $html .= <<<HTML
        </div>
        <div class="footer">
            <p>Program Completions Automation - {$generatedAt}</p>
            <p>{$modeText}</p>
        </div>
    </div>
</body>
</html>
HTML;

        return $html;
    }

    private function renderStatusTag(string $status): string
    {
        return match ($status) {
            'Success' => '<span class="tag tag-success">Updated</span>',
            'Would Update', 'Would Create' => '<span class="tag tag-preview">' . htmlspecialchars($status) . '</span>',
            'Skipped' => '<span class="tag tag-skip">Skipped</span>',
            default => '<span class="tag tag-error">Error</span>',
        };
    }
}
