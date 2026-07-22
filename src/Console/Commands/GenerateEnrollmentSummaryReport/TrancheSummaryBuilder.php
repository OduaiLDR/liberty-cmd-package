<?php

namespace Cmd\Reports\Console\Commands\GenerateEnrollmentSummaryReport;

use Cmd\Reports\Services\DBConnector;

/**
 * Rebuilds the "Tranche Summary" tab, mirroring VBA Sub GenerateTrancheSummaryReport
 * (ModReports.bas, Commission Management Database workbook).
 */
class TrancheSummaryBuilder
{
    private const EPF_NGF_IDS = [31213, 35285, 36678, 34684, 36503, 36790];

    /**
     * @return array<int, array<string, mixed>>
     */
    public function build(DBConnector $connector): array
    {
        $tranches = $this->scalarRows($connector, "
            SELECT Tranche, Payment_Date, Report_Date, Total_Debt, Payment, Flip_Date
            FROM TblDebtTrancheSales
            ORDER BY Tranche ASC
        ");

        $rows = [];
        foreach ($tranches as $tranche) {
            $trancheId = $tranche['Tranche'];

            $ldrContacts = (int) $this->scalar($connector, "
                SELECT COUNT(*) FROM TblEnrollment
                WHERE Tranche = ? AND Enrollment_Plan NOT LIKE 'PLAW%' AND UPPER(Enrollment_Plan) NOT LIKE '%PROGRESS%'
            ", [$trancheId]);

            $plawContacts = (int) $this->scalar($connector, "
                SELECT COUNT(*) FROM TblEnrollment WHERE Tranche = ? AND Enrollment_Plan LIKE 'PLAW%'
            ", [$trancheId]);

            $proLawContacts = (int) $this->scalar($connector, "
                SELECT COUNT(*) FROM TblEnrollment WHERE Tranche = ? AND UPPER(Enrollment_Plan) LIKE '%PROGRESS%'
            ", [$trancheId]);

            $totalContacts = (int) $this->scalar($connector, "
                SELECT COUNT(*) FROM TblEnrollment WHERE Tranche = ?
            ", [$trancheId]);

            $paidToLdr = (float) $tranche['Payment'];

            $lookbackDebt = (float) $this->scalar($connector, "
                SELECT SUM(Sold_Debt) FROM TblEnrollment WHERE Tranche = ? AND Lookback_Date IS NOT NULL
            ", [$trancheId]);
            $lookbackRepayment = round($lookbackDebt * 0.08, 2);

            $epfProjectionOriginal = (float) $this->scalar($connector, "
                SELECT SUM(Sold_Debt * EPF_Rate) FROM TblEnrollment WHERE Tranche = ?
            ", [$trancheId]);

            $epfProjectionCurrent = (float) $this->scalar($connector, "
                SELECT SUM(Sold_Debt * EPF_Rate) FROM TblEnrollment
                WHERE Tranche = ? AND Lookback_Date IS NULL AND Cancel_Date IS NULL
            ", [$trancheId]);

            $preferredReturn = round($paidToLdr * 1.1, 2);

            $ngfIn = implode(', ', self::EPF_NGF_IDS);

            // VBA uses `LLG_ID IN (SELECT LLG_ID FROM TblEnrollment WHERE Tranche = X)` (a semi-join) —
            // must stay a subquery, not a JOIN, or a contact with multiple enrollment rows for the same
            // tranche (e.g. a re-enrollment) would double-count their EPF payments.
            $epfFromDpp = (float) $this->scalar($connector, "
                SELECT SUM(Amount) FROM TblEPFs
                WHERE LLG_ID IN (SELECT LLG_ID FROM TblEnrollment WHERE Tranche = ?)
                  AND Cleared_Date IS NOT NULL
                  AND Paid_To IN ({$ngfIn})
            ", [$trancheId]);

            $epfFromLdr = (float) $this->scalar($connector, "
                SELECT SUM(Amount) FROM TblEPFDistributions
                WHERE Tranche = ? AND Reimbursement_Date IS NULL
            ", [$trancheId]);

            $epfPaidTotal = $epfFromDpp + $epfFromLdr;

            if ($epfPaidTotal < $preferredReturn) {
                $epfPaidPreferredReturn = $epfPaidTotal;
                $epfPaidStandardReturn = 0.0;
            } else {
                $epfPaidPreferredReturn = $preferredReturn;
                $epfPaidStandardReturn = $epfPaidTotal - $preferredReturn;
            }
            $preferredReturnBalance = max($preferredReturn - $epfPaidTotal, 0);
            $percentAchieved = $preferredReturn != 0.0 ? $epfPaidPreferredReturn / $preferredReturn : 0.0;

            $rows[] = [
                'Tranche' => $trancheId,
                'Sold Date' => $this->excelDate($tranche['Payment_Date']),
                'Tranch Date' => $this->excelDate($tranche['Report_Date']),
                'Enrolled Debt' => (float) $tranche['Total_Debt'],
                'LDR Contacts' => $ldrContacts,
                'PLAW Clients' => $plawContacts,
                'ProLaw Clients' => $proLawContacts,
                'Total Contacts' => $totalContacts,
                'Paid to LDR' => $paidToLdr,
                'Lookback Debt' => $lookbackDebt,
                'Lookback Repayment' => $lookbackRepayment,
                'EPF Projection Original' => $epfProjectionOriginal,
                'EPF Projection Current' => $epfProjectionCurrent,
                'Preferred Return' => $preferredReturn,
                'EPF Paid From DPP' => $epfFromDpp,
                'EPF Paid From LDR' => $epfFromLdr,
                'EPF Paid Total' => $epfPaidTotal,
                'EPF Paid Preferred Return' => $epfPaidPreferredReturn,
                'Preferred Return Balance' => $preferredReturnBalance,
                'EPF Paid Standard Return' => $epfPaidStandardReturn,
                'Percent of Preferred Return Achieved' => $percentAchieved,
                'Flip Date' => $this->excelDate($tranche['Flip_Date']),
            ];
        }

        return $rows;
    }

    private function excelDate(mixed $value): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        // TblDebtTrancheSales dates may come back as Excel serials or date strings depending on driver.
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

    /**
     * @return array<int, array<string, mixed>>
     */
    private function scalarRows(DBConnector $connector, string $sql, array $params = []): array
    {
        return $connector->querySqlServer($sql, $params)['data'] ?? [];
    }
}
