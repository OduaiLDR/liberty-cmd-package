<?php

namespace Cmd\Reports\Http\Controllers;

use Carbon\Carbon;
use Cmd\Reports\Http\Requests\MarketingReportRequest;
use Cmd\Reports\Repositories\MarketingReportRepository;
use Cmd\Reports\Support\CsvFormatting;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MarketingReportController extends Controller
{
    use CsvFormatting;

    public function __construct(
        protected MarketingReportRepository $repository
    ) {
    }

    public function index(MarketingReportRequest $request): View|StreamedResponse
    {
        $perPage = $this->normalizePerPage($request->input('per_page', 25));

        [$from, $to, $range] = $this->resolveRange(
            $request->input('from'),
            $request->input('to'),
            $request->input('range')
        );

        $filters = $request->only([
            'drop_name',
            'debt_tier',
            'drop_type',
            'vendor',
            'data_type',
            'mail_style',
            'language',
        ]);

        if ($request->input('export') === 'csv') {
            $rows = $this->repository->all($from, $to, $filters);

            return $this->exportCsv($rows);
        }

        $reports = $this->repository->paginate($from, $to, $perPage, $filters);

        return view('reports::reports.marketing', [
            'reports' => $reports,
            'options' => $this->repository->options(),
            'filters' => $filters,
            'perPage' => $perPage,
            'from' => $from,
            'to' => $to,
            'range' => $range,
        ]);
    }

    public function updateMailDropCost(Request $request, int $pk)
    {
        $validated = $request->validate([
            'mail_drop_cost' => ['required', 'numeric', 'min:0'],
        ]);

        $record = $this->repository->updateMailDropCost($pk, (float) $validated['mail_drop_cost']);

        return back()->with('status', 'Mail drop cost updated.')->with('marketing_record', $record);
    }

    public function updateDataDropCost(Request $request, int $pk)
    {
        $validated = $request->validate([
            'data_drop_cost' => ['required', 'numeric', 'min:0'],
        ]);

        $record = $this->repository->updateDataDropCost($pk, (float) $validated['data_drop_cost']);

        return back()->with('status', 'Data drop cost updated.')->with('marketing_record', $record);
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
        $filename = 'marketing_reports_' . Carbon::now()->format('Ymd_His') . '.csv';
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');

            fputcsv($out, [
                'PK',
                'Drop Name',
                'Debt Tier',
                'Drop Type',
                'Vendor',
                'Data Type',
                'Mail Style',
                'Send Date',
                'Amount Dropped',
                'Mail Invoice Number',
                'Mail Drop Cost',
                'Per Piece Mail Drop Cost',
                'Data Invoice Number',
                'Data Drop Cost',
                'Per Piece Data Drop Cost',
                'Total Drop Cost',
                'Per Piece Total Drop Cost',
                'Calls',
                'Language',
                'Drop Name Sequential',
            ]);

            foreach ($rows as $row) {
                fputcsv($out, [
                    $row->PK,
                    $row->Drop_Name,
                    $row->Debt_Tier,
                    $row->Drop_Type,
                    $row->Vendor,
                    $row->Data_Type,
                    $row->Mail_Style,
                    $this->formatCsvDate($row->Send_Date),
                    $row->Amount_Dropped,
                    $row->Mail_Invoice_Number,
                    $this->formatCsvCurrency($row->Mail_Drop_Cost),
                    $this->formatCsvRatio($row->Per_Piece_Mail_Cost, 4),
                    $row->Data_Invoice_Number,
                    $this->formatCsvCurrency($row->Data_Drop_Cost),
                    $this->formatCsvRatio($row->Per_Piece_Data_Cost, 4),
                    $this->formatCsvCurrency($row->Total_Drop_Cost),
                    $this->formatCsvRatio($row->Per_Piece_Total_Cost, 4),
                    $row->Calls,
                    $row->Language,
                    $row->Drop_Name_Sequential,
                ]);
            }

            fclose($out);
        }, $filename, $headers);
    }
}
