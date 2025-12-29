<?php

namespace Cmd\Reports\Http\Controllers;

use Carbon\Carbon;
use Cmd\Reports\Http\Requests\TrainingReportRequest;
use Cmd\Reports\Repositories\TrainingReportRepository;
use Cmd\Reports\Support\CsvFormatting;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TrainingReportController extends Controller
{
    use CsvFormatting;

    public function __construct(
        protected TrainingReportRepository $repository
    ) {
    }

    public function index(TrainingReportRequest $request): View|StreamedResponse
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
            'total' => $allRows->sum('total'),
        ];

        $reports = $this->repository->paginate($perPage, $filters);

        return view('reports::reports.training_report', [
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
        $filename = 'training_report_' . Carbon::now()->format('Ymd_His') . '.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');

            fputcsv($out, [
                'Agent',
                'Location',
                'On Phone Date',
                'Month',
                'Start Date',
                'End Date',
                'Contacts',
                'Deals',
                'Conversion',
                'Debt',
                'Total',
            ]);

            foreach ($rows as $row) {
                fputcsv($out, [
                    $row->agent,
                    $row->location,
                    $row->on_phone_date,
                    $row->month,
                    $row->start_date,
                    $row->end_date,
                    $row->contacts,
                    $row->deals,
                    $row->conversion,
                    $this->formatCsvCurrency($row->debt),
                    $this->formatCsvCurrency($row->total),
                ]);
            }
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }
}
