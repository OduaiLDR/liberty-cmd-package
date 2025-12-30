<?php

namespace Cmd\Reports\Http\Controllers;

use Carbon\Carbon;
use Cmd\Reports\Http\Requests\InvoiceReportRequest;
use Cmd\Reports\Repositories\InvoiceReportRepository;
use Cmd\Reports\Support\CsvFormatting;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InvoiceReportController extends Controller
{
    use CsvFormatting;

    public function __construct(protected InvoiceReportRepository $repository)
    {
    }

    public function index(InvoiceReportRequest $request): View|StreamedResponse
    {
        $perPage = $this->normalizePerPage($request->input('per_page', 25));
        $filters = $this->extractFilters($request);

        if ($request->input('export') === 'csv') {
            $rows = $this->repository->all($filters);
            return $this->exportCsv($rows);
        }

        $reports = $this->repository->paginate($perPage, $filters);

        return view('reports::reports.invoice_report', [
            'reports' => $reports,
            'columns' => $this->repository->columns(),
            'filters' => $filters,
            'perPage' => $perPage,
            'reportTypes' => $this->reportTypes(),
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
            'report_type' => $this->trimOrNull($request->input('report_type')),
            'agent' => $this->trimOrNull($request->input('agent')),
            'date_from' => $this->trimOrNull($request->input('date_from')),
            'date_to' => $this->trimOrNull($request->input('date_to')),
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
        $filename = 'invoice_report_' . Carbon::now()->format('Ymd_His') . '.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Report', 'Submitted Date', 'Agent', 'Debt Amount', 'LLG ID']);
            foreach ($rows as $row) {
                fputcsv($out, [
                    $row->report_type,
                    $row->submitted_date,
                    $row->agent,
                    $this->formatCsvCurrency($row->debt_amount),
                    $row->llg_id,
                ]);
            }
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    /**
     * @return array<int, array{key:string,label:string,group:string}>
     */
    protected function reportTypes(): array
    {
        return [
            ['key' => 'Invoice D Jordan Combined', 'label' => 'Invoice D Jordan Combined', 'group' => 'Jordan'],
            ['key' => 'Invoice S Jordan Combined', 'label' => 'Invoice S Jordan Combined', 'group' => 'Jordan'],
            ['key' => 'Invoice D Jordan LDR', 'label' => 'Invoice D Jordan LDR', 'group' => 'Jordan'],
            ['key' => 'Invoice S Jordan LDR', 'label' => 'Invoice S Jordan LDR', 'group' => 'Jordan'],
            ['key' => 'Invoice D Jordan LT', 'label' => 'Invoice D Jordan LT', 'group' => 'Jordan'],
            ['key' => 'Invoice S Jordan LT', 'label' => 'Invoice S Jordan LT', 'group' => 'Jordan'],
            ['key' => 'Invoice D Jordan PLAW', 'label' => 'Invoice D Jordan PLAW', 'group' => 'Jordan'],
            ['key' => 'Invoice S Jordan PLAW', 'label' => 'Invoice S Jordan PLAW', 'group' => 'Jordan'],
            ['key' => 'Invoice D Guatemala Combined', 'label' => 'Invoice D Guatemala Combined', 'group' => 'Guatemala'],
            ['key' => 'Invoice S Guatemala Combined', 'label' => 'Invoice S Guatemala Combined', 'group' => 'Guatemala'],
            ['key' => 'Invoice D Guatemala LDR', 'label' => 'Invoice D Guatemala LDR', 'group' => 'Guatemala'],
            ['key' => 'Invoice S Guatemala LDR', 'label' => 'Invoice S Guatemala LDR', 'group' => 'Guatemala'],
            ['key' => 'Invoice D Guatemala LT', 'label' => 'Invoice D Guatemala LT', 'group' => 'Guatemala'],
            ['key' => 'Invoice S Guatemala LT', 'label' => 'Invoice S Guatemala LT', 'group' => 'Guatemala'],
            ['key' => 'Invoice D Guatemala PLAW', 'label' => 'Invoice D Guatemala PLAW', 'group' => 'Guatemala'],
            ['key' => 'Invoice S Guatemala PLAW', 'label' => 'Invoice S Guatemala PLAW', 'group' => 'Guatemala'],
        ];
    }
}
