<?php

namespace Cmd\Reports\Http\Controllers;

use Carbon\Carbon;
use Cmd\Reports\Http\Requests\ContactAnalysisReportRequest;
use Cmd\Reports\Repositories\ContactAnalysisReportRepository;
use Cmd\Reports\Support\CsvFormatting;
use Illuminate\Contracts\View\View;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ContactAnalysisReportController extends Controller
{
    use CsvFormatting;

    public function __construct(
        protected ContactAnalysisReportRepository $repository
    ) {
    }

    public function index(ContactAnalysisReportRequest $request): View|StreamedResponse
    {
        [$from, $to, $range] = $this->resolveRange(
            $request->input('from'),
            $request->input('to'),
            $request->input('range')
        );

        $filters = $request->only(['data_source']);
        $chartFilters = $request->only(['chart_year', 'chart_month', 'chart_min_debt', 'chart_max_debt']);

        $allData = $this->repository->getWeeklyData($from, $to, $filters, $chartFilters);
        $perPage = (int) $request->input('per_page', 15) ?: 15;
        $reports = $this->repository->paginateWeekly($from, $to, $filters, $perPage);

        if ($request->input('export') === 'csv') {
            return $this->exportCsv($allData);
        }

        $contactsChart = $this->repository->getContactsChartData($allData);
        $enrolledDebtChart = $this->repository->getEnrolledDebtChartData($allData);
        $responseRateChart = $this->repository->getResponseRateChartData($allData);
        $dataSources = $this->repository->getDataSources();

        $totals = [
            'contacts' => $allData->sum('contacts'),
            'enrolled_debt' => $allData->sum('enrolled_debt'),
            'wcc' => $allData->sum('wcc'),
            'cancels' => $allData->sum('cancels'),
            'nsfs' => $allData->sum('nsfs'),
            'active_deals' => $allData->sum('active_deals'),
            'avg_debt' => $allData->avg('avg_debt'),
            'mailers' => $allData->sum('mailers'),
            'response_rate' => $allData->sum('mailers') > 0 ? $allData->sum('contacts') / $allData->sum('mailers') : 0,
        ];

        return view('reports::reports.contact_analysis', [
            'reports' => $reports,
            'totals' => $totals,
            'from' => $from,
            'to' => $to,
            'range' => $range,
            'filters' => $filters,
            'contactsChart' => $contactsChart,
            'enrolledDebtChart' => $enrolledDebtChart,
            'responseRateChart' => $responseRateChart,
            'dataSources' => $dataSources,
            'perPage' => $perPage,
            'chartFilters' => $chartFilters,
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
            case 'this_quarter':
                return [
                    $today->copy()->startOfQuarter()->format('Y-m-d'),
                    $today->copy()->endOfQuarter()->format('Y-m-d'),
                    'this_quarter',
                ];
            case 'last_quarter':
                $start = $today->copy()->subQuarterNoOverflow()->startOfQuarter()->format('Y-m-d');
                $end = $today->copy()->subQuarterNoOverflow()->endOfQuarter()->format('Y-m-d');
                return [$start, $end, 'last_quarter'];
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

    protected function exportCsv($data): StreamedResponse
    {
        $filename = 'contact_analysis_' . Carbon::now()->format('Ymd_His') . '.csv';
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        return response()->streamDownload(function () use ($data) {
            $out = fopen('php://output', 'w');

            fputcsv($out, [
                'Start Date', 'End Date', 'Contacts', 'Enrolled Debt', 'WCC',
                'Cancels', 'NSFs', 'Active Deals', 'Avg Debt', 'Mail Date',
                'Mailers', 'Response Rate'
            ]);

            foreach ($data as $row) {
                fputcsv($out, [
                    $row->start_date ? date('m/d/Y', strtotime($row->start_date)) : '',
                    $row->end_date ? date('m/d/Y', strtotime($row->end_date)) : '',
                    number_format($row->contacts),
                    '$' . number_format($row->enrolled_debt, 2),
                    number_format($row->wcc),
                    number_format($row->cancels),
                    number_format($row->nsfs),
                    number_format($row->active_deals),
                    '$' . number_format($row->avg_debt, 2),
                    $row->mail_date ? date('m/d/Y', strtotime($row->mail_date)) : '',
                    number_format($row->mailers),
                    number_format($row->response_rate * 100, 2) . '%',
                ]);
            }

            fclose($out);
        }, $filename, $headers);
    }
}
