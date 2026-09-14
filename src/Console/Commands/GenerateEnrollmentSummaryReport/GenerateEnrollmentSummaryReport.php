<?php

namespace Cmd\Reports\Console\Commands\GenerateEnrollmentSummaryReport;

use Cmd\Reports\Services\DBConnector;
use Cmd\Reports\Services\EmailSenderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class GenerateEnrollmentSummaryReport extends Command
{
    protected $signature = 'Generate:enrollment-summary-report
        {--date= : Window date (Y-m-d), drives which month the "Paying in X" projection window starts from. If omitted, resolved automatically via SoldTranche() (see resolveDefaultWindowDate).}
        {--snapshot-date= : TESTING ONLY. Overrides today\'s date for snapshot metrics (Gross Enrollments, Cancels, NSFs, etc). The VBA always uses today for these regardless of --date.}
        {--no-email : Skip sending the email, just build the file}
        {--output= : Save the workbook to this path instead of the temp storage path (implies --no-email unless combined with normal flow)}
        {--ledger-write=auto : Record today\'s Unprocessed Peel Offs in TblEnrollmentPeelOffs so later runs exclude them from NSF/Cancel. auto = only on a real run (no --snapshot-date, no --output); on / off force it.}';

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

    /** Trailing-6 sellable ratio for the report month (Total column only); computed once per run. */
    private ?float $trailing6SellableRatio = null;

    /** Peel-off detail for the window's first month; built once per run, read by every column pass. */
    private ?PeelOffsBuilder $peelOffs = null;

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
            $this->trailing6SellableRatio = $this->computeTrailing6SellableRatio(
                $connector,
                $windowDate,
                self::COLUMNS['Total']
            );
            $this->info('[INFO] Trailing 6 Month Sellable Ratio: ' . (
                $this->trailing6SellableRatio === null
                    ? 'n/a'
                    : round($this->trailing6SellableRatio * 100) . '%'
            ));

            // Peel offs (PRD 2026-09-10; every window month since Jacob's 2026-09-14 12:08 ask).
            // Collected once here on the Total population for the whole window (the column and
            // month splits are applied in PHP) so the summary rows and the Peel Offs sheet come
            // from one query.
            [$windowStart, $windowEnd] = $this->windowBounds($windowDate);
            $this->peelOffs = new PeelOffsBuilder($connector, $snapshotDate, $windowStart, $windowEnd, self::COLUMNS['Total']);
            $peelOffRows = $this->peelOffs->collect();
            foreach ($this->peelOffs->warnings() as $warning) {
                $this->warn('[WARN] ' . $warning);
            }
            $byMonth = [];
            foreach ($peelOffRows['unprocessed'] as $row) {
                $byMonth[$row['Paying_In_Label']] = ($byMonth[$row['Paying_In_Label']] ?? 0) + 1;
            }
            $this->info(sprintf(
                '[INFO] Peel offs for %s (window %s to %s): NSF %d, Cancel %d, Unprocessed %d (newly since %s%s).',
                $snapshotDate,
                $windowStart,
                $windowEnd,
                count($peelOffRows['nsf']),
                count($peelOffRows['cancel']),
                count($peelOffRows['unprocessed']),
                PeelOffsBuilder::previousReportDate($snapshotDate),
                $byMonth === [] ? '' : '; ' . implode(', ', array_map(fn ($m, $n) => "{$m}: {$n}", array_keys($byMonth), $byMonth))
            ));

            foreach (self::COLUMNS as $columnKey => $criteria) {
                $this->info("[INFO] Building column: {$columnKey}");
                $this->buildColumn($connector, $columnKey, $criteria, $windowDate, $snapshotDate);
            }
        } catch (\Throwable $e) {
            $this->error('Failed to build Enrollment Summary Report: ' . $e->getMessage());
            Log::error('GenerateEnrollmentSummaryReport: build failed', ['exception' => $e]);
            return Command::FAILURE;
        }

        $this->writePeelOffLedger($snapshotDate);

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

        $formatter = new Formatter();
        $workbook = $formatter->buildWorkbook($this->rows, array_keys(self::COLUMNS), $snapshotDate, $trancheRows, $capitalReport, $monthlyResiduals, $peelOffRows);
        $emailHtml = $formatter->buildEnrollmentSummaryEmailHtml($this->rows, array_keys(self::COLUMNS), $snapshotDate);

        $outputPath = $this->option('output');
        if ($outputPath !== null && $workbook !== null) {
            copy($workbook['path'], $outputPath);
            $this->info("[INFO] Workbook saved to {$outputPath}");

            $htmlPath = preg_replace('/\.xlsx$/i', '-Enrollment-Summary.html', $outputPath)
                ?: ($outputPath . '-Enrollment-Summary.html');
            file_put_contents($htmlPath, $emailHtml);
            $this->info("[INFO] Enrollment Summary HTML preview saved to {$htmlPath}");
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

        // Same schedule: send Gross/Net as a separate email right after Enrollment Summary.
        if (!$skipEmail) {
            $this->info('[INFO] Triggering Enrollment Gross/Net report (separate email)...');
            $grossNetExit = Artisan::call('Generate:enrollment-gross-net-report');
            $this->output->write(Artisan::output());
            if ($grossNetExit !== Command::SUCCESS) {
                $this->warn('[WARN] Enrollment Gross/Net report failed after Enrollment Summary.');
            }
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

        $this->buildMonthBuckets($connector, $columnKey, $criteria, $windowDate, $snapshotDate);

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
     * Month-bucketed "Paying in X" rows, mirrors the VBA's `For j = StartDate To EndDate` month loop.
     */
    private function buildMonthBuckets(DBConnector $connector, string $columnKey, string $criteria, string $windowDate, string $snapshotDate): void
    {
        // VBA: StartDate = first day of ReportDate's month (no offset); EndDate = last day of the
        // month containing ReportDate + 45 days.
        [$windowStart, $windowEnd] = $this->windowBounds($windowDate);
        $start = new \DateTime($windowStart);
        $end = new \DateTime($windowEnd);

        $cursor = clone $start;
        $monthIndex = 0;

        while ($cursor <= $end) {
            $monthIndex++;
            $monthStart = $cursor->format('Y-m-d');
            $monthEnd = (clone $cursor)->modify('last day of this month')->format('Y-m-d');
            $monthLabel = $cursor->format('F');

            $this->setRow('', $columnKey, null, 'blank', blank: true);

            $paidCoalesce = PeelOffsBuilder::PAID_COALESCE;

            // Jacob 2026-08-17 (#4): on the currently-paying PROJECTION totals (Total Deals/Debt,
            // Sellable, Reconsideration — the ones already scoped to Cancel_Date IS NULL AND
            // NSF_Date IS NULL), drop a payment that never processed: not cleared and its date is
            // 3+ days past. Cleared always counts; not-yet-due (within 3 days) still counts; NSF'd
            // is already excluded by NSF_Date IS NULL. No-op for future months (their dates are
            // always >= the cutoff). The Gross/day-based rows above are left as-is per Jacob ("the
            // new debt and clients is only for that particular day so that is the same").
            // The grace period itself lives in PeelOffsBuilder so the Unprocessed Peel Offs row
            // (PRD 2026-09-10) is derived from the same number.
            $threeDayCutoff = PeelOffsBuilder::unprocessedCutoff($snapshotDate);
            $payingClause = "AND (First_Payment_Cleared_Date IS NOT NULL OR {$paidCoalesce} >= '{$threeDayCutoff}')";

            // PRD 2026-09-10 §2: a client already recorded as an Unprocessed Peel Off on an earlier
            // report is not counted again as an NSF or Cancel peel off. Applied to the count rows as
            // well as the debt rows so each block stays internally consistent, and to EVERY month:
            // sync:first-payment-date still recomputes First_Payment_Date to the first
            // cleared-or-returned draft, so a client whose first draft never processed and whose new
            // draft bounces moves into the NEXT month's bucket. The Unprocessed rows are on every
            // month too (Jacob 2026-09-14 12:08): while the report is anchored a month behind, the
            // newly-unprocessed payments are the second month's; the third month is always 0.
            $hasPeelOffs = $this->peelOffs !== null;
            $peelOffExclusion = $hasPeelOffs ? $this->peelOffs->ledgerExclusionSql() : '';

            $grossNew = (int) $this->scalar($connector, "
                SELECT COUNT(*) FROM TblEnrollment
                WHERE Welcome_Call_Date = ?
                  AND {$paidCoalesce} >= ? AND {$paidCoalesce} <= ? {$criteria}
            ", [$snapshotDate, $monthStart, $monthEnd]);
            $this->setRow("Gross New Enrollments Paying In {$monthLabel}", $columnKey, $grossNew, 'count');

            $cancels = (int) $this->scalar($connector, "
                SELECT COUNT(*) FROM TblEnrollment
                WHERE Cancel_Date = ?
                  AND {$paidCoalesce} >= ? AND {$paidCoalesce} <= ? {$criteria} {$peelOffExclusion}
            ", [$snapshotDate, $monthStart, $monthEnd]);
            $this->setRow("Cancels of Client's Paying in {$monthLabel}", $columnKey, $cancels, 'count');

            $nsfs = (int) $this->scalar($connector, "
                SELECT COUNT(*) FROM TblEnrollment
                WHERE NSF_Date = ?
                  AND {$paidCoalesce} >= ? AND {$paidCoalesce} <= ? {$criteria} {$peelOffExclusion}
            ", [$snapshotDate, $monthStart, $monthEnd]);
            $this->setRow("NSFs of Client's Paying in {$monthLabel}", $columnKey, $nsfs, 'count');

            // PRD §1.1: Unprocessed Peel Offs — count here, debt below — for this month's payers.
            // The Net rows subtract them like the other two peel-off types (Jacob 2026-09-14 10:54).
            $unprocessed = $hasPeelOffs ? $this->peelOffs->aggregate('unprocessed', $columnKey, $monthStart) : ['count' => 0, 'debt' => 0.0];
            if ($hasPeelOffs) {
                $this->setRow("Unprocessed Payments of Client's Paying in {$monthLabel}", $columnKey, $unprocessed['count'], 'count');
            }

            $this->setRow("Net New Clients Paying in {$monthLabel}", $columnKey, $grossNew - $cancels - $nsfs - $unprocessed['count'], 'count');

            $grossDebt = (float) $this->scalar($connector, "
                SELECT SUM(Debt_Amount) FROM TblEnrollment
                WHERE Welcome_Call_Date = ?
                  AND {$paidCoalesce} >= ? AND {$paidCoalesce} <= ? {$criteria}
            ", [$snapshotDate, $monthStart, $monthEnd]);
            $this->setRow("Gross Debt Enrolled Paying in {$monthLabel}", $columnKey, $grossDebt, 'currency');

            $debtCancel = (float) $this->scalar($connector, "
                SELECT SUM(Debt_Amount) FROM TblEnrollment
                WHERE Cancel_Date = ?
                  AND {$paidCoalesce} >= ? AND {$paidCoalesce} <= ? {$criteria} {$peelOffExclusion}
            ", [$snapshotDate, $monthStart, $monthEnd]);
            $this->setRow("Cancel Peel Offs Paying in {$monthLabel}", $columnKey, $debtCancel, 'currency');

            $debtNsf = (float) $this->scalar($connector, "
                SELECT SUM(Debt_Amount) FROM TblEnrollment
                WHERE NSF_Date = ?
                  AND {$paidCoalesce} >= ? AND {$paidCoalesce} <= ? {$criteria} {$peelOffExclusion}
            ", [$snapshotDate, $monthStart, $monthEnd]);
            $this->setRow("NSF Peel Offs Paying in {$monthLabel}", $columnKey, $debtNsf, 'currency');

            if ($hasPeelOffs) {
                $this->setRow("Unprocessed Peel Offs Paying in {$monthLabel}", $columnKey, $unprocessed['debt'], 'currency');
            }

            $this->setRow("Total Net Debt Enrolled Paying in {$monthLabel}", $columnKey, $grossDebt - $debtCancel - $debtNsf - $unprocessed['debt'], 'currency');

            $deals = (int) $this->scalar($connector, "
                SELECT COUNT(*) FROM TblEnrollment
                WHERE Cancel_Date IS NULL AND NSF_Date IS NULL
                  AND {$paidCoalesce} >= ? AND {$paidCoalesce} <= ? {$criteria} {$payingClause}
            ", [$monthStart, $monthEnd]);
            $this->setRow("Total Deals Paying in {$monthLabel}", $columnKey, $deals, 'count', bold: true);

            $totalDebt = (float) $this->scalar($connector, "
                SELECT SUM(Debt_Amount) FROM TblEnrollment
                WHERE Cancel_Date IS NULL AND NSF_Date IS NULL
                  AND {$paidCoalesce} >= ? AND {$paidCoalesce} <= ? {$criteria} {$payingClause}
            ", [$monthStart, $monthEnd]);
            $this->setRow("Total Debt Paying in {$monthLabel}", $columnKey, $totalDebt, 'currency', bold: true);

            $sellableDebt = (float) $this->scalar($connector, "
                SELECT SUM(Debt_Amount) FROM TblEnrollment
                WHERE Cancel_Date IS NULL AND NSF_Date IS NULL
                  AND {$paidCoalesce} >= ? AND {$paidCoalesce} <= ?
                  AND Debt_Sold_To IS NULL
                  AND Enrollment_Status IN('LDR Enrolled', 'ProLaw Enrolled', 'Approved') {$criteria} {$payingClause}
            ", [$monthStart, $monthEnd]);
            $this->setRow("Sellable Debt Paying in {$monthLabel}", $columnKey, $sellableDebt, 'currency', bold: true);

            // Gross Debt Paying in X: all scheduled debt for the month regardless of cancel, NSF, status, or 3-day rule.
            $grossDebtScheduled = (float) $this->scalar($connector, "
                SELECT SUM(Debt_Amount) FROM TblEnrollment
                WHERE {$paidCoalesce} >= ? AND {$paidCoalesce} <= ? {$criteria}
            ", [$monthStart, $monthEnd]);
            $this->setRow("Gross Debt Paying in {$monthLabel}", $columnKey, $grossDebtScheduled, 'currency', bold: true);

            // Trailing 6 Month Sellable Ratio + Projected Sellable: Total column only; LDR/Legal grayed.
            // Projected Sellable = Gross Debt Paying in X * Trailing 6 Month Sellable Ratio.
            $rate = $this->trailing6SellableRatio;
            $projected = ($columnKey === 'Total' && $rate !== null) ? ($grossDebtScheduled * $rate) : null;
            $this->setRow(
                "Trailing 6 Month Sellable Ratio ({$monthLabel})",
                $columnKey,
                $columnKey === 'Total' ? $rate : null,
                'percent',
                bold: false,
                totalOnly: true
            );
            $this->setRow(
                "Projected Sellable ({$monthLabel})",
                $columnKey,
                $projected,
                'currency',
                bold: false,
                totalOnly: true
            );

            $reconsiderationDebt = (float) $this->scalar($connector, "
                SELECT SUM(Debt_Amount) FROM TblEnrollment
                WHERE Cancel_Date IS NULL AND NSF_Date IS NULL
                  AND {$paidCoalesce} >= ? AND {$paidCoalesce} <= ?
                  AND Debt_Sold_To IS NULL
                  AND Enrollment_Status = 'Enrolled (Reconsideration Pending)' {$criteria} {$payingClause}
            ", [$monthStart, $monthEnd]);
            $this->setRow("Reconsideration Pending Debt Paying in {$monthLabel}", $columnKey, $reconsiderationDebt, 'currency', bold: true);

            // Only computed for the first two months in the window (VBA: `If Row = 23` / `ElseIf Row = 37`).
            // Month 1 uses a hardcoded 7/1/2022 lower bound on First_Payment_Date; month 2 uses that month's bounds.
            if ($monthIndex === 1 || $monthIndex === 2) {
                $firstPaymentStart = $monthIndex === 1 ? '2022-07-01' : $monthStart;

                $clearedDebt = (float) $this->scalar($connector, "
                    SELECT SUM(Debt_Amount) FROM TblEnrollment
                    WHERE First_Payment_Date >= ? AND First_Payment_Date <= ?
                      AND First_Payment_Cleared_Date IS NOT NULL
                      AND Debt_Sold_To IS NULL
                      AND Enrollment_Status IN('LDR Enrolled', 'ProLaw Enrolled', 'Approved') {$criteria}
                ", [$firstPaymentStart, $monthEnd]);
                $this->setRow("Sellable Debt Cleared in {$monthLabel}", $columnKey, $clearedDebt, 'currency', bold: true);
            }

            $cursor->modify('first day of next month');
        }
    }

    /**
     * Jacob 2026-08-20: 12-month linear regression trend + 6-month weighted forecasting ratio.
     * Evaluates monthly numerator/denominator over trailing 12 months, fits linear trend slope,
     * applies 6-month weights (months 1-3 @ 20%, months 4-6 @ 13.33%), and adjusts by 3 months of trend slope.
     */
    private function computeTrailing6SellableRatio(DBConnector $connector, string $windowDate, string $criteria): ?float
    {
        $reportMonth = new \DateTimeImmutable($windowDate);
        $reportMonthStart = $reportMonth->modify('first day of this month');
        $trailStart = $reportMonthStart->modify('-12 months');
        $trailEnd = $reportMonthStart->modify('-1 day');

        $cur = clone $trailStart;
        $monthlyData = [];
        $monthIdx = 0;

        while ($cur <= $trailEnd) {
            $monthIdx++;
            $mStart = $cur->format('Y-m-d');
            $mEnd = $cur->modify('last day of this month')->format('Y-m-d');

            $den = (float) $this->scalar($connector, "
                SELECT COALESCE(SUM(Debt_Amount), 0) FROM TblEnrollment
                WHERE Welcome_Call_Date >= ? AND Welcome_Call_Date <= ? {$criteria}
            ", [$mStart, $mEnd]);

            $num = (float) $this->scalar($connector, "
                SELECT COALESCE(SUM(Debt_Amount), 0) FROM TblEnrollment
                WHERE Welcome_Call_Date >= ? AND Welcome_Call_Date <= ?
                  AND (
                        (Debt_Sold_To IS NOT NULL AND Tranche IS NOT NULL)
                     OR (
                            Debt_Sold_To IS NULL
                        AND (Tranche IS NULL)
                        AND Enrollment_Status IN ('LDR Enrolled', 'ProLaw Enrolled')
                     )
                  ) {$criteria}
            ", [$mStart, $mEnd]);

            $ratio = $den > 0 ? ($num / $den) : 0.0;

            $monthlyData[] = [
                'month_number' => $monthIdx,
                'numerator'    => $num,
                'denominator'  => $den,
                'ratio'        => $ratio,
            ];

            $cur = $cur->modify('first day of next month');
        }

        if (empty($monthlyData)) {
            return null;
        }

        // 1. Calculate 12-month Linear Regression Trend Slope
        $n = count($monthlyData);
        $avgX = array_sum(array_column($monthlyData, 'month_number')) / $n;
        $avgY = array_sum(array_column($monthlyData, 'ratio')) / $n;

        $numeratorSlope = 0.0;
        $denominatorSlope = 0.0;

        foreach ($monthlyData as $m) {
            $xDiff = $m['month_number'] - $avgX;
            $yDiff = $m['ratio'] - $avgY;
            $numeratorSlope += ($xDiff * $yDiff);
            $denominatorSlope += pow($xDiff, 2);
        }

        $trendSlope = $denominatorSlope > 0 ? ($numeratorSlope / $denominatorSlope) : 0.0;

        // 2. Six Month Weighted Ratio (Months 7 to 12 -> 1 to 6 in 6-month window)
        $sixMonths = array_slice($monthlyData, -6);
        $baseWeightedRatio = 0.0;

        foreach ($sixMonths as $idx => $m) {
            $sixMonthNumber = $idx + 1; // 1 to 6
            $weight = $sixMonthNumber <= 3 ? 0.20 : 0.1333333333;
            $baseWeightedRatio += ($m['ratio'] * $weight);
        }

        // 3. Forecast Ratio = Base_Weighted_Ratio + (Trend_Slope * 3)
        $forecastRatio = $baseWeightedRatio + ($trendSlope * 3);

        return $forecastRatio;
    }

    /**
     * Sets (or updates, on later column passes) a row value by label. Row order is fixed by the first pass (Total).
     *
     * Blank spacer rows have no label to dedupe on, so they're given a synthetic one ("__blank_N__")
     * that increments per call and resets every buildColumn() pass — since every pass makes the same
     * sequence of setRow() calls in the same order, the same synthetic label recurs across all 3 passes
     * and collapses into a single row instead of one blank row per column.
     */
    private function setRow(
        string $label,
        string $columnKey,
        mixed $value,
        string $format,
        bool $bold = false,
        bool $blank = false,
        bool $totalOnly = false
    ): void {
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
            'totalOnly' => $totalOnly,
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

    /**
     * First day of the window's first month and last day of its last month — the VBA's StartDate
     * (first day of ReportDate's month) and EndDate (last day of the month containing
     * ReportDate + 45 days). One place, so the month buckets and the peel offs cannot drift.
     *
     * @return array{0: string, 1: string}
     */
    private function windowBounds(string $windowDate): array
    {
        $date = new \DateTimeImmutable($windowDate);

        return [
            $date->modify('first day of this month')->format('Y-m-d'),
            $date->modify('+45 days')->modify('last day of this month')->format('Y-m-d'),
        ];
    }

    /**
     * Records today's Unprocessed Peel Offs so later runs exclude them from NSF / Cancel (PRD §2).
     * `auto` writes only on a real run: a --snapshot-date or --output run is a review copy and must
     * not stamp the ledger with a date the report did not actually run for.
     */
    private function writePeelOffLedger(string $snapshotDate): void
    {
        if ($this->peelOffs === null) {
            return;
        }

        $mode = strtolower((string) $this->option('ledger-write'));
        if (!in_array($mode, ['auto', 'on', 'off'], true)) {
            $this->warn("[WARN] Unknown --ledger-write value '{$mode}'; treating it as 'off'.");
            $mode = 'off';
        }

        $isReviewRun = $this->option('snapshot-date') !== null || $this->option('output') !== null;
        $shouldWrite = $mode === 'on' || ($mode === 'auto' && !$isReviewRun);

        if (!$shouldWrite) {
            $this->info('[INFO] Peel-off ledger: write skipped (' . ($mode === 'off' ? '--ledger-write=off' : 'review run; pass --ledger-write=on to record it') . ').');
            return;
        }

        $result = $this->peelOffs->writeLedger();
        if ($result['failed'] > 0) {
            $this->warn(sprintf(
                '[WARN] Peel-off ledger: %d of %d unprocessed row(s) FAILED to record for %s — tomorrow\'s NSF/Cancel peel offs may count them again. See the log.',
                $result['failed'],
                $result['attempted'],
                $snapshotDate
            ));
            return;
        }

        $this->info(sprintf(
            '[INFO] Peel-off ledger: %d new unprocessed row(s) recorded for %s (%d already present).',
            $result['written'],
            $snapshotDate,
            $result['attempted'] - $result['written']
        ));
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
