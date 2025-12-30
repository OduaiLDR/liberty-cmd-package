<?php

namespace Cmd\Reports\Http\Controllers;

use Carbon\Carbon;
use Cmd\Reports\Http\Requests\JordanExpensesReportRequest;
use Cmd\Reports\Repositories\JordanExpensesReportRepository;
use Cmd\Reports\Support\CsvFormatting;
use Illuminate\Contracts\View\View;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class JordanExpensesReportController extends Controller
{
    use CsvFormatting;

    public function __construct(
        protected JordanExpensesReportRepository $repository
    ) {
    }

    public function index(JordanExpensesReportRequest $request): View|StreamedResponse
    {
        $perPage = $this->normalizePerPage($request->input('per_page', 25));

        [$from, $to, $range] = $this->resolveRange(
            $request->input('from'),
            $request->input('to'),
            $request->input('range')
        );

        $filters = $request->only(['category', 'company', 'description']);
        $allColumns = $this->repository->columns();

        if ($request->input('export') === 'csv') {
            $rows = $this->repository->all($from, $to, $filters);
            $visibleColumns = $this->visibleColumns($rows, $allColumns);

            return $this->exportCsv($rows, $visibleColumns);
        }

        $reports = $this->repository->paginate($from, $to, $perPage, $filters);
        $sample = $this->repository->sample($from, $to, 500, $filters);
        $visibleColumns = $this->visibleColumns($sample, $allColumns);
        
        // Get total amount for all filtered records (not just current page)
        $totalAmount = $this->repository->getTotalAmount($from, $to, $filters);

        return view('reports::reports.jordan_expenses', [
            'reports' => $reports,
            'columns' => $visibleColumns,
            'filters' => $filters,
            'perPage' => $perPage,
            'from' => $from,
            'to' => $to,
            'range' => $range,
            'totalAmount' => $totalAmount,
        ]);
    }

    protected function normalizePerPage(int|string|null $perPage): int
    {
        $perPage = (int) ($perPage ?? 25);

        return $perPage > 0 && $perPage <= 1000 ? $perPage : 25;
    }

    /**
     * @return array{0:?string,1:?string,2:?string}
     */
    protected function resolveRange(?string $from, ?string $to, ?string $range): array
    {
        if (!$range) {
            return [$from, $to, null];
        }

        $today = Carbon::today();

        switch ($range) {
            case 'all':
                return [null, null, 'all'];
            case 'today':
                $date = $today->format('Y-m-d');
                return [$date, $date, 'today'];
            case 'this_month':
                return [
                    $today->copy()->startOfMonth()->format('Y-m-d'),
                    $today->copy()->endOfMonth()->format('Y-m-d'),
                    'this_month',
                ];
            case 'last_month':
                $start = $today->copy()->subMonthNoOverflow()->startOfMonth()->format('Y-m-d');
                $end = $today->copy()->subMonthNoOverflow()->endOfMonth()->format('Y-m-d');
                return [$start, $end, 'last_month'];
            default:
                if (is_numeric($range)) {
                    $days = (int) $range;
                    if ($days > 0) {
                        $start = $today->copy()->subDays($days - 1)->format('Y-m-d');
                        $end = $today->format('Y-m-d');
                        return [$start, $end, $range];
                    }
                }

                return [$from, $to, $range];
        }
    }

    /**
     * @param  array<string, string>  $allColumns
     * @return array<string, string>
     */
    protected function visibleColumns(Collection $rows, array $allColumns): array
    {
        if ($rows->count() === 0) {
            return $allColumns;
        }

        $visible = [];

        foreach ($allColumns as $key => $label) {
            $hasData = $rows->contains(function ($row) use ($key) {
                $value = $row->{$key} ?? null;

                if ($value === null) {
                    return false;
                }

                if (is_string($value)) {
                    return trim($value) !== '' && strtolower(trim($value)) !== '0';
                }

                if (is_numeric($value)) {
                    return (float) $value !== 0.0;
                }

                return true;
            });

            if ($hasData) {
                $visible[$key] = $label;
            }
        }

        return count($visible) > 0 ? $visible : $allColumns;
    }

    protected function isCurrencyKey(string $key): bool
    {
        return strtolower($key) === 'amount' || str_contains(strtolower($key), 'amount') || str_contains(strtolower($key), 'cost');
    }

    protected function isDateKey(string $key): bool
    {
        return strtolower($key) === 'date' || str_contains(strtolower($key), 'date');
    }

    /**
     * @param  array<string, string>  $columns
     */
    protected function exportCsv(Collection $rows, array $columns): StreamedResponse
    {
        $filename = 'jordan_expenses_' . Carbon::now()->format('Ymd_His') . '.csv';
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        return response()->streamDownload(function () use ($rows, $columns) {
            $out = fopen('php://output', 'w');

            fputcsv($out, array_values($columns));

            foreach ($rows as $row) {
                $record = [];

                foreach (array_keys($columns) as $key) {
                    $value = $row->{$key} ?? '';

                    if ($value === null || $value === '') {
                        $record[] = '';
                        continue;
                    }

                    if ($this->isDateKey($key)) {
                        $record[] = $this->formatCsvDate($value);
                        continue;
                    }

                    if ($this->isCurrencyKey($key)) {
                        $record[] = $this->formatCsvCurrency($value);
                        continue;
                    }

                    $record[] = $value;
                }

                fputcsv($out, $record);
            }

            fclose($out);
        }, $filename, $headers);
    }
}
