<?php

namespace Cmd\Reports\Console\Commands\GenerateSyncSummary;

use Cmd\Reports\Services\EmailSenderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class Formatter
{
    public function buildHtmlBody(array $rows): string
    {
        $today = date('l, F j, Y');
        
        $html = "
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                body {
                    font-family: Arial, sans-serif;
                    margin: 20px;
                    background-color: #f5f5f5;
                }
                .container {
                    background-color: white;
                    padding: 20px;
                    border-radius: 8px;
                    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
                }
                h1 {
                    color: #2c3e50;
                    border-bottom: 3px solid #3498db;
                    padding-bottom: 10px;
                }
                h2 {
                    color: #34495e;
                    margin-top: 30px;
                }
                table {
                    width: 100%;
                    border-collapse: collapse;
                    margin-top: 20px;
                }
                th {
                    background-color: #3498db;
                    color: white;
                    padding: 12px;
                    text-align: left;
                    font-weight: bold;
                }
                td {
                    padding: 10px;
                    border-bottom: 1px solid #ddd;
                }
                tr:hover {
                    background-color: #f8f9fa;
                }
                .date-header {
                    color: #7f8c8d;
                    font-size: 14px;
                    margin-bottom: 20px;
                }
                .summary-stats {
                    background-color: #ecf0f1;
                    padding: 15px;
                    border-radius: 5px;
                    margin-bottom: 20px;
                }
                .stat-item {
                    display: inline-block;
                    margin-right: 30px;
                }
                .stat-number {
                    font-size: 24px;
                    font-weight: bold;
                    color: #2980b9;
                }
                .stat-label {
                    font-size: 12px;
                    color: #7f8c8d;
                    text-transform: uppercase;
                }
            </style>
        </head>
        <body>
            <div class='container'>
                <h1>Sync & Macro Execution Summary</h1>
                <div class='date-header'>Generated on {$today}</div>
                
                <div class='summary-stats'>
                    <div class='stat-item'>
                        <div class='stat-number'>" . count($rows) . "</div>
                        <div class='stat-label'>Total Executions Today</div>
                    </div>
                </div>

                <h2>Recent Macro Executions (Today)</h2>
                <table>
                    <thead>
                        <tr>
                            <th>Macro Name</th>
                            <th>Description</th>
                            <th>Action</th>
                            <th>Result</th>
                            <th>Last Run Date</th>
                            <th>Weekday</th>
                        </tr>
                    </thead>
                    <tbody>";

        foreach ($rows as $row) {
            $macroName = htmlspecialchars($row['Macro_Name'] ?? 'N/A');
            $description = htmlspecialchars($row['Description'] ?? 'N/A');
            $action = htmlspecialchars($row['Action'] ?? 'N/A');
            $result = htmlspecialchars($row['Result'] ?? 'N/A');
            $lastRunDate = htmlspecialchars($row['Last_Run_Date'] ?? 'N/A');
            $weekday = htmlspecialchars($row['Last_Run_Weekday'] ?? 'N/A');

            $html .= "
                        <tr>
                            <td><strong>{$macroName}</strong></td>
                            <td>{$description}</td>
                            <td>{$action}</td>
                            <td>{$result}</td>
                            <td>{$lastRunDate}</td>
                            <td>{$weekday}</td>
                        </tr>";
        }

        $html .= "
                    </tbody>
                </table>

                <h2>Upcoming Scheduled Syncs</h2>
                <table>
                    <thead>
                        <tr>
                            <th>Macro Name</th>
                            <th>Schedule</th>
                            <th>Next Run</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong>SyncVerifiedDebts</strong></td>
                            <td>Daily at 2:00 AM</td>
                            <td>Tomorrow 2:00 AM</td>
                        </tr>
                        <tr>
                            <td><strong>SyncBalances</strong></td>
                            <td>Daily at 3:00 AM</td>
                            <td>Tomorrow 3:00 AM</td>
                        </tr>
                        <tr>
                            <td><strong>SyncContactsData</strong></td>
                            <td>Daily at 4:00 AM</td>
                            <td>Tomorrow 4:00 AM</td>
                        </tr>
                        <tr>
                            <td><strong>SyncEnrollmentStatus</strong></td>
                            <td>Daily at 5:00 AM</td>
                            <td>Tomorrow 5:00 AM</td>
                        </tr>
                        <tr>
                            <td><strong>Report Generation</strong></td>
                            <td>Daily at 7:00 AM</td>
                            <td>Tomorrow 7:00 AM</td>
                        </tr>
                    </tbody>
                </table>

                <div style='margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; color: #7f8c8d; font-size: 12px;'>
                    <p>This is an automated summary generated by the Liberty Debt Relief reporting system.</p>
                    <p>For questions or issues, please contact the system administrator.</p>
                </div>
            </div>
        </body>
        </html>
        ";

        return $html;
    }

    public function sendReport(string $html, ?Command $console = null): bool
    {
        $email = new EmailSenderService();

        $subject = 'Daily Sync & Macro Summary - ' . date('m/d/Y');
        $body = $html;

        $recipients = ['oduai@libertydebtrelief.com'];

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
}
