<?php

namespace Cmd\Reports\Http\Controllers;

use Carbon\Carbon;
use Cmd\Reports\Http\Requests\UnclearedSettlementPaymentsReportRequest;
use Cmd\Reports\Repositories\UnclearedSettlementPaymentsReportRepository;
use Cmd\Reports\Support\CsvFormatting;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UnclearedSettlementPaymentsReportController extends Controller
{
    use CsvFormatting;

    public function __construct(
        protected UnclearedSettlementPaymentsReportRepository $repository
    ) {
    }

    public function index(UnclearedSettlementPaymentsReportRequest $request): View|StreamedResponse
    {
        $perPage = $this->normalizePerPage($request->input('per_page', 25));

        $filters = $this->extractFilters($request);

        if ($request->input('export') === 'csv') {
            $rows = $this->repository->all($filters);

            return $this->exportCsv($rows);
        }

        $reports = $this->repository->paginate($perPage, $filters);

        return view('reports::reports.uncleared_settlement_payments', [
            'reports' => $reports,
            'columns' => $this->repository->columns(),
            'filters' => $filters,
            'perPage' => $perPage,
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
            'process_date_from' => $this->trimOrNull($request->input('process_date_from')),
            'process_date_to' => $this->trimOrNull($request->input('process_date_to')),
            'amount_min' => $this->trimOrNull($request->input('amount_min')),
            'amount_max' => $this->trimOrNull($request->input('amount_max')),
            'memo' => $this->trimOrNull($request->input('memo')),
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
        $filename = 'uncleared_settlement_payments_' . Carbon::now()->format('Ymd_His') . '.csv';
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');

            fputcsv($out, [
                'LLG ID',
                'Process Date',
                'Amount',
                'Memo',
            ]);

            foreach ($rows as $row) {
                fputcsv($out, [
                    $row->llg_id,
                    $this->formatCsvDate($row->process_date),
                    $this->formatCsvCurrency($row->amount),
                    $row->memo,
                ]);
            }

            fclose($out);
        }, $filename, $headers);
    }
}
