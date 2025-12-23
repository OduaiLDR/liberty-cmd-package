<?php

namespace Cmd\Reports\Http\Controllers;

use Carbon\Carbon;
use Cmd\Reports\Http\Requests\DroppedReportRequest;
use Cmd\Reports\Repositories\DroppedReportRepository;
use Cmd\Reports\Support\CsvFormatting;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DroppedReportController extends Controller
{
    use CsvFormatting;

    public function __construct(
        protected DroppedReportRepository $repository
    ) {
    }

    public function index(DroppedReportRequest $request): View|StreamedResponse
    {
        $perPage = $this->normalizePerPage($request->input('per_page', 25));

        $filters = $this->extractFilters($request);

        if ($request->input('export') === 'csv') {
            $rows = $this->repository->all($filters);

            return $this->exportCsv($rows);
        }

        $reports = $this->repository->paginate($perPage, $filters);
        $options = $this->repository->options();

        return view('reports::reports.dropped', [
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
            'client' => $this->trimOrNull($request->input('client')),
            'dropped_reason' => $this->trimOrNull($request->input('dropped_reason')),
            'status' => $this->trimOrNull($request->input('status')),
            'enrollment_plan' => $this->trimOrNull($request->input('enrollment_plan')),
            'enrolled_from' => $this->trimOrNull($request->input('enrolled_from')),
            'enrolled_to' => $this->trimOrNull($request->input('enrolled_to')),
            'dropped_from' => $this->trimOrNull($request->input('dropped_from')),
            'dropped_to' => $this->trimOrNull($request->input('dropped_to')),
            'days_enrolled_min' => $this->trimOrNull($request->input('days_enrolled_min')),
            'days_enrolled_max' => $this->trimOrNull($request->input('days_enrolled_max')),
            'debt_min' => $this->trimOrNull($request->input('debt_min')),
            'debt_max' => $this->trimOrNull($request->input('debt_max')),
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
        $filename = 'dropped_report_' . Carbon::now()->format('Ymd_His') . '.csv';
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');

            fputcsv($out, [
                'CID',
                'Client',
                'Enrolled Date',
                'Dropped Date',
                'Days Enrolled',
                'Enrollment Plan',
                'Enrolled Debt',
                'Dropped Reason',
                'Status',
            ]);

            foreach ($rows as $row) {
                fputcsv($out, [
                    $row->llg_id,
                    $row->client,
                    $this->formatCsvDate($row->enrolled_date),
                    $this->formatCsvDate($row->dropped_date),
                    $row->days_enrolled,
                    $row->enrollment_plan,
                    $this->formatCsvCurrency($row->debt_amount),
                    $row->dropped_reason,
                    $row->status,
                ]);
            }

            fclose($out);
        }, $filename, $headers);
    }
}
