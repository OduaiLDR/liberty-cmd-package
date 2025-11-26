<?php

namespace Cmd\Reports\Http\Controllers;

use Cmd\Reports\Repositories\DropSummaryRepository;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class DropSummaryController extends Controller
{
    public function __construct(protected DropSummaryRepository $repo)
    {
    }

    public function index(Request $request): View
    {
        $filters = [
            'send_start' => trim((string) $request->query('send_start', '')),
            'send_end' => trim((string) $request->query('send_end', '')),
            'month' => (int) $request->query('month', 0),
            'year' => (int) $request->query('year', 0),
            'tier' => strtoupper(trim((string) $request->query('tier', ''))),
            'vendor' => trim((string) $request->query('vendor', '')),
            'data_provider' => trim((string) $request->query('data_provider', '')),
            'drops' => $request->query('drops'),
            'chart_start' => trim((string) $request->query('chart_start', '')),
            'chart_end' => trim((string) $request->query('chart_end', '')),
            'chart_period' => trim((string) $request->query('chart_period', 'weekly')),
        ];

        $agg = $this->repo->dropSummaryAggregates($filters);
        $ts = $this->repo->dropSummaryTimeSeries($filters);

        return view('reports::reports.drop_summary', [
            'summary' => $agg['summary'],
            'allDrops' => $this->repo->listDrops(),
            'allVendors' => $this->repo->listVendors(),
            'allDataProviders' => $this->repo->listDataProviders(),
            'sendStart' => $filters['send_start'],
            'sendEnd' => $filters['send_end'],
            'month' => $filters['month'],
            'year' => $filters['year'],
            'tier' => $filters['tier'],
            'vendor' => $filters['vendor'],
            'dataProvider' => $filters['data_provider'],
            'selectedDrops' => is_array($filters['drops']) ? $filters['drops'] : (is_string($filters['drops']) ? array_filter(array_map('trim', explode(',', $filters['drops']))) : []),
            'chartStart' => $filters['chart_start'],
            'chartEnd' => $filters['chart_end'],
            'chartPeriod' => $filters['chart_period'],
            'labels' => $ts['labels'],
            'seriesAmount' => $ts['amount'],
            'seriesCost' => $ts['cost'],
            'seriesCalls' => $ts['calls'],
            'seriesAvgReps' => $ts['avg_reps'],
            'seriesResponse' => $ts['response'],
            'hasChartData' => count($ts['labels']) > 0,
        ]);
    }
}

