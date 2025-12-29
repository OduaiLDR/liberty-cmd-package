<?php

namespace Cmd\Reports\Http\Controllers;

use Carbon\Carbon;
use Cmd\Reports\Http\Requests\EnrollmentModelReportRequest;
use Cmd\Reports\Repositories\EnrollmentModelReportRepository;
use Cmd\Reports\Support\CsvFormatting;
use Illuminate\Contracts\View\View;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EnrollmentModelReportController extends Controller
{
    use CsvFormatting;

    public function __construct(
        protected EnrollmentModelReportRepository $repository
    ) {
    }

    public function index(EnrollmentModelReportRequest $request): View|StreamedResponse
    {
        $year = $request->input('year') ? (int) $request->input('year') : (int) date('Y');
        $fromMonth = $request->input('from_month') ? (int) $request->input('from_month') : null;
        $toMonth = $request->input('to_month') ? (int) $request->input('to_month') : null;
        
        $allColumns = $this->repository->columns();

        // Filter columns based on month range
        if ($fromMonth || $toMonth) {
            $filteredColumns = ['category' => 'Revenue and Expenses'];
            $months = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];
            foreach ($months as $key => $num) {
                $include = true;
                if ($fromMonth && $num < $fromMonth) $include = false;
                if ($toMonth && $num > $toMonth) $include = false;
                if ($include) {
                    $filteredColumns[$key] = ucfirst($key);
                }
            }
            $allColumns = $filteredColumns;
        }

        // Get monthly pivoted data
        $reports = $this->repository->getMonthlyData($year, $fromMonth, $toMonth);

        if ($request->input('export') === 'csv') {
            return $this->exportCsv($reports, $allColumns);
        }

        // Get available years for filter
        $availableYears = range((int) date('Y'), (int) date('Y') - 5);

        return view('reports::reports.enrollment_model', [
            'reports' => $reports,
            'columns' => $allColumns,
            'year' => $year,
            'availableYears' => $availableYears,
            'fromMonth' => $fromMonth,
            'toMonth' => $toMonth,
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

    protected function isDateKey(string $key, string $label): bool
    {
        $k = strtolower($key);
        $l = strtolower($label);

        return str_contains($k, 'date')
            || preg_match('/\b(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)\b/', $k)
            || preg_match('/\b(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)\b/', $l);
    }

    protected function isPercentKey(string $key, string $label): bool
    {
        $k = strtolower($key);
        $l = strtolower($label);

        return str_contains($k, 'rate') || str_contains($k, 'percent') || str_contains($k, 'pct') || str_contains($k, 'roi')
            || str_contains($l, 'rate') || str_contains($l, 'percent') || str_contains($l, 'pct') || str_contains($l, 'roi');
    }

    protected function isCurrencyKey(string $key, string $label): bool
    {
        $k = strtolower($key);
        $l = strtolower($label);

        if ($this->isDateKey($key, $label) || $this->isPercentKey($key, $label)) {
            return false;
        }

        if (str_contains($k, 'id') || str_contains($k, 'number')) {
            return false;
        }

        return (bool) preg_match('/(revenue|expense|cost|fee|amount|total|profit|loss)/', $k)
            || (bool) preg_match('/(revenue|expense|cost|fee|amount|total|profit|loss)/', $l);
    }

    /**
     * @param  array<string, string>  $columns
     */
    protected function exportCsv(Collection $rows, array $columns): StreamedResponse
    {
        $filename = 'enrollment_model_' . Carbon::now()->format('Ymd_His') . '.csv';
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        return response()->streamDownload(function () use ($rows, $columns) {
            $out = fopen('php://output', 'w');

            fputcsv($out, array_values($columns));

            foreach ($rows as $row) {
                $record = [];

                foreach ($columns as $key => $label) {
                    $value = $row->{$key} ?? '';

                    if ($value === null || $value === '') {
                        $record[] = '';
                        continue;
                    }

                    if ($this->isDateKey($key, $label)) {
                        $record[] = $this->formatCsvDate($value);
                        continue;
                    }

                    if ($this->isPercentKey($key, $label)) {
                        $num = (float) $value;
                        $pct = $num <= 1 ? ($num * 100) : $num;
                        $record[] = $this->formatCsvRatio($pct, 2) . '%';
                        continue;
                    }

                    if ($this->isCurrencyKey($key, $label)) {
                        $record[] = $this->formatCsvCurrency($value);
                        continue;
                    }

                    if (is_numeric($value)) {
                        $record[] = number_format((float) $value, 0, '.', ',');
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
