<?php

namespace Cmd\Reports\Console\Commands\GenerateAgentSummaryReport;

use Cmd\Reports\Services\DBConnector;

/**
 * Agent Summary metric definitions — the "EOM" (as-of-end-of-month) snapshot from the
 * original macro. Every enrollment metric shares one base: a contact assigned in the
 * period whose enrollment was Submitted_Date <= period-end. From there:
 *
 *   Total Contacts : contacts assigned in the period, excluding EXCLUDED_STATUSES
 *   Total Deals    : submitted, and NOT cancelled/NSF as of period-end
 *                    (Cancel_Date/NSF_Date NULL or > period-end)
 *   Total Debt     : SUM(Debt_Amount) over the Deals set
 *   Total Cancels  : submitted, and Cancel_Date <= period-end
 *   Total NSFs     : submitted, and NSF_Date <= period-end
 *   Conversion     : Deals / Contacts
 *   Cancel %       : Cancels / (Deals + Cancels)
 *   NSF %          : NSFs / (Deals + NSFs)
 *
 * Deals/Debt/Cancels/NSFs need the TblEnrollment join, so this report must run AFTER the
 * nightly Sync:contacts-data matching links TblContacts.LLG_ID to TblEnrollment.
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

        $contacts = $this->fetchContacts($sql, $startDate, $endExclusive, $baseCriteria);
        $eom      = $this->fetchEomMetrics($sql, $startDate, $endExclusive, $baseCriteria, $endDate);
        $smp      = $this->fetchSameMonthPay($sql, $startDate, $endExclusive, $baseCriteria, $endDate);

        $terminated = $this->fetchTerminatedAgentNames($sql);

        $rows = [];
        foreach ($contacts as $agent => $contactsCount) {
            if ($agent === '' || \in_array($agent, $terminated, true)) {
                continue;
            }

            $contactsN = (int) $contactsCount;
            $m         = $eom[$agent] ?? ['deals' => 0, 'debt' => 0, 'cancels' => 0, 'nsfs' => 0];
            $deals     = (int) $m['deals'];
            $cancels   = (int) $m['cancels'];
            $nsfs      = (int) $m['nsfs'];
            $debtSum   = (float) $m['debt'];

            $rows[] = [
                'agent'               => $agent,
                'contacts'            => $contactsN,
                'deals_current'       => $deals,
                'conversion_current'  => $contactsN > 0 ? $deals / $contactsN : 0,
                'debt_current'        => $debtSum,
                'avg_debt_current'    => $deals > 0 ? $debtSum / $deals : 0,
                'cancels_current'     => $cancels,
                'nsfs_current'        => $nsfs,
                // Share of decided outcomes, not of all contacts.
                'cancels_pct_current' => ($deals + $cancels) > 0 ? $cancels / ($deals + $cancels) : 0,
                'nsfs_pct_current'    => ($deals + $nsfs) > 0 ? $nsfs / ($deals + $nsfs) : 0,
                'smp_current'         => (int) ($smp[$agent] ?? 0),
            ];
        }

        usort($rows, fn($a, $b) => $b['contacts'] <=> $a['contacts']);
        return $rows;
    }

    /** Total Contacts — assigned in the period, minus the excluded statuses. No join. */
    private function fetchContacts(
        DBConnector $sql,
        string $startDate,
        string $endExclusive,
        string $baseCriteria
    ): array {
        $excludedStatuses = $this->quoteList(self::EXCLUDED_STATUSES);
        $startEsc = $this->esc($startDate);
        $endEsc   = $this->esc($endExclusive);

        $query = "
            SELECT c.Agent AS agent,
                SUM(CASE WHEN (c.Status IS NULL OR c.Status NOT IN ({$excludedStatuses})) THEN 1 ELSE 0 END) AS value
            FROM TblContacts AS c
            WHERE COALESCE(c.Assigned_Date, c.Created_Date) >= '{$startEsc}'
              AND COALESCE(c.Assigned_Date, c.Created_Date) < '{$endEsc}'
              {$baseCriteria}
            GROUP BY c.Agent
        ";

        return $this->keyByAgent($sql->querySqlServer($query));
    }

    /**
     * Deals / Debt / Cancels / NSFs — all on the EOM basis, one query. Shared base:
     * assigned in period + Submitted_Date <= period-end. Then split by the cancel/NSF dates.
     */
    private function fetchEomMetrics(
        DBConnector $sql,
        string $startDate,
        string $endExclusive,
        string $baseCriteria,
        string $endDate
    ): array {
        $startEsc   = $this->esc($startDate);
        $endEsc     = $this->esc($endExclusive);
        $endDateEsc = $this->esc($endDate);

        $notCancelledOrNsf =
            "(e.Cancel_Date IS NULL OR e.Cancel_Date > '{$endDateEsc}') "
            . "AND (e.NSF_Date IS NULL OR e.NSF_Date > '{$endDateEsc}')";

        $query = "
            SELECT c.Agent AS agent,
                SUM(CASE WHEN {$notCancelledOrNsf} THEN 1 ELSE 0 END) AS deals,
                SUM(CASE WHEN {$notCancelledOrNsf} THEN e.Debt_Amount ELSE 0 END) AS debt,
                SUM(CASE WHEN e.Cancel_Date <= '{$endDateEsc}' THEN 1 ELSE 0 END) AS cancels,
                SUM(CASE WHEN e.NSF_Date <= '{$endDateEsc}' THEN 1 ELSE 0 END) AS nsfs
            FROM TblContacts AS c
            JOIN TblEnrollment AS e ON c.LLG_ID = e.LLG_ID
            WHERE COALESCE(c.Assigned_Date, c.Created_Date) >= '{$startEsc}'
              AND COALESCE(c.Assigned_Date, c.Created_Date) < '{$endEsc}'
              {$baseCriteria}
              AND e.Submitted_Date <= '{$endDateEsc}'
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
                'deals'   => $row['deals'] ?? 0,
                'debt'    => $row['debt'] ?? 0,
                'cancels' => $row['cancels'] ?? 0,
                'nsfs'    => $row['nsfs'] ?? 0,
            ];
        }
        return $out;
    }

    /**
     * Same Month Pay — enrolled contacts whose first payment cleared within the period
     * and are not cancelled/NSF as of period end.
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
     * and the data-source filter.
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
