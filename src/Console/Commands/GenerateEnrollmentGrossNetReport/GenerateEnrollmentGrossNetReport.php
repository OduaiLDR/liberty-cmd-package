<?php

namespace Cmd\Reports\Console\Commands\GenerateEnrollmentGrossNetReport;

use Cmd\Reports\Services\DBConnector;
use Cmd\Reports\Services\EmailSenderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Enrollment Gross/Net Summary (Oduai's daily digest, extended per Jacob):
 * Excel workbook with 13 months (current + prior 12), column A frozen,
 * Projection Total (enrolled + pending), and Sell Rate
 * ((sold + eligible to sell) / total enrolled).
 *
 * Bucket definitions from Oduai's original command (2026-08-04):
 *   Gross     = every row by Submitted_Date
 *   Cancelled = Enrollment_Status LIKE '%Dropped%Cancelled%'
 *   Sellable  = LDR Enrolled / ProLaw Enrolled
 *   At-Risk   = Gross - Cancelled - Sellable
 *   Sold      = Tranche IS NOT NULL (any status)
 *   Cleared   = Sellable AND Tranche IS NULL AND Payments > 0
 *   Pending   = Sellable AND Tranche IS NULL AND (Payments IS NULL OR 0)
 */
class GenerateEnrollmentGrossNetReport extends Command
{
    protected $signature = 'Generate:enrollment-gross-net-report
                            {--test : Prefix subject with [TEST]; still uses TblReports recipients}
                            {--no-email : Build workbook only, skip email}
                            {--output= : Save workbook to this path}';

    protected $description = 'Generate Enrollment Gross/Net Summary Excel (13 months) and email for review.';

    /** TblReports.Report_Name — recipients live in dbo.TblReports, not in code. */
    private const REPORT_NAME = 'EnrollmentGrossNet';

    private const MONTH_COUNT = 13;

    private const NET_STATUSES = ['LDR Enrolled', 'ProLaw Enrolled'];

    private const PENDING_STATUSES = ['Submitted', 'Approved', 'Attorney Approved CFLN'];

    private const CANCELLED_PATTERN = '%Dropped%Cancelled%';

    public function handle(): int
    {
        $this->info('[INFO] Enrollment Gross/Net report: starting.');

        try {
            $connector = $this->initializeSqlServerConnector();
        } catch (\Throwable $e) {
            $this->error('Failed to initialize SQL Server connector: ' . $e->getMessage());
            Log::error('GenerateEnrollmentGrossNetReport: SQL init failed.', ['exception' => $e]);
            return Command::FAILURE;
        }

        try {
            $months = $this->monthWindows();
            $data = [];
            foreach ($months as $key => $window) {
                $buckets = $this->fetchBuckets($connector, $window['start'], $window['end']);
                $grossDebt = (float) $buckets['gross']['debt'];
                $soldOrEligible = (float) $buckets['sold']['debt']
                    + (float) $buckets['cleared']['debt']
                    + (float) $buckets['pending']['debt'];
                $buckets['projection_total'] = [
                    'count' => $buckets['sellable']['count'] + $buckets['projection_pending']['count'],
                    'debt' => $buckets['sellable']['debt'] + $buckets['projection_pending']['debt'],
                ];
                $buckets['sell_rate'] = $grossDebt > 0 ? ($soldOrEligible / $grossDebt) : null;

                $data[$key] = [
                    'label' => $window['label'],
                    'buckets' => $buckets,
                ];
            }
        } catch (\Throwable $e) {
            $this->error('Failed to build Gross/Net dataset: ' . $e->getMessage());
            Log::error('GenerateEnrollmentGrossNetReport: query failed.', ['exception' => $e]);
            return Command::FAILURE;
        }

        $reportDate = (new \DateTimeImmutable('now', new \DateTimeZone('America/Los_Angeles')))->format('Y-m-d');
        $formatter = new Formatter();
        $workbook = $formatter->buildWorkbook($data, $reportDate);
        $emailHtml = $formatter->buildEmailBody($data, $reportDate);

        $outputPath = $this->option('output');
        if ($outputPath !== null && $workbook !== null) {
            copy($workbook['path'], $outputPath);
            $this->info("[INFO] Workbook saved to {$outputPath}");

            $htmlPath = preg_replace('/\.xlsx$/i', '.html', $outputPath) ?: ($outputPath . '.html');
            file_put_contents($htmlPath, $emailHtml);
            $this->info("[INFO] Email HTML body saved to {$htmlPath}");
        }

        $skipEmail = $this->option('no-email') || $outputPath !== null;
        $sent = true;
        if (! $skipEmail) {
            $sent = $this->sendReport($connector, $workbook, $emailHtml, $reportDate, (bool) $this->option('test'));
        } else {
            $this->info('[INFO] Skipping email send (--no-email or --output was used).');
        }

        if ($workbook !== null && is_file($workbook['path']) && $outputPath !== $workbook['path']) {
            @unlink($workbook['path']);
        }

        if (! $sent) {
            $this->warn('[WARN] Enrollment Gross/Net report email failed to send.');
            return Command::FAILURE;
        }

        $this->info('[SUCCESS] Enrollment Gross/Net report completed.');
        return Command::SUCCESS;
    }

    /**
     * Current month (MTD through yesterday) + prior 12 full months.
     * Order: current month first, then prior months to the right.
     *
     * @return array<string, array{label:string,start:string,end:string}>
     */
    private function monthWindows(): array
    {
        $windows = [];
        $today = now(new \DateTimeZone('America/Los_Angeles'));
        $mtdCutoff = $today->copy()->subDay();

        for ($i = 0; $i < self::MONTH_COUNT; $i++) {
            $monthStart = $today->copy()->subMonthsNoOverflow($i)->startOfMonth();
            $end = $i === 0 ? $mtdCutoff->copy()->endOfDay() : $monthStart->copy()->endOfMonth();

            $windows[$monthStart->format('Y-m')] = [
                'label' => $monthStart->format('M Y'),
                'start' => $monthStart->format('Y-m-d'),
                'end' => $end->format('Y-m-d'),
            ];
        }

        return $windows;
    }

    /**
     * @return array{
     *   gross: array{count:int,debt:float},
     *   cancelled: array{count:int,debt:float},
     *   at_risk: array{count:int,debt:float},
     *   sellable: array{count:int,debt:float},
     *   sold: array{count:int,debt:float},
     *   cleared: array{count:int,debt:float},
     *   pending: array{count:int,debt:float},
     *   projection_pending: array{count:int,debt:float}
     * }
     */
    private function fetchBuckets(DBConnector $connector, string $start, string $end): array
    {
        $netList = implode(', ', array_map(fn (string $s): string => "'" . $this->esc($s) . "'", self::NET_STATUSES));
        $pendingList = implode(', ', array_map(fn (string $s): string => "'" . $this->esc($s) . "'", self::PENDING_STATUSES));
        $cancelledPattern = $this->esc(self::CANCELLED_PATTERN);
        $startEsc = $this->esc($start);
        $endEsc = $this->esc($end);
        $sellableCond = "Enrollment_Status IN ({$netList})";

        $sql = "
            SELECT
                COUNT(*) AS GrossCnt,
                SUM(Debt_Amount) AS GrossDebt,
                SUM(CASE WHEN Enrollment_Status LIKE '{$cancelledPattern}' THEN 1 ELSE 0 END) AS CancelledCnt,
                SUM(CASE WHEN Enrollment_Status LIKE '{$cancelledPattern}' THEN Debt_Amount ELSE 0 END) AS CancelledDebt,
                SUM(CASE WHEN {$sellableCond} THEN 1 ELSE 0 END) AS SellableCnt,
                SUM(CASE WHEN {$sellableCond} THEN Debt_Amount ELSE 0 END) AS SellableDebt,
                SUM(CASE WHEN Tranche IS NOT NULL THEN 1 ELSE 0 END) AS SoldCnt,
                SUM(CASE WHEN Tranche IS NOT NULL THEN Debt_Amount ELSE 0 END) AS SoldDebt,
                SUM(CASE WHEN {$sellableCond} AND Tranche IS NULL AND Payments > 0 THEN 1 ELSE 0 END) AS ClearedCnt,
                SUM(CASE WHEN {$sellableCond} AND Tranche IS NULL AND Payments > 0 THEN Debt_Amount ELSE 0 END) AS ClearedDebt,
                SUM(CASE WHEN {$sellableCond} AND Tranche IS NULL AND (Payments IS NULL OR Payments = 0) THEN 1 ELSE 0 END) AS PendingCnt,
                SUM(CASE WHEN {$sellableCond} AND Tranche IS NULL AND (Payments IS NULL OR Payments = 0) THEN Debt_Amount ELSE 0 END) AS PendingDebt,
                SUM(CASE WHEN Enrollment_Status IN ({$pendingList}) THEN 1 ELSE 0 END) AS ProjectionPendingCnt,
                SUM(CASE WHEN Enrollment_Status IN ({$pendingList}) THEN Debt_Amount ELSE 0 END) AS ProjectionPendingDebt
            FROM dbo.TblEnrollment
            WHERE Submitted_Date >= '{$startEsc}'
              AND Submitted_Date <= '{$endEsc}'
        ";

        $result = $connector->querySqlServer($sql);
        $row = $result['data'][0] ?? [];

        $grossCnt = (int) ($row['GrossCnt'] ?? 0);
        $grossDebt = (float) ($row['GrossDebt'] ?? 0);
        $cancelledCnt = (int) ($row['CancelledCnt'] ?? 0);
        $cancelledDebt = (float) ($row['CancelledDebt'] ?? 0);
        $sellableCnt = (int) ($row['SellableCnt'] ?? 0);
        $sellableDebt = (float) ($row['SellableDebt'] ?? 0);

        return [
            'gross' => ['count' => $grossCnt, 'debt' => $grossDebt],
            'cancelled' => ['count' => $cancelledCnt, 'debt' => $cancelledDebt],
            'at_risk' => [
                'count' => $grossCnt - $cancelledCnt - $sellableCnt,
                'debt' => $grossDebt - $cancelledDebt - $sellableDebt,
            ],
            'sellable' => ['count' => $sellableCnt, 'debt' => $sellableDebt],
            'sold' => [
                'count' => (int) ($row['SoldCnt'] ?? 0),
                'debt' => (float) ($row['SoldDebt'] ?? 0),
            ],
            'cleared' => [
                'count' => (int) ($row['ClearedCnt'] ?? 0),
                'debt' => (float) ($row['ClearedDebt'] ?? 0),
            ],
            'pending' => [
                'count' => (int) ($row['PendingCnt'] ?? 0),
                'debt' => (float) ($row['PendingDebt'] ?? 0),
            ],
            'projection_pending' => [
                'count' => (int) ($row['ProjectionPendingCnt'] ?? 0),
                'debt' => (float) ($row['ProjectionPendingDebt'] ?? 0),
            ],
        ];
    }

    /**
     * @param array{filename:string,path:string}|null $workbook
     */
    private function sendReport(
        DBConnector $connector,
        ?array $workbook,
        string $emailHtml,
        string $reportDate,
        bool $isTest
    ): bool {
        $subject = ($isTest ? '[TEST] ' : '') . 'Enrollment Gross/Net Summary - ' . date('m/d/Y', strtotime($reportDate));

        $attachments = [];
        if ($workbook !== null) {
            $attachments[] = [
                'name' => $workbook['filename'],
                'contentType' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'contentBytes' => base64_encode((string) file_get_contents($workbook['path'])),
            ];
        }

        $email = new EmailSenderService();
        $sent = $email->sendMailUsingTblReportsHtml(
            $connector,
            [self::REPORT_NAME],
            ['LDR'],
            $subject,
            $emailHtml,
            $attachments,
            true
        );

        if ($sent) {
            $this->info('[INFO] Enrollment Gross/Net report emailed (TblReports recipients for ' . self::REPORT_NAME . ').');
        } else {
            $this->warn('[WARN] Enrollment Gross/Net report not sent (no TblReports recipients found or send failed).');
            Log::warning('GenerateEnrollmentGrossNetReport: email send failed.');
        }

        return $sent;
    }

    private function initializeSqlServerConnector(): DBConnector
    {
        $candidates = ['ldr', 'plaw', 'production', 'sandbox'];
        $errors = [];

        foreach ($candidates as $env) {
            try {
                $connector = DBConnector::fromEnvironment($env);
                $connector->initializeSqlServer();
                return $connector;
            } catch (\Throwable $e) {
                $errors[] = "{$env}: {$e->getMessage()}";
            }
        }

        throw new \RuntimeException('Unable to initialize SQL Server connector. Tried: ' . implode('; ', $errors));
    }

    private function esc(string $value): string
    {
        return str_replace("'", "''", $value);
    }
}
