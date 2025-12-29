<?php

namespace Cmd\Reports\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AgentSummaryReportRepository extends SqlSrvRepository
{
    /**
     * @return array<string, string>
     */
    public function columns(): array
    {
        return [
            'agent_id' => 'Agent ID',
            'agent' => 'Agent',
            'available_unit' => 'Available Unit',
            'max_leads' => 'Max Leads',
            'conversion_ratio' => 'Conversion Ratio',
            'avg_debt_assigned' => 'Average Debt Assigned',
            'target' => 'Target',
            'variance' => 'Variance',
            'leads' => 'Leads',
            'assigned' => 'Assigned',
            'debt_assigned' => 'Debt Assigned',
            'avg_debt_assigned_dup' => 'Average Debt Assigned (90d)',
            't1' => 'T1',
            't2' => 'T2',
            't3' => 'T3',
            't4' => 'T4',
            't5' => 'T5',
            't6' => 'T6',
            't7' => 'T7',
            't8' => 'T8',
            't9' => 'T9',
        ];
    }

    /**
     * @return array<string, Collection<int, string>>
     */
    public function options(): array
    {
        $base = $this->table('TblEnrollment');

        return [
            'agents' => $this->distinctValues($base, 'Agent'),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function all(array $filters = []): Collection
    {
        return $this->baseQuery($filters)->get();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(int $perPage = 25, array $filters = []): LengthAwarePaginator
    {
        return $this->paginateBuilder($this->baseQuery($filters), $perPage);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function baseQuery(array $filters = []): Builder
    {
        // Compute tier counts based on Debt_Amount ranges
        // T1: 0-10k, T2: 10k-20k, T3: 20k-30k, T4: 30k-40k, T5: 40k-50k, T6: 50k-60k, T7: 60k-70k, T8: 70k-80k, T9: 80k+
        $query = $this->table('TblEnrollment')
            ->select([
                DB::raw("COALESCE(NULLIF(Agent,''), 'N/A') AS agent"),
                DB::raw("MIN(LLG_ID) AS agent_id"),
                DB::raw('COUNT(DISTINCT CASE WHEN Enrollment_Status = \'Active\' THEN LLG_ID END) AS available_unit'),
                DB::raw('COUNT(*) AS max_leads'),
                DB::raw('ROUND(CAST(COUNT(CASE WHEN First_Payment_Status = \'Cleared\' THEN 1 END) AS FLOAT) / NULLIF(COUNT(*), 0) * 100, 2) AS conversion_ratio'),
                DB::raw('AVG(Debt_Amount) AS avg_debt_assigned'),
                DB::raw('SUM(Debt_Amount) * 0.02 AS target'),
                DB::raw('SUM(Debt_Amount) - (SUM(Debt_Amount) * 0.02) AS variance'),
                DB::raw('COUNT(*) AS leads'),
                DB::raw('COUNT(CASE WHEN Negotiator IS NOT NULL AND Negotiator != \'\' THEN 1 END) AS assigned'),
                DB::raw('SUM(Debt_Amount) AS debt_assigned'),
                DB::raw('AVG(Debt_Amount) AS avg_debt_assigned_dup'),
                DB::raw('COUNT(CASE WHEN Debt_Amount < 10000 THEN 1 END) AS t1'),
                DB::raw('COUNT(CASE WHEN Debt_Amount >= 10000 AND Debt_Amount < 20000 THEN 1 END) AS t2'),
                DB::raw('COUNT(CASE WHEN Debt_Amount >= 20000 AND Debt_Amount < 30000 THEN 1 END) AS t3'),
                DB::raw('COUNT(CASE WHEN Debt_Amount >= 30000 AND Debt_Amount < 40000 THEN 1 END) AS t4'),
                DB::raw('COUNT(CASE WHEN Debt_Amount >= 40000 AND Debt_Amount < 50000 THEN 1 END) AS t5'),
                DB::raw('COUNT(CASE WHEN Debt_Amount >= 50000 AND Debt_Amount < 60000 THEN 1 END) AS t6'),
                DB::raw('COUNT(CASE WHEN Debt_Amount >= 60000 AND Debt_Amount < 70000 THEN 1 END) AS t7'),
                DB::raw('COUNT(CASE WHEN Debt_Amount >= 70000 AND Debt_Amount < 80000 THEN 1 END) AS t8'),
                DB::raw('COUNT(CASE WHEN Debt_Amount >= 80000 THEN 1 END) AS t9'),
            ])
            ->groupBy(DB::raw("COALESCE(NULLIF(Agent,''), 'N/A')"));

        if (!empty($filters['agent'])) {
            $query->having('agent', 'like', '%' . $filters['agent'] . '%');
        }

        if (!empty($filters['date_from'])) {
            $query->whereDate('Submitted_Date', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->whereDate('Submitted_Date', '<=', $filters['date_to']);
        }

        // Apply aggregate filters via HAVING
        if (!empty($filters['debt_min'])) {
            $query->having('debt_assigned', '>=', $filters['debt_min']);
        }

        if (!empty($filters['debt_max'])) {
            $query->having('debt_assigned', '<=', $filters['debt_max']);
        }

        if (!empty($filters['avg_debt_min'])) {
            $query->having('avg_debt_assigned', '>=', $filters['avg_debt_min']);
        }

        if (!empty($filters['avg_debt_max'])) {
            $query->having('avg_debt_assigned', '<=', $filters['avg_debt_max']);
        }

        if (!empty($filters['leads_min'])) {
            $query->having('leads', '>=', $filters['leads_min']);
        }

        if (!empty($filters['leads_max'])) {
            $query->having('leads', '<=', $filters['leads_max']);
        }

        if (!empty($filters['assigned_min'])) {
            $query->having('assigned', '>=', $filters['assigned_min']);
        }

        if (!empty($filters['assigned_max'])) {
            $query->having('assigned', '<=', $filters['assigned_max']);
        }

        return $query->orderBy('agent');
    }
}
