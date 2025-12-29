<?php

namespace Cmd\Reports\Http\Controllers;

use Carbon\Carbon;
use Cmd\Reports\Http\Requests\LendingUsaProspectsReportRequest;
use Cmd\Reports\Repositories\LendingUsaProspectsReportRepository;
use Cmd\Reports\Support\CsvFormatting;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LendingUsaProspectsReportController extends Controller
{
    use CsvFormatting;

    public function __construct(
        protected LendingUsaProspectsReportRepository $repository
    ) {
    }

    public function index(LendingUsaProspectsReportRequest $request): View|StreamedResponse
    {
        $perPage = $this->normalizePerPage($request->input('per_page', 25));

        $filters = $this->extractFilters($request);

        if ($request->input('export') === 'csv') {
            $rows = $this->repository->all($filters);

            return $this->exportCsv($rows);
        }

        $reports = $this->repository->paginate($perPage, $filters);
        $options = $this->repository->options();

        return view('reports::reports.lending_usa_prospects', [
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
            'llg_id' => $this->trimOrNull($request->input('llg_id')),
            'client' => $this->trimOrNull($request->input('client')),
            'state' => $this->trimOrNull($request->input('state')),
            'first_payment_from' => $this->trimOrNull($request->input('first_payment_from')),
            'first_payment_to' => $this->trimOrNull($request->input('first_payment_to')),
            'debt_min' => $this->trimOrNull($request->input('debt_min')),
            'debt_max' => $this->trimOrNull($request->input('debt_max')),
            'balance_min' => $this->trimOrNull($request->input('balance_min')),
            'balance_max' => $this->trimOrNull($request->input('balance_max')),
            'payments_min' => $this->trimOrNull($request->input('payments_min')),
            'payments_max' => $this->trimOrNull($request->input('payments_max')),
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
        $filename = 'lending_usa_prospects_' . Carbon::now()->format('Ymd_His') . '.csv';
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');

            fputcsv($out, [
                'LLG ID',
                'Client',
                'Email',
                'Phone',
                'Balance',
                'First Payment Date',
                'Debt Amount',
                'Total Income',
                'Payments',
                'State',
                'Debt Ratio',
            ]);

            foreach ($rows as $row) {
                fputcsv($out, [
                    $row->llg_id,
                    $row->client,
                    $row->email,
                    $row->phone,
                    $this->formatCsvCurrency($row->balance),
                    $this->formatCsvDate($row->first_payment_date),
                    $this->formatCsvCurrency($row->debt_amount),
                    $this->formatCsvCurrency($row->total_income),
                    $row->payments,
                    $row->state,
                    $row->debt_ratio,
                ]);
            }

            fclose($out);
        }, $filename, $headers);
    }
}
