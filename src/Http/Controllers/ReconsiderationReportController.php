<?php

namespace Cmd\Reports\Http\Controllers;

use Carbon\Carbon;
use Cmd\Reports\Http\Requests\ReconsiderationReportRequest;
use Cmd\Reports\Repositories\ReconsiderationReportRepository;
use Cmd\Reports\Support\CsvFormatting;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReconsiderationReportController extends Controller
{
    use CsvFormatting;

    public function __construct(
        protected ReconsiderationReportRepository $repository
    ) {
    }

    public function index(ReconsiderationReportRequest $request): View|StreamedResponse
    {
        $perPage = $this->normalizePerPage($request->input('per_page', 25));

        $filters = $this->extractFilters($request);

        if ($request->input('export') === 'csv') {
            $rows = $this->repository->all($filters);

            return $this->exportCsv($rows);
        }

        $reports = $this->repository->paginate($perPage, $filters);
        $options = $this->repository->options();

        return view('reports::reports.reconsideration', [
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
            'dropped_by' => $this->trimOrNull($request->input('dropped_by')),
            'dropped_reason' => $this->trimOrNull($request->input('dropped_reason')),
            'retention_agent' => $this->trimOrNull($request->input('retention_agent')),
            'assigned_to' => $this->trimOrNull($request->input('assigned_to')),
            'active_status' => $this->trimOrNull($request->input('active_status')),
            'current_status' => $this->trimOrNull($request->input('current_status')),
            'enrolled_from' => $this->trimOrNull($request->input('enrolled_from')),
            'enrolled_to' => $this->trimOrNull($request->input('enrolled_to')),
            'dropped_from' => $this->trimOrNull($request->input('dropped_from')),
            'dropped_to' => $this->trimOrNull($request->input('dropped_to')),
            'status_date_from' => $this->trimOrNull($request->input('status_date_from')),
            'status_date_to' => $this->trimOrNull($request->input('status_date_to')),
            'cancel_request_date_from' => $this->trimOrNull($request->input('cancel_request_date_from')),
            'cancel_request_date_to' => $this->trimOrNull($request->input('cancel_request_date_to')),
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
        $filename = 'reconsideration_report_' . Carbon::now()->format('Ymd_His') . '.csv';
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
                'Dropped By',
                'Enrolled Debt',
                'Active Status',
                'Current Status',
                'Status Date',
                'Last Status By',
                'Retention Agent',
                'Assigned To',
                'Retention Immediate Results',
            ]);

            foreach ($rows as $row) {
                fputcsv($out, [
                    $row->llg_id,
                    $row->client,
                    $this->formatCsvDate($row->enrolled_date),
                    $this->formatCsvDate($row->dropped_date),
                    $row->dropped_by,
                    $this->formatCsvCurrency($row->debt_amount),
                    $row->active_status,
                    $row->current_status,
                    $this->formatCsvDate($row->status_date),
                    $row->last_status_by,
                    $row->retention_agent,
                    $row->assigned_to,
                    $row->retention_immediate_results,
                ]);
            }

            fclose($out);
        }, $filename, $headers);
    }
}
