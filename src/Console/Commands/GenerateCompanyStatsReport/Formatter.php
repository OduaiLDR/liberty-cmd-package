<?php

namespace Cmd\Reports\Console\Commands\GenerateCompanyStatsReport;

use Cmd\Reports\Services\DBConnector;
use Cmd\Reports\Services\EmailSenderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class Formatter
{
    /**
     * Build HTML email body matching VBA format.
     */
    public function buildHtmlBody(array $companyStats): string
    {
        $body = 'Here are the current company stats.<br><br>';

        foreach ($companyStats as $label => $rows) {
            $body .= '<b>' . htmlspecialchars($label) . '</b><br>';
            $body .= '<table border="1" cellspacing="0" cellpadding="4" style="border-collapse: collapse; border: 1px solid #000; width: 70%;">';
            $body .= '<tr style="background-color: #D3D3D3;">';
            $body .= '<th style="border: 1px solid #000; padding: 6px; text-align: center; width: 50%;">Stat</th>';
            $body .= '<th style="border: 1px solid #000; padding: 6px; text-align: center; width: 25%;">Total</th>';
            $body .= '<th style="border: 1px solid #000; padding: 6px; text-align: center; width: 25%;">YTD</th>';
            $body .= '</tr>';

            foreach ($rows as $row) {
                $body .= '<tr>';
                $body .= '<td style="border: 1px solid #000; padding: 6px; text-align: left;">' . htmlspecialchars($row['label'] ?? '') . '</td>';
                $body .= '<td style="border: 1px solid #000; padding: 6px; text-align: right;">' . $this->formatValue($row['total'] ?? 0, $row['format'] ?? '') . '</td>';
                $body .= '<td style="border: 1px solid #000; padding: 6px; text-align: right;">' . $this->formatValue($row['ytd'] ?? 0, $row['format'] ?? '') . '</td>';
                $body .= '</tr>';
            }

            $body .= '</table><br><br>';
        }

        return $body;
    }

    /**
     * Send an LDR-family report (LDR or Paramount) using TblReports with LDR company.
     */
    public function sendLdrReport(string $subject, string $body, DBConnector $sqlConnector, ?Command $console = null): void
    {
        $email = new EmailSenderService();

        $sent = $email->sendMailUsingTblReportsHtml(
            $sqlConnector,
            ['Company Stats'],
            ['LDR'],
            $subject,
            $body
        );

        if ($console) {
            if ($sent) {
                $console->info('[INFO] LDR Company stats report sent.');
            } else {
                $console->warn('[WARN] LDR Company stats report failed to send.');
            }
        } elseif (!$sent) {
            Log::warning('GenerateCompanyStatsReport: LDR report failed to send.');
        }
    }

    /**
     * Send Progress Law report using TblReports with PLAW company.
     */
    public function sendProgressReport(string $body, DBConnector $sqlConnector, ?Command $console = null): void
    {
        $email = new EmailSenderService();
        $subject = 'Company Stats Report - Progress Law';

        $sent = $email->sendMailUsingTblReportsHtml(
            $sqlConnector,
            ['Company Stats'],
            ['PLAW'],
            $subject,
            $body
        );

        if ($console) {
            if ($sent) {
                $console->info('[INFO] PLAW Company stats report sent.');
            } else {
                $console->warn('[WARN] PLAW Company stats report failed to send.');
            }
        } elseif (!$sent) {
            Log::warning('GenerateCompanyStatsReport: PLAW report failed to send.');
        }
    }

    private function formatValue($value, string $format): string
    {
        if ($format === 'count') {
            return number_format((float) $value, 0, '.', ',');
        }

        if ($format === 'money') {
            return '$' . number_format((float) $value, 0, '.', ',');
        }

        if (str_starts_with($format, 'decimal')) {
            $parts = explode(':', $format, 2);
            $decimals = isset($parts[1]) && is_numeric($parts[1]) ? (int) $parts[1] : 2;
            return number_format((float) $value, $decimals, '.', ',');
        }

        return (string) $value;
    }
}
