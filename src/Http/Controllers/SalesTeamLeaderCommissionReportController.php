<?php

namespace Cmd\Reports\Http\Controllers;

use Carbon\Carbon;
use Cmd\Reports\Http\Requests\SalesTeamLeaderCommissionReportRequest;
use Cmd\Reports\Repositories\SalesTeamLeaderCommissionReportRepository;
use Cmd\Reports\Support\CsvFormatting;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SalesTeamLeaderCommissionReportController extends Controller
{
    use CsvFormatting;

    public function __construct(
        protected SalesTeamLeaderCommissionReportRepository $repository
    ) {
    }

    public function index(SalesTeamLeaderCommissionReportRequest $request): View|StreamedResponse
    {
        $perPage = $this->normalizePerPage($request->input('per_page', 25));
        $filters = $this->extractFilters($request);

        if ($request->input('export') === 'csv') {
            $rows = $this->repository->all($filters);
            return $this->exportCsv($rows);
        }

        $allRows = $this->repository->all($filters);
        $totals = [
            'enrollments' => $allRows->sum('enrollments'),
            'debt_amount' => $allRows->sum('debt_amount'),
            'lookback_count' => $allRows->sum('lookback_count'),
            'lookback_debt' => $allRows->sum('lookback_debt'),
            'net_debt' => $allRows->sum('net_debt'),
            'commission' => $allRows->sum('commission'),
        ];

        $reports = $this->repository->paginate($perPage, $filters);

        return view('reports::reports.sales_team_leader_commission', [
            'reports' => $reports,
            'columns' => $this->repository->columns(),
            'filters' => $filters,
            'perPage' => $perPage,
            'totals' => $totals,
        ]);
    }

    protected function normalizePerPage(int|string|null $perPage): int
    {
        $perPage = (int) ($perPage ?? 25);
        return $perPage > 0 && $perPage <= 1000 ? $perPage : 25;
    }

    /**
     * @return array<string, mixed>
     */
    protected function extractFilters(Request $request): array
    {
        return [
            'agent' => $this->trimOrNull($request->input('agent')),
            'month' => $this->trimOrNull($request->input('month')),
            'year' => $this->trimOrNull($request->input('year')),
            'debt_min' => $this->trimOrNull($request->input('debt_min')),
            'debt_max' => $this->trimOrNull($request->input('debt_max')),
            'enrollments_min' => $this->trimOrNull($request->input('enrollments_min')),
            'enrollments_max' => $this->trimOrNull($request->input('enrollments_max')),
        ];
    }

    protected function trimOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string) $value);
        return $trimmed === '' ? null : $trimmed;
    }

    protected function exportCsv(Collection $rows): StreamedResponse
    {
        $filename = 'sales_team_leader_commission_' . Carbon::now()->format('Ymd_His') . '.csv';
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');

            fputcsv($out, [
                'Agent',
                'Enrollments',
                'Debt Amount',
                'Lookback Count',
                'Lookback Debt',
                'Net Debt',
                'Commission',
            ]);

            foreach ($rows as $row) {
                fputcsv($out, [
                    $row->agent,
                    $row->enrollments,
                    $this->formatCsvCurrency($row->debt_amount),
                    $row->lookback_count,
                    $this->formatCsvCurrency($row->lookback_debt),
                    $this->formatCsvCurrency($row->net_debt),
                    $this->formatCsvCurrency($row->commission),
                ]);
            }

            fclose($out);
        }, $filename, $headers);
    }
}
