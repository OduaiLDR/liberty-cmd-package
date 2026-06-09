<?php

namespace Cmd\Reports\Console\Commands\GenerateAgentSummaryReport;

use Cmd\Reports\Services\DBConnector;

class DataFetcher
{
    public const EXCLUDED_AGENTS = [
        'Dummy User',
        'Debt PayPro',
        'Jasmine Scott',
        'Liam Anderson',
        'Tyler Wevik',
    ];

    public const EXCLUDED_STATUSES = [
        'Funded',
        'Freedom Plus Client',
        'Lexington Law Client',
        'Plush Funding',
        'Rejected (Not Qualified DS)',
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
        $criteria = $this->buildSqlCriteria($dataSource);

        $contacts       = $this->fetchAggregate($sql, $startDate, $endExclusive, $criteria, $this->contactsExtra(), 'COUNT(*)');
        $dealsEom       = $this->fetchAggregate($sql, $startDate, $endExclusive, $criteria, $this->dealsEomExtra($endDate), 'COUNT(*)', true);
        $dealsCurrent   = $this->fetchAggregate($sql, $startDate, $endExclusive, $criteria, $this->dealsCurrentExtra(), 'COUNT(*)', true);
        $debtEom        = $this->fetchAggregate($sql, $startDate, $endExclusive, $criteria, $this->dealsEomExtra($endDate), 'SUM(e.Debt_Amount)', true);
        $debtCurrent    = $this->fetchAggregate($sql, $startDate, $endExclusive, $criteria, $this->dealsCurrentExtra(), 'SUM(e.Debt_Amount)', true);
        $cancelsEom     = $this->fetchAggregate($sql, $startDate, $endExclusive, $criteria, $this->cancelsEomExtra($endDate), 'COUNT(*)', true);
        $cancelsCurrent = $this->fetchAggregate($sql, $startDate, $endExclusive, $criteria, $this->cancelsCurrentExtra(), 'COUNT(*)', true);
        $nsfsEom        = $this->fetchAggregate($sql, $startDate, $endExclusive, $criteria, $this->nsfsEomExtra($endDate), 'COUNT(*)', true);
        $nsfsCurrent    = $this->fetchAggregate($sql, $startDate, $endExclusive, $criteria, $this->nsfsCurrentExtra(), 'COUNT(*)', true);
        $smpEom         = $this->fetchAggregate($sql, $startDate, $endExclusive, $criteria, $this->smpExtra($endDate), 'COUNT(*)', true);
        $smpCurrent     = $smpEom; // VBA bug preserved: identical to EOM

        $terminated = $this->fetchTerminatedAgentNames($sql);

        $allAgents = array_unique(array_merge(array_keys($contacts), array_keys($dealsCurrent)));

        $rows = [];
        foreach ($allAgents as $agent) {
            if ($agent === '' || in_array($agent, $terminated, true)) {
                continue;
            }

            $contactsCount = (int) ($contacts[$agent] ?? 0);
            $dealsEomCount = (int) ($dealsEom[$agent] ?? 0);
            $dealsCurCount = (int) ($dealsCurrent[$agent] ?? 0);
            $debtEomSum    = (float) ($debtEom[$agent] ?? 0);
            $debtCurSum    = (float) ($debtCurrent[$agent] ?? 0);

            $rows[] = [
                'agent'             => $agent,
                'contacts'          => $contactsCount,
                'deals_eom'         => $dealsEomCount,
                'conversion_eom'    => $contactsCount > 0 ? $dealsEomCount / $contactsCount : 0,
                'debt_eom'          => $debtEomSum,
                'avg_debt_eom'      => $dealsEomCount > 0 ? $debtEomSum / $dealsEomCount : 0,
                'cancels_eom'       => (int) ($cancelsEom[$agent] ?? 0),
                'nsfs_eom'          => (int) ($nsfsEom[$agent] ?? 0),
                'cancels_pct_eom'   => $contactsCount > 0 ? ((int) ($cancelsEom[$agent] ?? 0)) / $contactsCount : 0,
                'nsfs_pct_eom'      => $contactsCount > 0 ? ((int) ($nsfsEom[$agent] ?? 0)) / $contactsCount : 0,
                'smp_eom'           => (int) ($smpEom[$agent] ?? 0),
                'deals_current'         => $dealsCurCount,
                'conversion_current'    => $contactsCount > 0 ? $dealsCurCount / $contactsCount : 0,
                'debt_current'          => $debtCurSum,
                'avg_debt_current'      => $dealsCurCount > 0 ? $debtCurSum / $dealsCurCount : 0,
                'cancels_current'       => (int) ($cancelsCurrent[$agent] ?? 0),
                'nsfs_current'          => (int) ($nsfsCurrent[$agent] ?? 0),
                'cancels_pct_current'   => $contactsCount > 0 ? ((int) ($cancelsCurrent[$agent] ?? 0)) / $contactsCount : 0,
                'nsfs_pct_current'      => $contactsCount > 0 ? ((int) ($nsfsCurrent[$agent] ?? 0)) / $contactsCount : 0,
                'smp_current'           => (int) ($smpCurrent[$agent] ?? 0),
            ];
        }

        usort($rows, fn($a, $b) => $b['contacts'] <=> $a['contacts']);
        return $rows;
    }

    private function buildSqlCriteria(string $dataSource): string
    {
        $excludedAgents = $this->quoteList(self::EXCLUDED_AGENTS);
        $excludedStatuses = $this->quoteList(self::EXCLUDED_STATUSES);

        $criteria = "
            AND c.Agent IN (
                SELECT Employee_Name FROM TblEmployees
                WHERE Access_Level = 'Agent'
                  AND Employee_Name NOT IN ({$excludedAgents})
            )
            AND c.Status NOT IN ({$excludedStatuses})
            AND c.Data_Source NOT LIKE 'EC Loan Leads%'
        ";

        $filter = self::DATA_SOURCE_FILTERS[$dataSource] ?? null;
        if ($filter !== null) {
            $filterEsc = $this->esc($filter);
            $criteria .= " AND UPPER(c.Data_Source) LIKE '{$filterEsc}' ";
        }

        return $criteria;
    }

    private function fetchAggregate(
        DBConnector $sql,
        string $startDate,
        string $endExclusive,
        string $criteria,
        string $extra,
        string $aggregateExpr,
        bool $joinEnrollment = false
    ): array {
        $join = $joinEnrollment ? 'LEFT JOIN TblEnrollment AS e ON c.LLG_ID = e.LLG_ID' : '';
        $startEsc = $this->esc($startDate);
        $endEsc = $this->esc($endExclusive);

        $query = "
            SELECT c.Agent AS agent, {$aggregateExpr} AS value
            FROM TblContacts AS c
            {$join}
            WHERE COALESCE(c.Assigned_Date, c.Created_Date) >= '{$startEsc}'
              AND COALESCE(c.Assigned_Date, c.Created_Date) < '{$endEsc}'
              {$criteria}
              {$extra}
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
            $out[$agent] = $row['value'] ?? 0;
        }
        return $out;
    }

    private function contactsExtra(): string
    {
        return '';
    }

    private function dealsEomExtra(string $endDate): string
    {
        $endEsc = $this->esc($endDate);
        return "
            AND e.Submitted_Date <= '{$endEsc}'
            AND (e.Cancel_Date IS NULL OR e.Cancel_Date > '{$endEsc}')
            AND (e.NSF_Date IS NULL OR e.NSF_Date > '{$endEsc}')
        ";
    }

    private function dealsCurrentExtra(): string
    {
        return "
            AND e.Welcome_Call_Date IS NOT NULL
            AND e.Cancel_Date IS NULL
            AND e.NSF_Date IS NULL
        ";
    }

    private function cancelsEomExtra(string $endDate): string
    {
        $endEsc = $this->esc($endDate);
        return "
            AND e.Submitted_Date <= '{$endEsc}'
            AND e.Cancel_Date <= '{$endEsc}'
        ";
    }

    private function cancelsCurrentExtra(): string
    {
        return "
            AND e.Welcome_Call_Date IS NOT NULL
            AND e.Cancel_Date IS NOT NULL
        ";
    }

    private function nsfsEomExtra(string $endDate): string
    {
        $endEsc = $this->esc($endDate);
        return "
            AND e.Submitted_Date <= '{$endEsc}'
            AND e.NSF_Date <= '{$endEsc}'
        ";
    }

    private function nsfsCurrentExtra(): string
    {
        return "
            AND e.Welcome_Call_Date IS NOT NULL
            AND e.NSF_Date IS NOT NULL
        ";
    }

    private function smpExtra(string $endDate): string
    {
        $endEsc = $this->esc($endDate);
        return "
            AND e.Submitted_Date <= '{$endEsc}'
            AND COALESCE(e.First_Payment_Date, e.Payment_Date_2, e.Payment_Date_1) <= '{$endEsc}'
            AND (e.Cancel_Date IS NULL OR e.Cancel_Date > '{$endEsc}')
            AND (e.NSF_Date IS NULL OR e.NSF_Date > '{$endEsc}')
            AND e.First_Payment_Cleared_Date IS NOT NULL
        ";
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
