<?php

namespace Cmd\Reports\Http\Controllers;

use Cmd\Reports\Http\Requests\LegalReportRequest;
use Cmd\Reports\Repositories\LegalReportRepository;
use Cmd\Reports\Support\CsvFormatting;
use Illuminate\Contracts\View\View;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LegalReportController extends Controller
{
    use CsvFormatting;

    protected LegalReportRepository $repository;

    public function __construct(LegalReportRepository $repository)
    {
        $this->repository = $repository;
    }

    public function index(LegalReportRequest $request): View|StreamedResponse
    {
        $perPage = $this->normalizePerPage($request->input('per_page', 25));

        $allowedFilters = $this->repository->allowedFilterInputs();
        $filters = $request->only($allowedFilters);

        $columns = $this->repository->columns();

        if ($request->input('export') === 'csv') {
            $rows = $this->repository->all($filters);

            return $this->exportCsv($rows, $columns);
        }

        $reports = $this->repository->paginate($perPage, $filters);

        return view('reports::reports.legal', [
            'reports' => $reports,
            'columns' => $columns,
            'filters' => $filters,
            'perPage' => $perPage,
            'allowedFilters' => $allowedFilters,
        ]);
    }

    protected function normalizePerPage(mixed $perPage): int
    {
        $value = (int) $perPage;
        if ($value < 1) {
            return 25;
        }
        if ($value > 1000) {
            return 1000;
        }
        return $value;
    }

    protected function exportCsv(iterable $rows, array $columns): StreamedResponse
    {
        $filename = 'legal_report_' . now()->format('Ymd_His') . '.csv';
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];

        return response()->streamDownload(function () use ($rows, $columns) {
            $out = fopen('php://output', 'w');
            fputcsv($out, array_values($columns));

            foreach ($rows as $row) {
                $record = [];
                foreach ($columns as $key => $label) {
                    $value = $row->{$key} ?? '';
                    if ($this->isCurrencyKey($key)) {
                        $record[] = $this->formatCsvCurrency($value);
                    } elseif ($this->isPercentKey($key)) {
                        $record[] = $this->formatCsvRatio($value);
                    } elseif ($this->isDateKey($key)) {
                        $record[] = $this->formatCsvDate($value);
                    } else {
                        $record[] = is_numeric($value) ? number_format((float) $value, 2, '.', ',') : $value;
                    }
                }
                fputcsv($out, $record);
            }

            fclose($out);
        }, $filename, $headers);
    }

    protected function isDateKey(string $key): bool
    {
        return str_contains(strtolower($key), 'date') || str_contains(strtolower($key), 'dob');
    }

    protected function isPercentKey(string $key): bool
    {
        return str_contains(strtolower($key), 'rate') || str_contains(strtolower($key), 'percent');
    }

    protected function isCurrencyKey(string $key): bool
    {
        return str_contains(strtolower($key), 'amount') || str_contains(strtolower($key), 'balance');
    }
}
