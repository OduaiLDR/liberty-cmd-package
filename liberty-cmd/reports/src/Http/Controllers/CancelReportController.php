<?php

namespace Cmd\Reports\Http\Controllers;

use Carbon\Carbon;
use Cmd\Reports\Http\Requests\CancelReportRequest;
use Cmd\Reports\Repositories\CancelReportRepository;
use Cmd\Reports\Support\CsvFormatting;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Support\Collection;

class CancelReportController extends Controller
{
    use CsvFormatting;

    public function __construct(
        protected CancelReportRepository $repository
    ) {
    }

    public function index(CancelReportRequest $request): View|StreamedResponse
    {
        $perPage = $this->normalizePerPage($request->input('per_page', 25));

        $cancelFrom = $request->input('from');
        $cancelTo = $request->input('to');
        [$submittedFrom, $submittedTo, $resolvedRange] = $this->resolveSubmittedRange(
            $request->input('range'),
            $cancelFrom,
            $cancelTo
        );

        $filters = $this->extractFilters($request);

        if ($request->input('export') === 'csv') {
            $rows = $this->repository->all($cancelFrom, $cancelTo, $submittedFrom, $submittedTo, $filters);

            return $this->exportCsv($rows);
        }

        $reports = $this->repository->paginate($cancelFrom, $cancelTo, $submittedFrom, $submittedTo, $perPage, $filters);
        $options = $this->repository->options();

        return view('reports::reports.cancel', [
            'reports' => $reports,
            'opts' => $options,
            'perPage' => $perPage,
            'from' => $cancelFrom,
            'to' => $cancelTo,
            'range' => $resolvedRange,
        ]);
    }

    /**
     * Normalize the per-page value the user can request.
     */
    protected function normalizePerPage(int|string|null $perPage): int
    {
        $perPage = (int) ($perPage ?? 25);

        return $perPage > 0 && $perPage <= 1000 ? $perPage : 25;
    }

    /**
     * Extract relevant filter values from the incoming request.
     *
     * @return array<string, mixed>
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
     * Resolve the submitted date range filters.
     *
     * @return array{0:?string,1:?string,2:?string}
     */
    protected function resolveSubmittedRange(?string $range, ?string $cancelFrom, ?string $cancelTo): array
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
                break;
        }

        // Fall back to manual submitted range inferred from provided cancel dates
        return [$cancelFrom, $cancelTo, $range];
    }

    /**
     * Stream a CSV export response.
     */
    protected function exportCsv(Collection $rows): StreamedResponse
    {
        $filename = 'cancel_reports_' . Carbon::now()->format('Ymd_His') . '.csv';
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
