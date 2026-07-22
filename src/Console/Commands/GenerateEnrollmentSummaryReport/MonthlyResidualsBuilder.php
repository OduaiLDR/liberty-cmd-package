<?php

namespace Cmd\Reports\Console\Commands\GenerateEnrollmentSummaryReport;

use Cmd\Reports\Services\DBConnector;

/**
 * Rebuilds the "Monthly Residuals" footer on the Capital Report tab (Legal Protection sub-table:
 * Veritas Legal / CDP Legal / Progress Law Legal), mirroring the tail of VBA Sub GenerateCapitalReport
 * (ModReportsCompany.bas).
 */
class MonthlyResidualsBuilder
{
    /**
     * @return array<int, array{label: string, contacts: int, fee: float, residual: float, projection: float, bold: bool}>
     */
    public function build(DBConnector $connector, string $snapshotDate): array
    {
        // VBA: DateSerial(Year(Date), Month(Date) - 2, 1) — first day of the month 2 months before today.
        $cutoff = (new \DateTimeImmutable($snapshotDate))->modify('first day of this month')->modify('-2 months')->format('Y-m-d');

        $rows = [
            $this->buildRow($connector, 'Veritas Legal', "
                SELECT e.LLG_ID, e.Program_Length, e.Payments
                FROM TblEnrollment AS e
                LEFT JOIN TblVeritasEnrollments AS v ON e.LLG_ID = v.LLG_ID
                WHERE e.Cancel_Date IS NULL
                  AND v.Company = 'Lending Tower'
                  AND e.Payments < e.Program_Length
                  AND e.Enrollment_Status LIKE '%Enrolled%'
                  AND v.Payment_Date > ?
                ORDER BY e.LLG_ID
            ", 22.5, $cutoff),
            $this->buildRow($connector, 'CDP Legal', "
                SELECT e.LLG_ID, e.Program_Length, e.Payments
                FROM TblEnrollment AS e
                LEFT JOIN TblVeritasEnrollments AS v ON e.LLG_ID = v.LLG_ID
                WHERE e.Cancel_Date IS NULL
                  AND v.Company = 'Progress Law, LLC'
                  AND e.Payments < e.Program_Length
                  AND e.Enrollment_Status LIKE '%Enrolled%'
                  AND v.Payment_Date > ?
                ORDER BY e.LLG_ID
            ", 22.5, $cutoff),
            // VBA's "Projection" formula reuses the 22.5 rate here too (not 24.5) — replicated as-is.
            $this->buildRow($connector, 'Progress Law Legal', "
                SELECT e.LLG_ID, e.Program_Length, e.Payments
                FROM TblEnrollment AS e
                LEFT JOIN TblProgressLawEnrollments AS p ON e.Client = p.Client
                WHERE e.Cancel_Date IS NULL
                  AND p.Company = 'Lending Tower'
                  AND e.Payments < e.Program_Length
                  AND e.Enrollment_Status LIKE '%Enrolled%'
                  AND p.Payment_Date > ?
                ORDER BY e.LLG_ID
            ", 24.5, $cutoff),
        ];

        $totalContacts = array_sum(array_column($rows, 'contacts'));
        $totalResidual = array_sum(array_column($rows, 'residual'));
        $totalProjection = array_sum(array_column($rows, 'projection'));

        $rows[] = [
            'label' => 'Legal Protection Total',
            'contacts' => $totalContacts,
            'fee' => null,
            'residual' => $totalResidual,
            'projection' => $totalProjection,
            'bold' => true,
        ];

        return $rows;
    }

    private function buildRow(DBConnector $connector, string $label, string $sql, float $feeRate, string $cutoff): array
    {
        $result = $connector->querySqlServer($sql, [$cutoff])['data'] ?? [];

        // VBA: `IF(A2<>A1,1,0)` on LLG_ID-ordered results — collapses consecutive duplicate LLG_IDs,
        // keeping only the first occurrence per contact.
        $seen = [];
        $distinctCount = 0;
        $remainingSum = 0.0;
        foreach ($result as $row) {
            $llgId = $row['LLG_ID'] ?? null;
            if ($llgId === null || isset($seen[$llgId])) {
                continue;
            }
            $seen[$llgId] = true;
            $distinctCount++;
            $remainingSum += (float) ($row['Program_Length'] ?? 0) - (float) ($row['Payments'] ?? 0);
        }

        $residual = $distinctCount * $feeRate;
        $projection = $remainingSum * 22.5;

        return [
            'label' => $label,
            'contacts' => $distinctCount,
            'fee' => $feeRate,
            'residual' => $residual,
            'projection' => $projection,
            'bold' => false,
        ];
    }
}
