<?php

namespace Cmd\Reports\Http\Controllers;

use Cmd\Reports\Repositories\MarketingAdminRepository;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MarketingAdminController extends Controller
{
    public function __construct(protected MarketingAdminRepository $repo)
    {
    }

    public function index(Request $request): View
    {
        $perPage = (int) $request->query('per_page', 15);
        if ($perPage < 5) { $perPage = 5; }
        if ($perPage > 100) { $perPage = 100; }
        $page = max(1, (int) $request->query('page', 1));

        $filters = [
            'send_start' => trim((string) $request->query('send_start', '')),
            'send_end' => trim((string) $request->query('send_end', '')),
            'state' => strtoupper(trim((string) $request->query('state', ''))),
            'debt_min' => $request->query('debt_min'),
            'debt_max' => $request->query('debt_max'),
            'fico_min' => $request->query('fico_min'),
            'fico_max' => $request->query('fico_max'),
            'drops' => $request->query('drops'),
            'month' => (int) $request->query('month', 0),
            'year' => (int) $request->query('year', 0),
            'tier' => strtoupper(trim((string) $request->query('tier', ''))),
            'vendor' => trim((string) $request->query('vendor', '')),
            'data_provider' => trim((string) $request->query('data_provider', '')),
            'marketing_type' => strtoupper(trim((string) $request->query('marketing_type', ''))),
            'unique' => (int) $request->query('unique', 0) === 1,
            'sort' => strtolower((string) $request->query('sort', 'send_date')),
            'dir' => strtolower((string) $request->query('dir', 'asc')),
            'intent' => (function () use ($request) {
                $intent = strtolower((string) $request->query('intent', 'all'));
                return in_array($intent, ['all', 'yes', 'no'], true) ? $intent : 'all';
            })(),
        ];

        $submitted = $request->query->count() > 0;

        $data = $submitted
            ? $this->repo->summary($filters, $page, $perPage)
            : ['columns' => [], 'rows' => [], 'total' => 0, 'report' => 'marketing_admin'];

        $audit = $this->repo->auditIntentCounts();

        return view('reports::reports.marketing_admin', array_merge($data, [
            'perPage' => $perPage,
            'page' => $page,
            'filters' => $filters,
            'submitted' => $submitted,
            'allDrops' => $this->repo->listDrops(),
            'allStates' => $this->repo->listStates(),
            'allVendors' => $this->repo->listVendors(),
            'intentAudit' => $audit,
            'allDataProviders' => $this->repo->listDataProviders(),
            'chartJson' => $submitted ? $this->buildChartData($this->loadAllRowsForCharts($filters)) : 'null',
        ]));
    }

    /**
     * @param array<string,mixed> $filters
     * @return array<int,object>
     */
    private function loadAllRowsForCharts(array $filters): array
    {
        $countResult = $this->repo->summary($filters, 1, 1);
        $totalRows = (int) ($countResult['total'] ?? 0);

        if ($totalRows <= 0) {
            return [];
        }

        $allRowsResult = $this->repo->summary($filters, 1, $totalRows);

        return $allRowsResult['rows'] ?? [];
    }

    /** @param array<int, object> $rows */
    private function buildChartData(array $rows): string
    {
        $tierBuckets  = [];
        $funnelTotals = [
            'Total Leads'       => 0,
            'Qualified Leads'   => 0,
            'Unqualified Leads' => 0,
            'Total Enrollments' => 0,
            'Net Enrollments'   => 0,
        ];
        $dropBuckets = [];
        $trendBuckets = [];

        foreach ($rows as $row) {
            $r = (array) $row;

            $funnelTotals['Total Leads']       += (int) ($r['Total Leads']       ?? 0);
            $funnelTotals['Qualified Leads']   += (int) ($r['Qualified Leads']   ?? 0);
            $funnelTotals['Unqualified Leads'] += (int) ($r['Unqualified Leads'] ?? 0);
            $funnelTotals['Total Enrollments'] += (int) ($r['Total Enrollments'] ?? 0);
            $funnelTotals['Net Enrollments']   += (int) ($r['Net Enrollments']   ?? 0);

            // Match table display: strip ".X" suffix so "T1.5" rolls into "T1"
            $tier = preg_replace('/\..*$/', '', trim((string) ($r['Tier'] ?? '')));
            // Skip unknown/empty tiers instead of grouping them
            if ($tier !== '' && $tier !== null) {
                $tierBuckets[$tier]['leads']    = ($tierBuckets[$tier]['leads']    ?? 0) + (int) ($r['Total Leads']       ?? 0);
                $tierBuckets[$tier]['enrolled'] = ($tierBuckets[$tier]['enrolled'] ?? 0) + (int) ($r['Total Enrollments'] ?? 0);
                $tierBuckets[$tier]['cost']     = ($tierBuckets[$tier]['cost']     ?? 0) + (float) ($r['Drop Cost']       ?? 0);
            }

            $drop = trim((string) ($r['Drop Name'] ?? ''));
            if ($drop !== '') {
                $dropBuckets[$drop] = ($dropBuckets[$drop] ?? 0) + (int) ($r['Total Enrollments'] ?? 0);
            }

            // Trend data by send date (weekly grouping)
            $sendDate = $r['Send Date'] ?? null;
            if ($sendDate) {
                try {
                    $dateObj = is_string($sendDate) ? new \DateTime($sendDate) : $sendDate;
                    $weekStart = $dateObj->modify('monday this week')->format('Y-m-d');
                    
                    if (!isset($trendBuckets[$weekStart])) {
                        $trendBuckets[$weekStart] = ['leads' => 0, 'enrollments' => 0, 'cost' => 0];
                    }
                    $trendBuckets[$weekStart]['leads'] += (int) ($r['Total Leads'] ?? 0);
                    $trendBuckets[$weekStart]['enrollments'] += (int) ($r['Total Enrollments'] ?? 0);
                    $trendBuckets[$weekStart]['cost'] += (float) ($r['Drop Cost'] ?? 0);
                } catch (\Exception $e) {
                    // Skip invalid dates
                }
            }
        }

        uksort($tierBuckets, fn($a, $b) => strnatcmp($a, $b));

        arsort($dropBuckets);
        $topDrops = array_slice($dropBuckets, 0, 10, true);

        ksort($trendBuckets);
        $trendLabels = array_keys($trendBuckets);
        $trendLeads = array_column(array_values($trendBuckets), 'leads');
        $trendEnrollments = array_column(array_values($trendBuckets), 'enrollments');
        $trendCost = array_column(array_values($trendBuckets), 'cost');

        return json_encode([
            'funnel' => [
                'labels' => array_keys($funnelTotals),
                'values' => array_values($funnelTotals),
            ],
            'tier' => [
                'labels'   => array_keys($tierBuckets),
                'leads'    => array_column(array_values($tierBuckets), 'leads'),
                'enrolled' => array_column(array_values($tierBuckets), 'enrolled'),
            ],
            'drops' => [
                'labels' => array_keys($topDrops),
                'values' => array_values($topDrops),
            ],
            'trend' => [
                'labels' => $trendLabels,
                'leads' => $trendLeads,
                'enrollments' => $trendEnrollments,
                'cost' => $trendCost,
            ],
        ], JSON_THROW_ON_ERROR);
    }

    public function chartData(Request $request)
    {
        $filters = [
            'send_start' => trim((string) $request->query('send_start', '')),
            'send_end' => trim((string) $request->query('send_end', '')),
            'state' => strtoupper(trim((string) $request->query('state', ''))),
            'tier' => strtoupper(trim((string) $request->query('tier', ''))),
            'vendor' => trim((string) $request->query('vendor', '')),
            'drops' => $request->query('drops'),
            'month' => (int) $request->query('month', 0),
            'year' => (int) $request->query('year', 0),
            'data_provider' => trim((string) $request->query('data_provider', '')),
            'marketing_type' => strtoupper(trim((string) $request->query('marketing_type', ''))),
            'intent' => (function () use ($request) {
                $intent = strtolower((string) $request->query('intent', 'all'));
                return in_array($intent, ['all', 'yes', 'no'], true) ? $intent : 'all';
            })(),
            'sort' => 'send_date',
            'dir' => 'asc',
        ];

        $chartJson = $this->buildChartData($this->loadAllRowsForCharts($filters));

        return response()->json(json_decode($chartJson, true));
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = [
            'send_start' => trim((string) $request->query('send_start', '')),
            'send_end' => trim((string) $request->query('send_end', '')),
            'state' => strtoupper(trim((string) $request->query('state', ''))),
            'debt_min' => $request->query('debt_min'),
            'debt_max' => $request->query('debt_max'),
            'fico_min' => $request->query('fico_min'),
            'fico_max' => $request->query('fico_max'),
            'drops' => $request->query('drops'),
            'month' => (int) $request->query('month', 0),
            'year' => (int) $request->query('year', 0),
            'tier' => strtoupper(trim((string) $request->query('tier', ''))),
            'vendor' => trim((string) $request->query('vendor', '')),
            'data_provider' => trim((string) $request->query('data_provider', '')),
            'marketing_type' => strtoupper(trim((string) $request->query('marketing_type', ''))),
            'unique' => (int) $request->query('unique', 0) === 1,
            'sort' => strtolower((string) $request->query('sort', 'send_date')),
            'dir' => strtolower((string) $request->query('dir', 'asc')),
            'intent' => (function () use ($request) {
                $intent = strtolower((string) $request->query('intent', 'all'));
                return in_array($intent, ['all', 'yes', 'no'], true) ? $intent : 'all';
            })(),
        ];

        $data = $this->repo->summary($filters, 1, 10000);
        $filename = 'marketing_admin_report_' . date('Y-m-d_H-i-s') . '.csv';

        return response()->streamDownload(function () use ($data) {
            $output = fopen('php://output', 'w');
            fputcsv($output, $data['columns']);
            foreach ($data['rows'] as $row) {
                $csvRow = [];
                foreach ($data['columns'] as $column) {
                    $value = $row->{$column} ?? '';
                    $csvRow[] = is_numeric($value) ? $value : str_replace(['"', "\n", "\r"], ['""', ' ', ' '], (string) $value);
                }
                fputcsv($output, $csvRow);
            }
            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
}
