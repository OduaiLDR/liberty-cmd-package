<?php

namespace Cmd\Reports\Repositories;

use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LeaderboardReportRepository extends SqlSrvRepository
{
    /**
     * @return array<string, string>
     */
    public function columns(): array
    {
        return [
            'rank' => 'Rank',
            'agent' => 'Agent',
            'contacts' => 'Contacts',
            'deals' => 'Deals',
            'debt' => 'Debt',
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
     * Top N leaders across all time (no filters).
     *
     * @return Collection<int, object>
     */
    public function topAllTime(int $limit = 4): Collection
    {
        return $this->table('TblEnrollment')
            ->selectRaw("DENSE_RANK() OVER (ORDER BY SUM(Debt_Amount) DESC) AS rank")
            ->selectRaw("COALESCE(NULLIF(Agent,''), 'N/A') AS agent")
            ->selectRaw('COUNT(*) AS contacts')
            ->selectRaw('COUNT(*) AS deals')
            ->selectRaw('SUM(Debt_Amount) AS debt')
            ->selectRaw('MAX(Submitted_Date) AS leaderboard_date')
            ->whereNotNull('LLG_ID')
            ->groupBy(DB::raw("COALESCE(NULLIF(Agent,''), 'N/A')"))
            ->orderBy('rank')
            ->limit($limit)
            ->get();
    }

    /**
     * Company-wide aggregates for current filter window.
     *
     * @return array{contacts:int,deals:int,debt:float}
     */
    public function companyTotals(array $filters = []): array
    {
        [$from, $to] = $this->resolveWindow($filters);

        $row = $this->table('TblEnrollment')
            ->selectRaw('COUNT(*) AS contacts')
            ->selectRaw('COUNT(*) AS deals')
            ->selectRaw('SUM(Debt_Amount) AS debt')
            ->whereNotNull('LLG_ID')
            ->whereBetween('Submitted_Date', [$from, $to])
            ->first();

        return [
            'contacts' => (int) ($row->contacts ?? 0),
            'deals' => (int) ($row->deals ?? 0),
            'debt' => (float) ($row->debt ?? 0),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function baseQuery(array $filters = []): Builder
    {
        [$from, $to] = $this->resolveWindow($filters);

        $base = $this->table('TblEnrollment')
            ->whereNotNull('LLG_ID')
            ->whereBetween('Submitted_Date', [$from, $to]);

        $query = $base
            ->selectRaw("DENSE_RANK() OVER (ORDER BY SUM(Debt_Amount) DESC) AS rank")
            ->selectRaw("COALESCE(NULLIF(Agent,''), 'N/A') AS agent")
            ->selectRaw('COUNT(*) AS contacts')
            ->selectRaw('COUNT(*) AS deals')
            ->selectRaw('SUM(Debt_Amount) AS debt')
            ->groupBy(DB::raw("COALESCE(NULLIF(Agent,''), 'N/A')"));

        if (!empty($filters['agent'])) {
            $query->having('agent', 'like', '%' . $filters['agent'] . '%');
        }

        return $query->orderBy('rank')->orderBy('agent');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function resolveWindow(array $filters): array
    {
        $period = strtolower((string) ($filters['period'] ?? 'monthly'));
        $month = !empty($filters['month']) ? (int) $filters['month'] : null;
        $year = !empty($filters['year']) ? (int) $filters['year'] : null;
        $today = Carbon::now();

        return match ($period) {
            'daily' => [
                $today->copy()->startOfDay(),
                $today->copy()->endOfDay(),
            ],
            'weekly' => [
                $today->copy()->startOfWeek(Carbon::MONDAY),
                $today->copy()->endOfWeek(Carbon::MONDAY),
            ],
            default => $this->resolveMonthlyWindow($month, $year, $today),
        };
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function resolveMonthlyWindow(?int $month, ?int $year, Carbon $fallback): array
    {
        $useMonth = $month ?: (int) $fallback->month;
        $useYear = $year ?: (int) $fallback->year;
        $start = Carbon::createFromDate($useYear, $useMonth, 1)->startOfDay();
        $end = (clone $start)->endOfMonth()->endOfDay();
        return [$start, $end];
    }

    /**
     * @return array{label:string,from:Carbon,to:Carbon}
     */
    public function windowMeta(array $filters = []): array
    {
        [$from, $to] = $this->resolveWindow($filters);
        $label = $from->format('m/d/Y') . ' to ' . $to->format('m/d/Y');
        return ['label' => $label, 'from' => $from, 'to' => $to];
    }
}
