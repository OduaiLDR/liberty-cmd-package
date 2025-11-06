<?php

namespace Cmd\Reports\Http\Controllers;

use Carbon\Carbon;
use Cmd\Reports\Repositories\TrancheSummaryRepository;
use Cmd\Reports\Support\CsvFormatting;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TrancheSummaryController extends Controller
{
    use CsvFormatting;

    public function __construct(
        protected TrancheSummaryRepository $repository
    ) {
    }

    public function index(Request $request): View|StreamedResponse
    {
        $perPage = (int) $request->input('per_page', 25);
        if ($perPage <= 0 || $perPage > 1000) {
            $perPage = 25;
        }

        $from = $request->input('from');
        $to = $request->input('to');
        $range = $request->input('range');

        if ($range && $range !== 'custom') {
            $today = Carbon::today();
            switch ($range) {
                case 'all':
                    $from = null; $to = null; break;
                case 'today':
                    $from = $today->format('Y-m-d'); $to = $today->format('Y-m-d'); break;
                case 'this_month':
                    $from = $today->copy()->startOfMonth()->format('Y-m-d');
                    $to = $today->copy()->endOfMonth()->format('Y-m-d');
                    break;
                case 'last_month':
                    $from = $today->copy()->subMonthNoOverflow()->startOfMonth()->format('Y-m-d');
                    $to = $today->copy()->subMonthNoOverflow()->endOfMonth()->format('Y-m-d');
                    break;
                case '7':
                case '30':
                    $days = (int) $range; $start = $today->copy()->subDays($days - 1);
                    $from = $start->format('Y-m-d'); $to = $today->format('Y-m-d');
                    break;
            }
        }

        if ($request->query('export') === 'csv') {
            $rows = $this->repository->all($from, $to);
            return $this->exportCsv($rows);
        }

        $reports = $this->repository->paginate($from, $to, $perPage);

        return view('reports::reports.tranche_summary', [
            'reports' => $reports,
            'perPage' => $perPage,
            'from' => $from,
            'to' => $to,
            'range' => $range,
        ]);
    }

    protected function exportCsv($rows): StreamedResponse
    {
        $filename = 'tranche_summary_' . Carbon::now()->format('Ymd_His') . '.csv';
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'Tranche', 'Payment Date', 'Report Date', 'Total Debt', 'LDR Count', 'PLAW Count', 'Progress Count', 'Total Count',
                'Payment', 'Sold Debt (Lookback)', '8% of Lookback', 'EPF All', 'EPF Pending', 'EPF Amount', 'EPF Dist Amount',
                'EPF Total (Q)', 'Payment + 10% (N)', 'R = min(Q,N)', 'S = max(N-Q,0)', 'T = max(Q-N,0)', 'U = R/N', 'Flip Date'
            ]);

            $fmtDate = fn($d) => $d ? Carbon::parse($d)->format('m/d/Y') : '';
            $fmtMoney = fn($n) => $n === null ? '' : number_format((float) $n, 2, '.', '');

            foreach ($rows as $r) {
                fputcsv($out, [
                    $r->Tranche,
                    $fmtDate($r->Payment_Date ?? null),
                    $fmtDate($r->Report_Date ?? null),
                    $fmtMoney($r->Total_Debt ?? null),
                    (int) ($r->Count_LDR ?? 0),
                    (int) ($r->Count_PLAW ?? 0),
                    (int) ($r->Count_PROGRESS ?? 0),
                    (int) ($r->Count_Total ?? 0),
                    $fmtMoney($r->Payment ?? null),
                    $fmtMoney($r->SoldDebt_Lookback ?? null),
                    $fmtMoney($r->K_EightPercentOfLookback ?? null),
                    $fmtMoney($r->EPF_All ?? null),
                    $fmtMoney($r->EPF_Pending ?? null),
                    $fmtMoney($r->EPF_Amount ?? null),
                    $fmtMoney($r->EPFD_Amount ?? null),
                    $fmtMoney($r->Q_EpfTotal ?? null),
                    $fmtMoney($r->N_PaymentPlus10 ?? null),
                    $fmtMoney($r->R_MinQN ?? null),
                    $fmtMoney($r->S_MaxNMinusQ ?? null),
                    $fmtMoney($r->T_MaxQMinusN ?? null),
                    $r->U_Ratio !== null ? number_format((float) $r->U_Ratio, 4, '.', '') : '',
                    $fmtDate($r->Flip_Date ?? null),
                ]);
            }

            fclose($out);
        }, $filename, $headers);
    }
}

