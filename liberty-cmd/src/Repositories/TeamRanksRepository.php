<?php

namespace Cmd\Reports\Repositories;

use Illuminate\Support\Collection;

class TeamRanksRepository extends SqlSrvRepository
{
    /**
     * Build team ranks dataset for agents, teams, and company.
     *
     * @return array{agents: Collection, teams: Collection, company: object, options: array}
     */
    public function data(?string $from = null, ?string $to = null, ?string $dataSource = null): array
    {
        $conn = $this->connection();

        $dataSource = $dataSource && $dataSource !== 'All Data Sources' ? $dataSource : null;

        // Sales managers map
        $managers = $conn->table('TblEmployees as e')
            ->join('TblEmployeeSalesManagers as s', 'e.PK', '=', 's.Sales_Manager_ID')
            ->whereNull('s.End_Date')
            ->select('e.PK as manager_id', 'e.Employee_Name as manager_name')
            ->distinct()
            ->get()
            ->keyBy('manager_id');

        // Active agents and their manager assignment
        $agentsList = $conn->table('TblEmployees as e')
            ->leftJoin('TblEmployeeSalesManagers as s', 'e.PK', '=', 's.Agent_ID')
            ->whereNull('e.Term_Date')
            ->whereNull('s.End_Date')
            ->where('e.Access_Level', '=', 'Agent')
            ->where('e.Employee_Name', '<>', 'Debt PayPro')
            ->where('e.Employee_Name', 'not like', '% User')
            ->select('s.Sales_Manager_ID as manager_id', 'e.Employee_Name as agent_name')
            ->orderBy('s.Sales_Manager_ID')
            ->orderBy('e.Employee_Name')
            ->get();

        // Contacts aggregate
        $contacts = $conn->table('TblContacts')
            ->selectRaw('Agent, COUNT(*) as Contacts')
            ->when($from, fn($q) => $q->whereRaw('CAST(COALESCE(Assigned_Date, Created_Date) AS date) >= ?', [$from]))
            ->when($to, fn($q) => $q->whereRaw('CAST(COALESCE(Assigned_Date, Created_Date) AS date) <= ?', [$to]))
            ->whereNotIn('Status', ['Funded', 'Freedom Plus Client', 'Lexington Law Client', 'Plush Funding', 'No Credit Ran'])
            ->where('Status', '<>', 'Rejected (Not Qualified DS)')
            ->where('Data_Source', 'not like', 'EC Loan Leads%')
            ->when($dataSource && $dataSource !== 'All Data Sources', fn($q) => $q->where('Data_Source', '=', $dataSource))
            ->groupBy('Agent')
            ->get()
            ->keyBy('Agent');

        // Enrollment aggregates based on contacts timeframe and datasource
        $enrollBase = $conn->table('TblEnrollment as e')
            ->join('TblContacts as c', 'e.LLG_ID', '=', 'c.LLG_ID')
            ->when($from, fn($q) => $q->whereRaw('CAST(COALESCE(c.Assigned_Date, c.Created_Date) AS date) >= ?', [$from]))
            ->when($to, fn($q) => $q->whereRaw('CAST(COALESCE(c.Assigned_Date, c.Created_Date) AS date) <= ?', [$to]))
            ->whereNotIn('c.Status', ['Funded', 'Freedom Plus Client', 'Lexington Law Client', 'Plush Funding', 'No Credit Ran'])
            ->where('c.Status', '<>', 'Rejected (Not Qualified DS)')
            ->where('c.Data_Source', 'not like', 'EC Loan Leads%');

        // WCC and enrolled debt (VBA uses LIKE for DS here)
        $wccDebt = (clone $enrollBase)
            ->when($dataSource && $dataSource !== 'All Data Sources', fn($q) => $q->where('c.Data_Source', 'like', $dataSource . '%'))
            ->selectRaw('c.Agent, COUNT(*) as WCC, SUM(e.Debt_Amount) as Enrolled_Debt')
            ->groupBy('c.Agent')
            ->get()
            ->keyBy('Agent');

        // Cancels (VBA uses equality for DS here)
        $cancels = (clone $enrollBase)
            ->when($dataSource && $dataSource !== 'All Data Sources', fn($q) => $q->where('c.Data_Source', '=', $dataSource))
            ->whereNotNull('e.Cancel_Date')
            ->selectRaw('c.Agent, COUNT(*) as Cancels')
            ->groupBy('c.Agent')
            ->get()
            ->keyBy('Agent');

        // NSFs (VBA uses equality for DS here)
        $nsfs = (clone $enrollBase)
            ->when($dataSource && $dataSource !== 'All Data Sources', fn($q) => $q->where('c.Data_Source', '=', $dataSource))
            ->whereNotNull('e.NSF_Date')
            ->selectRaw('c.Agent, COUNT(*) as NSFs')
            ->groupBy('c.Agent')
            ->get()
            ->keyBy('Agent');

        // Assemble agent rows
        $rows = collect();
        foreach ($agentsList as $a) {
            $agent = $a->agent_name;
            if (!$agent) continue;

            $contactsCnt = (int) ($contacts[$agent]->Contacts ?? 0);
            $wcc = (int) ($wccDebt[$agent]->WCC ?? 0);
            $enrolledDebt = (float) ($wccDebt[$agent]->Enrolled_Debt ?? 0.0);
            $cancelCnt = (int) ($cancels[$agent]->Cancels ?? 0);
            $nsfCnt = (int) ($nsfs[$agent]->NSFs ?? 0);
            $net = $wcc - $cancelCnt - $nsfCnt;
            $ratio = ($wcc < 1 || $contactsCnt <= 0) ? null : ($net / max(1, $contactsCnt));

            $managerName = $managers[$a->manager_id]->manager_name ?? null;
            $teamName = $managerName ? ($managerName . "'s Team") : 'Training Team';

            $rows->push((object) [
                'team' => $teamName,
                'agent' => $agent,
                'contacts' => $contactsCnt,
                'wcc' => $wcc,
                'cancels' => $cancelCnt,
                'nsfs' => $nsfCnt,
                'enrolled_debt' => $enrolledDebt,
                'net' => $net,
                'ratio' => $ratio,
            ]);
        }

        // Rank and score
        $rows = $this->rankAgents($rows);

        // Team and company summaries
        $teams = $this->summarizeTeams($rows);
        $company = $this->summarizeCompany($rows);

        return [
            'agents' => $rows,
            'teams' => $teams,
            'company' => $company,
            'options' => $this->options(),
        ];
    }

    /**
     * Provide filter options.
     * @return array<string, Collection>
     */
    public function options(): array
    {
        $sources = $this->table('TblContacts')
            ->select('Data_Source')
            ->whereNotNull('Data_Source')
            ->distinct()
            ->orderBy('Data_Source')
            ->limit(500)
            ->pluck('Data_Source')
            ->filter(fn($v) => $v !== null && $v !== '')
            ->values();

        return [
            'data_sources' => $sources->prepend('All Data Sources')->unique()->values(),
        ];
    }

    protected function summarizeTeams(Collection $agents): Collection
    {
        $teams = $agents->groupBy('team')->map(function (Collection $items, string $team) {
            $contacts = (int) $items->sum('contacts');
            $wcc = (int) $items->sum('wcc');
            $cancels = (int) $items->sum('cancels');
            $nsfs = (int) $items->sum('nsfs');
            $enrolledDebt = (float) $items->sum('enrolled_debt');
            $net = $wcc - $cancels - $nsfs;
            $ratio = $contacts > 0 ? ($net / $contacts) : null;

            return (object) [
                'team' => $team,
                'agent' => 'All Agents',
                'contacts' => $contacts,
                'wcc' => $wcc,
                'cancels' => $cancels,
                'nsfs' => $nsfs,
                'enrolled_debt' => $enrolledDebt,
                'net' => $net,
                'ratio' => $ratio,
            ];
        })->values();

        return $this->rankTeams($teams)->sortBy(['team', '-score'])->values();
    }

    protected function summarizeCompany(Collection $agents): object
    {
        $company = (object) [
            'team' => 'Company-Wide',
            'agent' => 'All Agents',
            'contacts' => (int) $agents->sum('contacts'),
            'wcc' => (int) $agents->sum('wcc'),
            'cancels' => (int) $agents->sum('cancels'),
            'nsfs' => (int) $agents->sum('nsfs'),
            'enrolled_debt' => (float) $agents->sum('enrolled_debt'),
        ];

        $company->net = $company->wcc - $company->cancels - $company->nsfs;
        $company->ratio = $company->contacts > 0 ? ($company->net / $company->contacts) : null;
        return $company;
    }

    protected function rankAgents(Collection $agents): Collection
    {
        $rank = $this->rankFactory();
        $count = max(1, $agents->count());
        $ratioRanks = $rank($agents->pluck('ratio')->map(fn($v) => $v ?? -INF)->all());
        $wccRanks = $rank($agents->pluck('wcc')->all());
        $debtRanks = $rank($agents->pluck('enrolled_debt')->all());

        return $agents->values()->map(function ($row, int $i) use ($ratioRanks, $wccRanks, $debtRanks, $count) {
            $row->rank_ratio = $ratioRanks[$i] ?? null;
            $row->rank_wcc = $wccRanks[$i] ?? null;
            $row->rank_debt = $debtRanks[$i] ?? null;
            $row->score = ($row->rank_ratio && $row->rank_wcc && $row->rank_debt)
                ? (($count - $row->rank_ratio) * 50 + ($count - $row->rank_wcc) * 30 + ($count - $row->rank_debt) * 20)
                : null;
            return $row;
        })->sortBy(['team', '-score'])->values();
    }

    protected function rankTeams(Collection $teams): Collection
    {
        $rank = $this->rankFactory();
        $count = max(1, $teams->count());
        $ratioRanks = $rank($teams->pluck('ratio')->map(fn($v) => $v ?? -INF)->all());
        $wccRanks = $rank($teams->pluck('wcc')->all());
        $debtRanks = $rank($teams->pluck('enrolled_debt')->all());

        return $teams->values()->map(function ($row, int $i) use ($ratioRanks, $wccRanks, $debtRanks, $count) {
            $row->rank_ratio = $ratioRanks[$i] ?? null;
            $row->rank_wcc = $wccRanks[$i] ?? null;
            $row->rank_debt = $debtRanks[$i] ?? null;
            $row->score = ($row->rank_ratio && $row->rank_wcc && $row->rank_debt)
                ? (($count - $row->rank_ratio) * 50 + ($count - $row->rank_wcc) * 30 + ($count - $row->rank_debt) * 20)
                : null;
            return $row;
        });
    }

    protected function rankFactory(): callable
    {
        return static function (array $values): array {
            if (!count($values)) return [];
            arsort($values);
            $ranks = [];
            $position = 1;
            foreach ($values as $key => $value) {
                $identicalKeys = array_keys(array_filter($values, fn($v) => $v === $value));
                $sum = 0;
                foreach ($identicalKeys as $candidate) {
                    $sum += array_search($candidate, array_keys($values), true) + 1;
                }
                $average = count($identicalKeys) ? ($sum / count($identicalKeys)) : $position;
                $ranks[$key] = $average;
                $position++;
            }
            return $ranks;
        };
    }
}
