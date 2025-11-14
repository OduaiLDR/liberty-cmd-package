<?php

namespace Cmd\Reports\Http\Controllers;

use Carbon\Carbon;
use Cmd\Reports\Http\Requests\NegotiatorReportRequest;
use Cmd\Reports\Repositories\NegotiatorReportRepository;
use Cmd\Reports\Support\CsvFormatting;
use Illuminate\Contracts\View\View;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\StreamedResponse;

class NegotiatorReportController extends Controller
{
    use CsvFormatting;

    public function __construct(
        protected NegotiatorReportRepository $repository
    ) {
    }

    public function index(NegotiatorReportRequest $request): View|StreamedResponse
    {
        $perPage = $this->normalizePerPage($request->input('per_page', 25));
        $dateField = $request->input('date_field', 'payment');
        $from = $request->input('from');
        $to = $request->input('to');

        $filters = [
            'negotiator' => $this->trimOrNull($request->input('negotiator')),
            'ngo' => $this->trimOrNull($request->input('ngo')),
            'enrollment_status' => $this->trimOrNull($request->input('enrollment_status')),
        ];

        if ($request->input('export') === 'csv') {
            $rows = $this->repository->all($from, $to, $dateField, $filters);
            return $this->exportCsv($rows);
        }

        $reports = $this->repository->paginate($from, $to, $perPage, $dateField, $filters);
        $options = $this->repository->options();

        return view('reports::reports.negotiator', [
            'reports' => $reports,
            'opts' => $options,
            'perPage' => $perPage,
            'from' => $from,
            'to' => $to,
            'dateField' => $dateField,
        ]);
    }

    protected function normalizePerPage(int|string|null $perPage): int
    {
        $perPage = (int) ($perPage ?? 25);
        return $perPage > 0 && $perPage <= 1000 ? $perPage : 25;
    }

    protected function trimOrNull(mixed $value): ?string
    {
        if ($value === null) return null;
        $t = trim((string) $value);
        return $t === '' ? null : $t;
    }

    protected function exportCsv($rows): StreamedResponse
    {
        $filename = 'negotiator_report_' . Carbon::now()->format('Ymd_His') . '.csv';
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'LLG-ID', 'Contact Name', 'Enrollment Status', 'Assignment Status', 'Negotiator', 'Assigned Date',
                'Agent', 'NGO', 'Debt ID', 'Debt Amount', 'Custodial Balance', 'Debt / Balance %', 'Payments', 'Debt Tier',
                'Creditor', 'Collection Company', 'Creditor Group', 'Follow Up Date', 'Ready To Settle Date',
                'Account Not Ready Date', 'Account Not Ready Reason', 'Last Payment Date', 'Settlement Date',
                'Settlements', 'Days Since Last Activity', 'WCC Date', 'Balance Two Months Ago', 'Balance Last Month',
                'Balance Current', 'Send POA'
            ]);

            foreach ($rows as $r) {
                fputcsv($out, [
                    $r->CID,
                    $r->Contact_Name,
                    $r->Enrollment_Status,
                    $r->Assignment_Status,
                    $r->Negotiator,
                    $this->formatCsvDate($r->Negotiator_Assigned_Date),
                    $r->Agent,
                    $r->NGO,
                    $r->Debt_ID,
                    $this->formatCsvCurrency($r->Debt_Amount),
                    $this->formatCsvCurrency($r->Balance),
                    $this->formatCsvRatio($r->Debt_Balance_Ratio),
                    $this->formatCsvCurrency($r->Payments),
                    $r->Debt_Tier,
                    $r->Creditor,
                    $r->Collection_Company,
                    $r->Creditor_Group,
                    $this->formatCsvDate($r->Follow_Up_Date),
                    $this->formatCsvDate($r->Ready_To_Settle_Date),
                    $this->formatCsvDate($r->Account_Not_Ready_Date),
                    $r->Account_Not_Ready_Reason,
                    $this->formatCsvDate($r->Last_Payment_Date),
                    $this->formatCsvDate($r->Settlement_Date),
                    $r->Settlements,
                    $r->Days_Since_Activity,
                    $this->formatCsvDate($r->WCC_Date),
                    $this->formatCsvCurrency($r->Balance_Two_Months_Ago),
                    $this->formatCsvCurrency($r->Balance_Last_Month),
                    $this->formatCsvCurrency($r->Balance_Current),
                    $r->Send_POA,
                ]);
            }

            fclose($out);
        }, $filename, $headers);
    }
}
