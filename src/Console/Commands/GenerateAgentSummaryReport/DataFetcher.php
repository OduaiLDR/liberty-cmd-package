<?php

namespace Cmd\Reports\Console\Commands\GenerateAgentSummaryReport;

use Cmd\Reports\Services\DBConnector;

/**
 * Agent Summary metric definitions (Aug 2026 rewrite).
 *
 * Metrics are driven off TblContacts.Stage / TblContacts.Status — NOT enrollment
 * dates as before. Stage is the clean rollup of the (80+) noisy Status values:
 * e.g. every "Dropped / Cancelled", "System Cancel (…)" and "ProLaw Dropped/Cancelled"
 * status sums exactly to Stage='Cancel', so counting on Stage cannot silently miss rows.
 *
 * Definitions (confirmed with the reporting team):
 *   Total Contacts : contacts assigned in the period, excluding EXCLUDED_STATUSES
 *   Total Deals    : Stage = 'Client'  OR Status IN (DEAL_STATUSES)
 *   Conversion     : Deals / Total Contacts
 *   Total Debt     : SUM(TblEnrollment.Debt_Amount) over the Deals set
 *   Total Cancels  : Stage = 'Cancel'
 *   Cancel %       : Cancels / (Deals + Cancels)
 *   Total NSFs     : Stage = 'NSF'
 *   NSF %          : NSFs / (Deals + NSFs)
 *
 * Two intentional choices, isolated as constants so they are trivial to change:
 *   - DEALS include in-process Client-stage contacts (Contract Sent, Paused/Hold, …),
 *     because Stage='Client' is the business's "deal" marker. Switch to enrolled-only
 *     by emptying DEAL_STAGES and listing the enrolled statuses in DEAL_STATUSES.
 *   - NSFs use Stage='NSF' only (220 clean rows). We deliberately do NOT also match
 *     Status LIKE '%NSF%', because those (e.g. 'System Cancel (NSF-3)',
 *     'LDR Enrolled (NSF-1)') belong to the Cancel/Client stages and would double-count.
 */
class DataFetcher
{
    public const EXCLUDED_AGENTS = [
        'Dummy User',
        'Debt PayPro',
        'Jasmine Scott',
        'Liam Anderson',
        'Tyler Wevik',
    ];

    /** Statuses removed from Total Contacts (the conversion denominator). */
    public const EXCLUDED_STATUSES = [
        'Rejected (Not Qualified DS)',
        'No Credit Ran',
        'Funded',
    ];

    /** A contact counts as a Deal when its Stage is one of these … */
    public const DEAL_STAGES = ['Client'];

    /** … OR its Status is one of these (safety net for enrolled contacts whose Stage lags). */
    public const DEAL_STATUSES = ['ProLaw Enrolled', 'LDR Enrolled'];

    /** A contact counts as a Cancel when its Stage is one of these. */
    public const CANCEL_STAGES = ['Cancel'];

    /** A contact counts as an NSF when its Stage is one of these. */
    public const NSF_STAGES = ['NSF'];

    public const DATA_SOURCE_FILTERS = [
        'All Data Sources' => null,
        'LT Sales'         => '%LT-SALES%',
        'Apply Online'     => '%APPLY-ONLINE%',
        'LT Call Center'   => '%LT-CALL-CENTER%',
    ];

    public function fetchAgentMetrics(
        DBConnector $sql,
        string $startDate,
        string $endDate,
        string $dataSource
    ): array {
        $endExclusive = date('Y-m-d', strtotime($endDate . ' +1 day'));
        $baseCriteria = $this->buildBaseCriteria($dataSource);

        $counts = $this->fetchCounts($sql, $startDate, $endExclusive, $baseCriteria);
        $debt   = $this->fetchDebt($sql, $startDate, $endExclusive, $baseCriteria);
        $smp    = $this->fetchSameMonthPay($sql, $startDate, $endExclusive, $baseCriteria, $endDate);

        $terminated = $this->fetchTerminatedAgentNames($sql);

        $rows = [];
        foreach ($counts as $agent => $c) {
            if ($agent === '' || \in_array($agent, $terminated, true)) {
                continue;
            }

            $contacts = (int) $c['contacts'];
            $deals    = (int) $c['deals'];
            $cancels  = (int) $c['cancels'];
            $nsfs     = (int) $c['nsfs'];
            $debtSum  = (float) ($debt[$agent] ?? 0);

            $rows[] = [
                'agent'               => $agent,
                'contacts'            => $contacts,
                'deals_current'       => $deals,
                'conversion_current'  => $contacts > 0 ? $deals / $contacts : 0,
                'debt_current'        => $debtSum,
                'avg_debt_current'    => $deals > 0 ? $debtSum / $deals : 0,
                'cancels_current'     => $cancels,
                'nsfs_current'        => $nsfs,
                // Denominators per spec: share of decided outcomes, not of all contacts.
                'cancels_pct_current' => ($deals + $cancels) > 0 ? $cancels / ($deals + $cancels) : 0,
                'nsfs_pct_current'    => ($deals + $nsfs) > 0 ? $nsfs / ($deals + $nsfs) : 0,
                'smp_current'         => (int) ($smp[$agent] ?? 0),
            ];
        }

        usort($rows, fn($a, $b) => $b['contacts'] <=> $a['contacts']);
        return $rows;
    }

    /**
     * One pass over the period's contacts, computing every count with conditional
     * aggregation. No enrollment join here, so multi-enrollment contacts can't inflate counts.
     */
    private function fetchCounts(
        DBConnector $sql,
        string $startDate,
        string $endExclusive,
        string $baseCriteria
    ): array {
        $excludedStatuses = $this->quoteList(self::EXCLUDED_STATUSES);
        $dealStages       = $this->quoteList(self::DEAL_STAGES);
        $dealStatuses     = $this->quoteList(self::DEAL_STATUSES);
        $cancelStages     = $this->quoteList(self::CANCEL_STAGES);
        $nsfStages        = $this->quoteList(self::NSF_STAGES);
        $startEsc = $this->esc($startDate);
        $endEsc   = $this->esc($endExclusive);

        $query = "
            SELECT
                c.Agent AS agent,
                SUM(CASE WHEN (c.Status IS NULL OR c.Status NOT IN ({$excludedStatuses})) THEN 1 ELSE 0 END) AS contacts,
                SUM(CASE WHEN (c.Stage IN ({$dealStages}) OR c.Status IN ({$dealStatuses})) THEN 1 ELSE 0 END) AS deals,
                SUM(CASE WHEN c.Stage IN ({$cancelStages}) THEN 1 ELSE 0 END) AS cancels,
                SUM(CASE WHEN c.Stage IN ({$nsfStages}) THEN 1 ELSE 0 END) AS nsfs
            FROM TblContacts AS c
            WHERE COALESCE(c.Assigned_Date, c.Created_Date) >= '{$startEsc}'
              AND COALESCE(c.Assigned_Date, c.Created_Date) < '{$endEsc}'
              {$baseCriteria}
            GROUP BY c.Agent
        ";

        $result = $sql->querySqlServer($query);
        $rows = $result['data'] ?? [];

        $out = [];
        foreach ($rows as $row) {
            $agent = (string) ($row['agent'] ?? '');
            if ($agent === '') {
                continue;
            }
            $out[$agent] = [
                'contacts' => $row['contacts'] ?? 0,
                'deals'    => $row['deals'] ?? 0,
                'cancels'  => $row['cancels'] ?? 0,
                'nsfs'     => $row['nsfs'] ?? 0,
            ];
        }
        return $out;
    }

    /** SUM of enrolled debt (TblEnrollment.Debt_Amount) over the Deals set. */
    private function fetchDebt(
        DBConnector $sql,
        string $startDate,
        string $endExclusive,
        string $baseCriteria
    ): array {
        $dealStages   = $this->quoteList(self::DEAL_STAGES);
        $dealStatuses = $this->quoteList(self::DEAL_STATUSES);
        $startEsc = $this->esc($startDate);
        $endEsc   = $this->esc($endExclusive);

        $query = "
            SELECT c.Agent AS agent, SUM(e.Debt_Amount) AS value
            FROM TblContacts AS c
            JOIN TblEnrollment AS e ON c.LLG_ID = e.LLG_ID
            WHERE COALESCE(c.Assigned_Date, c.Created_Date) >= '{$startEsc}'
              AND COALESCE(c.Assigned_Date, c.Created_Date) < '{$endEsc}'
              {$baseCriteria}
              AND (c.Stage IN ({$dealStages}) OR c.Status IN ({$dealStatuses}))
            GROUP BY c.Agent
        ";

        return $this->keyByAgent($sql->querySqlServer($query));
    }

    /**
     * Same Month Pay — unchanged from the prior report: enrolled contacts whose first
     * payment cleared within the period and are not cancelled/NSF as of period end.
     */
    private function fetchSameMonthPay(
        DBConnector $sql,
        string $startDate,
        string $endExclusive,
        string $baseCriteria,
        string $endDate
    ): array {
        $startEsc = $this->esc($startDate);
        $endEsc   = $this->esc($endExclusive);
        $endDateEsc = $this->esc($endDate);

        $query = "
            SELECT c.Agent AS agent, COUNT(*) AS value
            FROM TblContacts AS c
            JOIN TblEnrollment AS e ON c.LLG_ID = e.LLG_ID
            WHERE COALESCE(c.Assigned_Date, c.Created_Date) >= '{$startEsc}'
              AND COALESCE(c.Assigned_Date, c.Created_Date) < '{$endEsc}'
              {$baseCriteria}
              AND e.Submitted_Date <= '{$endDateEsc}'
              AND COALESCE(e.First_Payment_Date, e.Payment_Date_2, e.Payment_Date_1) <= '{$endDateEsc}'
              AND (e.Cancel_Date IS NULL OR e.Cancel_Date > '{$endDateEsc}')
              AND (e.NSF_Date IS NULL OR e.NSF_Date > '{$endDateEsc}')
              AND e.First_Payment_Cleared_Date IS NOT NULL
            GROUP BY c.Agent
        ";

        return $this->keyByAgent($sql->querySqlServer($query));
    }

    /**
     * Base filters shared by every metric query: active Agent, no EC Loan Leads,
     * and the data-source filter. Status/Stage conditions are applied per-metric, NOT here.
     */
    private function buildBaseCriteria(string $dataSource): string
    {
        $excludedAgents = $this->quoteList(self::EXCLUDED_AGENTS);

        $criteria = "
            AND c.Agent IN (
                SELECT Employee_Name FROM TblEmployees
                WHERE Access_Level = 'Agent'
                  AND Employee_Name NOT IN ({$excludedAgents})
            )
            AND c.Data_Source NOT LIKE 'EC Loan Leads%'
        ";

        $filter = self::DATA_SOURCE_FILTERS[$dataSource] ?? null;
        if ($filter !== null) {
            $filterEsc = $this->esc($filter);
            $criteria .= " AND UPPER(c.Data_Source) LIKE '{$filterEsc}' ";
        }

        return $criteria;
    }

    private function fetchTerminatedAgentNames(DBConnector $sql): array
    {
        $result = $sql->querySqlServer("
            SELECT Employee_Name AS name
            FROM TblEmployees
            WHERE Term_Date IS NOT NULL
        ");
        $rows = $result['data'] ?? [];
        $names = [];
        foreach ($rows as $row) {
            $name = (string) ($row['name'] ?? '');
            if ($name !== '') {
                $names[] = $name;
            }
        }
        return $names;
    }

    private function keyByAgent($result): array
    {
        $rows = $result['data'] ?? [];
        $out = [];
        foreach ($rows as $row) {
            $agent = (string) ($row['agent'] ?? '');
            if ($agent === '') {
                continue;
            }
            $out[$agent] = $row['value'] ?? 0;
        }
        return $out;
    }

    private function quoteList(array $items): string
    {
        $escaped = array_map(fn($v) => "'" . $this->esc((string) $v) . "'", $items);
        return implode(', ', $escaped);
    }

    private function esc(string $value): string
    {
        return str_replace("'", "''", $value);
    }
}
