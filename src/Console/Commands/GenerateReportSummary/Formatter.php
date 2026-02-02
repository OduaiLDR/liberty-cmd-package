<?php

namespace Cmd\Reports\Console\Commands\GenerateReportSummary;

use Cmd\Reports\Services\EmailSenderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PDO;

class Formatter
{
    public function buildHtmlBody(array $rows): string
    {
        // Separate reports and automations
        $reports = [];
        $automations = [];
        
        foreach ($rows as $row) {
            $type = $row['Type'] ?? $row['TYPE'] ?? '';
            if ($type === 'Automation') {
                $automations[] = $row;
            } else {
                $reports[] = $row;
            }
        }

        $html = '
        <html>
        <head>
            <style>
                body { font-family: Calibri, Arial, sans-serif; font-size: 11pt; }
                table { border-collapse: collapse; width: 100%; margin-bottom: 30px; }
                th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                th { background-color: #4472C4; color: white; }
                tr:nth-child(even) { background-color: #f2f2f2; }
                tr:hover { background-color: #ddd; }
                h2 { color: #4472C4; }
                h3 { color: #4472C4; margin-top: 20px; margin-bottom: 10px; }
            </style>
        </head>
        <body>
            <h2>Daily Report Summary</h2>
            <p>The following reports and automations are configured in the system:</p>';

        // Reports Table
        if (!empty($reports)) {
            $html .= '
            <h3>Reports</h3>
            <table>
                <thead>
                    <tr>
                        <th>Type</th>
                        <th>Report/Automation Name</th>
                        <th>Company</th>
                        <th>Schedule</th>
                        <th>Last Run Date</th>
                        <th>Last Run Weekday</th>
                    </tr>
                </thead>
                <tbody>';

            foreach ($reports as $row) {
                $reportName = $row['Report_Name'] ?? $row['REPORT_NAME'] ?? '';
                $company = $row['Company'] ?? $row['COMPANY'] ?? '';
                $schedule = $row['Schedule'] ?? $row['SCHEDULE'] ?? '';
                $lastRunDate = $row['Last_Run_Date'] ?? $row['LAST_RUN_DATE'] ?? '';
                $lastRunWeekday = $row['Last_Run_Weekday'] ?? $row['LAST_RUN_WEEKDAY'] ?? '';

                // Format the date if it's a valid date
                if ($lastRunDate && $lastRunDate !== '' && $lastRunDate !== null) {
                    $timestamp = strtotime($lastRunDate);
                    if ($timestamp !== false) {
                        $lastRunDate = date('m/d/Y g:i A', $timestamp);
                    }
                } else {
                    $lastRunDate = '<span style="color: #999;">Never run</span>';
                }

                // If no weekday, show empty
                if (!$lastRunWeekday || $lastRunWeekday === '') {
                    $lastRunWeekday = '<span style="color: #999;">-</span>';
                }

                $html .= "
                    <tr>
                        <td>{$reportName}</td>
                        <td>{$company}</td>
                        <td>{$schedule}</td>
                        <td>{$lastRunDate}</td>
                        <td>{$lastRunWeekday}</td>
                    </tr>";
            }

            $html .= '
                </tbody>
            </table>';
        }

        // Automations Table
        if (!empty($automations)) {
            $html .= '
            <h3>Automations</h3>
            <table>
                <thead>
                    <tr>
                        <th>Automation Name</th>
                        <th>Company</th>
                        <th>Schedule</th>
                        <th>Last Run Date</th>
                        <th>Last Run Weekday</th>
                    </tr>
                </thead>
                <tbody>';

            foreach ($automations as $row) {
                $reportName = $row['Report_Name'] ?? $row['REPORT_NAME'] ?? '';
                $company = $row['Company'] ?? $row['COMPANY'] ?? '';
                $schedule = $row['Schedule'] ?? $row['SCHEDULE'] ?? '';
                $lastRunDate = $row['Last_Run_Date'] ?? $row['LAST_RUN_DATE'] ?? '';
                $lastRunWeekday = $row['Last_Run_Weekday'] ?? $row['LAST_RUN_WEEKDAY'] ?? '';

                // Format the date if it's a valid date
                if ($lastRunDate && $lastRunDate !== '' && $lastRunDate !== null) {
                    $timestamp = strtotime($lastRunDate);
                    if ($timestamp !== false) {
                        $lastRunDate = date('m/d/Y g:i A', $timestamp);
                    }
                } else {
                    $lastRunDate = '<span style="color: #999;">Never run</span>';
                }

                // If no weekday, show empty
                if (!$lastRunWeekday || $lastRunWeekday === '') {
                    $lastRunWeekday = '<span style="color: #999;">-</span>';
                }

                $html .= "
                    <tr>
                        <td>{$type}</td>
                        <td>{$reportName}</td>
                        <td>{$company}</td>
                        <td>{$schedule}</td>
                        <td>{$lastRunDate}</td>
                        <td>{$lastRunWeekday}</td>
                    </tr>";
            }

            $html .= '
                </tbody>
            </table>';
        }

        $html .= '
            <br>
            <p>Generated on ' . date('m/d/Y g:i A') . '</p>
        </body>
        </html>';

        return $html;
    }

    public function sendReport(string $html, ?Command $console = null): bool
    {
        $email = new EmailSenderService();

        $subject = 'Daily Report Summary - ' . date('m/d/Y');
        $body = $html;

        // Get recipients from TblReports directly via PDO
        $recipients = $this->getRecipientsFromTblReports();

        if (empty($recipients)) {
            if ($console) {
                $console->warn('[WARN] Report Summary not sent (no recipients found in TblReports).');
            }
            Log::warning('GenerateReportSummary: no recipients found in TblReports.');
            return false;
        }

        // Send email
        $sent = $email->sendMailHtml($subject, $body, $recipients);

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

    private function getRecipientsFromTblReports(): array
    {
        try {
            // Use dbConfig from package which reads from env variables
            $config = config('dbConfig.sql_server.sql_server_connection');
            
            if (!$config) {
                return [];
            }

            $dsn = $config['dsn'];
            $username = $config['username'];
            $password = $config['password'];

            $pdo = new PDO(
                $dsn,
                $username,
                $password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );

            // Query TblReports for ReportSummary recipients
            $sql = "
                SELECT Send_To, Send_CC, Send_BCC
                FROM dbo.TblReports
                WHERE Report_Name IN ('ReportSummary', 'Report Summary')
            ";

            $stmt = $pdo->query($sql);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $emails = [];
            foreach ($rows as $row) {
                foreach (['Send_To', 'Send_CC', 'Send_BCC'] as $col) {
                    $value = $row[$col] ?? '';
                    if ($value) {
                        $parts = array_map('trim', explode(';', $value));
                        foreach ($parts as $email) {
                            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                                $emails[] = $email;
                            }
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
}
