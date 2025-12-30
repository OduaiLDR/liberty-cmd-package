<?php

namespace Cmd\Reports\Http\Controllers;

use Carbon\Carbon;
use Cmd\Reports\Http\Requests\ClientSubmissionReportRequest;
use Cmd\Reports\Repositories\ClientSubmissionReportRepository;
use Cmd\Reports\Support\CsvFormatting;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ClientSubmissionReportController extends Controller
{
    use CsvFormatting;

    public function __construct(
        protected ClientSubmissionReportRepository $repository
    ) {
    }

    public function index(ClientSubmissionReportRequest $request): View|StreamedResponse
    {
        $perPage = $this->normalizePerPage($request->input('per_page', 25));

        $filters = $this->extractFilters($request);

        if ($request->input('export') === 'csv') {
            $rows = $this->repository->all($filters);

            return $this->exportCsv($rows);
        }

        $reports = $this->repository->paginate($perPage, $filters);
        $options = $this->repository->options();

        return view('reports::reports.client_submission', [
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
            'date_from' => $this->trimOrNull($request->input('date_from')),
            'date_to' => $this->trimOrNull($request->input('date_to')),
            'first_payment_from' => $this->trimOrNull($request->input('first_payment_from')),
            'first_payment_to' => $this->trimOrNull($request->input('first_payment_to')),
            'debt_min' => $this->trimOrNull($request->input('debt_min')),
            'debt_max' => $this->trimOrNull($request->input('debt_max')),
            'program_length_min' => $this->trimOrNull($request->input('program_length_min')),
            'program_length_max' => $this->trimOrNull($request->input('program_length_max')),
            'monthly_deposit_min' => $this->trimOrNull($request->input('monthly_deposit_min')),
            'monthly_deposit_max' => $this->trimOrNull($request->input('monthly_deposit_max')),
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
        $filename = 'client_submission_' . Carbon::now()->format('Ymd_His') . '.csv';
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');

            fputcsv($out, [
                'Date',
                'Agent',
                'Record ID',
                'Client',
                'First Payment Date',
                'Enrolled Debt Amount',
                'Program Length',
                'Monthly Deposit',
                'Data Source',
                'Veritas Initial Setup Fee',
            ]);

            foreach ($rows as $row) {
                fputcsv($out, [
                    $this->formatCsvDate($row->date),
                    $row->agent,
                    $row->record_id,
                    $row->client,
                    $this->formatCsvDate($row->first_payment_date),
                    $this->formatCsvCurrency($row->enrolled_debt_amount),
                    $row->program_length,
                    $this->formatCsvCurrency($row->monthly_deposit),
                    $row->data_source,
                    $this->formatCsvCurrency($row->veritas_initial_setup_fee),
                ]);
            }

            fclose($out);
        }, $filename, $headers);
    }
}
