<?php

namespace Cmd\Reports\Console\Commands\GenerateEnrollmentSummaryReport;

use Cmd\Reports\Services\DBConnector;
use Cmd\Reports\Services\EmailSenderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class GenerateEnrollmentSummaryReport extends Command
{
    protected $signature = 'Generate:enrollment-summary-report
        {--date= : Window date (Y-m-d). If omitted, resolved automatically via SoldTranche() (see resolveDefaultWindowDate).}
        {--snapshot-date= : TESTING ONLY. Overrides today\'s date for snapshot metrics (Gross Enrollments, Cancels, NSFs, etc). The VBA always uses today for these regardless of --date.}
        {--no-email : Skip sending the email, just build the file}
        {--output= : Save the workbook to this path instead of the temp storage path (implies --no-email unless combined with normal flow)}';

    protected $description = 'Generate the Enrollment Summary Report (Total/LDR/Legal breakdown from TblEnrollment) and email it.';

    /** Column key => Enrollment_Plan SQL criteria, mirrors the VBA i-loop (0=Total, 1=LDR, 2=Legal). */
    private const COLUMNS = [
        'Total' => "AND State NOT IN ('WI') ",
        'LDR' => "AND Enrollment_Plan NOT LIKE '%Progress%' AND State NOT IN ('WI') ",
        'Legal' => "AND Enrollment_Plan LIKE '%Progress%' AND State NOT IN ('WI') ",
    ];

    /** @var array<int, array<string, mixed>> Ordered row definitions: label, values per column, format, bold, blank */
    private array $rows = [];

    /** Reset per buildColumn() pass so blank-row synthetic labels line up identically across all 3 passes. */
    private int $blankCounter = 0;

    public function handle(): int
    {
        // VBA: ReportDate only drives the projection window's start month. Every snapshot metric
        // (Gross Enrollments, Cancels, NSFs, title, etc.) literally uses `Date` (today), regardless
        // of ReportDate. --snapshot-date exists only to reproduce a specific day for testing.
        //
        // "Today" must be evaluated in the business's own timezone (America/Los_Angeles — this
        // report's automation entry is configured for that zone, running at 20:00 PT Mon-Fri), NOT
        // the app's default UTC. At 8pm PT the UTC clock has already rolled to the next calendar
        // day, so a naive date('Y-m-d') (UTC) reports one day ahead of the intended business day —
        // confirmed against a real run: the report fired at 20:00 PT on 7/22 but titled itself
        // "For 7/23" because date('Y-m-d') read 03:xx UTC on the 23rd.
        $snapshotDate = $this->option('snapshot-date')
            ?: (new \DateTimeImmutable('now', new \DateTimeZone('America/Los_Angeles')))->format('Y-m-d');

        try {
            $connector = $this->initializeSqlServerConnector();
        } catch (\Throwable $e) {
            $this->error('Failed to initialize SQL Server connector: ' . $e->getMessage());
            Log::error('GenerateEnrollmentSummaryReport: connector init failed', ['exception' => $e]);
            return Command::FAILURE;
        }

        $windowDate = $this->option('date') ?: $this->resolveDefaultWindowDate($connector, $snapshotDate);

        $this->info("[INFO] Enrollment Summary Report: starting (window={$windowDate}, snapshot={$snapshotDate}).");

        try {
            foreach (self::COLUMNS as $columnKey => $criteria) {
                $this->info("[INFO] Building column: {$columnKey}");
                $this->buildColumn($connector, $columnKey, $criteria, $windowDate, $snapshotDate);
            }
        } catch (\Throwable $e) {
            $this->error('Failed to build Enrollment Summary Report: ' . $e->getMessage());
            Log::error('GenerateEnrollmentSummaryReport: build failed', ['exception' => $e]);
            return Command::FAILURE;
        }

        $trancheRows = null;
        try {
            $this->info('[INFO] Building Tranche Summary...');
            $trancheRows = (new TrancheSummaryBuilder())->build($connector);
            $this->info('[INFO] Tranche Summary rows: ' . count($trancheRows));
        } catch (\Throwable $e) {
            $this->warn('[WARN] Tranche Summary failed: ' . $e->getMessage());
            Log::error('GenerateEnrollmentSummaryReport: tranche summary failed', ['exception' => $e]);
        }

        $capitalReport = null;
        try {
            $this->info('[INFO] Building Capital Report...');
            $capitalReport = (new CapitalReportBuilder())->build($connector);
            $this->info('[INFO] Capital Report rows: ' . count($capitalReport['rows']));
        } catch (\Throwable $e) {
            $this->warn('[WARN] Capital Report failed: ' . $e->getMessage());
            Log::error('GenerateEnrollmentSummaryReport: capital report failed', ['exception' => $e]);
        }

        $monthlyResiduals = null;
        try {
            $this->info('[INFO] Building Monthly Residuals...');
            $monthlyResiduals = (new MonthlyResidualsBuilder())->build($connector, $snapshotDate);
        } catch (\Throwable $e) {
            $this->warn('[WARN] Monthly Residuals failed: ' . $e->getMessage());
            Log::error('GenerateEnrollmentSummaryReport: monthly residuals failed', ['exception' => $e]);
        }

        $monthlyEnrollmentSummary = null;
        try {
            $this->info('[INFO] Building Monthly Enrollment Summary...');
            $monthlyEnrollmentSummary = (new MonthlyEnrollmentSummaryBuilder())->build(
                $connector,
                $snapshotDate,
                self::COLUMNS['Total']
            );
        } catch (\Throwable $e) {
            $this->warn('[WARN] Monthly Enrollment Summary failed: ' . $e->getMessage());
            Log::error('GenerateEnrollmentSummaryReport: monthly enrollment summary failed', ['exception' => $e]);
        }

        $formatter = new Formatter();
        $workbook = $formatter->buildWorkbook(
            $this->rows,
            array_keys(self::COLUMNS),
            $snapshotDate,
            $trancheRows,
            $capitalReport,
            $monthlyResiduals,
            $monthlyEnrollmentSummary
        );

        $outputPath = $this->option('output');
        if ($outputPath !== null && $workbook !== null) {
            copy($workbook['path'], $outputPath);
            $this->info("[INFO] Workbook saved to {$outputPath}");
        }

        $skipEmail = $this->option('no-email') || $outputPath !== null;

        $sent = true;
        if (!$skipEmail) {
            $sent = $this->sendReport($connector, $workbook, $snapshotDate);
        } else {
            $this->info('[INFO] Skipping email send (--no-email or --output was used).');
        }

        if ($workbook !== null && is_file($workbook['path']) && $outputPath !== $workbook['path']) {
            @unlink($workbook['path']);
        }

        if (!$sent) {
            $this->warn('[WARN] Enrollment Summary Report email failed to send.');
            return Command::FAILURE;
        }

        $this->info('[SUCCESS] Enrollment Summary Report completed.');
        return Command::SUCCESS;
    }

    /**
     * Runs every metric for one output column (Total/LDR/Legal), mirroring one pass of the VBA's `For i = 0 To 2` loop.
     */
    private function buildColumn(DBConnector $connector, string $columnKey, string $criteria, string $windowDate, string $snapshotDate): void
    {
        $this->blankCounter = 0;

        $enrollments = (int) $this->scalar($connector, "
            SELECT COUNT(*) FROM TblEnrollment
            WHERE Welcome_Call_Date = ? {$criteria}
        ", [$snapshotDate]);
        $this->setRow('Gross Enrollments', $columnKey, $enrollments, 'count');

        $debt = (float) $this->scalar($connector, "
            SELECT SUM(Debt_Amount) FROM TblEnrollment
            WHERE Welcome_Call_Date = ? {$criteria}
        ", [$snapshotDate]);
        $avgDebt = $enrollments > 0 ? round($debt / $enrollments) : 0;
        $this->setRow("Today's Average Debt", $columnKey, $avgDebt, 'currency');

        $minDebt = (float) $this->scalar($connector, "
            SELECT MIN(Debt_Amount) FROM TblEnrollment
            WHERE Welcome_Call_Date = ? {$criteria}
        ", [$snapshotDate]);
        $this->setRow("Today's Min Debt", $columnKey, $minDebt, 'currency');

        $maxDebt = (float) $this->scalar($connector, "
            SELECT MAX(Debt_Amount) FROM TblEnrollment
            WHERE Welcome_Call_Date = ? {$criteria}
        ", [$snapshotDate]);
        $this->setRow("Today's Max Debt", $columnKey, $maxDebt, 'currency');

        $cancels = (int) $this->scalar($connector, "
            SELECT COUNT(*) FROM TblEnrollment
            WHERE Cancel_Date = ? {$criteria}
        ", [$snapshotDate]);
        $this->setRow('Cancels', $columnKey, $cancels, 'count');

        $nsfs = (int) $this->scalar($connector, "
            SELECT COUNT(*) FROM TblEnrollment
            WHERE NSF_Date = ? {$criteria}
        ", [$snapshotDate]);
        $this->setRow('NSFs', $columnKey, $nsfs, 'count');

        $this->setRow('Net New Enrollments', $columnKey, $enrollments - $cancels - $nsfs, 'count');

        // Post-loop totals (Cancel/NSF Peel Offs are hard-coded to Category = 'LDR' in the VBA, kept as-is).
        $this->setRow('', $columnKey, null, 'blank', blank: true);

        $grossDebt = (float) $this->scalar($connector, "
            SELECT SUM(Debt_Amount) FROM TblEnrollment
            WHERE Welcome_Call_Date = ? {$criteria}
        ", [$snapshotDate]);
        $this->setRow('Gross Debt Enrolled', $columnKey, $grossDebt, 'currency');

        $cancelPeelOffs = (float) $this->scalar($connector, "
            SELECT SUM(Debt_Amount) FROM TblEnrollment
            WHERE Cancel_Date = ? AND Category = 'LDR' {$criteria}
        ", [$snapshotDate]);
        $this->setRow('Cancel Peel Offs', $columnKey, $cancelPeelOffs, 'currency');

        $nsfPeelOffs = (float) $this->scalar($connector, "
            SELECT SUM(Debt_Amount) FROM TblEnrollment
            WHERE NSF_Date = ? AND Category = 'LDR' {$criteria}
        ", [$snapshotDate]);
        $this->setRow('NSF Peel Offs', $columnKey, $nsfPeelOffs, 'currency');

        $this->setRow('Total Net Debt Enrolled', $columnKey, $grossDebt - $cancelPeelOffs - $nsfPeelOffs, 'currency');

        $this->setRow('', $columnKey, null, 'blank', blank: true);

        $totalEnrollments = (int) $this->scalar($connector, "
            SELECT COUNT(Debt_Amount) FROM TblEnrollment
            WHERE Category = 'LDR' {$criteria}
        ");
        $this->setRow('Total Enrollments', $columnKey, $totalEnrollments, 'count');

        $totalEnrolledDebt = (float) $this->scalar($connector, "
            SELECT SUM(Debt_Amount) FROM TblEnrollment
            WHERE Category = 'LDR' {$criteria}
        ");
        $this->setRow('Total Enrolled Debt', $columnKey, $totalEnrolledDebt, 'currency');

        $totalCancels = (int) $this->scalar($connector, "
            SELECT COUNT(Debt_Amount) FROM TblEnrollment
            WHERE Category = 'LDR' AND Cancel_Date IS NOT NULL {$criteria}
        ");
        $this->setRow('Total Cancels', $columnKey, $totalCancels, 'count');

        $totalCancelledDebt = (float) $this->scalar($connector, "
            SELECT SUM(Debt_Amount) FROM TblEnrollment
            WHERE Category = 'LDR' AND Cancel_Date IS NOT NULL {$criteria}
        ");
        $this->setRow('Total Cancelled Debt', $columnKey, $totalCancelledDebt, 'currency');

        $totalNsfs = (int) $this->scalar($connector, "
            SELECT COUNT(Debt_Amount) FROM TblEnrollment
            WHERE Category = 'LDR' AND NSF_Date IS NOT NULL {$criteria}
        ");
        $this->setRow('Total NSFs', $columnKey, $totalNsfs, 'count');

        $totalNsfedDebt = (float) $this->scalar($connector, "
            SELECT SUM(Debt_Amount) FROM TblEnrollment
            WHERE Category = 'LDR' AND NSF_Date IS NOT NULL {$criteria}
        ");
        $this->setRow('Total NSFed Debt', $columnKey, $totalNsfedDebt, 'currency');

        $totalNetEnrollments = (int) $this->scalar($connector, "
            SELECT COUNT(Debt_Amount) FROM TblEnrollment
            WHERE Category = 'LDR' AND Cancel_Date IS NULL AND NSF_Date IS NULL {$criteria}
        ");
        $this->setRow('Total Net Enrollments', $columnKey, $totalNetEnrollments, 'count');

        $totalNetEnrolledDebt = (float) $this->scalar($connector, "
            SELECT SUM(Debt_Amount) FROM TblEnrollment
            WHERE Category = 'LDR' AND Cancel_Date IS NULL AND NSF_Date IS NULL {$criteria}
        ");
        $this->setRow('Total Net Enrolled Debt', $columnKey, $totalNetEnrolledDebt, 'currency');
    }

    /**
     * Sets (or updates, on later column passes) a row value by label. Row order is fixed by the first pass (Total).
     *
     * Blank spacer rows have no label to dedupe on, so they're given a synthetic one ("__blank_N__")
     * that increments per call and resets every buildColumn() pass — since every pass makes the same
     * sequence of setRow() calls in the same order, the same synthetic label recurs across all 3 passes
     * and collapses into a single row instead of one blank row per column.
     */
    private function setRow(string $label, string $columnKey, mixed $value, string $format, bool $bold = false, bool $blank = false): void
    {
        if ($blank) {
            $label = '__blank_' . ($this->blankCounter++) . '__';
        }

        foreach ($this->rows as &$row) {
            if ($row['blank'] === $blank && $row['label'] === $label && !array_key_exists($columnKey, $row['values'])) {
                $row['values'][$columnKey] = $value;
                return;
            }
        }
        unset($row);

        $this->rows[] = [
            'label' => $label,
            'values' => [$columnKey => $value],
            'format' => $format,
            'bold' => $bold,
            'blank' => $blank,
        ];
    }

    private function sendReport(DBConnector $connector, ?array $workbook, string $reportDate): bool
    {
        $subject = 'Enrollment Summary Report - ' . date('m/d/Y', strtotime($reportDate));
        $body = 'Attached is the Enrollment Summary Report for ' . date('m/d/Y', strtotime($reportDate)) . '.';

        $attachments = [];
        if ($workbook !== null) {
            $attachments[] = [
                'name' => $workbook['filename'],
                'contentType' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'contentBytes' => base64_encode(file_get_contents($workbook['path'])),
            ];
        }

        $email = new EmailSenderService();
        $sent = $email->sendMailUsingTblReports(
            $connector,
            ['EnrollmentSummary'],
            ['LDR', 'PLAW'],
            $subject,
            $body,
            $attachments,
            true
        );

        if ($sent) {
            $this->info('[INFO] Enrollment Summary Report emailed (TblReports recipients).');
        } else {
            $this->warn('[WARN] Enrollment Summary Report not sent (no TblReports recipients found or send failed).');
            Log::warning('GenerateEnrollmentSummaryReport: notification email failed.');
        }

        return $sent;
    }

    private function scalar(DBConnector $connector, string $sql, array $params = []): mixed
    {
        $result = $connector->querySqlServer($sql, $params);
        $row = $result['data'][0] ?? null;
        if ($row === null) {
            return null;
        }

        return array_values($row)[0] ?? null;
    }

    /**
     * Mirrors the scheduled-task caller's date selection:
     *   If SoldTranche(DateSerial(Year(Date), Month(Date) - 1, 1)) = True Then
     *       GenerateEnrollmentSummaryReport(Date)
     *   Else
     *       GenerateEnrollmentSummaryReport(DateSerial(Year(Date), Month(Date), 0))
     *   End If
     * i.e. if a tranche has already been sold with Report_Date = the 1st of last month, use today as
     * the window date; otherwise a tranche for last month hasn't been sold yet, so fall back to the
     * last day of the month before that (pushing the projection window back a month).
     */
    private function resolveDefaultWindowDate(DBConnector $connector, string $today): string
    {
        $lastMonthFirstDay = (new \DateTimeImmutable($today))->modify('first day of last month')->format('Y-m-d');

        if ($this->soldTranche($connector, $lastMonthFirstDay)) {
            return $today;
        }

        // VBA: DateSerial(Year(Date), Month(Date), 0) = day 0 of this month = last day of last month.
        return (new \DateTimeImmutable($today))->modify('last day of last month')->format('Y-m-d');
    }

    private function soldTranche(DBConnector $connector, string $reportDate): bool
    {
        $count = (int) $this->scalar($connector, "
            SELECT COUNT(*) FROM TblDebtTrancheSales WHERE Report_Date = ?
        ", [$reportDate]);

        return $count > 0;
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
}
