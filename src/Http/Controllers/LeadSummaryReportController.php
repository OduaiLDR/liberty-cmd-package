<?php

namespace Cmd\Reports\Http\Controllers;

use Carbon\Carbon;
use Cmd\Reports\Http\Requests\LeadSummaryReportRequest;
use Cmd\Reports\Repositories\LeadSummaryReportRepository;
use Cmd\Reports\Support\CsvFormatting;
use Illuminate\Contracts\View\View;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LeadSummaryReportController extends Controller
{
    use CsvFormatting;

    public function __construct(
        protected LeadSummaryReportRepository $repository
    ) {
    }

    public function index(LeadSummaryReportRequest $request): View|StreamedResponse
    {
        [$from, $to, $range] = $this->resolveRange(
            $request->input('from'),
            $request->input('to'),
            $request->input('range')
        );

        $filters = $request->only(['debt_tier']);

        $hourlyData = $this->repository->getHourlyData($from, $to, $filters);
        $summary = $this->repository->getSummaryBySource($from, $to, $filters);
        $chartData = $this->repository->getChartData($hourlyData);

        if ($request->input('export') === 'csv') {
            return $this->exportCsv($hourlyData, $summary);
        }

        return view('reports::reports.lead_summary', [
            'hourlyData' => $hourlyData,
            'summary' => $summary,
            'chartData' => $chartData,
            'from' => $from,
            'to' => $to,
            'range' => $range,
            'filters' => $filters,
        ]);
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

    protected function exportCsv(array $hourlyData, array $summary): StreamedResponse
    {
        $filename = 'lead_summary_' . Carbon::now()->format('Ymd_His') . '.csv';
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        return response()->streamDownload(function () use ($hourlyData, $summary) {
            $out = fopen('php://output', 'w');

            fputcsv($out, ['Hour Bucket', 'Call Center', 'Apply Online', 'Manual Entry', 'Total']);

            foreach ($hourlyData as $bucket => $sources) {
                $total = array_sum($sources);
                fputcsv($out, [
                    $bucket,
                    number_format($sources['Call Center']),
                    number_format($sources['Apply Online']),
                    number_format($sources['Manual Entry']),
                    number_format($total),
                ]);
            }

            fputcsv($out, []);
            fputcsv($out, ['Summary']);
            fputcsv($out, ['Source', 'Total Leads']);

            foreach ($summary as $source => $count) {
                fputcsv($out, [$source, number_format($count)]);
            }

            fclose($out);
        }, $filename, $headers);
    }
}
