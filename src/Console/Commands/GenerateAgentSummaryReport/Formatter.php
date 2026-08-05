<?php

namespace Cmd\Reports\Console\Commands\GenerateAgentSummaryReport;

use Cmd\Reports\Services\DBConnector;
use Cmd\Reports\Services\EmailSenderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class Formatter
{
    /**
     * Send a single email with one or more PDF attachments.
     *
     * @param array $files Array of ['name' => string, 'path' => string]
     */
    public function sendCombinedReport(
        DBConnector $connector,
        array $files,
        array $dataSources,
        bool $continuation,
        string $startDate,
        string $endDate,
        ?Command $console = null
    ): bool {
        $attachments = [];
        foreach ($files as $file) {
            $path = $file['path'] ?? '';
            $name = $file['name'] ?? '';
            if ($path === '' || !is_file($path)) {
                Log::warning('GenerateAgentSummaryReport: attachment file missing.', ['path' => $path]);
                continue;
            }
            $attachments[] = [
                'name' => $name,
                'contentType' => 'application/pdf',
                'contentBytes' => base64_encode(file_get_contents($path)),
            ];
        }

        if (empty($attachments)) {
            if ($console) {
                $console->warn('[WARN] Agent Summary report not sent (no attachments).');
            }
            return false;
        }

        $contLabel = $continuation ? 'Continuation ' : '';
        $contSuffix = $continuation ? ' (' . date('F Y', strtotime($startDate)) . ')' : '';
        // Subject/body show the report's MONTH plus TODAY's date (the generation date),
        // not the month-end — so an MTD report is never mistaken for an end-of-month one.
        $reportMonth  = date('F', strtotime($startDate));
        $todayDisplay = date('m-d-Y');

        $subject = "{$reportMonth} Agent Summary {$contLabel}Report as of {$todayDisplay}{$contSuffix}";

        if (count($dataSources) === 1) {
            $body = "Attached is the {$reportMonth} Agent Summary {$contLabel}Report as of {$todayDisplay}{$contSuffix} - {$dataSources[0]}.";
        } else {
            $list = implode(', ', $dataSources);
            $body = "Attached are the {$reportMonth} Agent Summary {$contLabel}Reports as of {$todayDisplay}{$contSuffix} for: {$list}.";
        }

        $email = new EmailSenderService();
        $sent = $email->sendMailUsingTblReports(
            $connector,
            ['AgentSummaryReport', 'Agent Summary Report'],
            ['LDR'],
            $subject,
            $body,
            $attachments,
            true
        );

        if ($console) {
            if ($sent) {
                $console->info(sprintf(
                    '[INFO] Agent Summary report sent (%d attachment%s).',
                    count($attachments),
                    count($attachments) === 1 ? '' : 's'
                ));
            } else {
                $console->warn('[WARN] Agent Summary report not sent (no recipients or send failed).');
            }
        } elseif (!$sent) {
            Log::warning('GenerateAgentSummaryReport: failed to send email.', ['data_sources' => $dataSources]);
        }

        return $sent;
    }
}
