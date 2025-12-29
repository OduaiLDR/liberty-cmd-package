<?php

namespace Cmd\Reports\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LendingUsaProspectsReportRepository extends SqlSrvRepository
{
    /**
     * @return array<string, string>
     */
    public function columns(): array
    {
        return [
            'llg_id' => 'LLG ID',
            'client' => 'Client',
            'email' => 'Email',
            'phone' => 'Phone',
            'balance' => 'Balance',
            'first_payment_date' => 'First Payment Date',
            'debt_amount' => 'Debt Amount',
            'total_income' => 'Total Income',
            'payments' => 'Payments',
            'state' => 'State',
            'debt_ratio' => 'Debt Ratio',
        ];
    }

    /**
     * @return array<string, Collection<int, string>>
     */
    public function options(): array
    {
        $base = $this->table('TblEnrollment');

        return [
            'states' => $this->distinctValues($base, 'State'),
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
        // Using TblEnrollment as the available source; several columns are proxies/placeholders
        $query = $this->table('TblEnrollment')
            ->select([
                DB::raw("CONCAT('LLG-', LLG_ID) AS llg_id"),
                'Client AS client',
                DB::raw("'' AS email"),
                DB::raw("'' AS phone"),
                'Debt_Amount AS balance',
                'First_Payment_Date AS first_payment_date',
                'Debt_Amount AS debt_amount',
                DB::raw("0 AS total_income"),
                'Payments AS payments',
                'State AS state',
                DB::raw("0 AS debt_ratio"),
            ])
            ->whereNotNull('LLG_ID');

        // Filters
        if (!empty($filters['llg_id'])) {
            $query->where(DB::raw("CONCAT('LLG-', LLG_ID)"), 'like', '%' . $filters['llg_id'] . '%');
        }

        if (!empty($filters['client'])) {
            $query->where('Client', 'like', '%' . $filters['client'] . '%');
        }

        if (!empty($filters['state'])) {
            $query->where('State', $filters['state']);
        }

        if (!empty($filters['first_payment_from'])) {
            $query->whereDate('First_Payment_Date', '>=', $filters['first_payment_from']);
        }

        if (!empty($filters['first_payment_to'])) {
            $query->whereDate('First_Payment_Date', '<=', $filters['first_payment_to']);
        }

        if (!empty($filters['debt_min'])) {
            $query->where('Debt_Amount', '>=', $filters['debt_min']);
        }

        if (!empty($filters['debt_max'])) {
            $query->where('Debt_Amount', '<=', $filters['debt_max']);
        }

        if (!empty($filters['balance_min'])) {
            $query->where('Debt_Amount', '>=', $filters['balance_min']);
        }

        if (!empty($filters['balance_max'])) {
            $query->where('Debt_Amount', '<=', $filters['balance_max']);
        }

        if (!empty($filters['payments_min'])) {
            $query->where('Payments', '>=', $filters['payments_min']);
        }

        if (!empty($filters['payments_max'])) {
            $query->where('Payments', '<=', $filters['payments_max']);
        }

        return $query->orderBy('Client');
    }
}
