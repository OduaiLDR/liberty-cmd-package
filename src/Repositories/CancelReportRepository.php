<?php

namespace Cmd\Reports\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class CancelReportRepository extends SqlSrvRepository
{
    /**
     * Retrieve all cancel reports for CSV export.
     *
     * @param  array<string, mixed>  $filters
     */
    public function all(
        ?string $cancelFrom = null,
        ?string $cancelTo = null,
        ?string $submittedFrom = null,
        ?string $submittedTo = null,
        array $filters = []
    ): Collection {
        return $this->baseQuery($cancelFrom, $cancelTo, $submittedFrom, $submittedTo, $filters)->get();
    }

    /**
     * Retrieve paginated cancel reports for the UI.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginate(
        ?string $cancelFrom = null,
        ?string $cancelTo = null,
        ?string $submittedFrom = null,
        ?string $submittedTo = null,
        int $perPage = 25,
        array $filters = []
    ): LengthAwarePaginator {
        return $this->paginateBuilder(
            $this->baseQuery($cancelFrom, $cancelTo, $submittedFrom, $submittedTo, $filters),
            $perPage
        );
    }

    /**
     * Gather select options for the cancel report filters.
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
     * Build the common query used for both list and export workflows.
     *
     * @param  array<string, mixed>  $filters
     */
    protected function baseQuery(
        ?string $cancelFrom = null,
        ?string $cancelTo = null,
        ?string $submittedFrom = null,
        ?string $submittedTo = null,
        array $filters = []
    ) {
        $query = $this->table('TblEnrollment')
            ->select([
                'Drop_Name',
                'LLG_ID',
                'State',
                'Agent',
                'Client',
                'Debt_Amount',
                'Welcome_Call_Date',
                'Payment_Date_1',
                'Payment_Date_2',
                'Cancel_Date',
                'NSF_Date',
                'Payments',
                'Negotiator',
                'Negotiator_Assigned_Date',
                'First_Payment_Date',
                'First_Payment_Cleared_Date',
                'Enrolled_Debt_Accounts',
                'Enrollment_Status',
                'Enrollment_Plan',
                'Program_Payment',
                'Program_Length',
                'First_Payment_Status',
                'Submitted_Date',
            ])
            ->whereNotNull('Cancel_Date');

        if ($cancelFrom) {
            $query->whereDate('Cancel_Date', '>=', $cancelFrom);
        }

        if ($cancelTo) {
            $query->whereDate('Cancel_Date', '<=', $cancelTo);
        }

        if ($submittedFrom) {
            $query->whereDate('Submitted_Date', '>=', $submittedFrom);
        }

        if ($submittedTo) {
            $query->whereDate('Submitted_Date', '<=', $submittedTo);
        }

        $this->applyFilters($query, $filters);

        return $query->orderByDesc('Submitted_Date');
    }

    /**
     * Apply user supplied filters to the query.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array<string, mixed>  $filters
     */
    protected function applyFilters($query, array $filters): void
    {
        $contains = static function ($value): bool {
            return $value !== null && $value !== '';
        };

        if ($contains($filters['agent'] ?? null)) {
            $query->where('Agent', 'like', '%' . trim((string) $filters['agent']) . '%');
        }

        if ($contains($filters['client'] ?? null)) {
            $query->where('Client', 'like', '%' . trim((string) $filters['client']) . '%');
        }

        if ($contains($filters['negotiator'] ?? null)) {
            $query->where('Negotiator', 'like', '%' . trim((string) $filters['negotiator']) . '%');
        }

        if ($contains($filters['state'] ?? null)) {
            $query->where('State', '=', trim((string) $filters['state']));
        }

        if ($contains($filters['enrollment_status'] ?? null)) {
            $query->where('Enrollment_Status', '=', trim((string) $filters['enrollment_status']));
        }

        if ($contains($filters['debt_min'] ?? null)) {
            $query->where('Debt_Amount', '>=', (float) $filters['debt_min']);
        }

        if ($contains($filters['debt_max'] ?? null)) {
            $query->where('Debt_Amount', '<=', (float) $filters['debt_max']);
        }

        if ($contains($filters['length_min'] ?? null)) {
            $query->where('Program_Length', '>=', (int) $filters['length_min']);
        }

        if ($contains($filters['length_max'] ?? null)) {
            $query->where('Program_Length', '<=', (int) $filters['length_max']);
        }

        if ($contains($filters['company'] ?? null)) {
            $value = strtolower(trim((string) $filters['company']));

            if ($value === 'progress') {
                $query->where('Enrollment_Plan', 'like', '%Progress%');
            } elseif ($value === 'ldr') {
                $query->where('Enrollment_Plan', 'not like', '%Progress%');
            }
        }
    }
}
