<?php

namespace Cmd\Reports\Http\Controllers;

use Carbon\Carbon;
use Cmd\Reports\Http\Requests\LeadReportRequest;
use Cmd\Reports\Repositories\LeadReportRepository;
use Cmd\Reports\Support\CsvFormatting;
use Illuminate\Contracts\View\View;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LeadReportController extends Controller
{
    use CsvFormatting;

    public function __construct(
        protected LeadReportRepository $repository
    ) {
    }

    public function index(LeadReportRequest $request): View|StreamedResponse
    {
        $perPage = $this->normalizePerPage($request->input('per_page', 25));

        [$from, $to, $range] = $this->resolveRange(
            $request->input('from'),
            $request->input('to'),
            $request->input('range')
        );

        $filters = $this->extractFilters($request->validated());

        if ($request->input('export') === 'csv') {
            $rows = $this->repository->all($from, $to, $filters);

            return $this->exportCsv($rows);
        }

        $reports = $this->repository->paginate($from, $to, $perPage, $filters);

        return view('reports::reports.lead', [
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
        $filters = [];

        $filters['agent'] = $this->trimOrNull($validated['agent'] ?? null);
        $filters['data_source'] = $this->trimOrNull($validated['data_source'] ?? null);
        $filters['debt_tier'] = $this->trimOrNull($validated['debt_tier'] ?? null);
        $filters['status_type'] = $validated['status_type'] ?? 'all';

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
        $filename = 'lead_reports_' . Carbon::now()->format('Ymd_His') . '.csv';
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');

            fputcsv($out, [
                'Created Date',
                'Assigned Date',
                'CID',
                'Campaign',
                'Data Source',
                'Agent',
                'Client',
                'Phone',
                'Email',
                'State',
                'Stage',
                'Status',
                'Lead Debt Amount',
                'Debt Tier',
                'Enrolled Debt',
                'Submitted Date',
                'Welcome Call Date',
                'Payment Date',
                'Cancel Date',
                'NSF Date',
            ]);

            foreach ($rows as $row) {
                fputcsv($out, [
                    $this->formatCsvDate($row->Created_Date),
                    $this->formatCsvDate($row->Assigned_Date),
                    preg_replace('/\D+/', '', (string) $row->LLG_ID),
                    $row->Campaign,
                    $row->Data_Source,
                    $row->Agent,
                    $row->Client,
                    $row->Phone,
                    $row->Email,
                    $row->State,
                    $row->Stage,
                    $row->Status,
                    $this->formatCsvDecimal($row->Debt_Amount),
                    $row->Debt_Tier,
                    $this->formatCsvDecimal($row->Enrolled_Debt),
                    $this->formatCsvDate($row->Submitted_Date),
                    $this->formatCsvDate($row->Welcome_Call_Date),
                    $this->formatCsvDate($row->Payment_Date),
                    $this->formatCsvDate($row->Cancel_Date),
                    $this->formatCsvDate($row->NSF_Date),
                ]);
            }

            fclose($out);
        }, $filename, $headers);
    }
}
