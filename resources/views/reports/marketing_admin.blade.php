@extends('layouts.app')

@section('content')
<div class="content">
    <div class="card">
        <div class="card-header">
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
                <h5 class="mb-0">Marketing Admin</h5>
                <div class="d-flex align-items-center gap-2">
                    <a href="{{ url()->current() }}" class="btn btn-outline-danger btn-sm">Reset All</a>
                    <a class="btn btn-outline-secondary btn-sm" data-bs-toggle="collapse" href="#advFilters" role="button" aria-expanded="false" aria-controls="advFilters" title="Advanced filters">⚙️ Advanced Filters</a>
                </div>
            </div>

            <form method="get" action="/cmd/reports/marketing-admin">
                @if($submitted && empty($filters['send_start'] ?? '') && empty($filters['send_end'] ?? ''))
                <div class="alert alert-info mb-3">
                    <strong>Default Date Range:</strong> Showing last 90 days. Specify dates above to change range.
                </div>
                @endif
                <div class="row g-3 align-items-end">
                    <div class="col-12 col-md-3">
                        <label class="form-label">Send Date From</label>
                        <input type="date" name="send_start" value="{{ $filters['send_start'] ?? '' }}" class="form-control">
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label">Send Date To</label>
                        <input type="date" name="send_end" value="{{ $filters['send_end'] ?? '' }}" class="form-control">
                    </div>
                    <div class="col-12 col-md-2">
                        <label class="form-label">Per page</label>
                        <select name="per_page" class="form-select">
                            @foreach([10,15,25,50,100] as $opt)
                                <option value="{{ $opt }}" @if(($perPage ?? 15) == $opt) selected @endif>{{ $opt }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="row g-3 mt-1">
                    <div class="col-12">
                        <label class="form-label">Drop Names <small class="text-muted">(comma separated)</small></label>
                        <input list="dropsList" name="drops" class="form-control" placeholder="Type to search, comma-separated"
                               value="{{ isset($filters['drops']) && is_array($filters['drops']) ? implode(', ', $filters['drops']) : (is_string($filters['drops'] ?? '') ? $filters['drops'] : '') }}">
                        <datalist id="dropsList">
                            @foreach(($allDrops ?? []) as $drop)
                                <option value="{{ $drop }}"></option>
                            @endforeach
                        </datalist>
                    </div>
                </div>

                <div class="collapse mt-3" id="advFilters">
                    <div class="row g-3">
                        <div class="col-12 col-md-3">
                            <label class="form-label">State</label>
                            <select name="state" class="form-select">
                                <option value="">All States</option>
                                @foreach(($allStates ?? []) as $st)
                                    <option value="{{ $st }}" @if(($filters['state'] ?? '') === $st) selected @endif>{{ $st }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-12 col-md-3">
                            <label class="form-label">Month</label>
                            <select name="month" class="form-select">
                                <option value="">All Months</option>
                                @for ($m = 1; $m <= 12; $m++)
                                    <option value="{{ $m }}" @if(($filters['month'] ?? 0) == $m) selected @endif>{{ $m }}</option>
                                @endfor
                            </select>
                        </div>
                        <div class="col-12 col-md-3">
                            <label class="form-label">Year</label>
                            <select name="year" class="form-select">
                                <option value="">All Years</option>
                                @for ($y = 2020; $y <= 2026; $y++)
                                    <option value="{{ $y }}" @if(($filters['year'] ?? 0) == $y) selected @endif>{{ $y }}</option>
                                @endfor
                            </select>
                        </div>
                        <div class="col-12 col-md-3">
                            <label class="form-label">Tier</label>
                            <select name="tier" class="form-select">
                                <option value="">All Tiers</option>
                                @foreach (['T1','T2','T3','T4','T5','T6','T7','T8','T9'] as $t)
                                    <option value="{{ $t }}" @if(($filters['tier'] ?? '') === $t) selected @endif>{{ $t }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-12 col-md-3">
                            <label class="form-label">Vendor</label>
                            <select name="vendor" class="form-select">
                                <option value="">All Vendors</option>
                                @foreach(($allVendors ?? []) as $v)
                                    <option value="{{ $v }}" @if(($filters['vendor'] ?? '') === $v) selected @endif>{{ $v }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-12 col-md-3">
                            <label class="form-label">Data Provider</label>
                            <select name="data_provider" class="form-select">
                                <option value="">All Data Providers</option>
                                @foreach(($allDataProviders ?? []) as $dp)
                                    <option value="{{ $dp }}" @if(($filters['data_provider'] ?? '') === $dp) selected @endif>{{ $dp }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-12 col-md-3">
                            <label class="form-label">Marketing Type</label>
                            <select name="marketing_type" class="form-select">
                                <option value="">All Types</option>
                                @foreach (['AO','NAO','X'] as $mt)
                                    <option value="{{ $mt }}" @if(($filters['marketing_type'] ?? '') === $mt) selected @endif>{{ $mt }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label">Debt Range</label>
                            <div class="d-flex gap-2">
                                <input type="number" name="debt_min" value="{{ $filters['debt_min'] ?? '' }}" class="form-control" placeholder="Min">
                                <input type="number" name="debt_max" value="{{ $filters['debt_max'] ?? '' }}" class="form-control" placeholder="Max">
                            </div>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label">Credit (FICO)</label>
                            <div class="d-flex gap-2">
                                <input type="number" name="fico_min" value="{{ $filters['fico_min'] ?? '' }}" class="form-control" placeholder="Min">
                                <input type="number" name="fico_max" value="{{ $filters['fico_max'] ?? '' }}" class="form-control" placeholder="Max">
                            </div>
                        </div>
                        <div class="col-12 col-md-6">
                            <div class="d-flex gap-2">
                                <div class="flex-fill">
                                    <label class="form-label">Sort</label>
                                    <select name="sort" class="form-select">
                                        @php $sorts = ['send_date' => 'Send Date','drop_name' => 'Drop Name','tier' => 'Tier','vendor' => 'Vendor','drop_cost' => 'Drop Cost','total_leads' => 'Total Leads','total_enrollments' => 'Total Enrollments','net_enrollments' => 'Net Enrollments','capital_partner' => 'Capital Partner','roi' => 'ROI']; @endphp
                                        @foreach($sorts as $k => $lbl)
                                            <option value="{{ $k }}" @if(($filters['sort'] ?? 'send_date') === $k) selected @endif>{{ $lbl }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div style="width:140px">
                                    <label class="form-label">Direction</label>
                                    <select name="dir" class="form-select">
                                        <option value="asc" @if(($filters['dir'] ?? 'asc') === 'asc') selected @endif>Ascending</option>
                                        <option value="desc" @if(($filters['dir'] ?? 'asc') === 'desc') selected @endif>Descending</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row g-3 mt-1">
                    <div class="col-12 d-flex justify-content-end gap-2">
                        <button type="submit" class="btn btn-primary">Apply Filters</button>
                        <a href="{{ url('/cmd/reports/marketing-admin/export') . '?' . http_build_query(request()->except(['_token'])) }}" class="btn btn-outline-success">Export CSV</a>
                    </div>
                </div>
            </form>
        </div>

        {{-- Intent audit cards temporarily disabled for performance (9+ second queries to remote SQL Server) --}}
        {{-- @isset($intentAudit)
        <div class="card-body border-bottom">
            <div class="row g-3">
                <div class="col-6 col-md-4">
                    <div class="border rounded p-3 text-center">
                        <div class="fs-4 fw-bold">{{ $intentAudit['total'] ?? 0 }}</div>
                        <div class="text-muted small text-uppercase">Total Drops</div>
                    </div>
                </div>
                <div class="col-6 col-md-4">
                    <div class="border rounded p-3 text-center">
                        <div class="fs-4 fw-bold text-success">{{ $intentAudit['with'] ?? 0 }}</div>
                        <div class="text-muted small text-uppercase">With Intent</div>
                    </div>
                </div>
                <div class="col-6 col-md-4">
                    <div class="border rounded p-3 text-center">
                        <div class="fs-4 fw-bold text-warning">{{ $intentAudit['without'] ?? 0 }}</div>
                        <div class="text-muted small text-uppercase">Without Intent</div>
                    </div>
                </div>
            </div>
        </div>
        @endisset --}}

        @php
            $chartOverview = null;
            if (!empty($chartJson) && $chartJson !== 'null') {
                $decodedChart = json_decode($chartJson, true);
                $chartOverview = is_array($decodedChart) ? ($decodedChart['overview'] ?? null) : null;
            }
        @endphp

        <div class="card-body">
            @isset($error)
                <div class="alert alert-danger">{{ $error }}</div>
            @endisset

            @if (!($submitted ?? false))
                <div class="text-muted py-4 text-center">Set your filters above and click <strong>Apply Filters</strong> to load data.</div>
            @elseif (empty($columns))
                <div class="text-muted">No records found.</div>
            @else
                @if(!empty($chartJson) && $chartJson !== 'null')
                @php $ov = json_decode($chartJson, true)['overview'] ?? []; @endphp
                <div class="row g-3 mb-3">
                    <div class="col-6 col-xl-2">
                        <div class="border rounded p-3 h-100">
                            <div class="text-muted small text-uppercase">Total ROI</div>
                            <div class="fs-5 fw-semibold {{ ((float)($ov['totalRoi'] ?? 0)) < 0 ? 'text-danger' : 'text-success' }}">${{ number_format((float)($ov['totalRoi'] ?? 0), 0) }}</div>
                        </div>
                    </div>
                    <div class="col-6 col-xl-2">
                        <div class="border rounded p-3 h-100">
                            <div class="text-muted small text-uppercase">Total Drop Cost</div>
                            <div class="fs-5 fw-semibold">${{ number_format((float)($ov['totalDropCost'] ?? 0), 0) }}</div>
                        </div>
                    </div>
                    <div class="col-6 col-xl-2">
                        <div class="border rounded p-3 h-100">
                            <div class="text-muted small text-uppercase">Capital Partner</div>
                            <div class="fs-5 fw-semibold">${{ number_format((float)($ov['totalCapitalPartner'] ?? 0), 0) }}</div>
                        </div>
                    </div>
                    <div class="col-6 col-xl-2">
                        <div class="border rounded p-3 h-100">
                            <div class="text-muted small text-uppercase">Veritas Fees</div>
                            <div class="fs-5 fw-semibold">${{ number_format((float)($ov['totalVeritas'] ?? 0), 0) }}</div>
                        </div>
                    </div>
                    <div class="col-6 col-xl-2">
                        <div class="border rounded p-3 h-100">
                            <div class="text-muted small text-uppercase">Net Enrollments</div>
                            <div class="fs-5 fw-semibold">{{ number_format((int)($ov['totalNetEnrollments'] ?? 0)) }}</div>
                        </div>
                    </div>
                    <div class="col-6 col-xl-2">
                        <div class="border rounded p-3 h-100">
                            <div class="text-muted small text-uppercase">Total Leads</div>
                            <div class="fs-5 fw-semibold">{{ number_format((int)($ov['totalLeads'] ?? 0)) }}</div>
                        </div>
                    </div>
                </div>
                @endif

                <div class="d-flex align-items-center justify-content-between mb-2 flex-wrap gap-2">
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <h6 class="mb-0 fw-semibold">Marketing Drop Data</h6>
                        <span class="text-muted small">Total: {{ $total }}</span>
                    </div>
                    <button class="btn btn-outline-secondary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#marketing-report-table" aria-expanded="true" aria-controls="marketing-report-table">Toggle Table</button>
                </div>

                <div class="collapse show" id="marketing-report-table">
                    <div class="table-responsive">
                        <table class="table table-striped table-hover align-middle">
                            <thead>
                            <tr>
                                @foreach ($columns as $col)
                                    <th class="text-nowrap">{{ $col }}</th>
                                @endforeach
                            </tr>
                            </thead>
                            <tbody>
                            @forelse ($rows as $r)
                                <tr>
                                    @foreach ($columns as $col)
                                        @php
                                            $val = $r->{$col} ?? null;
                                            $out = $val;
                                            $currency2 = ['Drop Cost','CPA','Cost Per Call','Capital Partner','Lookback','Commission','Veritas Enrollment','Veritas Monthly','ROI','Average Debt'];
                                            $currency4 = ['Price Per Drop','Per Piece ROI'];
                                            if ($val !== null) {
                                                if (in_array($col, $currency2, true)) {
                                                    $out = '$' . number_format((float) $val, 2);
                                                } elseif (in_array($col, $currency4, true)) {
                                                    $out = '$' . number_format((float) $val, 4);
                                                } elseif ($col === 'Enrolled Debt') {
                                                    $out = '$' . number_format((float) $val, 0);
                                                } elseif ($col === 'Amount Dropped') {
                                                    $out = number_format((float) $val, 0);
                                                } elseif (in_array($col, ['Amount Per Rep','Calls Per Rep'], true)) {
                                                    $out = number_format((float) $val, 2);
                                                } elseif ($col === 'Response Rate') {
                                                    $out = number_format((float) $val * 100, 4) . '%';
                                                } elseif ($col === 'Tier') {
                                                    $out = preg_replace('/\..*$/', '', (string) $val);
                                                }
                                            }
                                            $tdClass = ($col === 'Mail Style') ? 'text-nowrap' : '';
                                        @endphp
                                        <td class="{{ $tdClass }}">{{ $out }}</td>
                                    @endforeach
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="{{ count($columns) }}" class="text-center text-muted">No records</td>
                                </tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>

                    @php
                        $lastPage = max(1, (int) ceil(($total ?? 0) / ($perPage ?? 15)));
                        $baseQuery = request()->except('page');
                        $baseUrl = url()->current();
                        $current = $page ?? 1;
                        $window = 2;
                        $start = max(1, $current - $window);
                        $end = min($lastPage, $current + $window);
                        if ($end - $start < $window * 2) { $start = max(1, $end - $window * 2); }
                    @endphp
                    <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
                        <div class="text-muted small">Showing page {{ $current }} of {{ $lastPage }} &mdash; {{ $total }} total records</div>
                        <nav>
                            <ul class="pagination pagination-sm mb-0">
                                <li class="page-item @if($current <= 1) disabled @endif">
                                    <a class="page-link" href="{{ $baseUrl . '?' . http_build_query(array_merge($baseQuery, ['page' => 1, 'per_page' => $perPage])) }}">First</a>
                                </li>
                                <li class="page-item @if($current <= 1) disabled @endif">
                                    <a class="page-link" href="{{ $baseUrl . '?' . http_build_query(array_merge($baseQuery, ['page' => max(1, $current - 1), 'per_page' => $perPage])) }}">Prev</a>
                                </li>
                                @for ($p = $start; $p <= $end; $p++)
                                    <li class="page-item @if($p == $current) active @endif">
                                        <a class="page-link" href="{{ $baseUrl . '?' . http_build_query(array_merge($baseQuery, ['page' => $p, 'per_page' => $perPage])) }}">{{ $p }}</a>
                                    </li>
                                @endfor
                                <li class="page-item @if($current >= $lastPage) disabled @endif">
                                    <a class="page-link" href="{{ $baseUrl . '?' . http_build_query(array_merge($baseQuery, ['page' => min($lastPage, $current + 1), 'per_page' => $perPage])) }}">Next</a>
                                </li>
                                <li class="page-item @if($current >= $lastPage) disabled @endif">
                                    <a class="page-link" href="{{ $baseUrl . '?' . http_build_query(array_merge($baseQuery, ['page' => $lastPage, 'per_page' => $perPage])) }}">Last</a>
                                </li>
                            </ul>
                        </nav>
                    </div>
                </div>

            @endif

            @if(!empty($chartJson) && $chartJson !== 'null')
            <div class="mt-4 border-top pt-3" id="marketing-charts-wrapper">
                <h6 class="fw-semibold mb-3">ROI Trend by Month</h6>
                <div style="position:relative;height:340px;"><canvas id="cvRoi"></canvas></div>
                <div id="chart-error" class="alert alert-warning mt-2" style="display:none;">
                    Chart could not load. Check browser console for details.
                </div>
            </div>
            @endif

        </div>
    </div>
</div>

@if(!empty($chartJson) && $chartJson !== 'null')
@push('scripts_bottom')
<script type="application/json" id="marketing-admin-chart-data">{!! $chartJson !!}</script>
<script>
(function () {
    var chartDataElement = document.getElementById('marketing-admin-chart-data');
    var chartData = chartDataElement ? JSON.parse(chartDataElement.textContent || '{}') : {};
    var roiChart = null;

    function showError() {
        var el = document.getElementById('chart-error');
        if (el) el.style.display = '';
    }

    function buildRoiChart() {
        var trend = chartData.roiTrend || {};
        var labels         = trend.labels || [];
        var roi            = trend.roi || [];
        var dropCost       = trend.dropCost || [];
        var capitalPartner = trend.capitalPartner || [];
        var veritas        = trend.veritas || [];

        var canvas = document.getElementById('cvRoi');
        if (!canvas) return;
        if (roiChart) { roiChart.destroy(); }

        roiChart = new Chart(canvas.getContext('2d'), {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'ROI ($)',
                        data: roi,
                        borderColor: '#1cc88a',
                        backgroundColor: 'rgba(28,200,138,0.08)',
                        borderWidth: 2,
                        tension: 0.3,
                        fill: true,
                        pointRadius: 4
                    },
                    {
                        label: 'Drop Cost ($)',
                        data: dropCost.map(function(v){ return -v; }),
                        borderColor: '#e74a3b',
                        backgroundColor: 'transparent',
                        borderWidth: 2,
                        borderDash: [5, 4],
                        tension: 0.3,
                        fill: false,
                        pointRadius: 3
                    },
                    {
                        label: 'Capital Partner ($)',
                        data: capitalPartner,
                        borderColor: '#4e73df',
                        backgroundColor: 'transparent',
                        borderWidth: 2,
                        borderDash: [3, 3],
                        tension: 0.3,
                        fill: false,
                        pointRadius: 3
                    },
                    {
                        label: 'Veritas Fees ($)',
                        data: veritas,
                        borderColor: '#f6c23e',
                        backgroundColor: 'transparent',
                        borderWidth: 2,
                        borderDash: [3, 3],
                        tension: 0.3,
                        fill: false,
                        pointRadius: 3
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { position: 'top' },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) {
                                var v = ctx.parsed.y;
                                return ctx.dataset.label + ': $' + Math.abs(v).toLocaleString(undefined, {minimumFractionDigits: 0, maximumFractionDigits: 0});
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        ticks: {
                            callback: function(v) {
                                return '$' + (v < 0 ? '-' : '') + Math.abs(v).toLocaleString(undefined, {maximumFractionDigits: 0});
                            }
                        },
                        grid: { color: 'rgba(0,0,0,0.05)' }
                    },
                    x: { grid: { display: false } }
                }
            }
        });
    }

    function init() {
        if (typeof Chart === 'undefined') { showError(); return; }
        try { buildRoiChart(); } catch (e) { showError(); }
    }

    var script = document.createElement('script');
    script.src = 'https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js';
    script.onload = init;
    script.onerror = showError;
    document.head.appendChild(script);
})();
</script>
@endpush
@endif

@endsection
