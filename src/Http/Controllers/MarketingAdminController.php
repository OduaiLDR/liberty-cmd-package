<?php

namespace Cmd\Reports\Http\Controllers;

use Cmd\Reports\Repositories\MarketingAdminRepository;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MarketingAdminController extends Controller
{
    public function __construct(protected MarketingAdminRepository $repo) {}

    public function index(Request $request): View
    {
        $perPage = (int) $request->query('per_page', 15);
        if ($perPage < 5) {
            $perPage = 5;
        }
        if ($perPage > 100) {
            $perPage = 100;
        }
        $page = max(1, (int) $request->query('page', 1));

        $filters = $this->filtersFromRequest($request);

        $submitted = $request->query->count() > 0;

        $data = $submitted
            ? $this->repo->summary($filters, $page, $perPage)
            : ['columns' => [], 'rows' => [], 'total' => 0, 'report' => 'marketing_admin'];

        // Temporarily disabled: slow remote SQL queries (9+ seconds)
        // $audit = $this->repo->auditIntentCounts();
        $audit = ['total' => 0, 'with' => 0, 'without' => 0];

        // CHART DATA FIX: Charts need ALL filtered rows to show accurate trends, not just current page
        // With optimized single-query approach, this should be acceptable performance
        $chartRows = $submitted ? $this->loadAllRowsForCharts($filters) : [];

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
            'chartJson' => $submitted ? $this->buildChartData($chartRows) : 'null',
        ]));
    }

    /** @return array<string, mixed> */
    private function filtersFromRequest(Request $request): array
    {
        $intent = strtolower((string) $request->query('intent', 'all'));

        return [
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
            'intent' => in_array($intent, ['all', 'yes', 'no'], true) ? $intent : 'all',
        ];
    }

    /**
     * @param array<string,mixed> $filters
     * @return array<int,object>
     */
    private function loadAllRowsForCharts(array $filters): array
    {
        // OPTIMIZATION: With window function fix, we can fetch all rows in one query
        // Set a reasonable limit to prevent memory issues (1000 drops should cover most filters)
        $allRowsResult = $this->repo->summary($filters, 1, 1000);

        return $allRowsResult['rows'] ?? [];
    }

    /** @param array<int, object> $rows */
    private function buildChartData(array $rows): string
    {
        $overview = [
            'totalRoi'           => 0.0,
            'totalDropCost'      => 0.0,
            'totalCapitalPartner' => 0.0,
            'totalVeritas'       => 0.0,
            'totalNetEnrollments' => 0,
            'totalLeads'         => 0,
        ];

        $monthlyBuckets = [];

        foreach ($rows as $row) {
            $r               = (array) $row;
            $roi             = (float) ($r['ROI'] ?? 0);
            $dropCost        = (float) ($r['Drop Cost'] ?? 0);
            $capitalPartner  = (float) ($r['Capital Partner'] ?? 0);
            $veritas         = (float) ($r['Veritas Enrollment'] ?? 0) + (float) ($r['Veritas Monthly'] ?? 0);
            $netEnrollments  = (int)   ($r['Net Enrollments'] ?? 0);
            $totalLeads      = (int)   ($r['Total Leads'] ?? 0);

            $overview['totalRoi']            += $roi;
            $overview['totalDropCost']       += $dropCost;
            $overview['totalCapitalPartner'] += $capitalPartner;
            $overview['totalVeritas']        += $veritas;
            $overview['totalNetEnrollments'] += $netEnrollments;
            $overview['totalLeads']          += $totalLeads;

            $sendDate = $r['Send Date'] ?? null;
            if ($sendDate) {
                try {
                    $dateObj = $sendDate instanceof \DateTimeInterface
                        ? \DateTimeImmutable::createFromInterface($sendDate)
                        : new \DateTimeImmutable((string) $sendDate);

                    $monthKey = $dateObj->format('Y-m');
                    if (!isset($monthlyBuckets[$monthKey])) {
                        $monthlyBuckets[$monthKey] = [
                            'label'          => $dateObj->format('M Y'),
                            'roi'            => 0.0,
                            'dropCost'       => 0.0,
                            'capitalPartner' => 0.0,
                            'veritas'        => 0.0,
                        ];
                    }
                    $monthlyBuckets[$monthKey]['roi']            += $roi;
                    $monthlyBuckets[$monthKey]['dropCost']       += $dropCost;
                    $monthlyBuckets[$monthKey]['capitalPartner'] += $capitalPartner;
                    $monthlyBuckets[$monthKey]['veritas']        += $veritas;
                } catch (\Exception $e) {
                    // skip invalid dates
                }
            }
        }

        ksort($monthlyBuckets);
        $bucketValues = array_values($monthlyBuckets);

        return json_encode([
            'overview' => $overview,
            'roiTrend' => [
                'labels'         => array_map(static fn(array $b): string => $b['label'], $bucketValues),
                'roi'            => array_map(static fn(array $b): float => $b['roi'], $bucketValues),
                'dropCost'       => array_map(static fn(array $b): float => $b['dropCost'], $bucketValues),
                'capitalPartner' => array_map(static fn(array $b): float => $b['capitalPartner'], $bucketValues),
                'veritas'        => array_map(static fn(array $b): float => $b['veritas'], $bucketValues),
            ],
        ], JSON_THROW_ON_ERROR);
    }

    public function chartData(Request $request)
    {
        $filters = $this->filtersFromRequest($request);

        $chartJson = $this->buildChartData($this->loadAllRowsForCharts($filters));

        return response()->json(json_decode($chartJson, true));
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filtersFromRequest($request);

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
