<?php

namespace Cmd\Reports\Http\Controllers;

use Carbon\Carbon;
use Cmd\Reports\Http\Requests\MailerDataReportRequest;
use Cmd\Reports\Repositories\MailerDataReportRepository;
use Cmd\Reports\Support\CsvFormatting;
use Illuminate\Contracts\View\View;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MailerDataReportController extends Controller
{
    use CsvFormatting;

    public function __construct(
        protected MailerDataReportRepository $repository
    ) {
    }

    public function index(MailerDataReportRequest $request): View|StreamedResponse
    {
        $perPage = $this->normalizePerPage($request->input('per_page', 25));

        [$from, $to, $range] = $this->resolveRange(
            $request->input('from'),
            $request->input('to'),
            $request->input('range')
        );

        $filters = $request->only([
            'drop_name',
            'send_date',
            'debt_tier',
            'state',
            'month',
            'year',
            'drop_type',
            'data_type',
            'mail_style',
            'vendor',
            'visible',
        ]);

        if ($request->input('export') === 'csv') {
            $rows = $this->repository->all($from, $to, $filters);

            return $this->exportCsv($rows);
        }

        $reports = $this->repository->paginate($from, $to, $perPage, $filters);

        return view('reports::reports.mailer_data', [
            'reports' => $reports,
            'opts' => $this->repository->options(),
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

    protected function exportCsv(Collection $rows): StreamedResponse
    {
        $filename = 'mailer_data_' . Carbon::now()->format('Ymd_His') . '.csv';
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');

            fputcsv($out, [
                'Drop Name',
                'Send Date',
                'Debt Tier',
                'State',
                'Count',
                'Month',
                'Year',
                'Drop Type',
                'Data Type',
                'Mail Style',
                'Vendor',
                'Total Leads',
                'Qualified Leads',
                'Unqualified Leads',
                'Assigned Leads',
                'Visible',
            ]);

            foreach ($rows as $row) {
                fputcsv($out, [
                    $row->Drop_Name,
                    $this->formatCsvDate($row->Send_Date),
                    $row->Debt_Tier,
                    $row->State,
                    $row->Count === null || $row->Count === '' ? '' : number_format((float) $row->Count, 0, '.', ','),
                    $row->Month ?? '',
                    $row->Year ?? '',
                    $row->Drop_Type,
                    $row->Data_Type,
                    $row->Mail_Style,
                    $row->Vendor,
                    $row->Total_Leads === null || $row->Total_Leads === '' ? '' : number_format((float) $row->Total_Leads, 0, '.', ','),
                    $row->Qualified_Leads === null || $row->Qualified_Leads === '' ? '' : number_format((float) $row->Qualified_Leads, 0, '.', ','),
                    $row->Unqualified_Leads === null || $row->Unqualified_Leads === '' ? '' : number_format((float) $row->Unqualified_Leads, 0, '.', ','),
                    $row->Assigned_Leads === null || $row->Assigned_Leads === '' ? '' : number_format((float) $row->Assigned_Leads, 0, '.', ','),
                    isset($row->Visible) ? ((int) $row->Visible ? '1' : '0') : '',
                ]);
            }

            fclose($out);
        }, $filename, $headers);
    }
}
