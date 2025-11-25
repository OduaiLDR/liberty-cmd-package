<?php

namespace Cmd\Reports\Http\Controllers;

use Cmd\Reports\Repositories\MarketingAdminRepository;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

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
        ];

        $data = $this->repo->summary($filters, $page, $perPage);

        return view('reports::reports.marketing_admin', array_merge($data, [
            'perPage' => $perPage,
            'page' => $page,
            'filters' => $filters,
            'allDrops' => $this->repo->listDrops(),
            'allStates' => $this->repo->listStates(),
            'allVendors' => $this->repo->listVendors(),
            'allDataProviders' => $this->repo->listDataProviders(),
        ]));
    }
}
