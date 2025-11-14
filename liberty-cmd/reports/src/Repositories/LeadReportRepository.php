<?php

namespace Cmd\Reports\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LeadReportRepository extends SqlSrvRepository
{
    /**
     * Fetch all leads for CSV export.
     *
     * @param  array<string, mixed>  $filters
     */
    public function all(?string $from = null, ?string $to = null, array $filters = []): Collection
    {
        return $this->baseQuery($from, $to, $filters)->get();
    }

    /**
     * Paginate leads for the UI.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginate(?string $from = null, ?string $to = null, int $perPage = 25, array $filters = []): LengthAwarePaginator
    {
        return $this->paginateBuilder(
            $this->baseQuery($from, $to, $filters)->orderByDesc('c.Created_Date'),
            $perPage
        );
    }

    /**
     * Distinct filter options used by the lead report form.
     *
     * @return array<string, Collection<int, string>>
     */
    public function options(): array
    {
        $base = $this->table('TblContacts as c');

        $pluck = fn(string $column, int $limit = 500): Collection => $this->distinctValues($base, $column, $limit);

        return [
            'agents' => $pluck('Agent'),
            'data_sources' => $pluck('Data_Source'),
            'debt_tiers' => collect(range(1, 9))->map(fn($value) => (string) $value),
        ];
    }

    /**
     * Build the base query shared between list and export.
     *
     * @param  array<string, mixed>  $filters
     */
    protected function baseQuery(?string $from = null, ?string $to = null, array $filters = [])
    {
        $debtTier = $this->leadDebtTierCaseExpression('c.Debt_Amount');

        $query = $this->table('TblContacts as c')
            ->leftJoin('TblEnrollment as e', 'c.LLG_ID', '=', 'e.LLG_ID')
            ->select([
                'c.Created_Date',
                'c.Assigned_Date',
                'c.LLG_ID',
                'c.Campaign',
                'c.Data_Source',
                'c.Agent',
                'c.Client',
                'c.Phone',
                'c.Email',
                'c.State',
                'c.Stage',
                'c.Status',
                'c.Debt_Amount',
                DB::raw("{$debtTier} as Debt_Tier"),
                DB::raw('SUM(COALESCE(e.Debt_Amount, 0)) as Enrolled_Debt'),
                DB::raw('MAX(e.Submitted_Date) as Submitted_Date'),
                DB::raw('MAX(e.Welcome_Call_Date) as Welcome_Call_Date'),
                DB::raw('MAX(COALESCE(e.First_Payment_Date, e.Payment_Date_1)) as Payment_Date'),
                DB::raw('MAX(e.Cancel_Date) as Cancel_Date'),
                DB::raw('MAX(e.NSF_Date) as NSF_Date'),
            ])
            ->groupBy(
                'c.Created_Date',
                'c.Assigned_Date',
                'c.LLG_ID',
                'c.Campaign',
                'c.Data_Source',
                'c.Agent',
                'c.Client',
                'c.Phone',
                'c.Email',
                'c.State',
                'c.Stage',
                'c.Status',
                'c.Debt_Amount'
            );

        if ($from) {
            $query->whereDate('c.Created_Date', '>=', $from);
        }

        if ($to) {
            $query->whereDate('c.Created_Date', '<=', $to);
        }

        $this->applyFilters($query, $filters);

        return $query;
    }

    /**
     * Apply filter definitions to the query.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array<string, mixed>  $filters
     */
    protected function applyFilters($query, array $filters): void
    {
        $contains = static fn($value): bool => $value !== null && $value !== '';

        if ($contains($filters['agent'] ?? null)) {
            $query->where('c.Agent', 'like', '%' . trim((string) $filters['agent']) . '%');
        }

        if ($contains($filters['data_source'] ?? null)) {
            $query->where('c.Data_Source', 'like', '%' . trim((string) $filters['data_source']) . '%');
        }

        if ($contains($filters['debt_tier'] ?? null)) {
            $query->whereRaw($this->leadDebtTierCaseExpression('c.Debt_Amount') . ' = ?', [(int) $filters['debt_tier']]);
        }

        $statusType = $filters['status_type'] ?? 'all';
        if ($statusType && $statusType !== 'all') {
            switch ($statusType) {
                case 'active':
                    $query->where('c.Status', '=', 'Active');
                    break;
                case 'cancels':
                    $query->whereNotNull('e.Cancel_Date');
                    break;
                case 'nsfs':
                    $query->whereNotNull('e.NSF_Date');
                    break;
                case 'not_closed':
                    $query->whereNull('e.Submitted_Date');
                    break;
            }
        }
    }

    protected function leadDebtTierCaseExpression(string $column): string
    {
        return 'CASE'
            . " WHEN {$column} >= 0 AND {$column} < 12000 THEN 1"
            . " WHEN {$column} >= 12000 AND {$column} < 15001 THEN 2"
            . " WHEN {$column} >= 15001 AND {$column} < 19000 THEN 3"
            . " WHEN {$column} >= 19000 AND {$column} < 26000 THEN 4"
            . " WHEN {$column} >= 26000 AND {$column} < 35000 THEN 5"
            . " WHEN {$column} >= 35000 AND {$column} < 50000 THEN 6"
            . " WHEN {$column} >= 50000 AND {$column} < 65000 THEN 7"
            . " WHEN {$column} >= 65000 AND {$column} < 80000 THEN 8"
            . " WHEN {$column} >= 80000 THEN 9"
            . ' ELSE NULL END';
    }
}
