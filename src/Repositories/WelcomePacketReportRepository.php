<?php

namespace Cmd\Reports\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class WelcomePacketReportRepository extends SqlSrvRepository
{
    /**
     * @return array<string, string>
     */
    public function columns(): array
    {
        return [
            'llg_id' => 'LLG ID',
            'client' => 'Client',
            'plan' => 'Plan',
            'cleared_date' => 'Cleared Date',
            'return_address' => 'Return Address',
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
        // TRANSACTIONS/CONTACTS tables unavailable; using TblEnrollment with Submitted_Date as cleared date proxy
        $query = $this->table('TblEnrollment')
            ->select([
                DB::raw("CONCAT('LLG-', LLG_ID) AS llg_id"),
                'Client AS client',
                'Enrollment_Plan AS plan',
                'Submitted_Date AS cleared_date',
                DB::raw("'8383 Wilshire Blvd Suite 800. Beverly Hills, CA 90211' AS return_address"),
            ])
            ->whereNotNull('LLG_ID')
            ->whereNotNull('Submitted_Date');

        // Default date window: last 7 to last 1 days
        if (!empty($filters['cleared_from'])) {
            $query->whereDate('Submitted_Date', '>=', $filters['cleared_from']);
        } else {
            $query->whereDate('Submitted_Date', '>=', now()->subDays(7)->toDateString());
        }

        if (!empty($filters['cleared_to'])) {
            $query->whereDate('Submitted_Date', '<=', $filters['cleared_to']);
        } else {
            $query->whereDate('Submitted_Date', '<=', now()->subDay()->toDateString());
        }

        if (!empty($filters['client'])) {
            $query->where('Client', 'like', '%' . $filters['client'] . '%');
        }

        if (!empty($filters['plan'])) {
            $query->where('Enrollment_Plan', 'like', '%' . $filters['plan'] . '%');
        }

        if (!empty($filters['llg_id'])) {
            $query->where(DB::raw("CONCAT('LLG-', LLG_ID)"), 'like', '%' . $filters['llg_id'] . '%');
        }

        return $query->orderBy('Submitted_Date', 'asc');
    }
}
