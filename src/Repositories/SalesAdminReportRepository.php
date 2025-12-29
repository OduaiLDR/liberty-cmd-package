<?php

namespace Cmd\Reports\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SalesAdminReportRepository extends SqlSrvRepository
{
    /**
     * @return array<string, string>
     */
    public function columns(): array
    {
        return [
            'llg_id' => 'LLG ID',
            'agent' => 'Agent',
            'client' => 'Client',
            'state' => 'State',
            'submitted_date' => 'Submitted Date',
            'welcome_call_date' => 'Welcome Call Date',
            'payment_date' => 'Payment Date',
            'first_payment_cleared_date' => 'First Payment Cleared Date',
            'debt_amount' => 'Debt Amount',
            'enrolled_debt_accounts' => 'Enrolled Debt Accounts',
            'program_length' => 'Program Length',
            'monthly_deposit' => 'Monthly Deposit',
            'payments' => 'Payments',
            'enrollment_status' => 'Enrollment Status',
            'enrollment_plan' => 'Enrollment Plan',
            'first_payment_status' => 'First Payment Status',
            'negotiator' => 'Negotiator',
            'negotiator_assigned_date' => 'Negotiator Assigned Date',
            'cancel_date' => 'Cancel Date',
            'nsf_date' => 'NSF Date',
            'drop_name' => 'Drop Name',
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
        $query = $this->table('TblEnrollment')
            ->select([
                DB::raw("CONCAT('LLG-', LLG_ID) AS llg_id"),
                'Agent AS agent',
                'Client AS client',
                'State AS state',
                'Submitted_Date AS submitted_date',
                'Welcome_Call_Date AS welcome_call_date',
                'First_Payment_Date AS payment_date',
                'First_Payment_Cleared_Date AS first_payment_cleared_date',
                'Debt_Amount AS debt_amount',
                'Enrolled_Debt_Accounts AS enrolled_debt_accounts',
                'Program_Length AS program_length',
                'Program_Payment AS monthly_deposit',
                'Payments AS payments',
                'Enrollment_Status AS enrollment_status',
                'Enrollment_Plan AS enrollment_plan',
                'First_Payment_Status AS first_payment_status',
                'Negotiator AS negotiator',
                'Negotiator_Assigned_Date AS negotiator_assigned_date',
                'Cancel_Date AS cancel_date',
                'NSF_Date AS nsf_date',
                'Drop_Name AS drop_name',
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

        if (!empty($filters['payment_date_from'])) {
            $query->whereDate('First_Payment_Date', '>=', $filters['payment_date_from']);
        }

        if (!empty($filters['payment_date_to'])) {
            $query->whereDate('First_Payment_Date', '<=', $filters['payment_date_to']);
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

        if (!empty($filters['payments_min'])) {
            $query->where('Payments', '>=', $filters['payments_min']);
        }

        if (!empty($filters['payments_max'])) {
            $query->where('Payments', '<=', $filters['payments_max']);
        }

        if (!empty($filters['cancel_from'])) {
            $query->whereDate('Cancel_Date', '>=', $filters['cancel_from']);
        }

        if (!empty($filters['cancel_to'])) {
            $query->whereDate('Cancel_Date', '<=', $filters['cancel_to']);
        }

        if (!empty($filters['nsf_from'])) {
            $query->whereDate('NSF_Date', '>=', $filters['nsf_from']);
        }

        if (!empty($filters['nsf_to'])) {
            $query->whereDate('NSF_Date', '<=', $filters['nsf_to']);
        }

        return $query->orderBy('First_Payment_Date', 'desc');
    }
}
