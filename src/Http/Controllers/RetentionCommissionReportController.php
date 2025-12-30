<?php

namespace Cmd\Reports\Http\Controllers;

use Carbon\Carbon;
use Cmd\Reports\Http\Requests\RetentionCommissionReportRequest;
use Cmd\Reports\Repositories\RetentionCommissionReportRepository;
use Cmd\Reports\Support\CsvFormatting;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RetentionCommissionReportController extends Controller
{
    use CsvFormatting;

    public function __construct(
        protected RetentionCommissionReportRepository $repository
    ) {
    }

    public function index(RetentionCommissionReportRequest $request): View|StreamedResponse
    {
        $perPage = $this->normalizePerPage($request->input('per_page', 25));

        $filters = $this->extractFilters($request);

        if ($request->input('export') === 'csv') {
            $rows = $this->repository->all($filters);

            return $this->exportCsv($rows);
        }

        $reports = $this->repository->paginate($perPage, $filters);
        $options = $this->repository->options();

        return view('reports::reports.retention_commission', [
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
            'client' => $this->trimOrNull($request->input('client')),
            'retention_agent' => $this->trimOrNull($request->input('retention_agent')),
            'immediate_results' => $this->trimOrNull($request->input('immediate_results')),
            'retention_date_from' => $this->trimOrNull($request->input('retention_date_from')),
            'retention_date_to' => $this->trimOrNull($request->input('retention_date_to')),
            'dropped_date_from' => $this->trimOrNull($request->input('dropped_date_from')),
            'dropped_date_to' => $this->trimOrNull($request->input('dropped_date_to')),
            'reconsideration_date_from' => $this->trimOrNull($request->input('reconsideration_date_from')),
            'reconsideration_date_to' => $this->trimOrNull($request->input('reconsideration_date_to')),
            'retained_date_from' => $this->trimOrNull($request->input('retained_date_from')),
            'retained_date_to' => $this->trimOrNull($request->input('retained_date_to')),
            'retention_payment_date_from' => $this->trimOrNull($request->input('retention_payment_date_from')),
            'retention_payment_date_to' => $this->trimOrNull($request->input('retention_payment_date_to')),
            'enrolled_debt_min' => $this->trimOrNull($request->input('enrolled_debt_min')),
            'enrolled_debt_max' => $this->trimOrNull($request->input('enrolled_debt_max')),
            'cleared_payments_min' => $this->trimOrNull($request->input('cleared_payments_min')),
            'cleared_payments_max' => $this->trimOrNull($request->input('cleared_payments_max')),
            'commission_t1_min' => $this->trimOrNull($request->input('commission_t1_min')),
            'commission_t1_max' => $this->trimOrNull($request->input('commission_t1_max')),
            'commission_t2_min' => $this->trimOrNull($request->input('commission_t2_min')),
            'commission_t2_max' => $this->trimOrNull($request->input('commission_t2_max')),
            'commission_t3_min' => $this->trimOrNull($request->input('commission_t3_min')),
            'commission_t3_max' => $this->trimOrNull($request->input('commission_t3_max')),
            'cancel_request_date_from' => $this->trimOrNull($request->input('cancel_request_date_from')),
            'cancel_request_date_to' => $this->trimOrNull($request->input('cancel_request_date_to')),
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
        $filename = 'retention_commission_report_' . Carbon::now()->format('Ymd_His') . '.csv';
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');

            fputcsv($out, [
                'ID',
                'Client',
                'Retention Agent',
                'Retention Date',
                'Immediate Results',
                'Enrolled Debt',
                'Cleared Payments',
                'Reconsideration Date',
                'Dropped Date',
                'Retained Date',
                'Retention Payment Date',
                'Retention Commission T1',
                'Retention Commission T2',
                'Retention Commission T3',
                'Cancel Request Date',
            ]);

            foreach ($rows as $row) {
                fputcsv($out, [
                    $row->id,
                    $row->client,
                    $row->retention_agent,
                    $this->formatCsvDate($row->retention_date),
                    $row->immediate_results,
                    $this->formatCsvCurrency($row->enrolled_debt),
                    $row->cleared_payments,
                    $this->formatCsvDate($row->reconsideration_date),
                    $this->formatCsvDate($row->dropped_date),
                    $this->formatCsvDate($row->retained_date),
                    $this->formatCsvDate($row->retention_payment_date),
                    $this->formatCsvCurrency($row->retention_commission_t1),
                    $this->formatCsvCurrency($row->retention_commission_t2),
                    $this->formatCsvCurrency($row->retention_commission_t3),
                    $this->formatCsvDate($row->cancel_request_date),
                ]);
            }

            fclose($out);
        }, $filename, $headers);
    }
}
