<?php

namespace Cmd\Reports\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RetentionCommissionReportRepository extends SqlSrvRepository
{
    /**
     * @return array<string, string>
     */
    public function columns(): array
    {
        return [
            'id' => 'ID',
            'client' => 'Client',
            'retention_agent' => 'Retention Agent',
            'retention_date' => 'Retention Date',
            'immediate_results' => 'Immediate Results',
            'enrolled_debt' => 'Enrolled Debt',
            'cleared_payments' => 'Cleared Payments',
            'reconsideration_date' => 'Reconsideration Date',
            'dropped_date' => 'Dropped Date',
            'retained_date' => 'Retained Date',
            'retention_payment_date' => 'Retention Payment Date',
            'retention_commission_t1' => 'Retention Commission T1',
            'retention_commission_t2' => 'Retention Commission T2',
            'retention_commission_t3' => 'Retention Commission T3',
            'cancel_request_date' => 'Cancel Request Date',
        ];
    }

    /**
     * Gather select options for the retention commission report filters.
     *
     * @return array<string, Collection<int, string>>
     */
    public function options(): array
    {
        $query = $this->table('TblEnrollment');

        $pluckDistinct = fn(string $column, int $limit = 500): Collection => $this->distinctValues($query, $column, $limit);

        return [
            'states' => $pluckDistinct('State'),
            'agents' => $pluckDistinct('Agent'),
            'negotiators' => $pluckDistinct('Negotiator'),
            'enrollment_status' => $pluckDistinct('Enrollment_Status'),
            'clients' => $pluckDistinct('Client', 1000),
            'enrollment_plans' => $pluckDistinct('Enrollment_Plan'),
            'retention_agents' => collect([
                'Justin Wilson',
                'Melody Martinez',
                'Nick Jones',
                'Vicente Gonzalez',
                'Maria Lezana'
            ]),
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
        // Note: This is a simplified version since we don't have access to CONTACTS, CONTACTS_USERFIELDS, DEBTS, or TRANSACTIONS tables
        // We're using TblEnrollment as a proxy with mock data for demonstration
        $retentionAgents = [
            'Justin Wilson',
            'Melody Martinez', 
            'Nick Jones',
            'Vicente Gonzalez',
            'Maria Lezana'
        ];

        $query = $this->table('TblEnrollment')
            ->select([
                'LLG_ID AS id',
                'Client AS client',
                DB::raw("'" . $retentionAgents[array_rand($retentionAgents)] . "' AS retention_agent"), // Mock retention agent
                'Submitted_Date AS retention_date',
                'Enrollment_Plan AS immediate_results',
                'Debt_Amount AS enrolled_debt',
                DB::raw("0 AS cleared_payments"), // Mock data - TRANSACTIONS table not available
                DB::raw("NULL AS reconsideration_date"), // Mock data - CONTACTS_STATUS table not available
                'Cancel_Date AS dropped_date',
                DB::raw("NULL AS retained_date"), // Mock data
                DB::raw("NULL AS retention_payment_date"), // Mock data
                DB::raw("CASE 
                    WHEN Debt_Amount <= 15000 THEN 2
                    WHEN Debt_Amount <= 30000 THEN 5
                    WHEN Debt_Amount <= 60000 THEN 15
                    ELSE 20
                END AS retention_commission_t1"),
                DB::raw("CASE 
                    WHEN Debt_Amount <= 15000 THEN 5
                    WHEN Debt_Amount <= 30000 THEN 10
                    WHEN Debt_Amount <= 60000 THEN 30
                    ELSE 40
                END AS retention_commission_t2"),
                DB::raw("CASE 
                    WHEN Debt_Amount <= 15000 THEN 10
                    WHEN Debt_Amount <= 30000 THEN 20
                    WHEN Debt_Amount <= 60000 THEN 40
                    ELSE 60
                END AS retention_commission_t3"),
                DB::raw("NULL AS cancel_request_date"), // Mock data - CONTACTS_USERFIELDS table not available
            ])
            ->whereNotNull('LLG_ID')
            ->whereRaw("Client NOT LIKE '%TEST%'");

        // Apply filters
        if (!empty($filters['client'])) {
            $query->where('Client', 'like', '%' . $filters['client'] . '%');
        }

        if (!empty($filters['retention_agent'])) {
            $query->where(DB::raw("'" . $retentionAgents[array_rand($retentionAgents)] . "'"), 'like', '%' . $filters['retention_agent'] . '%');
        }

        if (!empty($filters['immediate_results'])) {
            $query->where('Enrollment_Plan', 'like', '%' . $filters['immediate_results'] . '%');
        }

        if (!empty($filters['retention_date_from'])) {
            $query->whereDate('Submitted_Date', '>=', $filters['retention_date_from']);
        }

        if (!empty($filters['retention_date_to'])) {
            $query->whereDate('Submitted_Date', '<=', $filters['retention_date_to']);
        }

        if (!empty($filters['dropped_date_from'])) {
            $query->whereDate('Cancel_Date', '>=', $filters['dropped_date_from']);
        }

        if (!empty($filters['dropped_date_to'])) {
            $query->whereDate('Cancel_Date', '<=', $filters['dropped_date_to']);
        }

        if (!empty($filters['enrolled_debt_min'])) {
            $query->where('Debt_Amount', '>=', $filters['enrolled_debt_min']);
        }

        if (!empty($filters['enrolled_debt_max'])) {
            $query->where('Debt_Amount', '<=', $filters['enrolled_debt_max']);
        }

        // Note: Many filters are not available due to missing tables:
        // - reconsideration_date_from/to (CONTACTS_STATUS table)
        // - retained_date_from/to (mock data)
        // - retention_payment_date_from/to (mock data)
        // - cleared_payments_min/max (TRANSACTIONS table)
        // - commission_t1/t2/t3_min/max (calculated fields)
        // - cancel_request_date_from/to (CONTACTS_USERFIELDS table)

        return $query->orderBy('Client', 'asc');
    }
}
