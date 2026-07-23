<?php

namespace Cmd\Reports\Console\Commands\GenerateCancellationReport;

/**
 * Reimplements the Cancellation Report template's COUNTIFS-based monthly cohort formulas
 * ("Report (All)" / "Report (Settlements)" / "Cancellation Report" sheets), extracted directly
 * from the template's cell formulas (Cancellation Report.xlsx) rather than guessed:
 *
 * Each of "Report (All)"/"Report (Settlements)" is 5 stacked 15-row blocks (rows 1, 16, 31, 46, 61),
 * one 12-month cohort table each: Net Enrollments, Net Enrollment Percent, Canceled,
 * Canceled With No Payments, Canceled With Payments. "Report (All)" reads 'Data 1', "Report
 * (Settlements)" reads 'Data 2' — otherwise identical formulas.
 *
 * "Cancellation Report" is 3 stacked 15-row blocks (rows 1, 16, 31), the same "Canceled" cohort
 * table filtered to each company bucket (LDR / Progress Law / PLAW), always sourced from 'Data 1'.
 *
 * Every block's row for month M, offset N (0-12) counts rows enrolled in month M whose
 * DROPPED_DATE falls within the calendar month that is N months after M.
 */
class CohortReportBuilder
{
    private const OFFSETS = 13; // month-offset columns 0..12, matching the template's row2 labels

    /**
     * The 5 stacked blocks that make up "Report (All)" / "Report (Settlements)".
     *
     * @param array<int, array<string, mixed>> $rows Data 1/Data 2 rows
     * @return array<int, array{title: string, percent: bool, months: string[], gross: int[], values: array<int,array<int,int|float|null>>}>
     */
    public function buildReportBlocks(array $rows): array
    {
        $net = $this->cohort($rows, null, null);

        $netValues = [];
        foreach ($net['gross'] as $i => $gross) {
            $netValues[$i] = array_map(fn (?int $c) => $c === null ? null : $gross - $c, $net['canceled'][$i]);
        }

        $percentValues = [];
        foreach ($net['gross'] as $i => $gross) {
            $percentValues[$i] = array_map(
                fn (?int $n) => ($n === null || $gross <= 0) ? null : $n / $gross,
                $netValues[$i]
            );
        }

        $noPayments = $this->cohort($rows, null, 'blank');
        $withPayments = $this->cohort($rows, null, 'nonblank');

        return [
            ['title' => 'Net Enrollments', 'percent' => false, 'months' => $net['months'], 'gross' => $net['gross'], 'values' => $netValues],
            ['title' => 'Net Enrollment Percent', 'percent' => true, 'months' => $net['months'], 'gross' => $net['gross'], 'values' => $percentValues],
            ['title' => 'Canceled', 'percent' => false, 'months' => $net['months'], 'gross' => $net['gross'], 'values' => $net['canceled']],
            ['title' => 'Canceled With No Payments', 'percent' => false, 'months' => $noPayments['months'], 'gross' => $noPayments['gross'], 'values' => $noPayments['canceled']],
            ['title' => 'Canceled With Payments', 'percent' => false, 'months' => $withPayments['months'], 'gross' => $withPayments['gross'], 'values' => $withPayments['canceled']],
        ];
    }

    private const CANCELLATION_REPORT_COMPANIES = [
        'LDR Cancellation' => 'LDR',
        'Progress Law Cancellations' => 'Progress Law',
        'Paramount Law Cancellation' => 'PLAW',
    ];

    /**
     * The company-filtered blocks that make up the "Cancellation Report" sheet, restricted to the
     * buckets that can actually appear in the calling category's own Snowflake source — e.g. the
     * 'ldr' account can contain both LDR- and PLAW-titled enrollment plans, but never Progress Law ones,
     * so the Progress Law block is dropped there; the 'plaw' account is 100% Progress Law.
     *
     * @param array<int, array<string, mixed>> $data1
     * @param string[] $companies Company buckets to include (subset of LDR/Progress Law/PLAW)
     * @return array<int, array{title: string, percent: bool, months: string[], gross: int[], values: array<int,array<int,int>>}>
     */
    public function buildCancellationReportBlocks(array $data1, array $companies): array
    {
        $blocks = [];
        foreach (self::CANCELLATION_REPORT_COMPANIES as $title => $company) {
            if (!in_array($company, $companies, true)) {
                continue;
            }
            $result = $this->cohort($data1, $company, null);
            $blocks[] = [
                'title' => $title,
                'percent' => false,
                'months' => $result['months'],
                'gross' => $result['gross'],
                'values' => $result['canceled'],
            ];
        }

        return $blocks;
    }

    /**
     * Computes gross enrollment counts and cancellation counts (per 0-12 month offset) for the
     * 12 calendar months ending this month, mirroring the template's backward-chained A-column
     * dates (each row = one month before the row below, bottom row anchored to TODAY()).
     *
     * An offset window that hasn't started yet (e.g. "6 months after" a month enrolled last
     * month) can't have happened — those cells return null so the sheet renders them blank
     * (a staircase, not a wall of misleading 0%/0-cancellation cells).
     *
     * @param array<int, array<string, mixed>> $rows
     * @param string|null $company Filter TITLE (Data 1 col E) to this bucket, or null for no filter
     * @param 'blank'|'nonblank'|null $paymentFilter
     * @return array{months: string[], gross: int[], canceled: array<int,array<int,int|null>>}
     */
    private function cohort(array $rows, ?string $company, ?string $paymentFilter): array
    {
        $today = (new \DateTimeImmutable())->format('Y-m-d');

        $anchor = new \DateTimeImmutable('first day of this month');
        $months = [];
        for ($i = 11; $i >= 0; $i--) {
            $months[] = $anchor->modify("-{$i} months");
        }

        $gross = [];
        $canceled = [];

        foreach ($months as $monthStart) {
            $monthEnd = $monthStart->modify('last day of this month');
            $monthStartStr = $monthStart->format('Y-m-d');
            $monthEndStr = $monthEnd->format('Y-m-d');

            $enrolledInMonth = [];
            foreach ($rows as $row) {
                if ($company !== null && ($row['TITLE'] ?? null) !== $company) {
                    continue;
                }
                $enrolled = $row['ENROLLED_DATE'] ?? null;
                if ($enrolled === null || $enrolled < $monthStartStr || $enrolled > $monthEndStr) {
                    continue;
                }
                $enrolledInMonth[] = $row;
            }
            $gross[] = count($enrolledInMonth);

            $offsets = [];
            for ($n = 0; $n < self::OFFSETS; $n++) {
                $winStart = $monthStart->modify("+{$n} months");
                $winStartStr = $winStart->format('Y-m-d');

                if ($winStartStr > $today) {
                    $offsets[] = null;
                    continue;
                }

                $winEnd = $winStart->modify('last day of this month');
                $winEndStr = $winEnd->format('Y-m-d');

                $count = 0;
                foreach ($enrolledInMonth as $row) {
                    $dropped = $row['DROPPED_DATE'] ?? null;
                    if ($dropped === null || $dropped < $winStartStr || $dropped > $winEndStr) {
                        continue;
                    }
                    if ($paymentFilter === 'blank' && !empty($row['PAYMENTS'])) {
                        continue;
                    }
                    if ($paymentFilter === 'nonblank' && empty($row['PAYMENTS'])) {
                        continue;
                    }
                    $count++;
                }
                $offsets[] = $count;
            }
            $canceled[] = $offsets;
        }

        return [
            'months' => array_map(fn (\DateTimeImmutable $d) => $d->format('Y-m-d'), $months),
            'gross' => $gross,
            'canceled' => $canceled,
        ];
    }
}
