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

        // The roster is AUTHORITATIVE when it has rows for this report/source: it decides who is
        // on the report, and it must be able to take someone OFF. This used to UNION with the
        // built-in list so a stale mirror could never shrink a report — but a union can only ever
        // add, so removals never took effect. Anthony Clark is a manager and was taken off the NSF
        // roster; he kept appearing on both NSF reports because he is also in the built-in list.
        //
        // The safety net stays where it belongs: an empty or unreachable roster means the mirror is
        // broken, not that nobody gets paid, so that case falls back and says so loudly.
        $agents = self::fromRoster($sql, $reportType, $source);

        if ($agents === null) {
            Log::warning(
                "CommissionRosterProvider: roster for {$reportType}/{$source} is EMPTY or unreachable — falling back to "
                . 'the built-in agent list. The Commission Review roster is not reaching Azure (dbo.' . self::TABLE
                . '); check the payroll-review roster mirror before trusting this report.'
            );
            return $fallback;
        }

        $dropped = array_values(array_diff(
            array_map([self::class, 'nameKey'], self::mergeUnique($fallback, [])),
            array_map([self::class, 'nameKey'], $agents)
        ));
        Log::info(
            'CommissionRosterProvider: ' . $reportType . '/' . $source . ' - ' . count($agents)
            . ' agents from the roster (built-in list has ' . count($fallback) . '; '
            . count($dropped) . ' built-in name(s) not on the roster and therefore excluded'
            . ($dropped === [] ? '' : ': ' . implode(', ', $dropped)) . ').'
        );

        return $agents;
    }

    /**
     * The roster names for one report/source, or NULL when the roster is unreachable or has no
     * rows at all.
     *
     * Callers that need to TELL THOSE APART use this instead of agents(). Retention Bonus is the
     * case in point: it has no built-in agent list to fall back to, so "the roster says nobody"
     * and "the roster is broken" would otherwise both render as an empty report. Returning null
     * lets it keep its current CRM-derived behaviour and warn, instead of emailing a blank summary.
     *
     * @param string $reportType 'nsf' | 'retention'
     * @param string $source     'ldr' | 'plaw'
     * @return array<int,string>|null
     */
    public static function fromRoster(DBConnector $sql, string $reportType, string $source): ?array
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
                Log::info(
                    "CommissionRosterProvider: roster query failed for {$reportType}/{$source}.",
                    ['error' => $res['error'] ?? '']
                );
                return null;
            }

            $agents = [];
            foreach ($res['data'] ?? [] as $row) {
                $name = trim((string) ($row['Agent'] ?? $row['agent'] ?? ''));
                if ($name !== '') {
                    $agents[] = $name;
                }
            }
            $agents = self::mergeUnique($agents, []);

            return $agents === [] ? null : $agents;
        } catch (\Throwable $e) {
            Log::warning('CommissionRosterProvider: lookup failed.', ['ex' => $e->getMessage()]);
            return null;
        }
    }

    // NOTE: a rosterSources()/sourceFor() pair briefly lived here to judge the company-mismatch
    // flag against each agent's own roster source, so that anyone rostered to "both" never flagged.
    // Removed on 2026-09-04 — it silenced Jacob's own examples (Katherine Caceres with a Progress
    // Law company on the LDR report, Lucas Wright the other way round; both are rostered "both").
    // The flag is judged against the report/page brand instead. Do not reintroduce it without
    // checking those two cases still show.

    /**
     * True when $name is on $roster, comparing case- and space-insensitively.
     *
     * @param array<int,string> $roster
     */
    public static function isOnRoster(array $roster, string $name): bool
    {
        $key = self::nameKey($name);
        if ($key === '') {
            return false;
        }
        foreach ($roster as $rosterName) {
            if (self::nameKey((string) $rosterName) === $key) {
                return true;
            }
        }

        return false;
    }

    /**
     * Case/space-insensitive union that keeps the first-seen spelling.
     *
     * @param array<int,string> $primary   Names that win on spelling (built-in list).
     * @param array<int,string> $secondary Additional names (the Azure roster mirror).
     * @return array<int,string>
     */
    private static function mergeUnique(array $primary, array $secondary): array
    {
        $out = [];
        $seen = [];
        foreach ([$primary, $secondary] as $list) {
            foreach ($list as $name) {
                $clean = trim((string) $name);
                if ($clean === '') {
                    continue;
                }
                $key = self::nameKey($clean);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $out[] = $clean;
            }
        }
        sort($out, SORT_NATURAL | SORT_FLAG_CASE);

        return $out;
    }

    /**
     * Comparison key for an agent name. The roster and the built-in lists are maintained by
     * different people and disagree on casing and spacing, so every name comparison in this
     * class goes through here.
     */
    private static function nameKey(string $name): string
    {
        return strtolower(trim((string) preg_replace('/\s+/', ' ', trim($name))));
    }
}
