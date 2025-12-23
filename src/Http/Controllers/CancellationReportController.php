<?php

namespace Cmd\Reports\Http\Controllers;

use Carbon\Carbon;
use Cmd\Reports\Http\Requests\CancellationReportRequest;
use Cmd\Reports\Repositories\CancellationReportRepository;
use Cmd\Reports\Support\CsvFormatting;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CancellationReportController extends Controller
{
    use CsvFormatting;

    public function __construct(
        protected CancellationReportRepository $repository
    ) {
    }

    public function index(CancellationReportRequest $request): View|StreamedResponse
    {
        $perPage = $this->normalizePerPage($request->input('per_page', 25));

        $filters = $this->extractFilters($request);

        if ($request->input('export') === 'csv') {
            $rows = $this->repository->all($filters);

            return $this->exportCsv($rows);
        }

        $reports = $this->repository->paginate($perPage, $filters);
        $options = $this->repository->options();

        return view('reports::reports.cancellation', [
            'reports' => $reports,
            'columns' => $this->repository->columns(),
            'filters' => $filters,
            'perPage' => $perPage,
            'opts' => $options,
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
            'id' => $this->trimOrNull($request->input('id')),
            'enrollment_plan' => $this->trimOrNull($request->input('enrollment_plan')),
            'category' => $this->trimOrNull($request->input('category')),
            'enrolled_from' => $this->trimOrNull($request->input('enrolled_from')),
            'enrolled_to' => $this->trimOrNull($request->input('enrolled_to')),
            'dropped_from' => $this->trimOrNull($request->input('dropped_from')),
            'dropped_to' => $this->trimOrNull($request->input('dropped_to')),
            'with_settlements' => $request->boolean('with_settlements', false),
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
        $filename = 'cancellation_report_' . Carbon::now()->format('Ymd_His') . '.csv';
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');

            fputcsv($out, [
                'CID',
                'Enrolled Date',
                'Dropped Date',
                'Payments',
                'Enrollment Plan',
                'Category',
            ]);

            foreach ($rows as $row) {
                fputcsv($out, [
                    $row->llg_id,
                    $this->formatCsvDate($row->enrolled_date),
                    $this->formatCsvDate($row->dropped_date),
                    $row->payments,
                    $row->enrollment_plan,
                    $row->category,
                ]);
            }

            fclose($out);
        }, $filename, $headers);
    }
}
