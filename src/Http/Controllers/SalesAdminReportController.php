<?php

namespace Cmd\Reports\Http\Controllers;

use Carbon\Carbon;
use Cmd\Reports\Http\Requests\SalesAdminReportRequest;
use Cmd\Reports\Repositories\SalesAdminReportRepository;
use Cmd\Reports\Support\CsvFormatting;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SalesAdminReportController extends Controller
{
    use CsvFormatting;

    public function __construct(
        protected SalesAdminReportRepository $repository
    ) {
    }

    public function index(SalesAdminReportRequest $request): View|StreamedResponse
    {
        $perPage = $this->normalizePerPage($request->input('per_page', 25));

        $filters = $this->extractFilters($request);

        if ($request->input('export') === 'csv') {
            $rows = $this->repository->all($filters);

            return $this->exportCsv($rows);
        }

        $reports = $this->repository->paginate($perPage, $filters);
        $options = $this->repository->options();

        return view('reports::reports.sales_admin', [
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
            'agent' => $this->trimOrNull($request->input('agent')),
            'client' => $this->trimOrNull($request->input('client')),
            'llg_id' => $this->trimOrNull($request->input('llg_id')),
            'payment_date_from' => $this->trimOrNull($request->input('payment_date_from')),
            'payment_date_to' => $this->trimOrNull($request->input('payment_date_to')),
            'debt_min' => $this->trimOrNull($request->input('debt_min')),
            'debt_max' => $this->trimOrNull($request->input('debt_max')),
            'program_length_min' => $this->trimOrNull($request->input('program_length_min')),
            'program_length_max' => $this->trimOrNull($request->input('program_length_max')),
            'payments_min' => $this->trimOrNull($request->input('payments_min')),
            'payments_max' => $this->trimOrNull($request->input('payments_max')),
            'cancel_from' => $this->trimOrNull($request->input('cancel_from')),
            'cancel_to' => $this->trimOrNull($request->input('cancel_to')),
            'nsf_from' => $this->trimOrNull($request->input('nsf_from')),
            'nsf_to' => $this->trimOrNull($request->input('nsf_to')),
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
        $filename = 'sales_admin_' . Carbon::now()->format('Ymd_His') . '.csv';
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');

            fputcsv($out, [
                'LLG ID',
                'Agent',
                'Client',
                'Payment Date',
                'Debt Amount',
                'Program Length',
                'Monthly Deposit',
                'Payments',
                'Cancel Date',
                'NSF Date',
            ]);

            foreach ($rows as $row) {
                fputcsv($out, [
                    $row->llg_id,
                    $row->agent,
                    $row->client,
                    $this->formatCsvDate($row->payment_date),
                    $this->formatCsvCurrency($row->debt_amount),
                    $row->program_length,
                    $this->formatCsvCurrency($row->monthly_deposit),
                    $row->payments,
                    $this->formatCsvDate($row->cancel_date),
                    $this->formatCsvDate($row->nsf_date),
                ]);
            }

            fclose($out);
        }, $filename, $headers);
    }
}
