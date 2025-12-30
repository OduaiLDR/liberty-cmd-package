<?php

namespace Cmd\Reports\Http\Controllers;

use Carbon\Carbon;
use Cmd\Reports\Http\Requests\WelcomePacketReportRequest;
use Cmd\Reports\Repositories\WelcomePacketReportRepository;
use Cmd\Reports\Support\CsvFormatting;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WelcomePacketReportController extends Controller
{
    use CsvFormatting;

    public function __construct(
        protected WelcomePacketReportRepository $repository
    ) {
    }

    public function index(WelcomePacketReportRequest $request): View|StreamedResponse
    {
        $perPage = $this->normalizePerPage($request->input('per_page', 25));

        $filters = $this->extractFilters($request);

        if ($request->input('export') === 'csv') {
            $rows = $this->repository->all($filters);

            return $this->exportCsv($rows);
        }

        $reports = $this->repository->paginate($perPage, $filters);

        return view('reports::reports.welcome_packet', [
            'reports' => $reports,
            'columns' => $this->repository->columns(),
            'filters' => $filters,
            'perPage' => $perPage,
        ]);
    }

    protected function normalizePerPage(int|string|null $perPage): int
    {
        $perPage = (int) ($perPage ?? 25);

        return $perPage > 0 && $perPage <= 1000 ? $perPage : 25;
    }

    /**
     * @return array<string, mixed>
     */
    protected function extractFilters(Request $request): array
    {
        return [
            'client' => $this->trimOrNull($request->input('client')),
            'plan' => $this->trimOrNull($request->input('plan')),
            'llg_id' => $this->trimOrNull($request->input('llg_id')),
            'cleared_from' => $this->trimOrNull($request->input('cleared_from')),
            'cleared_to' => $this->trimOrNull($request->input('cleared_to')),
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
        $filename = 'welcome_packet_report_' . Carbon::now()->format('Ymd_His') . '.csv';
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');

            fputcsv($out, [
                'LLG ID',
                'Client',
                'Plan',
                'Cleared Date',
                'Return Address',
            ]);

            foreach ($rows as $row) {
                fputcsv($out, [
                    $row->llg_id,
                    $row->client,
                    $row->plan,
                    $this->formatCsvDate($row->cleared_date),
                    $row->return_address,
                ]);
            }

            fclose($out);
        }, $filename, $headers);
    }
}
