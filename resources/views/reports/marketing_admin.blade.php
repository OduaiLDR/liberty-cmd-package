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
                            <label class="form-label">Intent</label>
                            <select name="intent" class="form-select">
                                <option value="all" @if(($filters['intent'] ?? 'all') === 'all') selected @endif>All</option>
                                <option value="yes" @if(($filters['intent'] ?? 'all') === 'yes') selected @endif>Yes (Has Intent)</option>
                                <option value="no" @if(($filters['intent'] ?? 'all') === 'no') selected @endif>No (No Intent)</option>
                            </select>
                        </div>
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
                                        @php $sorts = ['send_date' => 'Send Date','drop_name' => 'Drop Name','tier' => 'Tier','vendor' => 'Vendor','drop_cost' => 'Drop Cost','calls' => 'Calls','total_leads' => 'Total Leads','total_enrollments' => 'Total Enrollments']; @endphp
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

        @isset($intentAudit)
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
        @endisset

        <div class="card-body">
            @isset($error)
                <div class="alert alert-danger">{{ $error }}</div>
            @endisset

            @if (!($submitted ?? false))
                <div class="text-muted py-4 text-center">Set your filters above and click <strong>Apply Filters</strong> to load data.</div>
            @elseif (empty($columns))
                <div class="text-muted">No records found.</div>
            @else
                <div class="d-flex align-items-center justify-content-between mb-2 flex-wrap gap-2">
                    <h6 class="mb-0 fw-semibold">Marketing Drop Data</h6>
                    <span class="text-muted small">Total: {{ $total }}</span>
                </div>

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
                                        $currencyCols2 = ['Drop Cost','CPA','Price Per Drop','Cost Per Call'];
                                        if ($val !== null) {
                                            if (in_array($col, $currencyCols2, true)) {
                                                $out = '$' . number_format((float)$val, 2);
                                            } elseif (in_array($col, ['Amount Dropped', 'Amount Per Rep'], true)) {
                                                $out = number_format((float)$val, 0);
                                            } elseif (in_array($col, ['Enrolled Debt','Average Debt'], true)) {
                                                $out = '$' . number_format((float)$val, 0);
                                            } elseif ($col === 'Response Rate') {
                                                $out = number_format(((float)$val) * 100, 2) . '%';
                                            } elseif ($col === 'Tier') {
                                                $out = preg_replace('/\..*$/', '', (string)$val);
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

            @endif

            @if(!empty($chartJson) && $chartJson !== 'null')
            <div class="mt-4 border-top pt-3" id="marketing-charts-wrapper">
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <h6 class="mb-0 fw-semibold">Charts</h6>
                    <div class="btn-group btn-group-sm" role="group">
                        <button type="button" class="btn btn-outline-primary active" onclick="showChart('funnel', this)">Lead Funnel</button>
                        <button type="button" class="btn btn-outline-primary" onclick="showChart('tier', this)">Tier Performance</button>
                        <button type="button" class="btn btn-outline-primary" onclick="showChart('drops', this)">Top Drops</button>
                        <button type="button" class="btn btn-outline-primary" onclick="showChart('trend', this)">Trend Over Time</button>
                    </div>
                </div>

                <!-- Global Chart Filters -->
                <div class="card bg-light mb-3">
                    <div class="card-body py-2">
                        <div class="row g-2 align-items-end">
                            <div class="col-auto">
                                <label class="form-label mb-1 small">Chart Date Range</label>
                                <div class="d-flex gap-2">
                                    <input type="date" id="chartDateFrom" class="form-control form-control-sm" placeholder="From">
                                    <input type="date" id="chartDateTo" class="form-control form-control-sm" placeholder="To">
                                </div>
                            </div>
                            <div class="col-auto">
                                <label class="form-label mb-1 small">Chart Tier</label>
                                <select id="chartTier" class="form-select form-select-sm">
                                    <option value="">All Tiers</option>
                                    @foreach (['T1','T2','T3','T4','T5','T6','T7','T8','T9'] as $t)
                                        <option value="{{ $t }}">{{ $t }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-auto">
                                <label class="form-label mb-1 small">Chart Vendor</label>
                                <select id="chartVendor" class="form-select form-select-sm">
                                    <option value="">All Vendors</option>
                                    @foreach(($allVendors ?? []) as $v)
                                        <option value="{{ $v }}">{{ $v }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-auto">
                                <button type="button" class="btn btn-primary btn-sm" onclick="applyChartFilters()">Apply</button>
                                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="resetChartFilters()">Reset</button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Chart-Specific Filters -->
                <div id="chartSpecificFilters" class="mb-3"></div>

                <div id="chart-funnel" style="position:relative;height:340px;">
                    <canvas id="cvFunnel"></canvas>
                </div>
                <div id="chart-tier" style="position:relative;height:340px;display:none;">
                    <canvas id="cvTier"></canvas>
                </div>
                <div id="chart-drops" style="position:relative;height:340px;display:none;">
                    <canvas id="cvDrops"></canvas>
                </div>
                <div id="chart-trend" style="position:relative;height:340px;display:none;">
                    <canvas id="cvTrend"></canvas>
                </div>
                <div id="chart-error" class="alert alert-warning mt-2" style="display:none;">
                    Charts could not load. Check browser console for details.
                </div>
            </div>
            @endif

        </div>
    </div>
</div>

@if(!empty($chartJson) && $chartJson !== 'null')
@push('scripts_bottom')
<script>
(function () {
    var chartData = {!! $chartJson !!};
    var charts = {};
    var built = {};

    function showError() {
        var el = document.getElementById('chart-error');
        if (el) el.style.display = '';
    }

    function makeChart(id, cfg) {
        if (charts[id]) { charts[id].destroy(); }
        var canvas = document.getElementById(id);
        if (!canvas) return;
        charts[id] = new Chart(canvas.getContext('2d'), cfg);
    }

    function buildFunnel() {
        makeChart('cvFunnel', {
            type: 'bar',
            data: {
                labels: chartData.funnel.labels,
                datasets: [{ label: 'Count', data: chartData.funnel.values,
                    backgroundColor: ['#4e73df','#36b9cc','#1cc88a','#f6c23e'] }]
            },
            options: { responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false }, title: { display: true, text: 'Lead Funnel' } },
                scales: { y: { beginAtZero: true } } }
        });
    }

    function buildTier() {
        makeChart('cvTier', {
            type: 'bar',
            data: {
                labels: chartData.tier.labels,
                datasets: [
                    { label: 'Total Leads', data: chartData.tier.leads, backgroundColor: '#4e73df' },
                    { label: 'Enrolled',    data: chartData.tier.enrolled, backgroundColor: '#1cc88a' }
                ]
            },
            options: { responsive: true, maintainAspectRatio: false,
                plugins: { title: { display: true, text: 'Leads & Enrollments by Tier' } },
                scales: { y: { beginAtZero: true } } }
        });
    }

    function buildDrops() {
        makeChart('cvDrops', {
            type: 'bar',
            data: {
                labels: chartData.drops.labels,
                datasets: [{ label: 'Enrollments', data: chartData.drops.values, backgroundColor: '#e74a3b' }]
            },
            options: { responsive: true, maintainAspectRatio: false,
                indexAxis: 'y',
                plugins: { legend: { display: false }, title: { display: true, text: 'Top 10 Drops by Enrollments' } },
                scales: { x: { beginAtZero: true } } }
        });
    }

    function buildTrend() {
        if (!chartData.trend || !chartData.trend.labels) {
            chartData.trend = { labels: [], enrollments: [], leads: [], cost: [] };
        }
        makeChart('cvTrend', {
            type: 'line',
            data: {
                labels: chartData.trend.labels,
                datasets: [
                    { label: 'Total Leads', data: chartData.trend.leads, borderColor: '#4e73df', backgroundColor: 'rgba(78,115,223,0.1)', tension: 0.3 },
                    { label: 'Enrollments', data: chartData.trend.enrollments, borderColor: '#1cc88a', backgroundColor: 'rgba(28,200,138,0.1)', tension: 0.3 },
                    { label: 'Drop Cost ($)', data: chartData.trend.cost, borderColor: '#e74a3b', backgroundColor: 'rgba(231,74,59,0.1)', tension: 0.3, yAxisID: 'y1' }
                ]
            },
            options: { 
                responsive: true, 
                maintainAspectRatio: false,
                plugins: { title: { display: true, text: 'Marketing Trends Over Time' } },
                scales: { 
                    y: { beginAtZero: true, position: 'left', title: { display: true, text: 'Count' } },
                    y1: { beginAtZero: true, position: 'right', title: { display: true, text: 'Cost ($)' }, grid: { drawOnChartArea: false } }
                } 
            }
        });
    }

    window.showChart = function (name, btn) {
        ['funnel','tier','drops','trend'].forEach(function (n) {
            document.getElementById('chart-' + n).style.display = n === name ? '' : 'none';
        });
        document.querySelectorAll('#marketing-charts-wrapper .btn-group .btn').forEach(function (b) { b.classList.remove('active'); });
        btn.classList.add('active');
        
        var specificFilters = document.getElementById('chartSpecificFilters');
        specificFilters.innerHTML = '';
        
        if (name === 'drops') {
            specificFilters.innerHTML = '<div class="card bg-light"><div class="card-body py-2"><label class="form-label mb-1 small">Top N Drops</label><input type="number" id="topDropsLimit" class="form-control form-control-sm" value="10" min="5" max="50" onchange="applyChartFilters()"></div></div>';
        } else if (name === 'trend') {
            specificFilters.innerHTML = '<div class="card bg-light"><div class="card-body py-2"><label class="form-label mb-1 small">Group By</label><select id="trendGroupBy" class="form-select form-select-sm" onchange="applyChartFilters()"><option value="day">Daily</option><option value="week" selected>Weekly</option><option value="month">Monthly</option></select></div></div>';
        }
        
        if (!built[name]) {
            built[name] = true;
            try {
                if (name === 'funnel')  buildFunnel();
                if (name === 'tier')    buildTier();
                if (name === 'drops')   buildDrops();
                if (name === 'trend')   buildTrend();
            } catch (e) { showError(); }
        }
    };

    window.applyChartFilters = function() {
        var params = new URLSearchParams(window.location.search);
        
        var chartDateFrom = document.getElementById('chartDateFrom').value;
        var chartDateTo = document.getElementById('chartDateTo').value;
        var chartTier = document.getElementById('chartTier').value;
        var chartVendor = document.getElementById('chartVendor').value;
        
        if (chartDateFrom) {
            params.set('send_start', chartDateFrom);
        }
        if (chartDateTo) {
            params.set('send_end', chartDateTo);
        }
        if (chartTier) {
            params.set('tier', chartTier);
        }
        if (chartVendor) {
            params.set('vendor', chartVendor);
        }
        
        console.log('Fetching chart data with params:', params.toString());
        
        fetch('/cmd/reports/marketing-admin/chart-data?' + params.toString())
            .then(function(response) {
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }
                return response.json();
            })
            .then(function(newData) {
                console.log('Received chart data:', newData);
                chartData = newData;
                built = {};
                
                var activeBtn = document.querySelector('#marketing-charts-wrapper .btn-group .btn.active');
                if (activeBtn) {
                    var chartName = activeBtn.textContent.toLowerCase().replace(/\s+/g, '');
                    var name = chartName.includes('funnel') ? 'funnel' : 
                               chartName.includes('tier') ? 'tier' : 
                               chartName.includes('top') ? 'drops' : 'trend';
                    showChart(name, activeBtn);
                }
            })
            .catch(function(err) {
                console.error('Error fetching chart data:', err);
                showError();
            });
    };

    window.resetChartFilters = function() {
        document.getElementById('chartDateFrom').value = '';
        document.getElementById('chartDateTo').value = '';
        document.getElementById('chartTier').value = '';
        document.getElementById('chartVendor').value = '';
        
        var params = new URLSearchParams(window.location.search);
        params.delete('send_start');
        params.delete('send_end');
        params.delete('tier');
        params.delete('vendor');
        
        console.log('Resetting chart filters, params:', params.toString());
        
        fetch('/cmd/reports/marketing-admin/chart-data?' + params.toString())
            .then(function(response) {
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }
                return response.json();
            })
            .then(function(newData) {
                console.log('Received reset chart data:', newData);
                chartData = newData;
                built = {};
                
                var activeBtn = document.querySelector('#marketing-charts-wrapper .btn-group .btn.active');
                if (activeBtn) {
                    var chartName = activeBtn.textContent.toLowerCase().replace(/\s+/g, '');
                    var name = chartName.includes('funnel') ? 'funnel' : 
                               chartName.includes('tier') ? 'tier' : 
                               chartName.includes('top') ? 'drops' : 'trend';
                    showChart(name, activeBtn);
                }
            })
            .catch(function(err) {
                console.error('Error resetting chart data:', err);
                showError();
            });
    };

    function initCharts() {
        if (typeof Chart === 'undefined') {
            showError();
            return;
        }
        try {
            buildFunnel();
            built['funnel'] = true;
        } catch (e) { showError(); }
    }

    var script = document.createElement('script');
    script.src = 'https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js';
    script.onload = initCharts;
    script.onerror = showError;
    document.head.appendChild(script);
})();
</script>
@endpush
@endif

@endsection
