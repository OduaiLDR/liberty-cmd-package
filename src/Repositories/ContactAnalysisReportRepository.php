<?php

namespace Cmd\Reports\Repositories;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ContactAnalysisReportRepository extends SqlSrvRepository
{
    /**
     * Get weekly aggregated data for Contact Analysis (all rows for charts/totals).
     *
     * @param array<string, mixed> $filters
     */
    public function getWeeklyData(?string $from = null, ?string $to = null, array $filters = [], array $chartFilters = []): Collection
    {
        return $this->baseWeeklyQuery($from, $to, $filters, $chartFilters)
            ->orderBy('start_date', 'desc')
            ->get()
            ->map(fn($row) => $this->mapRow($row));
    }

    /**
     * Paginate weekly aggregated data for table display.
     *
     * @param array<string, mixed> $filters
     */
    public function paginateWeekly(?string $from = null, ?string $to = null, array $filters = [], int $perPage = 15)
    {
        $paginator = $this->baseWeeklyQuery($from, $to, $filters, [])
            ->orderBy('start_date', 'desc')
            ->paginate($perPage);

        $paginator->getCollection()->transform(fn($row) => $this->mapRow($row));

        return $paginator;
    }

    /**
     * Shared query builder for weekly aggregation.
     *
     * @param array<string, mixed> $filters
     */
    protected function baseWeeklyQuery(?string $from = null, ?string $to = null, array $filters = [], array $chartFilters = [])
    {
        $query = $this->connection()->table('TblContacts as c')
            ->leftJoin('TblEnrollment as e', 'c.LLG_ID', '=', 'e.LLG_ID')
            ->selectRaw("
                DATEADD(week, DATEDIFF(week, 0, c.Created_Date), 0) as start_date,
                DATEADD(day, 6, DATEADD(week, DATEDIFF(week, 0, c.Created_Date), 0)) as end_date,
                COUNT(DISTINCT c.LLG_ID) as contacts,
                SUM(COALESCE(e.Debt_Amount, 0)) as enrolled_debt,
                COUNT(CASE WHEN e.Welcome_Call_Date IS NOT NULL THEN 1 END) as wcc,
                COUNT(CASE WHEN e.Cancel_Date IS NOT NULL THEN 1 END) as cancels,
                COUNT(CASE WHEN e.NSF_Date IS NOT NULL THEN 1 END) as nsfs,
                COUNT(CASE WHEN e.Enrollment_Status = 'Active' THEN 1 END) as active_deals,
                AVG(COALESCE(e.Debt_Amount, 0)) as avg_debt,
                MIN(c.Created_Date) as mail_date,
                COUNT(DISTINCT CASE WHEN c.Data_Source LIKE '%mailer%' THEN c.LLG_ID END) as mailers
            ")
            ->groupBy(DB::raw('DATEADD(week, DATEDIFF(week, 0, c.Created_Date), 0)'));

        if ($from) {
            $query->whereDate('c.Created_Date', '>=', $from);
        }

        if ($to) {
            $query->whereDate('c.Created_Date', '<=', $to);
        }

        if (!empty($filters['data_source'])) {
            $query->where('c.Data_Source', 'like', '%' . trim((string) $filters['data_source']) . '%');
        }

        if (!empty($chartFilters['chart_year'])) {
            $query->whereYear('c.Created_Date', (int) $chartFilters['chart_year']);
        }

        if (!empty($chartFilters['chart_month'])) {
            $query->whereMonth('c.Created_Date', (int) $chartFilters['chart_month']);
        }

        if (!empty($chartFilters['chart_min_debt'])) {
            $query->whereRaw('COALESCE(e.Debt_Amount, 0) >= ?', [(float) $chartFilters['chart_min_debt']]);
        }

        if (!empty($chartFilters['chart_max_debt'])) {
            $query->whereRaw('COALESCE(e.Debt_Amount, 0) <= ?', [(float) $chartFilters['chart_max_debt']]);
        }

        return $query;
    }

    protected function mapRow($row): object
    {
        $contacts = (int) ($row->contacts ?? 0);
        $mailers = (int) ($row->mailers ?? 0);
        $responseRate = $mailers > 0 ? ($contacts / $mailers) : 0;

        return (object) [
            'start_date' => $row->start_date,
            'end_date' => $row->end_date,
            'contacts' => $contacts,
            'enrolled_debt' => (float) ($row->enrolled_debt ?? 0),
            'wcc' => (int) ($row->wcc ?? 0),
            'cancels' => (int) ($row->cancels ?? 0),
            'nsfs' => (int) ($row->nsfs ?? 0),
            'active_deals' => (int) ($row->active_deals ?? 0),
            'avg_debt' => (float) ($row->avg_debt ?? 0),
            'mail_date' => $row->mail_date,
            'mailers' => $mailers,
            'response_rate' => $responseRate,
        ];
    }

    /**
     * Get chart data for Contacts (mailers vs contacts over time).
     */
    public function getContactsChartData(Collection $data): array
    {
        $labels = [];
        $mailers = [];
        $contacts = [];

        foreach ($data->sortBy('start_date') as $row) {
            $labels[] = $row->start_date ? date('n/j/Y', strtotime($row->start_date)) : '';
            $mailers[] = $row->mailers;
            $contacts[] = $row->contacts;
        }

        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => 'Mailers',
                    'data' => $mailers,
                    'backgroundColor' => 'rgba(144, 238, 144, 0.7)',
                    'borderColor' => 'rgba(34, 139, 34, 1)',
                    'fill' => true,
                ],
                [
                    'label' => 'Contacts',
                    'data' => $contacts,
                    'backgroundColor' => 'rgba(70, 130, 180, 0.7)',
                    'borderColor' => 'rgba(0, 0, 139, 1)',
                    'fill' => true,
                ],
            ],
        ];
    }

    /**
     * Get chart data for Enrolled Debt over time.
     */
    public function getEnrolledDebtChartData(Collection $data): array
    {
        $labels = [];
        $mailers = [];
        $enrolledDebt = [];

        foreach ($data->sortBy('start_date') as $row) {
            $labels[] = $row->start_date ? date('n/j/Y', strtotime($row->start_date)) : '';
            $mailers[] = $row->mailers;
            $enrolledDebt[] = $row->enrolled_debt;
        }

        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => 'Mailers',
                    'data' => $mailers,
                    'backgroundColor' => 'rgba(144, 238, 144, 0.7)',
                    'borderColor' => 'rgba(34, 139, 34, 1)',
                    'fill' => true,
                    'yAxisID' => 'y1',
                ],
                [
                    'label' => 'Enrolled Debt',
                    'data' => $enrolledDebt,
                    'backgroundColor' => 'rgba(70, 130, 180, 0.7)',
                    'borderColor' => 'rgba(0, 0, 139, 1)',
                    'fill' => true,
                    'yAxisID' => 'y',
                ],
            ],
        ];
    }

    /**
     * Get chart data for Response Rate over time.
     */
    public function getResponseRateChartData(Collection $data): array
    {
        $labels = [];
        $mailers = [];
        $responseRate = [];

        foreach ($data->sortBy('start_date') as $row) {
            $labels[] = $row->start_date ? date('n/j/Y', strtotime($row->start_date)) : '';
            $mailers[] = $row->mailers;
            $responseRate[] = round($row->response_rate * 100, 2);
        }

        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => 'Mailers',
                    'data' => $mailers,
                    'backgroundColor' => 'rgba(144, 238, 144, 0.7)',
                    'borderColor' => 'rgba(34, 139, 34, 1)',
                    'fill' => true,
                    'yAxisID' => 'y1',
                ],
                [
                    'label' => 'Response Rate %',
                    'data' => $responseRate,
                    'backgroundColor' => 'rgba(70, 130, 180, 0.7)',
                    'borderColor' => 'rgba(0, 0, 139, 1)',
                    'fill' => true,
                    'yAxisID' => 'y',
                ],
            ],
        ];
    }

    /**
     * Get available data sources for filter dropdown.
     */
    public function getDataSources(): Collection
    {
        return $this->distinctValues(
            $this->table('TblContacts'),
            'Data_Source',
            100
        );
    }
}
