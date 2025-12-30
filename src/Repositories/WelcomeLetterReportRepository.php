<?php

namespace Cmd\Reports\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class WelcomeLetterReportRepository extends SqlSrvRepository
{
    /**
     * @return array<string, string>
     */
    public function columns(): array
    {
        return [
            'client' => 'Client',
            'plan' => 'Plan',
            'enrolled_debt_accounts' => 'Enrolled Debt Accounts',
            'enrolled_debt' => 'Enrolled Debt',
            'payment_date' => 'Payment Date',
            'payment' => 'Payment',
            'llg_id' => 'LLG ID',
            'frequency' => 'Frequency',
        ];
    }

    /**
     * Gather select options for the welcome letter report filters.
     *
     * @return array<string, Collection<int, string>>
     */
    public function options(): array
    {
        $query = $this->table('TblEnrollment')
            ->whereNotNull('Submitted_Date');

        $pluckDistinct = fn(string $column, int $limit = 500): Collection => $this->distinctValues($query, $column, $limit);

        return [
            'states' => $pluckDistinct('State'),
            'agents' => $pluckDistinct('Agent'),
            'negotiators' => $pluckDistinct('Negotiator'),
            'enrollment_status' => $pluckDistinct('Enrollment_Status'),
            'clients' => $pluckDistinct('Client', 1000),
            'enrollment_plans' => $pluckDistinct('Enrollment_Plan'),
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
        // Note: This is a simplified version since we don't have access to TRANSACTIONS table
        // The original SQL uses complex window functions and joins that aren't available in our simplified structure
        $query = $this->table('TblEnrollment')
            ->select([
                'Client AS client',
                'Enrollment_Plan AS plan',
                DB::raw("1 AS enrolled_debt_accounts"), // Simplified - would need DEBTS table
                'Debt_Amount AS enrolled_debt',
                'Submitted_Date AS payment_date', // Using enrolled date as payment date
                DB::raw("0 AS payment"), // Payment amount not available without TRANSACTIONS table
                DB::raw("CONCAT('LLG-', LLG_ID) AS llg_id"),
                DB::raw("CASE 
                    WHEN UPPER(Enrollment_Plan) LIKE '%WEEKLY%' THEN 'Weekly'
                    WHEN UPPER(Enrollment_Plan) LIKE '%BI-WEEKLY%' OR UPPER(Enrollment_Plan) LIKE '%BW%' THEN 'Bi-Weekly'
                    WHEN UPPER(Enrollment_Plan) LIKE '%SEMI-MONTHLY%' OR UPPER(Enrollment_Plan) LIKE '%SM%' THEN 'Semi-Monthly'
                    ELSE 'Monthly'
                END AS frequency"),
            ])
            ->whereNotNull('Submitted_Date')
            ->whereRaw("Client NOT LIKE '%TEST%'");

        // Apply date range filter (default to last 1-3 days based on weekday logic)
        if (!empty($filters['enrolled_from'])) {
            $query->whereDate('Submitted_Date', '>=', $filters['enrolled_from']);
        } else {
            // Default to yesterday or 3 days ago if Monday
            $query->whereDate('Submitted_Date', '>=', now()->subDays(now()->isMonday() ? 3 : 1)->toDateString());
        }

        if (!empty($filters['enrolled_to'])) {
            $query->whereDate('Submitted_Date', '<=', $filters['enrolled_to']);
        } else {
            // Default to yesterday
            $query->whereDate('Submitted_Date', '<=', now()->subDay()->toDateString());
        }

        // Apply payment date filter (using enrolled date as proxy)
        if (!empty($filters['payment_from'])) {
            $query->whereDate('Submitted_Date', '>=', $filters['payment_from']);
        }

        if (!empty($filters['payment_to'])) {
            $query->whereDate('Submitted_Date', '<=', $filters['payment_to']);
        }

        // Apply other filters
        if (!empty($filters['client'])) {
            $query->where('Client', 'like', '%' . $filters['client'] . '%');
        }

        if (!empty($filters['plan'])) {
            $query->where('Enrollment_Plan', 'like', '%' . $filters['plan'] . '%');
        }

        if (!empty($filters['frequency']) && $filters['frequency'] !== 'all') {
            $frequencyMap = [
                'weekly' => '%WEEKLY%',
                'bi-weekly' => '%BI-WEEKLY%',
                'semi-monthly' => '%SEMI-MONTHLY%',
                'monthly' => '%MONTHLY%'
            ];
            
            if (isset($frequencyMap[$filters['frequency']])) {
                $query->where('Enrollment_Plan', 'like', $frequencyMap[$filters['frequency']]);
            }
        }

        if (!empty($filters['debt_min'])) {
            $query->where('Debt_Amount', '>=', $filters['debt_min']);
        }

        if (!empty($filters['debt_max'])) {
            $query->where('Debt_Amount', '<=', $filters['debt_max']);
        }

        // Note: Address, City, State, Zip filters not available as these columns don't exist in TblEnrollment

        return $query->orderBy('Client', 'asc');
    }
}
