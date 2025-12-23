<?php

namespace Cmd\Reports\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DroppedReportRepository extends SqlSrvRepository
{
    /**
     * @return array<string, string>
     */
    public function columns(): array
    {
        return [
            'llg_id' => 'CID',
            'client' => 'Client',
            'enrolled_date' => 'Enrolled Date',
            'dropped_date' => 'Dropped Date',
            'days_enrolled' => 'Days Enrolled',
            'enrollment_plan' => 'Enrollment Plan',
            'debt_amount' => 'Enrolled Debt',
            'dropped_reason' => 'Dropped Reason',
            'status' => 'Status',
        ];
    }

    /**
     * Gather select options for the dropped report filters.
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
                'Client AS client',
                'Submitted_Date AS enrolled_date',
                'Cancel_Date AS dropped_date',
                DB::raw("DATEDIFF(day, Submitted_Date, Cancel_Date) AS days_enrolled"),
                'Enrollment_Plan AS enrollment_plan',
                'Debt_Amount AS debt_amount',
                'Drop_Name AS dropped_reason',
                'Enrollment_Status AS status',
            ])
            ->whereNotNull('Cancel_Date')
            ->whereRaw("Client NOT LIKE '%TEST%'");

        // Apply filters
        if (!empty($filters['id'])) {
            $query->where('LLG_ID', 'like', '%' . $filters['id'] . '%');
        }

        if (!empty($filters['client'])) {
            $query->where('Client', 'like', '%' . $filters['client'] . '%');
        }

        if (!empty($filters['dropped_reason'])) {
            $query->where('Drop_Name', 'like', '%' . $filters['dropped_reason'] . '%');
        }

        if (!empty($filters['status'])) {
            $query->where('Enrollment_Status', 'like', '%' . $filters['status'] . '%');
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

        if (!empty($filters['days_enrolled_min'])) {
            $query->havingRaw("DATEDIFF(day, Submitted_Date, Cancel_Date) >= ?", [$filters['days_enrolled_min']]);
        }

        if (!empty($filters['days_enrolled_max'])) {
            $query->havingRaw("DATEDIFF(day, Submitted_Date, Cancel_Date) <= ?", [$filters['days_enrolled_max']]);
        }

        if (!empty($filters['debt_min'])) {
            $query->where('Debt_Amount', '>=', $filters['debt_min']);
        }

        if (!empty($filters['debt_max'])) {
            $query->where('Debt_Amount', '<=', $filters['debt_max']);
        }

        return $query->orderBy('Cancel_Date', 'desc');
    }
}
