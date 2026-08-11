<?php

namespace Cmd\Reports\Console\Commands\GenerateEnrollmentSummaryReport;

use Cmd\Reports\Services\DBConnector;

/**
 * Builds the pivoted 13-month "Monthly Enrollment Summary" sheet (current month + prior 12).
 */
class MonthlyEnrollmentSummaryBuilder
{
    private const MONTH_COUNT = 13;

    private const PAID_COALESCE = 'COALESCE(First_Payment_Date, Payment_Date_2, Payment_Date_1)';

    private const ENROLLED_STATUSES = "'LDR Enrolled', 'ProLaw Enrolled'";

    private const PENDING_STATUSES = "'Submitted', 'Approved', 'Attorney Approved CFLN'";

    /**
     * @return array{
     *     months: array<int, array{key: string, label: string, start: string, end: string}>,
     *     rows: array<int, array{label: string, format: string, bold: bool, values: array<string, float|int|null>}>
     * }
     */
    public function build(DBConnector $connector, string $snapshotDate, string $criteria): array
    {
        $months = $this->monthWindow($snapshotDate);
        $metricRows = $this->metricDefinitions();
        $valuesByKey = [];
        foreach ($metricRows as $definition) {
            $valuesByKey[$definition['key']] = [];
        }

        $projectionValues = [];
        $sellRateValues = [];

        foreach ($months as $month) {
            $bucket = $this->buildMonthBucket($connector, $criteria, $month['start'], $month['end']);
            foreach ($metricRows as $definition) {
                $valuesByKey[$definition['key']][$month['key']] = $bucket[$definition['key']];
            }

            $enrolled = $this->scalar($connector, "
                SELECT COALESCE(SUM(Debt_Amount), 0) FROM TblEnrollment
                WHERE Submitted_Date >= ? AND Submitted_Date <= ?
                  AND Enrollment_Status IN (" . self::ENROLLED_STATUSES . ")
                  {$criteria}
            ", [$month['start'], $month['end']]);

            $pending = $this->scalar($connector, "
                SELECT COALESCE(SUM(Debt_Amount), 0) FROM TblEnrollment
                WHERE Submitted_Date >= ? AND Submitted_Date <= ?
                  AND Enrollment_Status IN (" . self::PENDING_STATUSES . ")
                  {$criteria}
            ", [$month['start'], $month['end']]);

            $projection = (float) $enrolled + (float) $pending;
            $projectionValues[$month['key']] = $projection;

            $grossDebt = (float) ($bucket['gross_debt_paying'] ?? 0);
            $sellRateValues[$month['key']] = $grossDebt > 0 ? ($projection / $grossDebt) : null;
        }

        $rows = [];
        foreach ($metricRows as $definition) {
            $rows[] = [
                'label' => $definition['label'],
                'format' => $definition['format'],
                'bold' => $definition['bold'],
                'values' => $valuesByKey[$definition['key']],
            ];
        }

        $rows[] = [
            'label' => 'Projection Total',
            'format' => 'currency',
            'bold' => true,
            'values' => $projectionValues,
        ];

        $rows[] = [
            'label' => 'Sell Rate',
            'format' => 'percent',
            'bold' => true,
            'values' => $sellRateValues,
        ];

        return [
            'months' => $months,
            'rows' => $rows,
        ];
    }

    /**
     * @return array<int, array{key: string, label: string, start: string, end: string}>
     */
    private function monthWindow(string $snapshotDate): array
    {
        $anchor = (new \DateTimeImmutable($snapshotDate))->modify('first day of this month');
        $months = [];
        for ($offset = self::MONTH_COUNT - 1; $offset >= 0; $offset--) {
            $cursor = $anchor->modify("-{$offset} months");
            $start = $cursor->format('Y-m-d');
            $end = $cursor->modify('last day of this month')->format('Y-m-d');
            $months[] = [
                'key' => $cursor->format('Y-m'),
                'label' => $cursor->format('M Y'),
                'start' => $start,
                'end' => $end,
            ];
        }

        return $months;
    }

    /**
     * @return array<int, array{key: string, label: string, format: string, bold: bool}>
     */
    private function metricDefinitions(): array
    {
        return [
            ['key' => 'gross_new_paying', 'label' => 'Gross New Enrollments Paying', 'format' => 'count', 'bold' => false],
            ['key' => 'cancels_paying', 'label' => "Cancels of Month Pays", 'format' => 'count', 'bold' => false],
            ['key' => 'nsfs_paying', 'label' => 'NSFs of Month Pays', 'format' => 'count', 'bold' => false],
            ['key' => 'net_new_paying', 'label' => 'Net New Clients Paying', 'format' => 'count', 'bold' => false],
            ['key' => 'gross_debt_paying', 'label' => 'Gross Debt Enrolled Paying', 'format' => 'currency', 'bold' => false],
            ['key' => 'cancel_peeloffs_paying', 'label' => 'Cancel Peel Offs Paying', 'format' => 'currency', 'bold' => false],
            ['key' => 'nsf_peeloffs_paying', 'label' => 'NSF Peel Offs Paying', 'format' => 'currency', 'bold' => false],
            ['key' => 'net_debt_paying', 'label' => 'Total Net Debt Enrolled Paying', 'format' => 'currency', 'bold' => false],
            ['key' => 'total_deals_paying', 'label' => 'Total Deals Paying', 'format' => 'count', 'bold' => true],
            ['key' => 'total_debt_paying', 'label' => 'Total Debt Paying', 'format' => 'currency', 'bold' => true],
            ['key' => 'sellable_debt_paying', 'label' => 'Sellable Debt Paying', 'format' => 'currency', 'bold' => true],
            ['key' => 'reconsideration_debt_paying', 'label' => 'Reconsideration Pending Debt Paying', 'format' => 'currency', 'bold' => true],
            ['key' => 'sellable_debt_cleared', 'label' => 'Sellable Debt Cleared', 'format' => 'currency', 'bold' => true],
        ];
    }

    /**
     * @return array<string, int|float>
     */
    private function buildMonthBucket(DBConnector $connector, string $criteria, string $monthStart, string $monthEnd): array
    {
        $paid = self::PAID_COALESCE;

        $grossNew = (int) $this->scalar($connector, "
            SELECT COUNT(*) FROM TblEnrollment
            WHERE Welcome_Call_Date >= ? AND Welcome_Call_Date <= ?
              AND {$paid} >= ? AND {$paid} <= ? {$criteria}
        ", [$monthStart, $monthEnd, $monthStart, $monthEnd]);

        $cancels = (int) $this->scalar($connector, "
            SELECT COUNT(*) FROM TblEnrollment
            WHERE Cancel_Date >= ? AND Cancel_Date <= ?
              AND {$paid} >= ? AND {$paid} <= ? {$criteria}
        ", [$monthStart, $monthEnd, $monthStart, $monthEnd]);

        $nsfs = (int) $this->scalar($connector, "
            SELECT COUNT(*) FROM TblEnrollment
            WHERE NSF_Date >= ? AND NSF_Date <= ?
              AND {$paid} >= ? AND {$paid} <= ? {$criteria}
        ", [$monthStart, $monthEnd, $monthStart, $monthEnd]);

        $grossDebt = (float) $this->scalar($connector, "
            SELECT COALESCE(SUM(Debt_Amount), 0) FROM TblEnrollment
            WHERE Welcome_Call_Date >= ? AND Welcome_Call_Date <= ?
              AND {$paid} >= ? AND {$paid} <= ? {$criteria}
        ", [$monthStart, $monthEnd, $monthStart, $monthEnd]);

        $debtCancel = (float) $this->scalar($connector, "
            SELECT COALESCE(SUM(Debt_Amount), 0) FROM TblEnrollment
            WHERE Cancel_Date >= ? AND Cancel_Date <= ?
              AND {$paid} >= ? AND {$paid} <= ? {$criteria}
        ", [$monthStart, $monthEnd, $monthStart, $monthEnd]);

        $debtNsf = (float) $this->scalar($connector, "
            SELECT COALESCE(SUM(Debt_Amount), 0) FROM TblEnrollment
            WHERE NSF_Date >= ? AND NSF_Date <= ?
              AND {$paid} >= ? AND {$paid} <= ? {$criteria}
        ", [$monthStart, $monthEnd, $monthStart, $monthEnd]);

        $deals = (int) $this->scalar($connector, "
            SELECT COUNT(*) FROM TblEnrollment
            WHERE Cancel_Date IS NULL AND NSF_Date IS NULL
              AND {$paid} >= ? AND {$paid} <= ? {$criteria}
        ", [$monthStart, $monthEnd]);

        $totalDebt = (float) $this->scalar($connector, "
            SELECT COALESCE(SUM(Debt_Amount), 0) FROM TblEnrollment
            WHERE Cancel_Date IS NULL AND NSF_Date IS NULL
              AND {$paid} >= ? AND {$paid} <= ? {$criteria}
        ", [$monthStart, $monthEnd]);

        $sellableDebt = (float) $this->scalar($connector, "
            SELECT COALESCE(SUM(Debt_Amount), 0) FROM TblEnrollment
            WHERE Cancel_Date IS NULL AND NSF_Date IS NULL
              AND {$paid} >= ? AND {$paid} <= ?
              AND Debt_Sold_To IS NULL
              AND Enrollment_Status IN('LDR Enrolled', 'ProLaw Enrolled', 'Approved') {$criteria}
        ", [$monthStart, $monthEnd]);

        $reconsiderationDebt = (float) $this->scalar($connector, "
            SELECT COALESCE(SUM(Debt_Amount), 0) FROM TblEnrollment
            WHERE Cancel_Date IS NULL AND NSF_Date IS NULL
              AND {$paid} >= ? AND {$paid} <= ?
              AND Debt_Sold_To IS NULL
              AND Enrollment_Status = 'Enrolled (Reconsideration Pending)' {$criteria}
        ", [$monthStart, $monthEnd]);

        $clearedDebt = (float) $this->scalar($connector, "
            SELECT COALESCE(SUM(Debt_Amount), 0) FROM TblEnrollment
            WHERE First_Payment_Date >= ? AND First_Payment_Date <= ?
              AND First_Payment_Cleared_Date IS NOT NULL
              AND Debt_Sold_To IS NULL
              AND Enrollment_Status IN('LDR Enrolled', 'ProLaw Enrolled', 'Approved') {$criteria}
        ", [$monthStart, $monthEnd]);

        return [
            'gross_new_paying' => $grossNew,
            'cancels_paying' => $cancels,
            'nsfs_paying' => $nsfs,
            'net_new_paying' => $grossNew - $cancels - $nsfs,
            'gross_debt_paying' => $grossDebt,
            'cancel_peeloffs_paying' => $debtCancel,
            'nsf_peeloffs_paying' => $debtNsf,
            'net_debt_paying' => $grossDebt - $debtCancel - $debtNsf,
            'total_deals_paying' => $deals,
            'total_debt_paying' => $totalDebt,
            'sellable_debt_paying' => $sellableDebt,
            'reconsideration_debt_paying' => $reconsiderationDebt,
            'sellable_debt_cleared' => $clearedDebt,
        ];
    }

    private function scalar(DBConnector $connector, string $sql, array $params = []): mixed
    {
        $result = $connector->querySqlServer($sql, $params);
        $row = $result['data'][0] ?? null;
        if ($row === null) {
            return 0;
        }

        return array_values($row)[0] ?? 0;
    }
}
