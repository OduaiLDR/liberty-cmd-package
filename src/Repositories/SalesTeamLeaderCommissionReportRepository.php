<?php

namespace Cmd\Reports\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SalesTeamLeaderCommissionReportRepository extends SqlSrvRepository
{
    /**
     * @return array<string, string>
     */
    public function columns(): array
    {
        return [
            'agent' => 'Agent',
            'enrollments' => 'Enrollments',
            'debt_amount' => 'Debt Amount',
            'lookback_count' => 'Lookback Count',
            'lookback_debt' => 'Lookback Debt',
            'net_debt' => 'Net Debt',
            'commission' => 'Commission',
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
        // Using TblEnrollment aggregates with proper lookback calculation
        // Lookback = enrollments that were later cancelled or had NSF
        $query = $this->table('TblEnrollment')
            ->select([
                DB::raw("COALESCE(NULLIF(Agent,''), 'N/A') AS agent"),
                DB::raw('COUNT(*) AS enrollments'),
                DB::raw('SUM(Debt_Amount) AS debt_amount'),
                DB::raw('COUNT(CASE WHEN Cancel_Date IS NOT NULL OR NSF_Date IS NOT NULL THEN 1 END) AS lookback_count'),
                DB::raw('SUM(CASE WHEN Cancel_Date IS NOT NULL OR NSF_Date IS NOT NULL THEN Debt_Amount ELSE 0 END) AS lookback_debt'),
                DB::raw('SUM(CASE WHEN Cancel_Date IS NULL AND NSF_Date IS NULL THEN Debt_Amount ELSE 0 END) AS net_debt'),
                DB::raw('ROUND(SUM(CASE WHEN Cancel_Date IS NULL AND NSF_Date IS NULL THEN Debt_Amount ELSE 0 END) * 0.0005, 2) AS commission'),
            ])
            ->whereNotNull('LLG_ID')
            ->groupBy(DB::raw("COALESCE(NULLIF(Agent,''), 'N/A')"));

        if (!empty($filters['agent'])) {
            $query->having('agent', 'like', '%' . $filters['agent'] . '%');
        }

        // Month/Year filter
        if (!empty($filters['month'])) {
            $query->whereRaw('MONTH(Submitted_Date) = ?', [$filters['month']]);
        }

        if (!empty($filters['year'])) {
            $query->whereRaw('YEAR(Submitted_Date) = ?', [$filters['year']]);
        }

        if (!empty($filters['debt_min'])) {
            $query->having('debt_amount', '>=', $filters['debt_min']);
        }

        if (!empty($filters['debt_max'])) {
            $query->having('debt_amount', '<=', $filters['debt_max']);
        }

        if (!empty($filters['enrollments_min'])) {
            $query->having('enrollments', '>=', $filters['enrollments_min']);
        }

        if (!empty($filters['enrollments_max'])) {
            $query->having('enrollments', '<=', $filters['enrollments_max']);
        }

        return $query->orderBy('agent');
    }
}
