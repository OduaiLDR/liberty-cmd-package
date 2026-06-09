<?php

namespace Cmd\Reports\Console\Commands\GenerateAgentSummaryReport;

use Cmd\Reports\Services\DBConnector;
use Cmd\Reports\Services\EmailSenderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class Formatter
{
    public function sendReport(
        DBConnector $connector,
        string $path,
        string $filename,
        string $dataSource,
        bool $continuation,
        string $startDate,
        string $endDate,
        ?Command $console = null
    ): bool {
        if (!is_file($path)) {
            Log::warning('GenerateAgentSummaryReport: report file missing.', ['path' => $path]);
            if ($console) {
                $console->warn('[WARN] Agent Summary report not sent (file missing).');
            }
            return false;
        }

        $attachments = [
            [
                'name' => $filename,
                'contentType' => 'application/pdf',
                'contentBytes' => base64_encode(file_get_contents($path)),
            ],
        ];

        $email = new EmailSenderService();

        $contLabel = $continuation ? 'Continuation ' : '';
        $contSuffix = $continuation ? ' (' . date('F Y', strtotime($startDate)) . ')' : '';
        $endDisplay = date('m-d-Y', strtotime($endDate));
        $endDisplaySlash = date('m/d/Y', strtotime($endDate));

        $subject = "Agent Summary {$contLabel}Report - {$endDisplay}{$contSuffix}";
        $body = "Attached is the Agent Summary {$contLabel}Report - {$endDisplaySlash}{$contSuffix} - {$dataSource}.";

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
                $console->info("[INFO] [{$dataSource}] Agent Summary report sent.");
            } else {
                $console->warn("[WARN] [{$dataSource}] Agent Summary report not sent (no recipients or send failed).");
            }
        } elseif (!$sent) {
            Log::warning('GenerateAgentSummaryReport: failed to send email.', ['data_source' => $dataSource]);
        }

        return $sent;
    }
}
