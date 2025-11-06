<?php

namespace Cmd\Reports\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class ContactReportRepository extends SqlSrvRepository
{
    /**
     * Retrieve all contacts (for export).
     *
     * @param  array<string, mixed>  $filters
     */
    public function all(
        ?string $assignedFrom = null,
        ?string $assignedTo = null,
        ?string $createdFrom = null,
        ?string $createdTo = null,
        array $filters = []
    ): Collection {
        return $this->baseQuery($assignedFrom, $assignedTo, $createdFrom, $createdTo, $filters)->get();
    }

    /**
     * Paginate contacts for the UI.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginate(
        ?string $assignedFrom = null,
        ?string $assignedTo = null,
        ?string $createdFrom = null,
        ?string $createdTo = null,
        int $perPage = 25,
        array $filters = []
    ): LengthAwarePaginator {
        return $this->paginateBuilder(
            $this->baseQuery($assignedFrom, $assignedTo, $createdFrom, $createdTo, $filters),
            $perPage
        );
    }

    /**
     * Distinct list options used in the filter form.
     *
     * @return array<string, Collection<int, string>>
     */
    public function options(): array
    {
        $base = $this->table('TblContacts');

        $pluck = fn(string $column, int $limit = 500): Collection => $this->distinctValues($base, $column, $limit);

        return [
            'agents' => $pluck('Agent'),
            'clients' => $pluck('Client'),
            'data_sources' => $pluck('Data_Source'),
            'stages' => $pluck('Stage'),
            'statuses' => $pluck('Status'),
            'states' => $pluck('State'),
        ];
    }

    /**
     * Build the base query shared by export & pagination.
     *
     * @param  array<string, mixed>  $filters
     */
    protected function baseQuery(
        ?string $assignedFrom = null,
        ?string $assignedTo = null,
        ?string $createdFrom = null,
        ?string $createdTo = null,
        array $filters = []
    ) {
        $query = $this->table('TblContacts')
            ->select([
                'Created_Date',
                'Assigned_Date',
                'LLG_ID',
                'External_ID',
                'Campaign',
                'Data_Source',
                'Agent',
                'Client',
                'Phone',
                'Email',
                'Stage',
                'Status',
                'Debt_Enrolled',
                'Address_1',
                'Address_2',
                'City',
                'State',
                'Zip',
                'Credit_Score',
            ]);

        if ($assignedFrom) {
            $query->whereDate('Assigned_Date', '>=', $assignedFrom);
        }

        if ($assignedTo) {
            $query->whereDate('Assigned_Date', '<=', $assignedTo);
        }

        if ($createdFrom) {
            $query->whereDate('Created_Date', '>=', $createdFrom);
        }

        if ($createdTo) {
            $query->whereDate('Created_Date', '<=', $createdTo);
        }

        $this->applyFilters($query, $filters);

        return $query->orderByDesc('Created_Date');
    }

    /**
     * Apply dynamic filters on the query.
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

        if ($contains($filters['client'] ?? null)) {
            $query->where('Client', 'like', '%' . trim((string) $filters['client']) . '%');
        }

        if ($contains($filters['data_source'] ?? null)) {
            $query->where('Data_Source', 'like', '%' . trim((string) $filters['data_source']) . '%');
        }

        if ($contains($filters['stage'] ?? null)) {
            $query->where('Stage', '=', trim((string) $filters['stage']));
        }

        if ($contains($filters['status'] ?? null)) {
            $query->where('Status', '=', trim((string) $filters['status']));
        }

        if ($contains($filters['state'] ?? null)) {
            $query->where('State', '=', trim((string) $filters['state']));
        }

        if ($contains($filters['debt_min'] ?? null)) {
            $query->where('Debt_Enrolled', '>=', (float) $filters['debt_min']);
        }

        if ($contains($filters['debt_max'] ?? null)) {
            $query->where('Debt_Enrolled', '<=', (float) $filters['debt_max']);
        }

        if ($contains($filters['score_min'] ?? null)) {
            $query->where('Credit_Score', '>=', (int) $filters['score_min']);
        }

        if ($contains($filters['score_max'] ?? null)) {
            $query->where('Credit_Score', '<=', (int) $filters['score_max']);
        }
    }
}
