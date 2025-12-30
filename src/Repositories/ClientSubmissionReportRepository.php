<?php

namespace Cmd\Reports\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ClientSubmissionReportRepository extends SqlSrvRepository
{
    /**
     * @return array<string, string>
     */
    public function columns(): array
    {
        return [
            'date' => 'Date',
            'agent' => 'Agent',
            'record_id' => 'Record ID',
            'client' => 'Client',
            'first_payment_date' => 'First Payment Date',
            'enrolled_debt_amount' => 'Enrolled Debt Amount',
            'program_length' => 'Program Length',
            'monthly_deposit' => 'Monthly Deposit',
            'veritas_initial_setup_fee' => 'Veritas Initial Setup Fee',
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
        // Using TblEnrollment columns as available
        $query = $this->table('TblEnrollment')
            ->select([
                'Submitted_Date AS date',
                'Agent AS agent',
                DB::raw("CONCAT('LLG-', LLG_ID) AS record_id"),
                'Client AS client',
                'First_Payment_Date AS first_payment_date',
                'Debt_Amount AS enrolled_debt_amount',
                'Program_Length AS program_length',
                'Program_Payment AS monthly_deposit',
                DB::raw('0 AS veritas_initial_setup_fee'),
            ])
            ->whereNotNull('LLG_ID');

        if (!empty($filters['agent'])) {
            $query->where('Agent', 'like', '%' . $filters['agent'] . '%');
        }

        if (!empty($filters['client'])) {
            $query->where('Client', 'like', '%' . $filters['client'] . '%');
        }

        if (!empty($filters['llg_id'])) {
            $query->where(DB::raw("CONCAT('LLG-', LLG_ID)"), 'like', '%' . $filters['llg_id'] . '%');
        }

        if (!empty($filters['date_from'])) {
            $query->whereDate('Submitted_Date', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->whereDate('Submitted_Date', '<=', $filters['date_to']);
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

        if (!empty($filters['program_length_min'])) {
            $query->where('Program_Length', '>=', $filters['program_length_min']);
        }

        if (!empty($filters['program_length_max'])) {
            $query->where('Program_Length', '<=', $filters['program_length_max']);
        }

        if (!empty($filters['monthly_deposit_min'])) {
            $query->where('Program_Payment', '>=', $filters['monthly_deposit_min']);
        }

        if (!empty($filters['monthly_deposit_max'])) {
            $query->where('Program_Payment', '<=', $filters['monthly_deposit_max']);
        }

        return $query->orderBy('Submitted_Date', 'desc');
    }
}
