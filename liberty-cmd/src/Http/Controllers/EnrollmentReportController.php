<?php

namespace Cmd\Reports\Http\Controllers;

use Carbon\Carbon;
use Cmd\Reports\Http\Requests\EnrollmentReportRequest;
use Cmd\Reports\Repositories\EnrollmentReportRepository;
use Cmd\Reports\Support\CsvFormatting;
use Illuminate\Contracts\View\View;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EnrollmentReportController extends Controller
{
    use CsvFormatting;

    public function __construct(
        protected EnrollmentReportRepository $repository
    ) {
    }

    public function index(EnrollmentReportRequest $request): View|StreamedResponse
    {
        $perPage = $this->normalizePerPage($request->input('per_page', 25));
        $dateBy = $request->input('date_by', 'submitted');

        [$from, $to, $range] = $this->resolveRange(
            $request->input('from'),
            $request->input('to'),
            $request->input('range')
        );

        $filters = $this->extractFilters($request->validated());

        if ($request->input('export') === 'csv') {
            $rows = $this->repository->all($from, $to, $dateBy, $filters);

            return $this->exportCsv($rows);
        }

        $reports = $this->repository->paginate($from, $to, $perPage, $dateBy, $filters);

        return view('reports::reports.enrollment', [
            'reports' => $reports,
            'opts' => $this->repository->options(),
            'perPage' => $perPage,
            'from' => $from,
            'to' => $to,
            'range' => $range,
            'dateBy' => $dateBy,
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
        if (!$range || $range === 'custom') {
            return [$from, $to, $range];
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
     * @param  array<string, mixed>  $validated
     * @return array<string, ?string>
     */
    protected function extractFilters(array $validated): array
    {
        $keys = [
            'agent',
            'negotiator',
            'state',
            'enrollment_status',
            'debt_min',
            'debt_max',
            'length_min',
            'length_max',
            'company',
        ];

        $filters = [];
        foreach ($keys as $key) {
            $filters[$key] = $this->trimOrNull($validated[$key] ?? null);
        }

        return $filters;
    }

    protected function trimOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    protected function exportCsv(Collection $rows): StreamedResponse
    {
        $filename = 'enrollment_reports_' . Carbon::now()->format('Ymd_His') . '.csv';
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');

            fputcsv($out, [
                'PK',
                'Drop Name',
                'CID',
                'Category',
                'State',
                'Agent',
                'Negotiator',
                'Client',
                'Debt Amount',
                'Welcome Call Date',
                'Submitted Date',
                'Payment Date 1',
                'Payment Date 2',
                'First Payment Cleared Date',
                'Cancel Date',
                'NSF Date',
                'Payments',
            ]);

            foreach ($rows as $row) {
                fputcsv($out, [
                    $row->PK,
                    $row->Drop_Name,
                    preg_replace('/\D+/', '', (string) $row->LLG_ID),
                    $row->Category,
                    $row->State,
                    $row->Agent,
                    $row->Negotiator,
                    $row->Client,
                    $this->formatCsvCurrency($row->Debt_Amount),
                    $this->formatCsvDate($row->Welcome_Call_Date),
                    $this->formatCsvDate($row->Submitted_Date),
                    $this->formatCsvDate($row->Payment_Date_1),
                    $this->formatCsvDate($row->Payment_Date_2),
                    $this->formatCsvDate($row->First_Payment_Cleared_Date),
                    $this->formatCsvDate($row->Cancel_Date),
                    $this->formatCsvDate($row->NSF_Date),
                    (string) (int) ($row->Payments ?? 0),
                ]);
            }

            fclose($out);
        }, $filename, $headers);
    }
}
