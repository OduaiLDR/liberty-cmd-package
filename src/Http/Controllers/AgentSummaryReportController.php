<?php

namespace Cmd\Reports\Http\Controllers;

use Carbon\Carbon;
use Cmd\Reports\Http\Requests\AgentSummaryReportRequest;
use Cmd\Reports\Repositories\AgentSummaryReportRepository;
use Cmd\Reports\Support\CsvFormatting;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AgentSummaryReportController extends Controller
{
    use CsvFormatting;

    public function __construct(
        protected AgentSummaryReportRepository $repository
    ) {
    }

    public function index(AgentSummaryReportRequest $request): View|StreamedResponse
    {
        $perPage = $this->normalizePerPage($request->input('per_page', 25));

        $filters = $this->extractFilters($request);

        if ($request->input('export') === 'csv') {
            $rows = $this->repository->all($filters);

            return $this->exportCsv($rows);
        }

        $reports = $this->repository->paginate($perPage, $filters);
        $options = $this->repository->options();

        return view('reports::reports.agent_summary', [
            'reports' => $reports,
            'columns' => $this->repository->columns(),
            'filters' => $filters,
            'perPage' => $perPage,
            'opts' => $options,
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
            'agent' => $this->trimOrNull($request->input('agent')),
            'date_from' => $this->trimOrNull($request->input('date_from')),
            'date_to' => $this->trimOrNull($request->input('date_to')),
            'debt_min' => $this->trimOrNull($request->input('debt_min')),
            'debt_max' => $this->trimOrNull($request->input('debt_max')),
            'avg_debt_min' => $this->trimOrNull($request->input('avg_debt_min')),
            'avg_debt_max' => $this->trimOrNull($request->input('avg_debt_max')),
            'leads_min' => $this->trimOrNull($request->input('leads_min')),
            'leads_max' => $this->trimOrNull($request->input('leads_max')),
            'assigned_min' => $this->trimOrNull($request->input('assigned_min')),
            'assigned_max' => $this->trimOrNull($request->input('assigned_max')),
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
        $filename = 'agent_summary_' . Carbon::now()->format('Ymd_His') . '.csv';
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');

            fputcsv($out, [
                'Agent ID',
                'Agent',
                'Available Unit',
                'Max Leads',
                'Conversion Ratio',
                'Average Debt Assigned',
                'Target',
                'Variance',
                'Leads',
                'Assigned',
                'Debt Assigned',
                'Average Debt Assigned (90d)',
                'T1', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'T8', 'T9'
            ]);

            foreach ($rows as $row) {
                fputcsv($out, [
                    $row->agent_id,
                    $row->agent,
                    $row->available_unit,
                    $row->max_leads,
                    $row->conversion_ratio,
                    $this->formatCsvCurrency($row->avg_debt_assigned),
                    $row->target,
                    $row->variance,
                    $row->leads,
                    $row->assigned,
                    $this->formatCsvCurrency($row->debt_assigned),
                    $this->formatCsvCurrency($row->avg_debt_assigned_dup),
                    $row->t1,
                    $row->t2,
                    $row->t3,
                    $row->t4,
                    $row->t5,
                    $row->t6,
                    $row->t7,
                    $row->t8,
                    $row->t9,
                ]);
            }

            fclose($out);
        }, $filename, $headers);
    }
}
