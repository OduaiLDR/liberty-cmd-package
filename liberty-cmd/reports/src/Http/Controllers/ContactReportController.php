<?php

namespace Cmd\Reports\Http\Controllers;

use Carbon\Carbon;
use Cmd\Reports\Http\Requests\ContactReportRequest;
use Cmd\Reports\Repositories\ContactReportRepository;
use Cmd\Reports\Support\CsvFormatting;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ContactReportController extends Controller
{
    use CsvFormatting;

    public function __construct(
        protected ContactReportRepository $repository
    ) {
    }

    public function index(ContactReportRequest $request): View|StreamedResponse
    {
        $perPage = $this->normalizePerPage($request->input('per_page', 25));

        $assignedFrom = $request->input('from');
        $assignedTo = $request->input('to');

        [$createdFrom, $createdTo, $range] = $this->resolveCreatedRange(
            $request->input('range')
        );

        $filters = $this->extractFilters($request);

        if ($request->input('export') === 'csv') {
            $rows = $this->repository->all($assignedFrom, $assignedTo, $createdFrom, $createdTo, $filters);

            return $this->exportCsv($rows);
        }

        $reports = $this->repository->paginate($assignedFrom, $assignedTo, $createdFrom, $createdTo, $perPage, $filters);

        return view('reports::reports.contact', [
            'reports' => $reports,
            'opts' => $this->repository->options(),
            'perPage' => $perPage,
            'from' => $assignedFrom,
            'to' => $assignedTo,
            'range' => $range,
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
    protected function resolveCreatedRange(?string $range): array
    {
        if (!$range) {
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
                return [null, null, $range];
        }
    }

    /**
     * @return array<string, ?string>
     */
    protected function extractFilters(Request $request): array
    {
        return [
            'agent' => $this->trimOrNull($request->input('agent')),
            'client' => $this->trimOrNull($request->input('client')),
            'data_source' => $this->trimOrNull($request->input('data_source')),
            'stage' => $this->trimOrNull($request->input('stage')),
            'status' => $this->trimOrNull($request->input('status')),
            'state' => $this->trimOrNull($request->input('state')),
            'debt_min' => $this->trimOrNull($request->input('debt_min')),
            'debt_max' => $this->trimOrNull($request->input('debt_max')),
            'score_min' => $this->trimOrNull($request->input('score_min')),
            'score_max' => $this->trimOrNull($request->input('score_max')),
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

    protected function exportCsv(Collection $rows): StreamedResponse
    {
        $filename = 'contact_reports_' . Carbon::now()->format('Ymd_His') . '.csv';
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
                'External ID',
                'Campaign',
                'Data Source',
                'Agent',
                'Client',
                'Phone',
                'Email',
                'Stage',
                'Status',
                'Debt Enrolled',
                'Address',
                'City',
                'State',
                'Zip',
                'Credit Score',
            ]);

            foreach ($rows as $row) {
                $cid = preg_replace('/\D+/', '', (string) $row->LLG_ID);
                $address = trim(implode(' ', array_filter([
                    trim((string) ($row->Address_1 ?? '')),
                    trim((string) ($row->Address_2 ?? '')),
                ])));

                fputcsv($out, [
                    $this->formatCsvDate($row->Created_Date),
                    $this->formatCsvDate($row->Assigned_Date),
                    $cid,
                    $row->External_ID,
                    $row->Campaign,
                    $row->Data_Source,
                    $row->Agent,
                    $row->Client,
                    $row->Phone,
                    $row->Email,
                    $row->Stage,
                    $row->Status,
                    $this->formatCsvCurrency($row->Debt_Enrolled),
                    $address,
                    $row->City,
                    $row->State,
                    $row->Zip,
                    $row->Credit_Score,
                ]);
            }

            fclose($out);
        }, $filename, $headers);
    }
}
