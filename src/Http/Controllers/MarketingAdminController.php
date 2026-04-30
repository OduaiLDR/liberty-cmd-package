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
        $tierBuckets  = [];
        $funnelTotals = [
            'Total Leads'       => 0,
            'Qualified Leads'   => 0,
            'Unqualified Leads' => 0,
            'Total Enrollments' => 0,
            'Net Enrollments'   => 0,
        ];
        $overview = [
            'total_leads' => 0,
            'qualified_leads' => 0,
            'total_enrollments' => 0,
            'net_enrollments' => 0,
            'drop_cost' => 0.0,
            'est_revenue' => 0.0,
            'est_profit' => 0.0,
        ];
        $dropBuckets = [];
        $trendBuckets = [];
        $trendMonthlyBuckets = [];

        foreach ($rows as $row) {
            $r = (array) $row;
            $totalLeads = (int) ($r['Total Leads'] ?? 0);
            $qualifiedLeads = (int) ($r['Qualified Leads'] ?? 0);
            $unqualifiedLeads = (int) ($r['Unqualified Leads'] ?? 0);
            $totalEnrollments = (int) ($r['Total Enrollments'] ?? 0);
            $netEnrollments = (int) ($r['Net Enrollments'] ?? 0);
            $dropCost = (float) ($r['Drop Cost'] ?? 0);
            $estimatedRevenue = (float) ($r['Est Revenue'] ?? 0);
            $estimatedProfit = (float) ($r['Est Profit'] ?? 0);
            $veritasEnrollmentFee = (float) ($r['Veritas Enrollment'] ?? 0);
            $veritasMonthlyFee = (float) ($r['Veritas Monthly'] ?? 0);

            $funnelTotals['Total Leads'] += $totalLeads;
            $funnelTotals['Qualified Leads'] += $qualifiedLeads;
            $funnelTotals['Unqualified Leads'] += $unqualifiedLeads;
            $funnelTotals['Total Enrollments'] += $totalEnrollments;
            $funnelTotals['Net Enrollments'] += $netEnrollments;

            $overview['total_leads'] += $totalLeads;
            $overview['qualified_leads'] += $qualifiedLeads;
            $overview['total_enrollments'] += $totalEnrollments;
            $overview['net_enrollments'] += $netEnrollments;
            $overview['drop_cost'] += $dropCost;
            $overview['est_revenue'] += $estimatedRevenue;
            $overview['est_profit'] += $estimatedProfit;

            // Match table display: strip ".X" suffix so "T1.5" rolls into "T1"
            $tier = preg_replace('/\..*$/', '', trim((string) ($r['Tier'] ?? '')));
            // Skip unknown/empty tiers instead of grouping them
            if ($tier !== '' && $tier !== null) {
                $tierBuckets[$tier]['leads'] = ($tierBuckets[$tier]['leads'] ?? 0) + $totalLeads;
                $tierBuckets[$tier]['enrollments'] = ($tierBuckets[$tier]['enrollments'] ?? 0) + $totalEnrollments;
                $tierBuckets[$tier]['netEnrollments'] = ($tierBuckets[$tier]['netEnrollments'] ?? 0) + $netEnrollments;
                $tierBuckets[$tier]['cost'] = ($tierBuckets[$tier]['cost'] ?? 0) + $dropCost;
                $tierBuckets[$tier]['profit'] = ($tierBuckets[$tier]['profit'] ?? 0) + $estimatedProfit;
                $tierBuckets[$tier]['veritasEnrollment'] = ($tierBuckets[$tier]['veritasEnrollment'] ?? 0) + $veritasEnrollmentFee;
                $tierBuckets[$tier]['veritasMonthly'] = ($tierBuckets[$tier]['veritasMonthly'] ?? 0) + $veritasMonthlyFee;
            }

            $drop = trim((string) ($r['Drop Name'] ?? ''));
            if ($drop !== '') {
                $dropBuckets[$drop]['leads'] = ($dropBuckets[$drop]['leads'] ?? 0) + $totalLeads;
                $dropBuckets[$drop]['enrollments'] = ($dropBuckets[$drop]['enrollments'] ?? 0) + $totalEnrollments;
                $dropBuckets[$drop]['profit'] = ($dropBuckets[$drop]['profit'] ?? 0) + $estimatedProfit;
                $dropBuckets[$drop]['revenue'] = ($dropBuckets[$drop]['revenue'] ?? 0) + $estimatedRevenue;
                $dropBuckets[$drop]['netEnrollments'] = ($dropBuckets[$drop]['netEnrollments'] ?? 0) + $netEnrollments;
                $dropBuckets[$drop]['cost'] = ($dropBuckets[$drop]['cost'] ?? 0) + $dropCost;
                $dropBuckets[$drop]['veritasEnrollment'] = ($dropBuckets[$drop]['veritasEnrollment'] ?? 0) + $veritasEnrollmentFee;
                $dropBuckets[$drop]['veritasMonthly'] = ($dropBuckets[$drop]['veritasMonthly'] ?? 0) + $veritasMonthlyFee;
            }

            // Trend data by send date (weekly grouping)
            $sendDate = $r['Send Date'] ?? null;
            if ($sendDate) {
                try {
                    $dateObj = $sendDate instanceof \DateTimeInterface
                        ? \DateTimeImmutable::createFromInterface($sendDate)
                        : new \DateTimeImmutable((string) $sendDate);
                    $weekStart = $dateObj->modify('monday this week')->format('Y-m-d');

                    if (!isset($trendBuckets[$weekStart])) {
                        $trendBuckets[$weekStart] = [
                            'leads' => 0,
                            'enrollments' => 0,
                            'netEnrollments' => 0,
                            'cost' => 0.0,
                            'revenue' => 0.0,
                            'profit' => 0.0,
                        ];
                    }
                    $trendBuckets[$weekStart]['leads'] += $totalLeads;
                    $trendBuckets[$weekStart]['enrollments'] += $totalEnrollments;
                    $trendBuckets[$weekStart]['netEnrollments'] += $netEnrollments;
                    $trendBuckets[$weekStart]['cost'] += $dropCost;
                    $trendBuckets[$weekStart]['revenue'] += $estimatedRevenue;
                    $trendBuckets[$weekStart]['profit'] += $estimatedProfit;

                    $monthStart = $dateObj->format('Y-m-01');
                    if (!isset($trendMonthlyBuckets[$monthStart])) {
                        $trendMonthlyBuckets[$monthStart] = [
                            'leads' => 0,
                            'enrollments' => 0,
                            'netEnrollments' => 0,
                            'cost' => 0.0,
                            'revenue' => 0.0,
                            'profit' => 0.0,
                        ];
                    }
                    $trendMonthlyBuckets[$monthStart]['leads'] += $totalLeads;
                    $trendMonthlyBuckets[$monthStart]['enrollments'] += $totalEnrollments;
                    $trendMonthlyBuckets[$monthStart]['netEnrollments'] += $netEnrollments;
                    $trendMonthlyBuckets[$monthStart]['cost'] += $dropCost;
                    $trendMonthlyBuckets[$monthStart]['revenue'] += $estimatedRevenue;
                    $trendMonthlyBuckets[$monthStart]['profit'] += $estimatedProfit;
                } catch (\Exception $e) {
                    // Skip invalid dates
                }
            }
        }

        uksort($tierBuckets, fn($a, $b) => strnatcmp($a, $b));

        // Prepare tier arrays, including per-tier conversion and ROI for business intelligence charts
        $tierLabels = array_keys($tierBuckets);
        $tierValues = array_values($tierBuckets);
        $tierLeads = array_column($tierValues, 'leads');
        $tierNetEnrollments = array_column($tierValues, 'netEnrollments');
        $tierCost = array_column($tierValues, 'cost');
        $tierProfit = array_column($tierValues, 'profit');
        $tierConversion = [];
        $tierRoi = [];
        foreach ($tierValues as $bucket) {
            $leadsForTier = (int) ($bucket['leads'] ?? 0);
            $enrollmentsForTier = (int) ($bucket['enrollments'] ?? 0);
            $costForTier = (float) ($bucket['cost'] ?? 0.0);
            $profitForTier = (float) ($bucket['profit'] ?? 0.0);

            $tierConversion[] = $leadsForTier > 0
                ? ($enrollmentsForTier / $leadsForTier) * 100.0
                : 0.0;
            $tierRoi[] = $costForTier > 0.0
                ? $profitForTier / $costForTier
                : 0.0;
        }

        uasort($dropBuckets, static fn (array $left, array $right): int => $right['profit'] <=> $left['profit']);
        $topDrops = array_slice($dropBuckets, 0, 10, true);

        // Build a separate Veritas ROI view, prioritizing drops where Veritas fees are material
        $veritasDrops = $dropBuckets;
        uasort($veritasDrops, static function (array $left, array $right): int {
            $leftTotal = (float) (($left['veritasEnrollment'] ?? 0.0) + ($left['veritasMonthly'] ?? 0.0));
            $rightTotal = (float) (($right['veritasEnrollment'] ?? 0.0) + ($right['veritasMonthly'] ?? 0.0));

            return $rightTotal <=> $leftTotal;
        });
        $veritasTop = array_slice($veritasDrops, 0, 10, true);

        ksort($trendBuckets);
        $trendLabels = array_keys($trendBuckets);
        $trendLeads = array_column(array_values($trendBuckets), 'leads');
        $trendEnrollments = array_column(array_values($trendBuckets), 'enrollments');
        $trendNetEnrollments = array_column(array_values($trendBuckets), 'netEnrollments');
        $trendCost = array_column(array_values($trendBuckets), 'cost');
        $trendRevenue = array_column(array_values($trendBuckets), 'revenue');
        $trendProfit = array_column(array_values($trendBuckets), 'profit');
        $trendConversion = array_map(
            static fn (array $bucket): float => $bucket['leads'] > 0
                ? ($bucket['enrollments'] / $bucket['leads']) * 100
                : 0.0,
            array_values($trendBuckets)
        );

        $trendCpl = array_map(
            static fn (array $bucket): float => $bucket['leads'] > 0 ? ($bucket['cost'] / $bucket['leads']) : 0.0,
            array_values($trendBuckets)
        );
        $trendRpl = array_map(
            static fn (array $bucket): float => $bucket['leads'] > 0 ? ($bucket['revenue'] / $bucket['leads']) : 0.0,
            array_values($trendBuckets)
        );
        $trendRoi = array_map(
            static fn (array $bucket): float => $bucket['cost'] > 0 ? ($bucket['profit'] / $bucket['cost']) : 0.0,
            array_values($trendBuckets)
        );

        ksort($trendMonthlyBuckets);
        $trendMonthlyLabels = array_keys($trendMonthlyBuckets);
        $trendMonthlyLeads = array_column(array_values($trendMonthlyBuckets), 'leads');
        $trendMonthlyEnrollments = array_column(array_values($trendMonthlyBuckets), 'enrollments');
        $trendMonthlyNetEnrollments = array_column(array_values($trendMonthlyBuckets), 'netEnrollments');
        $trendMonthlyCost = array_column(array_values($trendMonthlyBuckets), 'cost');
        $trendMonthlyRevenue = array_column(array_values($trendMonthlyBuckets), 'revenue');
        $trendMonthlyProfit = array_column(array_values($trendMonthlyBuckets), 'profit');
        $trendMonthlyConversion = array_map(
            static fn (array $bucket): float => $bucket['leads'] > 0
                ? ($bucket['enrollments'] / $bucket['leads']) * 100
                : 0.0,
            array_values($trendMonthlyBuckets)
        );
        $trendMonthlyCpl = array_map(
            static fn (array $bucket): float => $bucket['leads'] > 0 ? ($bucket['cost'] / $bucket['leads']) : 0.0,
            array_values($trendMonthlyBuckets)
        );
        $trendMonthlyRpl = array_map(
            static fn (array $bucket): float => $bucket['leads'] > 0 ? ($bucket['revenue'] / $bucket['leads']) : 0.0,
            array_values($trendMonthlyBuckets)
        );
        $trendMonthlyRoi = array_map(
            static fn (array $bucket): float => $bucket['cost'] > 0 ? ($bucket['profit'] / $bucket['cost']) : 0.0,
            array_values($trendMonthlyBuckets)
        );

        $conversionRate = $overview['total_leads'] > 0
            ? ($overview['total_enrollments'] / $overview['total_leads']) * 100
            : 0.0;
        $retentionRate = $overview['total_enrollments'] > 0
            ? ($overview['net_enrollments'] / $overview['total_enrollments']) * 100
            : 0.0;
        $roiRatio = $overview['drop_cost'] > 0
            ? $overview['est_profit'] / $overview['drop_cost']
            : 0.0;
        $costPerLead = $overview['total_leads'] > 0
            ? $overview['drop_cost'] / $overview['total_leads']
            : 0.0;
        $revenuePerLead = $overview['total_leads'] > 0
            ? $overview['est_revenue'] / $overview['total_leads']
            : 0.0;

        $dropStats = [];
        foreach ($dropBuckets as $dropName => $bucket) {
            $cost = (float) ($bucket['cost'] ?? 0.0);
            $profit = (float) ($bucket['profit'] ?? 0.0);
            $revenue = (float) ($bucket['revenue'] ?? 0.0);
            $leads = (int) ($bucket['leads'] ?? 0);
            $enrollments = (int) ($bucket['enrollments'] ?? 0);
            $net = (int) ($bucket['netEnrollments'] ?? 0);
            $veritasEnrollment = (float) ($bucket['veritasEnrollment'] ?? 0.0);
            $veritasMonthly = (float) ($bucket['veritasMonthly'] ?? 0.0);
            $veritasTotal = $veritasEnrollment + $veritasMonthly;

            $dropStats[] = [
                'drop' => $dropName,
                'leads' => $leads,
                'enrollments' => $enrollments,
                'netEnrollments' => $net,
                'cost' => $cost,
                'revenue' => $revenue,
                'profit' => $profit,
                'roiRatio' => $cost > 0.0 ? ($profit / $cost) : 0.0,
                'conversionRate' => $leads > 0 ? ($enrollments / $leads) * 100.0 : 0.0,
                'veritasEnrollmentFee' => $veritasEnrollment,
                'veritasMonthlyFee' => $veritasMonthly,
                'veritasTotalFee' => $veritasTotal,
                'veritasRoiRatio' => $cost > 0.0 ? ($veritasTotal / $cost) : 0.0,
            ];
        }

        return json_encode([
            'overview' => [
                'totalLeads' => $overview['total_leads'],
                'qualifiedLeads' => $overview['qualified_leads'],
                'totalEnrollments' => $overview['total_enrollments'],
                'netEnrollments' => $overview['net_enrollments'],
                'dropCost' => $overview['drop_cost'],
                'estRevenue' => $overview['est_revenue'],
                'estProfit' => $overview['est_profit'],
                'conversionRate' => $conversionRate,
                'retentionRate' => $retentionRate,
                'roiRatio' => $roiRatio,
                'costPerLead' => $costPerLead,
                'revenuePerLead' => $revenuePerLead,
            ],
            'funnel' => [
                'labels' => array_keys($funnelTotals),
                'values' => array_values($funnelTotals),
            ],
            'tier' => [
                'labels'   => $tierLabels,
                'leads'    => $tierLeads,
                'netEnrollments' => $tierNetEnrollments,
                'cost' => $tierCost,
                'profit' => $tierProfit,
                'conversionRate' => $tierConversion,
                'roiRatio' => $tierRoi,
                'veritasEnrollmentFee' => array_column($tierValues, 'veritasEnrollment'),
                'veritasMonthlyFee' => array_column($tierValues, 'veritasMonthly'),
                'veritasTotalFee' => array_map(
                    static fn (array $bucket): float => (float) (($bucket['veritasEnrollment'] ?? 0.0) + ($bucket['veritasMonthly'] ?? 0.0)),
                    $tierValues
                ),
                'veritasRoiRatio' => array_map(
                    static function (array $bucket): float {
                        $cost = (float) ($bucket['cost'] ?? 0.0);
                        $totalVeritas = (float) (($bucket['veritasEnrollment'] ?? 0.0) + ($bucket['veritasMonthly'] ?? 0.0));
                        return $cost > 0.0 ? $totalVeritas / $cost : 0.0;
                    },
                    $tierValues
                ),
            ],
            'drops' => [
                'labels' => array_keys($topDrops),
                'profit' => array_column(array_values($topDrops), 'profit'),
                'revenue' => array_column(array_values($topDrops), 'revenue'),
                'netEnrollments' => array_column(array_values($topDrops), 'netEnrollments'),
            ],
            'veritas' => [
                'labels' => array_keys($veritasTop),
                'enrollmentFee' => array_column(array_values($veritasTop), 'veritasEnrollment'),
                'monthlyFee' => array_column(array_values($veritasTop), 'veritasMonthly'),
                'totalFee' => array_map(
                    static function (array $bucket): float {
                        return (float) (($bucket['veritasEnrollment'] ?? 0.0) + ($bucket['veritasMonthly'] ?? 0.0));
                    },
                    array_values($veritasTop)
                ),
                'roiRatio' => array_map(
                    static function (array $bucket): float {
                        $cost = (float) ($bucket['cost'] ?? 0.0);
                        $totalVeritas = (float) (($bucket['veritasEnrollment'] ?? 0.0) + ($bucket['veritasMonthly'] ?? 0.0));

                        return $cost > 0.0 ? $totalVeritas / $cost : 0.0;
                    },
                    array_values($veritasTop)
                ),
            ],
            'trend' => [
                'labels' => $trendLabels,
                'leads' => $trendLeads,
                'enrollments' => $trendEnrollments,
                'netEnrollments' => $trendNetEnrollments,
                'cost' => $trendCost,
                'revenue' => $trendRevenue,
                'profit' => $trendProfit,
                'conversionRate' => $trendConversion,
                'cpl' => $trendCpl,
                'rpl' => $trendRpl,
                'roiRatio' => $trendRoi,
            ],
            'trendMonthly' => [
                'labels' => $trendMonthlyLabels,
                'leads' => $trendMonthlyLeads,
                'enrollments' => $trendMonthlyEnrollments,
                'netEnrollments' => $trendMonthlyNetEnrollments,
                'cost' => $trendMonthlyCost,
                'revenue' => $trendMonthlyRevenue,
                'profit' => $trendMonthlyProfit,
                'conversionRate' => $trendMonthlyConversion,
                'cpl' => $trendMonthlyCpl,
                'rpl' => $trendMonthlyRpl,
                'roiRatio' => $trendMonthlyRoi,
            ],
            'dropStats' => $dropStats,
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
