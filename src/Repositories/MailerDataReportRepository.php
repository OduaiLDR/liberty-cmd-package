<?php

namespace Cmd\Reports\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MailerDataReportRepository extends SqlSrvRepository
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function all(?string $from = null, ?string $to = null, array $filters = []): Collection
    {
        return $this->baseQuery($from, $to, $filters)->get();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(?string $from = null, ?string $to = null, int $perPage = 25, array $filters = []): LengthAwarePaginator
    {
        return $this->paginateBuilder(
            $this->baseQuery($from, $to, $filters),
            $perPage
        );
    }

    /**
     * @return array<string, Collection<int, string>>
     */
    public function options(): array
    {
        $marketing = $this->table('TblMarketing');
        $contacts = $this->table('TblContacts');

        $pluckDistinct = fn($builder, string $column, int $limit = 500): Collection => $this->distinctValues($builder, $column, $limit);

        return [
            'drops' => $pluckDistinct($marketing, 'Drop_Name', 500),
            'vendors' => $pluckDistinct($marketing, 'Vendor'),
            'drop_types' => $pluckDistinct($marketing, 'Drop_Type'),
            'data_types' => $pluckDistinct($marketing, 'Data_Type'),
            'mail_styles' => $pluckDistinct($marketing, 'Mail_Style'),
            'debt_tiers' => $pluckDistinct($marketing, 'Debt_Tier'),
            'states' => $pluckDistinct($contacts, 'State'),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function baseQuery(?string $from = null, ?string $to = null, array $filters = [])
    {
        $query = $this->table('TblMarketing as m')
            ->leftJoin('TblContacts as c', 'c.Campaign', '=', 'm.Drop_Name')
            ->leftJoin('TblEnrollment as e', 'e.LLG_ID', '=', 'c.LLG_ID')
            ->select([
                'm.Drop_Name',
                'm.Send_Date',
                'm.Debt_Tier',
                'c.State',
                DB::raw('COALESCE(m.Amount_Dropped, 0) as [Count]'),
                DB::raw('MONTH(m.Send_Date) as [Month]'),
                DB::raw('YEAR(m.Send_Date) as [Year]'),
                'm.Drop_Type',
                'm.Data_Type',
                'm.Mail_Style',
                'm.Vendor',
                DB::raw('COUNT(1) as Total_Leads'),
                DB::raw('SUM(CASE WHEN e.LLG_ID IS NULL THEN 0 ELSE 1 END) as Qualified_Leads'),
                DB::raw('COUNT(1) - SUM(CASE WHEN e.LLG_ID IS NULL THEN 0 ELSE 1 END) as Unqualified_Leads'),
                DB::raw('SUM(CASE WHEN c.Assigned_Date IS NULL THEN 0 ELSE 1 END) as Assigned_Leads'),
                DB::raw('CAST(1 as bit) as Visible'),
            ])
            ->groupBy(
                'm.Drop_Name',
                'm.Send_Date',
                'm.Debt_Tier',
                'c.State',
                'm.Amount_Dropped',
                'm.Drop_Type',
                'm.Data_Type',
                'm.Mail_Style',
                'm.Vendor'
            );

        if ($from) {
            $query->whereDate('m.Send_Date', '>=', $from);
        }

        if ($to) {
            $query->whereDate('m.Send_Date', '<=', $to);
        }

        $this->applyFilters($query, $filters);

        return $query
            ->orderBy('m.Send_Date', 'desc')
            ->orderBy('m.Drop_Name', 'asc')
            ->orderBy('c.State', 'asc');
    }

    /**
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array<string, mixed>  $filters
     */
    protected function applyFilters($query, array $filters): void
    {
        $contains = static fn($value): bool => $value !== null && $value !== '';

        if ($contains($filters['drop_name'] ?? null)) {
            $query->where('m.Drop_Name', 'like', '%' . trim((string) $filters['drop_name']) . '%');
        }

        if ($contains($filters['send_date'] ?? null)) {
            $query->whereDate('m.Send_Date', '=', trim((string) $filters['send_date']));
        }

        if ($contains($filters['debt_tier'] ?? null)) {
            $query->where('m.Debt_Tier', 'like', '%' . trim((string) $filters['debt_tier']) . '%');
        }

        if ($contains($filters['state'] ?? null)) {
            $query->where('c.State', '=', strtoupper(trim((string) $filters['state'])));
        }

        if ($contains($filters['month'] ?? null)) {
            $month = (int) $filters['month'];
            if ($month >= 1 && $month <= 12) {
                $query->whereRaw('MONTH(m.Send_Date) = ?', [$month]);
            }
        }

        if ($contains($filters['year'] ?? null)) {
            $year = (int) $filters['year'];
            if ($year >= 2000 && $year <= 2100) {
                $query->whereRaw('YEAR(m.Send_Date) = ?', [$year]);
            }
        }

        if ($contains($filters['drop_type'] ?? null)) {
            $query->where('m.Drop_Type', 'like', '%' . trim((string) $filters['drop_type']) . '%');
        }

        if ($contains($filters['data_type'] ?? null)) {
            $query->where('m.Data_Type', 'like', '%' . trim((string) $filters['data_type']) . '%');
        }

        if ($contains($filters['mail_style'] ?? null)) {
            $query->where('m.Mail_Style', 'like', '%' . trim((string) $filters['mail_style']) . '%');
        }

        if ($contains($filters['vendor'] ?? null)) {
            $query->where('m.Vendor', 'like', '%' . trim((string) $filters['vendor']) . '%');
        }

        if ($contains($filters['visible'] ?? null)) {
            $visible = (int) $filters['visible'];
            $query->havingRaw('CAST(1 as int) = ?', [$visible]);
        }
    }
}
