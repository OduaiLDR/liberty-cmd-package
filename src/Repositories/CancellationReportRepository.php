<?php

namespace Cmd\Reports\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CancellationReportRepository extends SqlSrvRepository
{
    /**
     * @return array<string, string>
     */
    public function columns(): array
    {
        return [
            'llg_id' => 'CID',
            'enrolled_date' => 'Enrolled Date',
            'dropped_date' => 'Dropped Date',
            'payments' => 'Payments',
            'enrollment_plan' => 'Enrollment Plan',
            'category' => 'Category',
        ];
    }

    /**
     * Gather select options for the cancellation report filters.
     *
     * @return array<string, Collection<int, string>>
     */
    public function options(): array
    {
        $query = $this->table('TblEnrollment')
            ->whereNotNull('Cancel_Date');

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
        $query = $this->table('TblEnrollment')
            ->select([
                'LLG_ID AS llg_id',
                'Submitted_Date AS enrolled_date',
                'Cancel_Date AS dropped_date',
                DB::raw("0 AS payments"),
                'Enrollment_Plan AS enrollment_plan',
                DB::raw("CASE 
                    WHEN UPPER(LEFT(Enrollment_Plan, 3)) = 'LDR' THEN 'LDR'
                    WHEN UPPER(LEFT(Enrollment_Plan, 4)) = 'LT L' THEN 'LDR'
                    WHEN UPPER(LEFT(Enrollment_Plan, 4)) = 'PLAW' THEN 'PLAW'
                    WHEN CHARINDEX('Progress', Enrollment_Plan) > 0 THEN 'ProLaw'
                    WHEN UPPER(LEFT(Enrollment_Plan, 4)) = 'LT P' THEN 'ProLaw'
                    WHEN UPPER(LEFT(Enrollment_Plan, 3)) = 'CCS' THEN 'ProLaw'
                    ELSE 'Other'
                END AS category"),
            ])
            ->whereNotNull('Cancel_Date')
            ->whereRaw("Client NOT LIKE '%TEST%'");

        // Apply category filter
        if (!empty($filters['category']) && $filters['category'] !== 'all') {
            if ($filters['category'] === 'ldr') {
                $query->whereRaw("(UPPER(LEFT(Enrollment_Plan, 3)) = 'LDR' OR UPPER(LEFT(Enrollment_Plan, 4)) = 'LT L')");
            } elseif ($filters['category'] === 'plaw') {
                $query->whereRaw("(UPPER(LEFT(Enrollment_Plan, 4)) = 'PLAW' OR CHARINDEX('Progress', Enrollment_Plan) > 0 OR UPPER(LEFT(Enrollment_Plan, 4)) = 'LT P' OR UPPER(LEFT(Enrollment_Plan, 3)) = 'CCS')");
            }
        }

        // Apply other filters
        if (!empty($filters['id'])) {
            $query->where('LLG_ID', 'like', '%' . $filters['id'] . '%');
        }

        if (!empty($filters['enrollment_plan'])) {
            $query->where('Enrollment_Plan', 'like', '%' . $filters['enrollment_plan'] . '%');
        }

        if (!empty($filters['enrolled_from'])) {
            $query->whereDate('Submitted_Date', '>=', $filters['enrolled_from']);
        }

        if (!empty($filters['enrolled_to'])) {
            $query->whereDate('Submitted_Date', '<=', $filters['enrolled_to']);
        }

        if (!empty($filters['dropped_from'])) {
            $query->whereDate('Cancel_Date', '>=', $filters['dropped_from']);
        }

        if (!empty($filters['dropped_to'])) {
            $query->whereDate('Cancel_Date', '<=', $filters['dropped_to']);
        }

        // Note: Payments filter removed since TRANSACTIONS table doesn't exist
        // Payments column shows 0 for all records until TRANSACTIONS table is available

        return $query->orderBy('Cancel_Date', 'desc');
    }
}
