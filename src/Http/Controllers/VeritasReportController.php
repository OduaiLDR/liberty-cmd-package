<?php

namespace Cmd\Reports\Http\Controllers;

use Carbon\Carbon;
use Cmd\Reports\Http\Requests\VeritasReportRequest;
use Cmd\Reports\Repositories\VeritasReportRepository;
use Cmd\Reports\Support\CsvFormatting;
use Illuminate\Contracts\View\View;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VeritasReportController extends Controller
{
    use CsvFormatting;

    public function __construct(
        protected VeritasReportRepository $repository
    ) {
    }

    public function index(VeritasReportRequest $request): View|StreamedResponse
    {
        $perPage = $this->normalizePerPage($request->input('per_page', 25));

        [$from, $to, $range] = $this->resolveRange(
            $request->input('from'),
            $request->input('to'),
            $request->input('range')
        );

        $filters = $request->only(['llg_id', 'client', 'company', 'category']);

        $allColumns = $this->repository->columns();

        if ($request->input('export') === 'csv') {
            $rows = $this->repository->all($from, $to, $filters);
            $visibleColumns = $this->visibleColumns($rows, $allColumns);

            return $this->exportCsv($rows, $visibleColumns);
        }

        $reports = $this->repository->paginate($from, $to, $perPage, $filters);
        $sample = $this->repository->sample($from, $to, 500, $filters);
        $visibleColumns = $this->visibleColumns($sample, $allColumns);

        return view('reports::reports.veritas', [
            'reports' => $reports,
            'columns' => $visibleColumns,
            'perPage' => $perPage,
            'from' => $from,
            'to' => $to,
            'range' => $range,
            'filters' => $filters,
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

        return str_contains($k, 'date') || str_contains($l, 'date');
    }

    protected function isPercentKey(string $key, string $label): bool
    {
        $k = strtolower($key);
        $l = strtolower($label);

        return str_contains($k, 'rate') || str_contains($k, 'percent') || str_contains($k, 'pct')
            || str_contains($l, 'rate') || str_contains($l, 'percent') || str_contains($l, 'pct');
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

        return (bool) preg_match('/(amount|loan|commission|payment|debt|fee|cost|total)/', $k)
            || (bool) preg_match('/(amount|loan|commission|payment|debt|fee|cost|total)/', $l);
    }

    /**
     * @param  array<string, string>  $columns
     */
    protected function exportCsv(Collection $rows, array $columns): StreamedResponse
    {
        $filename = 'veritas_' . Carbon::now()->format('Ymd_His') . '.csv';
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
