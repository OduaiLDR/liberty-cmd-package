<?php

namespace Cmd\Reports\Http\Controllers;

use Carbon\Carbon;
use Cmd\Reports\Http\Requests\EpfPaidReportRequest;
use Cmd\Reports\Repositories\EpfPaidReportRepository;
use Cmd\Reports\Support\CsvFormatting;
use Illuminate\Contracts\View\View;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EpfPaidReportController extends Controller
{
    use CsvFormatting;

    public function __construct(
        protected EpfPaidReportRepository $repository
    ) {
    }

    public function index(EpfPaidReportRequest $request): View|StreamedResponse
    {
        $perPage = $this->normalizePerPage($request->input('per_page', 25));

        [$from, $to, $range] = $this->resolveRange(
            $request->input('from'),
            $request->input('to'),
            $request->input('range')
        );

        $filters = $request->only([
            'llg_id',
            'state',
            'tranche',
            'creditor',
            'settlement_id',
            'payment_number',
            'confirmation',
        ]);

        $columns = $this->repository->columns();

        if ($request->input('export') === 'csv') {
            $rows = $this->repository->all($from, $to, $filters);

            return $this->exportCsv($rows, $columns);
        }

        $reports = $this->repository->paginate($from, $to, $perPage, $filters);

        return view('reports::reports.epf_paid', [
            'reports' => $reports,
            'columns' => $columns,
            'filters' => $filters,
            'perPage' => $perPage,
            'from' => $from,
            'to' => $to,
            'range' => $range,
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
     * @param  array<string, string>  $columns
     */
    protected function exportCsv(Collection $rows, array $columns): StreamedResponse
    {
        $filename = 'epf_paid_' . Carbon::now()->format('Ymd_His') . '.csv';
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

                    $k = strtolower($key);
                    $isDate = str_contains($k, 'date');
                    $isPercent = str_contains($k, 'rate') || str_contains($k, 'percent') || str_contains($k, 'pct');
                    $isCurrency = !$isDate && !$isPercent
                        && $k !== 'llg_id'
                        && !str_contains($k, 'number')
                        && !str_contains($k, 'id')
                        && (bool) preg_match('/(amount|payment|debt|settlement|epf)/', $k);

                    if ($isDate) {
                        $record[] = $this->formatCsvDate($value);
                        continue;
                    }

                    if ($isPercent) {
                        $num = (float) $value;
                        $pct = $num <= 1 ? ($num * 100) : $num;
                        $record[] = $this->formatCsvRatio($pct, 2) . '%';
                        continue;
                    }

                    if ($isCurrency) {
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
