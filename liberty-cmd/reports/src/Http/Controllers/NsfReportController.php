<?php

namespace Cmd\Reports\Http\Controllers;

use Carbon\Carbon;
use Cmd\Reports\Http\Requests\NsfReportRequest;
use Cmd\Reports\Repositories\NsfReportRepository;
use Cmd\Reports\Support\CsvFormatting;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class NsfReportController extends Controller
{
    use CsvFormatting;

    public function __construct(
        protected NsfReportRepository $repository
    ) {
    }

    public function index(NsfReportRequest $request): View|StreamedResponse
    {
        $perPage = $this->normalizePerPage($request->input('per_page', 25));

        $nsfFrom = $request->input('from');
        $nsfTo = $request->input('to');
        [$submittedFrom, $submittedTo, $range] = $this->resolveSubmittedRange(
            $request->input('range'),
            $nsfFrom,
            $nsfTo
        );

        $filters = $this->extractFilters($request);

        if ($request->input('export') === 'csv') {
            $rows = $this->repository->all($nsfFrom, $nsfTo, $submittedFrom, $submittedTo, $filters);

            return $this->exportCsv($rows);
        }

        $reports = $this->repository->paginate($nsfFrom, $nsfTo, $submittedFrom, $submittedTo, $perPage, $filters);
        $options = $this->repository->options();

        return view('reports::reports.nsf', [
            'reports' => $reports,
            'opts' => $options,
            'perPage' => $perPage,
            'from' => $nsfFrom,
            'to' => $nsfTo,
            'range' => $range,
        ]);
    }

    protected function normalizePerPage(int|string|null $perPage): int
    {
        $perPage = (int) ($perPage ?? 25);

        return $perPage > 0 && $perPage <= 1000 ? $perPage : 25;
    }

    /**
     * @return array<string, ?string>
     */
    protected function extractFilters(Request $request): array
    {
        return [
            'agent' => $this->trimOrNull($request->input('agent')),
            'client' => $this->trimOrNull($request->input('client')),
            'negotiator' => $this->trimOrNull($request->input('negotiator')),
            'state' => $this->trimOrNull($request->input('state')),
            'enrollment_status' => $this->trimOrNull($request->input('enrollment_status')),
            'debt_min' => $this->trimOrNull($request->input('debt_min')),
            'debt_max' => $this->trimOrNull($request->input('debt_max')),
            'length_min' => $this->trimOrNull($request->input('length_min')),
            'length_max' => $this->trimOrNull($request->input('length_max')),
            'company' => $this->trimOrNull($request->input('company')),
        ];
    }

    protected function trimOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Resolve quick submitted-date filters.
     *
     * @return array{0:?string,1:?string,2:?string}
     */
    protected function resolveSubmittedRange(?string $range, ?string $fallbackFrom, ?string $fallbackTo): array
    {
        if ($range === null || $range === '') {
            return [null, null, null];
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
        }

        return [$fallbackFrom, $fallbackTo, $range];
    }

    protected function exportCsv(Collection $rows): StreamedResponse
    {
        $filename = 'nsf_reports_' . Carbon::now()->format('Ymd_His') . '.csv';
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');

            fputcsv($out, [
                'Drop Name',
                'CID',
                'State',
                'Agent',
                'Client',
                'Debt Amount',
                'Welcome Call Date',
                'Payment Date 1',
                'Payment Date 2',
                'Cancel Date',
                'NSF Date',
                'Payments',
                'Negotiator',
                'Negotiator Assigned Date',
                'First Payment Date',
                'First Payment Cleared Date',
                'Enrolled Debt Accounts',
                'Enrollment Status',
                'Enrollment Plan',
                'Program Payment',
                'Program Length',
                'First Payment Status',
                'Submitted Date',
            ]);

            foreach ($rows as $row) {
                fputcsv($out, [
                    $row->Drop_Name,
                    preg_replace('/\D+/', '', (string) $row->LLG_ID),
                    $row->State,
                    $row->Agent,
                    $row->Client,
                    $this->formatCsvCurrency($row->Debt_Amount),
                    $this->formatCsvDate($row->Welcome_Call_Date),
                    $this->formatCsvDate($row->Payment_Date_1),
                    $this->formatCsvDate($row->Payment_Date_2),
                    $this->formatCsvDate($row->Cancel_Date),
                    $this->formatCsvDate($row->NSF_Date),
                    $this->formatCsvCurrency($row->Payments),
                    $row->Negotiator,
                    $this->formatCsvDate($row->Negotiator_Assigned_Date),
                    $this->formatCsvDate($row->First_Payment_Date),
                    $this->formatCsvDate($row->First_Payment_Cleared_Date),
                    $row->Enrolled_Debt_Accounts,
                    $row->Enrollment_Status,
                    $row->Enrollment_Plan,
                    $this->formatCsvCurrency($row->Program_Payment),
                    $row->Program_Length,
                    $row->First_Payment_Status,
                    $this->formatCsvDate($row->Submitted_Date),
                ]);
            }

            fclose($out);
        }, $filename, $headers);
    }
}
