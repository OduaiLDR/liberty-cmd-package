<?php

namespace Cmd\Reports\Http\Controllers;

use Carbon\Carbon;
use Cmd\Reports\Repositories\ProgramCompletionRepository;
use Cmd\Reports\Support\CsvFormatting;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProgramCompletionController extends Controller
{
    use CsvFormatting;

    public function __construct(
        protected ProgramCompletionRepository $repository
    ) {
    }

    /**
     * Display the Program Completion report or export it as CSV.
     */
    public function index(Request $request): View|StreamedResponse
    {
        $perPage = $this->normalizePerPage($request->input('per_page', 25));
        [$from, $to, $range] = $this->resolveDateRange(
            $request->input('from'),
            $request->input('to'),
            $request->input('range')
        );

        $filters = [
            'llg_id' => $request->input('llg_id'),
            'client' => $request->input('client'),
        ];

        if ($request->input('export') === 'csv') {
            $rows = $this->repository->all($from, $to, $filters);

            return $this->exportCsv($rows);
        }

        $reports = $this->repository->paginate($from, $to, $perPage, $filters);

        return view('reports::reports.program_completion', [
            'reports' => $reports,
            'perPage' => $perPage,
            'filters' => $filters,
            'from' => $from,
            'to' => $to,
            'range' => $range,
        ]);
    }

    /**
     * Provide a JSON payload for asynchronous consumers.
     */
    public function data(Request $request): JsonResponse
    {
        $perPage = $this->normalizePerPage($request->input('per_page', 25));
        [$from, $to, $range] = $this->resolveDateRange(
            $request->input('from'),
            $request->input('to'),
            $request->input('range')
        );

        $filters = [
            'llg_id' => $request->input('llg_id'),
            'client' => $request->input('client'),
        ];

        $reports = $this->repository->paginate($from, $to, $perPage, $filters);

        return response()->json([
            'data' => $reports->items(),
            'meta' => [
                'total' => $reports->total(),
                'per_page' => $reports->perPage(),
                'current_page' => $reports->currentPage(),
                'last_page' => $reports->lastPage(),
                'range' => $range,
                'from' => $from,
                'to' => $to,
            ],
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
     * Resolve the requested date range into usable boundaries.
     *
     * @return array{0: ?string, 1: ?string, 2: string}
     */
    protected function resolveDateRange(?string $from, ?string $to, ?string $range): array
    {
        $range = $range ?? 'all';

        if ($range !== 'custom') {
            $today = Carbon::today();
            switch ($range) {
                case 'all':
                    $from = null;
                    $to = null;
                    break;
                case 'today':
                    $from = $today->format('Y-m-d');
                    $to = $today->format('Y-m-d');
                    break;
                case 'this_month':
                    $from = $today->copy()->startOfMonth()->format('Y-m-d');
                    $to = $today->copy()->endOfMonth()->format('Y-m-d');
                    break;
                case 'last_month':
                    $from = $today->copy()->subMonthNoOverflow()->startOfMonth()->format('Y-m-d');
                    $to = $today->copy()->subMonthNoOverflow()->endOfMonth()->format('Y-m-d');
                    break;
                default:
                    if (Str::of($range)->isNumeric()) {
                        $days = (int) $range;
                        if ($days > 0) {
                            $start = $today->copy()->subDays($days - 1);
                            $from = $start->format('Y-m-d');
                            $to = $today->format('Y-m-d');
                        }
                    }
                    break;
            }
        }

        return [$from, $to, $range];
    }

    /**
     * Stream a CSV export response.
     */
    protected function exportCsv(\Illuminate\Support\Collection $rows): StreamedResponse
    {
        $filename = 'program_completion_report_' . Carbon::now()->format('Ymd_His') . '.csv';
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');

            fputcsv($out, [
                'CID',
                'Client',
                'Welcome Call Date',
                'Total Settlement Amounts Accepted',
                'Original Debt Amount Settled',
                'Enrolled Debt',
                'Settlement Rate',
                'Program Completion',
                'Latest Settlement Date',
            ]);

            foreach ($rows as $row) {
                fputcsv($out, [
                    preg_replace('/\D+/', '', (string) $row->LLG_ID),
                    $row->Client,
                    $this->formatCsvDate($row->Welcome_Call_Date ?? null),
                    $this->formatCsvDecimal($row->Total_Settlement_Amounts_Accepted ?? null),
                    $this->formatCsvDecimal($row->Original_Debt_Amount_Settled ?? null),
                    $this->formatCsvDecimal($row->Enrolled_Debt ?? null),
                    $this->formatCsvRatio($row->Settlement_Rate ?? null),
                    $this->formatCsvRatio($row->Program_Completion ?? null),
                    $this->formatCsvDate($row->Latest_Settlement_Date ?? null),
                ]);
            }

            fclose($out);
        }, $filename, $headers);
    }
}
