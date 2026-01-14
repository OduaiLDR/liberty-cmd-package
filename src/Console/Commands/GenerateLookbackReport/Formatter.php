<?php

namespace Cmd\Reports\Console\Commands\GenerateLookbackSummaryReport;

use Cmd\Reports\Services\DBConnector;
use Cmd\Reports\Services\EmailSenderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class Formatter
{

    /**
     * Build an HTML email body that mirrors the VBA macro layout.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    public function buildEmailBody(array $rows, string $reportDate): string
    {
        $title = '<b>Current Lookback Summary - ' . date('m/d/Y', strtotime($reportDate)) . '</b><br><br>';

        $table = '<table border="1" cellspacing="0" cellpadding="4" style="border-collapse: collapse; border: 1px solid #000;">';
        $table .= '<tr style="background-color: #D3D3D3;">';
        $table .= '<th style="border: 1px solid #000; padding: 4px; text-align: center;">Tranche</th>';
        $table .= '<th style="border: 1px solid #000; padding: 4px; text-align: center;">Tranche Sold Date</th>';
        $table .= '<th style="border: 1px solid #000; padding: 4px; text-align: center;">Total Cancels</th>';
        $table .= '<th style="border: 1px solid #000; padding: 4px; text-align: center;">Total Lookback</th>';
        $table .= '<th style="border: 1px solid #000; padding: 4px; text-align: center;">Pending Lookback</th>';
        $table .= '<th style="border: 1px solid #000; padding: 4px; text-align: center;">Completed Lookback</th>';
        $table .= '</tr>';

        foreach ($rows as $row) {
            $table .= '<tr>';
            $table .= '<td style="border: 1px solid #000; padding: 4px; text-align: center;">' . htmlspecialchars((string) ($row['Tranche'] ?? '')) . '</td>';

            $paymentDate = $row['Payment_Date'] ?? null;
            $formattedDate = $paymentDate
                ? date('m/d/Y', strtotime((string) $paymentDate))
                : '';
            $table .= '<td style="border: 1px solid #000; padding: 4px; text-align: center;">' . $formattedDate . '</td>';

            $table .= '<td style="border: 1px solid #000; padding: 4px; text-align: center;">' . number_format((float) ($row['Total_Cancels'] ?? 0), 0) . '</td>';
            $table .= '<td style="border: 1px solid #000; padding: 4px; text-align: right;">' . $this->formatCurrency($row['Total_Lookback'] ?? 0) . '</td>';
            $table .= '<td style="border: 1px solid #000; padding: 4px; text-align: right;">' . $this->formatCurrency($row['Pending_Lookback'] ?? 0) . '</td>';
            $table .= '<td style="border: 1px solid #000; padding: 4px; text-align: right;">' . $this->formatCurrency($row['Completed_Lookback'] ?? 0) . '</td>';
            $table .= '</tr>';
        }

        $table .= '</table>';

        return $title . $table;
    }

    /**
     * Send the email using TblReports with LDR company.
     */
    public function sendEmail(DBConnector $connector, string $subject, string $body, ?Command $console = null): bool
    {
        $email = new EmailSenderService();

        $sent = $email->sendMailUsingTblReportsHtml(
            $connector,
            ['Lookback Summary'],
            ['LDR'],
            $subject,
            $body
        );
        
        if ($sent) {
            if ($console) {
                $console->info('[INFO] Lookback Summary email sent.');
            }
            return true;
        }

        Log::warning('GenerateLookbackSummaryReport: failed to send email.');
        return false;
    }

    private function formatCurrency(float $value): string
    {
        return '$' . number_format($value, 2);
    }
}
