<?php

namespace Cmd\Reports\Services;

use Illuminate\Support\Facades\Log;

/**
 * Reads the NSF / Retention roster that Rama manages in the Commission Review app.
 *
 * The app (CMD_PAYROLL_REVIEW lambda) mirrors its roster into Azure SQL
 * (CommissionDatabase.dbo.TblCommissionRoster) on every roster change, so the generators can read it
 * with the SQL Server connection they already hold — no Aurora credentials and no extra HTTP hop.
 *
 * SAFETY: these are payroll reports. If the roster table is missing, unreachable, or has no rows for
 * this report/source, we fall back to the command's hard-coded agent list and log it. A roster
 * problem must never silently empty a payroll report.
 *
 * Source semantics: rows are tagged 'ldr', 'plaw' or 'both'; a report for a given source gets the
 * rows for that source plus the 'both' rows (some agents legitimately appear on both reports).
 */
class CommissionRosterProvider
{
    private const TABLE = 'TblCommissionRoster';

    /**
     * @param DBConnector $sql        A SQL Server-connected DBConnector.
     * @param string      $reportType 'nsf' | 'retention'
     * @param string      $source     'ldr' | 'plaw'
     * @param array       $fallback   The command's hard-coded agent list.
     * @return array Agent names to include on the report.
     */
    public static function agents(DBConnector $sql, string $reportType, string $source, array $fallback): array
    {
        $reportType = strtolower(trim($reportType));
        $source     = strtolower(trim($source));

        try {
            $res = $sql->querySqlServer(
                'SELECT Agent FROM dbo.' . self::TABLE . "
                 WHERE Report_Type = ? AND (Source = ? OR Source = 'both')
                 ORDER BY Agent",
                [$reportType, $source]
            );

            if (($res['success'] ?? false) !== true) {
                Log::info("CommissionRosterProvider: roster unavailable for {$reportType}/{$source} — using the built-in agent list.", ['error' => $res['error'] ?? '']);
                return $fallback;
            }

            $agents = [];
            foreach ($res['data'] ?? [] as $row) {
                $name = trim((string) ($row['Agent'] ?? $row['agent'] ?? ''));
                if ($name !== '') {
                    $agents[] = $name;
                }
            }
            $agents = array_values(array_unique($agents));

            if (empty($agents)) {
                Log::info("CommissionRosterProvider: roster empty for {$reportType}/{$source} — using the built-in agent list.");
                return $fallback;
            }

            Log::info('CommissionRosterProvider: using the managed roster for ' . $reportType . '/' . $source . ' (' . count($agents) . ' agents; built-in list has ' . count($fallback) . ').');

            return $agents;
        } catch (\Throwable $e) {
            Log::warning('CommissionRosterProvider: lookup failed — using the built-in agent list.', ['ex' => $e->getMessage()]);
            return $fallback;
        }
    }
}
