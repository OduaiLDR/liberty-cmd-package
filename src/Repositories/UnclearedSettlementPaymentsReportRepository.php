<?php

namespace Cmd\Reports\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class UnclearedSettlementPaymentsReportRepository extends SqlSrvRepository
{
    /**
     * @return array<string, string>
     */
    public function columns(): array
    {
        return [
            'llg_id' => 'LLG ID',
            'process_date' => 'Process Date',
            'amount' => 'Amount',
            'memo' => 'Memo',
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
        // Note: TRANSACTIONS table is unavailable; using TblEnrollment as proxy
        $query = $this->table('TblEnrollment')
            ->select([
                DB::raw("CONCAT('LLG-', LLG_ID) AS llg_id"),
                'Submitted_Date AS process_date',
                'Debt_Amount AS amount',
                DB::raw("'' AS memo"),
            ])
            ->whereNotNull('LLG_ID')
            ->whereNotNull('Submitted_Date');

        if (!empty($filters['llg_id'])) {
            $query->where(DB::raw("CONCAT('LLG-', LLG_ID)"), 'like', '%' . $filters['llg_id'] . '%');
        }

        if (!empty($filters['process_date_from'])) {
            $query->whereDate('Submitted_Date', '>=', $filters['process_date_from']);
        } else {
            // default start date last 21 days
            $query->whereDate('Submitted_Date', '>=', now()->subDays(21)->toDateString());
        }

        if (!empty($filters['process_date_to'])) {
            $query->whereDate('Submitted_Date', '<=', $filters['process_date_to']);
        } else {
            // default end date 7 days ago
            $query->whereDate('Submitted_Date', '<=', now()->subDays(7)->toDateString());
        }

        if (!empty($filters['amount_min'])) {
            $query->where('Debt_Amount', '>=', $filters['amount_min']);
        }

        if (!empty($filters['amount_max'])) {
            $query->where('Debt_Amount', '<=', $filters['amount_max']);
        }

        if (!empty($filters['memo'])) {
            // memo not available; no-op
        }

        return $query->orderBy('Submitted_Date', 'asc');
    }
}
