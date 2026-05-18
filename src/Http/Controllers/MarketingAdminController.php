<?php

namespace Cmd\Reports\Http\Controllers;

use Cmd\Reports\Repositories\MarketingAdminRepository;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MarketingAdminController extends Controller
{
    public function __construct(protected MarketingAdminRepository $repo)
    {
    }

    /**
     * Render the page shell only — no heavy queries here.
     * Summary, chart, and table data each load asynchronously via AJAX.
     */
    public function index(Request $request): View
    {
        $filters = $this->filtersFromRequest($request);

        return view('reports::reports.marketing_admin', [
            'filters'          => $filters,
            'snapshotAt'       => $this->repo->snapshotAt(),
            'allDrops'         => $this->repo->listDrops(),
            'allStates'        => $this->repo->listStates(),
            'allVendors'       => $this->repo->listVendors(),
            'allDataProviders' => $this->repo->listDataProviders(),
            'allTiers'         => $this->repo->listTiers(),
            'allYears'         => $this->repo->listYears(),
            'allMailStyles'    => $this->repo->listMailStyles(),
            'allDropTypes'     => $this->repo->listDropTypes(),
        ]);
    }

    /** @return array<string, mixed> */
    private function filtersFromRequest(Request $request): array
    {
        $intent = strtolower((string) $request->query('intent', 'all'));

        $normalizeArray = static function (mixed $v): array {
            if (is_array($v)) {
                return array_values(array_filter(array_map('trim', $v)));
            }
            if (is_string($v) && $v !== '') {
                return array_values(array_filter(array_map('trim', explode(',', $v))));
            }
            return [];
        };

        return [
            'send_start'      => trim((string) $request->query('send_start', '')),
            'send_end'        => trim((string) $request->query('send_end', '')),
            'drops'           => $normalizeArray($request->query('drops', [])),
            'states'          => $normalizeArray($request->query('states', [])),
            'tiers'           => $normalizeArray($request->query('tiers', [])),
            'vendors'         => $normalizeArray($request->query('vendors', [])),
            'data_providers'  => $normalizeArray($request->query('data_providers', [])),
            'marketing_types' => $normalizeArray($request->query('marketing_types', [])),
            'mail_styles'     => $normalizeArray($request->query('mail_styles', [])),
            'months'          => array_values(array_filter(array_map('intval', $normalizeArray($request->query('months', []))), fn ($m) => $m >= 1 && $m <= 12)),
            'years'           => array_values(array_filter(array_map('intval', $normalizeArray($request->query('years', []))), fn ($y) => $y >= 2000 && $y <= 2100)),
            'debt_min'        => $request->query('debt_min'),
            'debt_max'        => $request->query('debt_max'),
            'fico_min'        => $request->query('fico_min'),
            'fico_max'        => $request->query('fico_max'),
            'unique'          => (int) $request->query('unique', 0) === 1,
            'sort'            => strtolower((string) $request->query('sort', 'send_date')),
            'dir'             => strtolower((string) $request->query('dir', 'asc')),
            'intent'          => in_array($intent, ['all', 'yes', 'no'], true) ? $intent : 'all',
            'chart_period'    => in_array($request->query('chart_period', 'weekly'), ['weekly', 'monthly']) ? $request->query('chart_period', 'weekly') : 'weekly',
        ];
    }

    /** Lightweight time-series for the line chart (AJAX). */
    public function chartData(Request $request): JsonResponse
    {
        $filters = $this->filtersFromRequest($request);

        return response()->json($this->repo->timeSeries($filters, $filters['chart_period']));
    }

    /** Drop Summary aggregates panel (AJAX, returns rendered HTML). */
    public function summaryData(Request $request): View
    {
        $filters           = $this->filtersFromRequest($request);
        $summaryAggregates = $this->repo->summaryAggregates($filters);

        return view('reports::reports.partials.marketing_admin_summary', [
            'summaryAggregates' => $summaryAggregates,
        ]);
    }

    /** Paginated data table (AJAX, returns rendered HTML — only fetched when toggled open). */
    public function tableData(Request $request): View
    {
        $perPage = max(5, min(100, (int) $request->query('per_page', 15)));
        $page    = max(1, (int) $request->query('page', 1));
        $filters = $this->filtersFromRequest($request);

        $data = $this->repo->summary($filters, $page, $perPage);

        return view('reports::reports.partials.marketing_admin_table', array_merge($data, [
            'page'    => $page,
            'perPage' => $perPage,
        ]));
    }

    /** Rebuild the nightly snapshot on demand (AJAX). */
    public function refresh(Request $request): JsonResponse
    {
        $at = $this->repo->cacheSnapshot();

        return response()->json([
            'success'     => true,
            'snapshot_at' => $at->diffForHumans(),
            'snapshot_ts' => $at->toDateTimeString(),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filters  = $this->filtersFromRequest($request);
        $data     = $this->repo->summary($filters, 1, 10000);
        $filename = 'marketing_admin_report_'.date('Y-m-d_H-i-s').'.csv';

        return response()->streamDownload(function () use ($data) {
            $output = fopen('php://output', 'w');
            fputcsv($output, $data['columns']);
            foreach ($data['rows'] as $row) {
                $csvRow = [];
                foreach ($data['columns'] as $column) {
                    $value    = $row->{$column} ?? '';
                    $csvRow[] = is_numeric($value) ? $value : str_replace(['"', "\n", "\r"], ['""', ' ', ' '], (string) $value);
                }
                fputcsv($output, $csvRow);
            }
            fclose($output);
        }, $filename, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }
}
