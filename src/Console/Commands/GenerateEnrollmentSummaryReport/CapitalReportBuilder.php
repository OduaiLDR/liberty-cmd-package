<?php

namespace Cmd\Reports\Console\Commands\GenerateEnrollmentSummaryReport;

use Cmd\Reports\Services\DBConnector;

/**
 * Rebuilds the "Capital Report" tab's main table (columns A-AM), mirroring VBA Sub GenerateCapitalReport
 * (ModReportsCompany.bas, "Commission Management Database (Scheduled Tasks - Snowflake)" workbook).
 *
 * NOTE: The "Monthly Residuals" footer (Legal Protection / Veritas / CDP / Progress Law queries) is NOT
 * included here — out of scope for this pass.
 */
class CapitalReportBuilder
{
    private const EPF_NGF_IDS = [31213, 35285, 36678, 34684, 36503, 36790];

    /** @var string[] Columns summed on the Totals row (matches the source's SUM(X2:X{lastRow}) per column, D through AH). */
    private const TOTALS_COLUMNS = [
        'Sold Debt Amount', 'LDR EPF Original', 'PLAW EPF Original', 'Pro Law EPF Original',
        'LDR EPF Current', 'PLAW EPF Current', 'Pro Law EPF Current', 'NGF Payments', 'Preferred Return',
        'LDR EPF Paid', 'PLAW EPF Paid', 'Pro Law EPF Paid', 'LDR EPF Remaining', 'PLAW EPF Remaining',
        'Pro Law EPF Remaining', 'Total EPF Original', 'Total EPF Current', 'Total EPF Paid',
        'Total EPF Remaining', 'LDR EPF Earned (15%)', 'EPF Paid To NGF', 'Preferred Return Paid', 'Preferred Return Remaining',
        'EPF Needed to Cap Preferred Return', 'EPF LDR (15%)', 'EPF LDR (85%)', 'PLAW EPF Paid 5%',
        'Pro Law EPF Paid 3%', 'PLAW EPF Projection', 'Pro Law EPF Projection', 'LDR Net',
    ];

    /**
     * @return array{rows: array<int, array<string, mixed>>, totals: array<string, mixed>}
     */
    public function build(DBConnector $connector): array
    {
        $tranches = $connector->querySqlServer("
            SELECT e.Tranche, t.Payment_Date, t.Report_Date, SUM(e.Sold_Debt) AS Sold_Debt
            FROM TblEnrollment AS e
            LEFT JOIN TblDebtTrancheSales AS t ON e.Tranche = t.Tranche
            WHERE e.Tranche IS NOT NULL
            GROUP BY e.Tranche, t.Payment_Date, t.Report_Date
            ORDER BY e.Tranche
        ")['data'] ?? [];

        $realRows = [];
        $maxTranche = 0;
        foreach ($tranches as $tranche) {
            $realRows[] = $this->buildTrancheRow($connector, $tranche);
            $maxTranche = max($maxTranche, (int) $tranche['Tranche']);
        }

        // Columns P-W apply to real tranche rows only. VBA computes `LastRow = LastRow + 1` to point
        // at the Unsold Tranche row *before* appending the 3 future rows, and never updates LastRow
        // afterward — so every `Range(...& LastRow)` formula (P through AH) only ever reaches real
        // tranche rows. The Unsold Tranche row's cells get written too, but the row is merged A:AL
        // immediately beforehand, which silently discards writes to anything but the top-left cell.
        // Net effect: only real tranche rows end up with P onward populated.
        foreach ($realRows as &$row) {
            $row = $this->applyEpfColumns($row);
        }
        unset($row);

        // X (EPF Paid To NGF): VBA's `For j = 2 To LastRow - 1` (LastRow still = Unsold Tranche's row
        // at this point) also only covers real tranche rows.
        foreach ($realRows as &$row) {
            $row['EPF Paid To NGF'] = $this->fetchEpfPaidToNgf($connector, $row['Tranche']);
        }
        unset($row);

        foreach ($realRows as &$row) {
            $row = $this->applyPreferredReturnColumns($row);
        }
        unset($row);

        // AI-AM (Collection Rate, NGF Total, Annualized Return): same LastRow-1 scoping, real tranches only.
        $lookbackByTranche = $this->fetchLookbackByTranche($connector);
        foreach ($realRows as &$row) {
            $row = $this->applyReturnColumns($row, $lookbackByTranche[$row['Tranche']] ?? 0.0);
        }
        unset($row);

        $lastRealTrancheDate = end($tranches)['Report_Date'] ?? null;
        $rows = $realRows;
        $rows[] = $this->buildUnsoldTrancheRow();
        array_push($rows, ...$this->buildFutureTrancheRows($connector, $maxTranche, $this->excelDate($lastRealTrancheDate)));

        // Totals row sums whatever each column actually has — real tranches contribute P onward,
        // Unsold Tranche/future rows only contribute D-O (K-O being explicit 0s), matching VBA's
        // SUM(X2:X{finalLastRow}) which treats the blank cells as 0.
        $totals = ['Tranche' => 'Totals'];
        foreach (self::TOTALS_COLUMNS as $col) {
            $totals[$col] = array_sum(array_column($rows, $col));
        }

        return ['rows' => $rows, 'totals' => $totals];
    }

    private function buildTrancheRow(DBConnector $connector, array $tranche): array
    {
        $trancheId = $tranche['Tranche'];

        $ldrOrig = (float) $this->scalar($connector, "
            SELECT SUM(Sold_Debt * COALESCE(EPF_Rate,.25)) FROM TblEnrollment
            WHERE Tranche = ? AND Enrollment_Plan NOT LIKE 'PLAW%' AND UPPER(Enrollment_Plan) NOT LIKE '%PROGRESS%'
        ", [$trancheId]);

        $plawOrig = (float) $this->scalar($connector, "
            SELECT SUM(Sold_Debt * COALESCE(EPF_Rate,.25)) FROM TblEnrollment
            WHERE Tranche = ? AND Enrollment_Plan LIKE 'PLAW%'
        ", [$trancheId]);

        $proLawOrig = (float) $this->scalar($connector, "
            SELECT SUM(Sold_Debt * COALESCE(EPF_Rate,.25)) FROM TblEnrollment
            WHERE Tranche = ? AND UPPER(Enrollment_Plan) LIKE '%PROGRESS%'
        ", [$trancheId]);

        $ldrCurrent = (float) $this->scalar($connector, "
            SELECT SUM(Sold_Debt * COALESCE(EPF_Rate,.25)) FROM TblEnrollment
            WHERE Tranche = ? AND Enrollment_Plan NOT LIKE 'PLAW%' AND UPPER(Enrollment_Plan) NOT LIKE '%PROGRESS%'
              AND Cancel_Date IS NULL
        ", [$trancheId]);

        $plawCurrent = (float) $this->scalar($connector, "
            SELECT SUM(Sold_Debt * COALESCE(EPF_Rate,.25)) FROM TblEnrollment
            WHERE Tranche = ? AND Enrollment_Plan LIKE 'PLAW%' AND Cancel_Date IS NULL
        ", [$trancheId]);

        $proLawCurrent = (float) $this->scalar($connector, "
            SELECT SUM(Sold_Debt * COALESCE(EPF_Rate,.25)) FROM TblEnrollment
            WHERE Tranche = ? AND UPPER(Enrollment_Plan) LIKE '%PROGRESS%' AND Cancel_Date IS NULL
        ", [$trancheId]);

        $ngfPayments = (float) $this->scalar($connector, "
            SELECT Payment FROM TblDebtTrancheSales WHERE Tranche = ?
        ", [(int) $trancheId]);
        $preferredReturn = round($ngfPayments * 1.1, 2);

        // M gained a `Cleared_Date > Payment_Date` filter vs. the older version — only count EPF paid after the tranche sold.
        $ldrPaid = (float) $this->scalar($connector, "
            SELECT SUM(p.Amount) FROM TblEPFs AS p
            LEFT JOIN TblEnrollment AS e ON p.LLG_ID = e.LLG_ID
            LEFT JOIN TblDebtTrancheSales AS t ON e.Tranche = t.Tranche
            WHERE p.Cleared_Date >= '2022-07-01' AND e.Tranche = ?
              AND p.Cleared_Date IS NOT NULL AND p.Returned_Date IS NULL
              AND e.Enrollment_Plan NOT LIKE 'PLAW%' AND UPPER(e.Enrollment_Plan) NOT LIKE '%PROGRESS%'
              AND p.Cleared_Date > t.Payment_Date
        ", [$trancheId]);

        $plawPaid = (float) $this->scalar($connector, "
            SELECT SUM(p.Amount) FROM TblEPFs AS p
            LEFT JOIN TblEnrollment AS e ON p.LLG_ID = e.LLG_ID
            WHERE p.Cleared_Date >= '2022-07-01' AND e.Tranche = ?
              AND p.Cleared_Date IS NOT NULL AND p.Returned_Date IS NULL
              AND e.Enrollment_Plan LIKE 'PLAW%'
        ", [$trancheId]);

        $proLawPaid = (float) $this->scalar($connector, "
            SELECT SUM(p.Amount) FROM TblEPFs AS p
            LEFT JOIN TblEnrollment AS e ON p.LLG_ID = e.LLG_ID
            WHERE p.Cleared_Date >= '2022-07-01' AND e.Tranche = ?
              AND p.Cleared_Date IS NOT NULL AND p.Returned_Date IS NULL
              AND UPPER(e.Enrollment_Plan) LIKE '%PROGRESS%'
        ", [$trancheId]);

        return [
            'Tranche' => $trancheId,
            'Sold Date' => $this->excelDate($tranche['Payment_Date']),
            'Tranche Date' => $this->excelDate($tranche['Report_Date']),
            'Sold Debt Amount' => (float) $tranche['Sold_Debt'],
            'LDR EPF Original' => $ldrOrig,
            'PLAW EPF Original' => $plawOrig,
            'Pro Law EPF Original' => $proLawOrig,
            'LDR EPF Current' => $ldrCurrent,
            'PLAW EPF Current' => $plawCurrent,
            'Pro Law EPF Current' => $proLawCurrent,
            'NGF Payments' => $ngfPayments,
            'Preferred Return' => $preferredReturn,
            'LDR EPF Paid' => $ldrPaid,
            'PLAW EPF Paid' => $plawPaid,
            'Pro Law EPF Paid' => $proLawPaid,
        ];
    }

    /**
     * VBA only sets the label + merges cells for this row — D through O are never assigned, so they're 0.
     */
    private function buildUnsoldTrancheRow(): array
    {
        return [
            'Tranche' => 'Unsold Tranche',
            'Sold Date' => null,
            'Tranche Date' => null,
            'Sold Debt Amount' => 0.0,
            'LDR EPF Original' => 0.0,
            'PLAW EPF Original' => 0.0,
            'Pro Law EPF Original' => 0.0,
            'LDR EPF Current' => 0.0,
            'PLAW EPF Current' => 0.0,
            'Pro Law EPF Current' => 0.0,
            'NGF Payments' => 0.0,
            'Preferred Return' => 0.0,
            'LDR EPF Paid' => 0.0,
            'PLAW EPF Paid' => 0.0,
            'Pro Law EPF Paid' => 0.0,
        ];
    }

    /**
     * 3 forward-projected tranche rows (next 3 months after the last real tranche's Report_Date),
     * numbered maxTranche+1, +2, +3. Mirrors VBA's `For j = 1 To 3` loop.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildFutureTrancheRows(DBConnector $connector, int $maxTranche, ?\DateTimeImmutable $lastReportDate): array
    {
        $cursor = $lastReportDate ?? new \DateTimeImmutable();
        $rows = [];

        for ($j = 1; $j <= 3; $j++) {
            $cursor = $cursor->modify('first day of next month');
            $monthStart = $cursor->format('Y-m-d');
            $monthEnd = $cursor->modify('last day of this month')->format('Y-m-d');
            $cursor = new \DateTimeImmutable($monthStart);

            $paidCoalesce = 'COALESCE(First_Payment_Date, Payment_Date_2, Payment_Date_1)';
            $clearedCoalesce = 'COALESCE(First_Payment_Cleared_Date, Payment_Date_2, Payment_Date_1)';
            $baseWhere = "
                WHERE Category IN ('LDR', 'CCS')
                  AND Enrollment_Status IN ('LDR Enrolled', 'PLAW Enrolled', 'ProLaw Enrolled')
                  AND Cancel_Date IS NULL
                  AND Debt_Sold_To IS NULL
                  AND UPPER(Enrollment_Plan) NOT LIKE '%FAMILY%'
            ";

            $debt = (float) $this->scalar($connector, "
                SELECT SUM(Debt_Amount) FROM TblEnrollment {$baseWhere}
                  AND {$paidCoalesce} >= ? AND {$paidCoalesce} <= ?
            ", [$monthStart, $monthEnd]);

            $ldrOrig = (float) $this->scalar($connector, "
                SELECT SUM(Debt_Amount * COALESCE(EPF_Rate,.25)) FROM TblEnrollment {$baseWhere}
                  AND Enrollment_Plan NOT LIKE 'PLAW%' AND UPPER(Enrollment_Plan) NOT LIKE '%PROGRESS%'
                  AND {$clearedCoalesce} >= ? AND {$clearedCoalesce} <= ?
            ", [$monthStart, $monthEnd]);

            $plawOrig = (float) $this->scalar($connector, "
                SELECT SUM(Debt_Amount * COALESCE(EPF_Rate,.25)) FROM TblEnrollment {$baseWhere}
                  AND Enrollment_Plan LIKE 'PLAW%'
                  AND {$clearedCoalesce} >= ? AND {$clearedCoalesce} <= ?
            ", [$monthStart, $monthEnd]);

            $proLawOrig = (float) $this->scalar($connector, "
                SELECT SUM(Debt_Amount * COALESCE(EPF_Rate,.25)) FROM TblEnrollment {$baseWhere}
                  AND UPPER(Enrollment_Plan) LIKE '%PROGRESS%'
                  AND {$clearedCoalesce} >= ? AND {$clearedCoalesce} <= ?
            ", [$monthStart, $monthEnd]);

            // VBA repeats "Cancel_Date IS NULL" for the Current columns (already in $baseWhere) — Current == Original.
            $ldrCurrent = (float) $this->scalar($connector, "
                SELECT SUM(Debt_Amount * COALESCE(EPF_Rate,.25)) FROM TblEnrollment {$baseWhere}
                  AND Enrollment_Plan NOT LIKE 'PLAW%' AND UPPER(Enrollment_Plan) NOT LIKE '%PROGRESS%'
                  AND {$clearedCoalesce} >= ? AND {$clearedCoalesce} <= ?
            ", [$monthStart, $monthEnd]);

            $plawCurrent = (float) $this->scalar($connector, "
                SELECT SUM(Debt_Amount * COALESCE(EPF_Rate,.25)) FROM TblEnrollment {$baseWhere}
                  AND Enrollment_Plan LIKE 'PLAW%'
                  AND {$clearedCoalesce} >= ? AND {$clearedCoalesce} <= ?
            ", [$monthStart, $monthEnd]);

            $proLawCurrent = (float) $this->scalar($connector, "
                SELECT SUM(Debt_Amount * COALESCE(EPF_Rate,.25)) FROM TblEnrollment {$baseWhere}
                  AND UPPER(Enrollment_Plan) LIKE '%PROGRESS%'
                  AND {$clearedCoalesce} >= ? AND {$clearedCoalesce} <= ?
            ", [$monthStart, $monthEnd]);

            $rows[] = [
                'Tranche' => (string) ($maxTranche + $j),
                'Sold Date' => null,
                'Tranche Date' => $cursor,
                'Sold Debt Amount' => $debt,
                'LDR EPF Original' => $ldrOrig,
                'PLAW EPF Original' => $plawOrig,
                'Pro Law EPF Original' => $proLawOrig,
                'LDR EPF Current' => $ldrCurrent,
                'PLAW EPF Current' => $plawCurrent,
                'Pro Law EPF Current' => $proLawCurrent,
                'NGF Payments' => 0.0,
                'Preferred Return' => 0.0,
                'LDR EPF Paid' => 0.0,
                'PLAW EPF Paid' => 0.0,
                'Pro Law EPF Paid' => 0.0,
            ];
        }

        return $rows;
    }

    private function fetchEpfPaidToNgf(DBConnector $connector, string|int $tranche): float
    {
        // Non-numeric "Tranche" (the Unsold Tranche row) never matches a real Tranche — 0.
        if (!is_numeric($tranche)) {
            return 0.0;
        }

        $ngfIn = implode(', ', self::EPF_NGF_IDS);

        $value = (float) $this->scalar($connector, "
            SELECT SUM(Amount) FROM TblEPFs
            WHERE LLG_ID IN (SELECT LLG_ID FROM TblEnrollment WHERE Tranche = ?)
              AND Cleared_Date IS NOT NULL
              AND Paid_To IN ({$ngfIn})
        ", [$tranche]);

        $value += (float) $this->scalar($connector, "
            SELECT SUM(Amount) FROM TblEPFDistributions
            WHERE LLG_ID IN (SELECT LLG_ID FROM TblEnrollment WHERE Tranche = ?)
              AND Cleared_Date IS NOT NULL
        ", [$tranche]);

        return $value;
    }

    /**
     * @return array<int|string, float>
     */
    private function fetchLookbackByTranche(DBConnector $connector): array
    {
        $rows = $connector->querySqlServer("
            SELECT Tranche, SUM(Sold_Debt) * 0.08 AS Lookback
            FROM TblEnrollment
            WHERE Lookback_Date IS NOT NULL AND Tranche IS NOT NULL
            GROUP BY Tranche
            ORDER BY Tranche
        ")['data'] ?? [];

        $map = [];
        foreach ($rows as $row) {
            $map[$row['Tranche']] = (float) $row['Lookback'];
        }

        return $map;
    }

    /**
     * Columns P-W. Applies to every row.
     */
    private function applyEpfColumns(array $row): array
    {
        $row['LDR EPF Remaining'] = $row['LDR EPF Current'] - $row['LDR EPF Paid'];
        $row['PLAW EPF Remaining'] = $row['PLAW EPF Current'] - $row['PLAW EPF Paid'];
        $row['Pro Law EPF Remaining'] = $row['Pro Law EPF Current'] - $row['Pro Law EPF Paid'];

        $row['Total EPF Original'] = $row['LDR EPF Original'] + $row['PLAW EPF Original'] + $row['Pro Law EPF Original'];
        $row['Total EPF Current'] = $row['LDR EPF Current'] + $row['PLAW EPF Current'] + $row['Pro Law EPF Current'];
        $row['Total EPF Paid'] = $row['LDR EPF Paid'] + $row['PLAW EPF Paid'] + $row['Pro Law EPF Paid'];
        $row['Total EPF Remaining'] = $row['LDR EPF Remaining'] + $row['PLAW EPF Remaining'] + $row['Pro Law EPF Remaining'];

        $row['LDR EPF Earned (15%)'] = $row['Total EPF Paid'] * 0.15;

        return $row;
    }

    /**
     * Columns Y-AH (Preferred Return waterfall). Depends on X (EPF Paid To NGF), applied separately first.
     */
    private function applyPreferredReturnColumns(array $row): array
    {
        $epfPaidToNgf = $row['EPF Paid To NGF'];

        $row['Preferred Return Paid'] = min($row['Preferred Return'], $epfPaidToNgf);
        $row['Preferred Return Remaining'] = max($row['Preferred Return'] - $epfPaidToNgf, 0);
        $row['EPF Needed to Cap Preferred Return'] = $row['Preferred Return Remaining'] / 0.85;

        $row['EPF LDR (15%)'] = $row['EPF Needed to Cap Preferred Return'] * 0.15;
        $row['EPF LDR (85%)'] = ($row['Total EPF Remaining'] - $row['EPF Needed to Cap Preferred Return']) * 0.85;
        $row['PLAW EPF Paid 5%'] = $row['PLAW EPF Paid'] * 0.05;
        $row['Pro Law EPF Paid 3%'] = $row['Pro Law EPF Paid'] * 0.03;
        $row['PLAW EPF Projection'] = -$row['PLAW EPF Remaining'] * 0.05;
        $row['Pro Law EPF Projection'] = -$row['Pro Law EPF Remaining'] * 0.03;
        $row['LDR Net'] = $row['EPF LDR (15%)'] + $row['EPF LDR (85%)'] + $row['PLAW EPF Projection'] + $row['Pro Law EPF Projection'];

        return $row;
    }

    /**
     * Columns AI-AM. Excluded from the last (3rd) future-projection row.
     */
    private function applyReturnColumns(array $row, float $lookback): array
    {
        $row['Collection Rate'] = $row['Total EPF Original'] != 0.0 ? $row['Total EPF Current'] / $row['Total EPF Original'] : 0.0;

        $ngfPayments = $row['NGF Payments'];
        $ngfTotal = $row['Preferred Return'] + (($row['Total EPF Current'] - ($row['Preferred Return'] / 0.85)) * 0.15);
        $row['NGF Total'] = $ngfTotal;
        $row['Annualized Return Initial'] = $ngfPayments != 0.0 ? (($ngfTotal / $ngfPayments) ** 0.25) - 1 : 0.0;

        $row['Lookback'] = $lookback;
        $denominator = $ngfPayments - $lookback;
        $row['Annualized Return with Lookback'] = $denominator != 0.0 ? (($ngfTotal / $denominator) ** 0.25) - 1 : 0.0;

        return $row;
    }

    private function excelDate(mixed $value): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeImmutable) {
            return $value;
        }

        if (is_numeric($value)) {
            $timestamp = mktime(0, 0, 0, 12, 30, 1899) + ((int) $value * 86400);
            return (new \DateTimeImmutable())->setTimestamp($timestamp);
        }

        return new \DateTimeImmutable(substr((string) $value, 0, 10));
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
}
