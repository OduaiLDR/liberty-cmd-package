<?php

namespace Cmd\Reports\Http\Controllers;

use Carbon\Carbon;
use Cmd\Reports\Http\Requests\SettlementAdminReportRequest;
use Cmd\Reports\Repositories\SettlementAdminReportRepository;
use Cmd\Reports\Support\CsvFormatting;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SettlementAdminReportController extends Controller
{
    use CsvFormatting;

    public function __construct(
        protected SettlementAdminReportRepository $repository
    ) {
    }

    public function index(SettlementAdminReportRequest $request): View|StreamedResponse
    {
        $perPage = $this->normalizePerPage($request->input('per_page', 25));
        $filters = $this->extractFilters($request);

        if ($request->input('export') === 'csv') {
            $rows = $this->repository->all($filters);
            return $this->exportCsv($rows);
        }

        $reports = $this->repository->paginate($perPage, $filters);

        return view('reports::reports.settlement_admin', [
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
            'contact_name' => $this->trimOrNull($request->input('contact_name')),
            'llg_id' => $this->trimOrNull($request->input('llg_id')),
            'settlement_from' => $this->trimOrNull($request->input('settlement_from')),
            'settlement_to' => $this->trimOrNull($request->input('settlement_to')),
            'debt_min' => $this->trimOrNull($request->input('debt_min')),
            'debt_max' => $this->trimOrNull($request->input('debt_max')),
            'per_page' => $this->trimOrNull($request->input('per_page')),
        ];
    }

    protected function trimOrNull(mixed $value): ?string
    {
        if ($value === null) return null;
        $trimmed = trim((string) $value);
        return $trimmed === '' ? null : $trimmed;
    }

    protected function exportCsv(Collection $rows): StreamedResponse
    {
        $filename = 'settlement_admin_' . Carbon::now()->format('Ymd_His') . '.csv';
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');

            fputcsv($out, [
                'Contact Name',
                'LLG ID',
                'Original Debt Amount',
                'Settlement ID',
                'Creditor Name',
                'Collection Company',
                'Settlement Date',
            ]);

            foreach ($rows as $row) {
                fputcsv($out, [
                    $row->contact_name,
                    $row->llg_id,
                    $this->formatCsvCurrency($row->original_debt_amount),
                    $row->settlement_id,
                    $row->creditor_name,
                    $row->collection_company,
                    $this->formatCsvDate($row->settlement_date),
                ]);
            }

            fclose($out);
        }, $filename, $headers);
    }
}
