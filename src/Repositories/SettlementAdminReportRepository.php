<?php

namespace Cmd\Reports\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SettlementAdminReportRepository extends SqlSrvRepository
{
    /**
     * @return array<string, string>
     */
    public function columns(): array
    {
        return [
            'llg_id' => 'LLG ID',
            'original_debt_amount' => 'Original Debt Amount',
            'settlement_id' => 'Settlement ID',
            'creditor_name' => 'Creditor Name',
            'collection_company' => 'Collection Company',
            'settlement_date' => 'Settlement Date',
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
        // Using TblEnrollment with settlement-related columns
        // Real data format: LLG_ID is the actual ID (e.g., 1087276519)
        $query = $this->table('TblEnrollment')
            ->select([
                'LLG_ID AS llg_id',
                'Debt_Amount AS original_debt_amount',
                'LLG_ID AS settlement_id',
                DB::raw("COALESCE(Drop_Name, 'N/A') AS creditor_name"),
                DB::raw("COALESCE(Enrollment_Plan, 'N/A') AS collection_company"),
                'Submitted_Date AS settlement_date',
            ])
            ->whereNotNull('LLG_ID');

        if (!empty($filters['contact_name'])) {
            $query->where('Client', 'like', '%' . $filters['contact_name'] . '%');
        }

        if (!empty($filters['llg_id'])) {
            $query->where('LLG_ID', 'like', '%' . $filters['llg_id'] . '%');
        }

        if (!empty($filters['agent'])) {
            $query->where('Agent', 'like', '%' . $filters['agent'] . '%');
        }

        if (!empty($filters['negotiator'])) {
            $query->where('Negotiator', 'like', '%' . $filters['negotiator'] . '%');
        }

        if (!empty($filters['debt_min'])) {
            $query->where('Debt_Amount', '>=', $filters['debt_min']);
        }

        if (!empty($filters['debt_max'])) {
            $query->where('Debt_Amount', '<=', $filters['debt_max']);
        }

        if (!empty($filters['settlement_from'])) {
            $query->whereDate('Submitted_Date', '>=', $filters['settlement_from']);
        }

        if (!empty($filters['settlement_to'])) {
            $query->whereDate('Submitted_Date', '<=', $filters['settlement_to']);
        }

        return $query->orderBy('Submitted_Date', 'desc');
    }
}
