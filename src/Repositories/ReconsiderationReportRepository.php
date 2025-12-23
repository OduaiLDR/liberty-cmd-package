<?php

namespace Cmd\Reports\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReconsiderationReportRepository extends SqlSrvRepository
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
            'dropped_by' => 'Dropped By',
            'debt_amount' => 'Enrolled Debt',
            'active_status' => 'Active Status',
            'current_status' => 'Current Status',
            'status_date' => 'Status Date',
            'last_status_by' => 'Last Status By',
            'retention_agent' => 'Retention Agent',
            'assigned_to' => 'Assigned To',
            'retention_immediate_results' => 'Retention Immediate Results',
        ];
    }

    /**
     * Gather select options for the reconsideration report filters.
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
                'Drop_Name AS dropped_by',
                'Debt_Amount AS debt_amount',
                DB::raw("CASE WHEN Cancel_Date IS NULL THEN 'Active' ELSE 'Dropped' END AS active_status"),
                'Enrollment_Status AS current_status',
                'Submitted_Date AS status_date',
                'Agent AS last_status_by',
                'Agent AS retention_agent',
                'Negotiator AS assigned_to',
                'Enrollment_Plan AS retention_immediate_results',
            ])
            ->whereNotNull('Submitted_Date')
            ->whereRaw("Client NOT LIKE '%TEST%'")
            ->whereNotNull('Cancel_Date');

        // Apply filters
        if (!empty($filters['id'])) {
            $query->where('LLG_ID', 'like', '%' . $filters['id'] . '%');
        }

        if (!empty($filters['client'])) {
            $query->where('Client', 'like', '%' . $filters['client'] . '%');
        }

        if (!empty($filters['dropped_by'])) {
            $query->where('Drop_Name', 'like', '%' . $filters['dropped_by'] . '%');
        }

        if (!empty($filters['retention_agent'])) {
            $query->where('Agent', 'like', '%' . $filters['retention_agent'] . '%');
        }

        if (!empty($filters['assigned_to'])) {
            $query->where('Negotiator', 'like', '%' . $filters['assigned_to'] . '%');
        }

        if (!empty($filters['active_status'])) {
            if ($filters['active_status'] === 'Active') {
                $query->whereNull('Cancel_Date');
            } elseif ($filters['active_status'] === 'Dropped') {
                $query->whereNotNull('Cancel_Date');
            }
        }

        if (!empty($filters['current_status'])) {
            $query->where('Enrollment_Status', 'like', '%' . $filters['current_status'] . '%');
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

        if (!empty($filters['status_date_from'])) {
            $query->whereDate('Submitted_Date', '>=', $filters['status_date_from']);
        }

        if (!empty($filters['status_date_to'])) {
            $query->whereDate('Submitted_Date', '<=', $filters['status_date_to']);
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
