<?php

namespace Cmd\Reports\Repositories;

use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ProgramCompletionRepository extends SqlSrvRepository
{
    /**
     * Retrieve all results for export.
     *
     * @param  array<string, mixed>  $filters
     */
    public function all(?string $from = null, ?string $to = null, array $filters = []): Collection
    {
        return $this->baseQuery($from, $to, $filters)->get();
    }

    /**
     * Retrieve paginated results for the UI.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginate(?string $from = null, ?string $to = null, int $perPage = 25, array $filters = []): LengthAwarePaginator
    {
        return $this->paginateBuilder(
            $this->baseQuery($from, $to, $filters),
            $perPage
        );
    }

    /**
     * Build the shared base query used by the report.
     *
     * @param  array<string, mixed>  $filters
     */
    protected function baseQuery(?string $from = null, ?string $to = null, array $filters = []): Builder
    {
        $enrollmentAggregate = $this->table('TblEnrollment')
            ->select([
                'LLG_ID',
                DB::raw('MAX(Welcome_Call_Date) as Welcome_Call_Date'),
                DB::raw('SUM(COALESCE(Debt_Amount, 0)) as Enrolled_Debt'),
            ])
            ->groupBy('LLG_ID');

        $query = $this->table('TblSettlementDetails as s')
            ->leftJoinSub($enrollmentAggregate, 'e', function ($join) {
                $join->on('s.LLG_ID', '=', 'e.LLG_ID');
            })
            ->whereNotNull('s.LLG_ID');

        if ($from) {
            $query->whereDate('s.Settlement_Date', '>=', $from);
        }

        if ($to) {
            $query->whereDate('s.Settlement_Date', '<=', $to);
        }

        $llgId = $filters['llg_id'] ?? null;
        if ($llgId !== null && $llgId !== '') {
            $query->where('s.LLG_ID', 'like', '%' . trim((string) $llgId) . '%');
        }

        $client = $filters['client'] ?? null;
        if ($client !== null && $client !== '') {
            $query->where('s.Client', 'like', '%' . trim((string) $client) . '%');
        }

        $programCompletionExpr = <<<SQL
CASE
    WHEN MAX(e.Enrolled_Debt) IS NULL OR MAX(e.Enrolled_Debt) = 0 THEN 0
    ELSE SUM(COALESCE(s.Debt_Amount, 0)) / MAX(e.Enrolled_Debt)
END
SQL;

        return $query
            ->select([
                's.LLG_ID',
                DB::raw('MAX(s.Client) as Client'),
                DB::raw('MAX(e.Welcome_Call_Date) as Welcome_Call_Date'),
                DB::raw('SUM(COALESCE(s.Settlement, 0)) as Total_Settlement_Amounts_Accepted'),
                DB::raw('SUM(COALESCE(s.Debt_Amount, 0)) as Original_Debt_Amount_Settled'),
                DB::raw('MAX(e.Enrolled_Debt) as Enrolled_Debt'),
                DB::raw('CASE WHEN SUM(COALESCE(s.Debt_Amount, 0)) = 0 THEN 0 ELSE SUM(COALESCE(s.Settlement, 0)) / SUM(COALESCE(s.Debt_Amount, 0)) END as Settlement_Rate'),
                DB::raw("{$programCompletionExpr} as Program_Completion"),
                DB::raw('MAX(s.Settlement_Date) as Latest_Settlement_Date'),
            ])
            ->groupBy('s.LLG_ID')
            ->orderByDesc(DB::raw($programCompletionExpr));
    }
}
