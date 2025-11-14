<?php

namespace Cmd\Reports\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class EnrollmentReportRepository extends SqlSrvRepository
{
    /**
     * Retrieve all enrollment rows (for CSV export).
     *
     * @param  array<string, mixed>  $filters
     */
    public function all(
        ?string $from = null,
        ?string $to = null,
        string $dateBy = 'submitted',
        array $filters = []
    ): Collection {
        return $this->baseQuery($from, $to, $dateBy, $filters)->get();
    }

    /**
     * Paginate enrollment rows for the UI.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginate(
        ?string $from = null,
        ?string $to = null,
        int $perPage = 25,
        string $dateBy = 'submitted',
        array $filters = []
    ): LengthAwarePaginator {
        return $this->paginateBuilder(
            $this->baseQuery($from, $to, $dateBy, $filters),
            $perPage
        );
    }

    /**
     * Distinct filter options for dropdowns/datalists.
     *
     * @return array<string, Collection<int, string>>
     */
    public function options(): array
    {
        $base = $this->table('TblEnrollment');

        $pluck = fn(string $column, int $limit = 500): Collection => $this->distinctValues($base, $column, $limit);

        return [
            'states' => $pluck('State'),
            'agents' => $pluck('Agent'),
            'negotiators' => $pluck('Negotiator'),
            'enrollment_status' => $pluck('Enrollment_Status'),
        ];
    }

    /**
     * Build the shared base query for enrollment reports.
     *
     * @param  array<string, mixed>  $filters
     */
    protected function baseQuery(
        ?string $from = null,
        ?string $to = null,
        string $dateBy = 'submitted',
        array $filters = []
    ) {
        $query = $this->table('TblEnrollment')
            ->select([
                'PK',
                'Drop_Name',
                'LLG_ID',
                'Category',
                'State',
                'Agent',
                'Negotiator',
                'Client',
                'Debt_Amount',
                'Welcome_Call_Date',
                'Submitted_Date',
                'Payment_Date_1',
                'Payment_Date_2',
                'First_Payment_Cleared_Date',
                'Cancel_Date',
                'NSF_Date',
                'Payments',
            ]);

        $this->applyDateFilter($query, $dateBy, $from, $to);
        $this->applyFilters($query, $filters);

        return $query->orderByDesc('Submitted_Date');
    }

    protected function applyDateFilter($query, string $dateBy, ?string $from, ?string $to): void
    {
        if ($dateBy === 'welcome_call') {
            if ($from) {
                $query->whereDate('Welcome_Call_Date', '>=', $from);
            }
            if ($to) {
                $query->whereDate('Welcome_Call_Date', '<=', $to);
            }
            return;
        }

        if ($dateBy === 'payment') {
            if ($from) {
                $query->whereRaw('CAST(COALESCE(First_Payment_Cleared_Date, Payment_Date_2, Payment_Date_1) AS date) >= ?', [$from]);
            }
            if ($to) {
                $query->whereRaw('CAST(COALESCE(First_Payment_Cleared_Date, Payment_Date_2, Payment_Date_1) AS date) <= ?', [$to]);
            }
            return;
        }

        if ($from) {
            $query->whereDate('Submitted_Date', '>=', $from);
        }
        if ($to) {
            $query->whereDate('Submitted_Date', '<=', $to);
        }
    }

    /**
     * Apply additional filters.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array<string, mixed>  $filters
     */
    protected function applyFilters($query, array $filters): void
    {
        $contains = static fn($value): bool => $value !== null && $value !== '';

        if ($contains($filters['agent'] ?? null)) {
            $query->where('Agent', 'like', '%' . trim((string) $filters['agent']) . '%');
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
