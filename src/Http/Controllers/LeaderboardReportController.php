<?php

namespace Cmd\Reports\Http\Controllers;

use Carbon\Carbon;
use Cmd\Reports\Http\Requests\LeaderboardReportRequest;
use Cmd\Reports\Repositories\LeaderboardReportRepository;
use Cmd\Reports\Support\CsvFormatting;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LeaderboardReportController extends Controller
{
    use CsvFormatting;

    public function __construct(protected LeaderboardReportRepository $repository)
    {
    }

    public function index(LeaderboardReportRequest $request): View|StreamedResponse
    {
        $perPage = $this->normalizePerPage($request->input('per_page', 25));
        $filters = $this->extractFilters($request);

        if ($request->input('export') === 'csv') {
            $rows = $this->repository->all($filters);
            return $this->exportCsv($rows);
        }

        $allRows = $this->repository->all($filters);
        $totals = [
            'contacts' => $allRows->sum('contacts'),
            'deals' => $allRows->sum('deals'),
            'debt' => $allRows->sum('debt'),
        ];

        $reports = $this->repository->paginate($perPage, $filters);
        $windowMeta = $this->repository->windowMeta($filters);
        $recordHolders = $this->repository->topAllTime(4);
        $companyTotals = $this->repository->companyTotals($filters);

        return view('reports::reports.leaderboard', [
            'reports' => $reports,
            'columns' => $this->repository->columns(),
            'filters' => $filters,
            'perPage' => $perPage,
            'totals' => $totals,
            'periodLabel' => ucfirst($filters['period'] ?? 'Monthly'),
            'categoryLabel' => $filters['category'] ?? 'Cancellation Ratio',
            'windowMeta' => $windowMeta,
            'recordHolders' => $recordHolders,
            'companyTotals' => $companyTotals,
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
            'period' => $this->trimOrNull($request->input('period')),
            'category' => $this->trimOrNull($request->input('category')),
            'month' => $this->trimOrNull($request->input('month')),
            'year' => $this->trimOrNull($request->input('year')),
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
        $filename = 'leaderboard_' . Carbon::now()->format('Ymd_His') . '.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Rank', 'Agent', 'Contacts', 'Deals', 'Debt']);
            foreach ($rows as $row) {
                fputcsv($out, [
                    $row->rank,
                    $row->agent,
                    $row->contacts,
                    $row->deals,
                    $this->formatCsvCurrency($row->debt),
                ]);
            }
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }
}
