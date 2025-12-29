<?php

namespace Cmd\Reports\Repositories;

use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TrainingReportRepository extends SqlSrvRepository
{
    /**
     * @return array<string, string>
     */
    public function columns(): array
    {
        return [
            'agent' => 'Agent',
            'location' => 'Location',
            'on_phone_date' => 'On Phone Date',
            'month' => 'Month',
            'start_date' => 'Start Date',
            'end_date' => 'End Date',
            'contacts' => 'Contacts',
            'deals' => 'Deals',
            'conversion' => 'Conversion',
            'debt' => 'Debt',
            'total_deals' => 'Total Deals',
            'total_conversion' => 'Total Conversion',
            'total_debt' => 'Total Debt',
            'total' => 'Total',
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
        // Using TblEnrollment as proxy for training metrics
        $month = !empty($filters['month']) ? (int) $filters['month'] : null;
        $year = !empty($filters['year']) ? (int) $filters['year'] : null;

        $query = $this->table('TblEnrollment')
            ->selectRaw("COALESCE(NULLIF(Agent,''), 'N/A') AS agent")
            ->selectRaw("COALESCE(State, 'N/A') AS location")
            ->selectRaw('MIN(First_Payment_Date) AS on_phone_date')
            ->selectRaw('DATENAME(MONTH, MIN(Submitted_Date)) AS month')
            ->selectRaw('DATEFROMPARTS(YEAR(MIN(Submitted_Date)), MONTH(MIN(Submitted_Date)), 1) AS start_date')
            ->selectRaw('EOMONTH(MIN(Submitted_Date)) AS end_date')
            ->selectRaw('COUNT(*) AS contacts')
            ->selectRaw("COUNT(CASE WHEN First_Payment_Status = 'Cleared' THEN 1 END) AS deals")
            ->selectRaw('ROUND(CAST(COUNT(CASE WHEN First_Payment_Status = \'Cleared\' THEN 1 END) AS FLOAT) / NULLIF(COUNT(*), 0) * 100, 2) AS conversion')
            ->selectRaw('SUM(Debt_Amount) AS debt')
            ->selectRaw('COUNT(*) AS total_deals')
            ->selectRaw('ROUND(CAST(COUNT(CASE WHEN First_Payment_Status = \'Cleared\' THEN 1 END) AS FLOAT) / NULLIF(COUNT(*), 0) * 100, 2) AS total_conversion')
            ->selectRaw('SUM(Debt_Amount) AS total_debt')
            ->selectRaw('SUM(Debt_Amount) AS total')
            ->whereNotNull('LLG_ID');

        if ($month !== null) {
            $query->whereRaw('MONTH(Submitted_Date) = ?', [$month]);
        }

        if ($year !== null) {
            $query->whereRaw('YEAR(Submitted_Date) = ?', [$year]);
        }

        $query->groupBy(DB::raw("COALESCE(NULLIF(Agent,''), 'N/A')"), 'State');

        if (!empty($filters['agent'])) {
            $query->whereRaw("COALESCE(NULLIF(Agent,''), 'N/A') LIKE ?", ['%' . $filters['agent'] . '%']);
        }

        if (!empty($filters['location'])) {
            $query->where('State', 'like', '%' . $filters['location'] . '%');
        }

        return $query->orderBy('agent');
    }
}
