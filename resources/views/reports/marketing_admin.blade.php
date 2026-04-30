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
                                        @php $sorts = ['send_date' => 'Send Date','drop_name' => 'Drop Name','tier' => 'Tier','vendor' => 'Vendor','drop_cost' => 'Drop Cost','calls' => 'Calls','total_leads' => 'Total Leads','total_enrollments' => 'Total Enrollments','net_enrollments' => 'Net Enrollments','est_profit' => 'Est Profit','conversion_rate' => 'Conversion Rate']; @endphp
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
                @if(is_array($chartOverview))
                <div class="row g-3 mb-3">
                    <div class="col-6 col-xl-2">
                        <div class="border rounded p-3 h-100">
                            <div class="text-muted small text-uppercase">Conversion Rate</div>
                            <div class="fs-5 fw-semibold">{{ number_format((float) ($chartOverview['conversionRate'] ?? 0), 2) }}%</div>
                        </div>
                    </div>
                    <div class="col-6 col-xl-2">
                        <div class="border rounded p-3 h-100">
                            <div class="text-muted small text-uppercase">Retention Rate</div>
                            <div class="fs-5 fw-semibold">{{ number_format((float) ($chartOverview['retentionRate'] ?? 0), 2) }}%</div>
                        </div>
                    </div>
                    <div class="col-6 col-xl-2">
                        <div class="border rounded p-3 h-100">
                            <div class="text-muted small text-uppercase">ROI Ratio</div>
                            <div class="fs-5 fw-semibold">{{ number_format((float) ($chartOverview['roiRatio'] ?? 0), 2) }}x</div>
                        </div>
                    </div>
                    <div class="col-6 col-xl-2">
                        <div class="border rounded p-3 h-100">
                            <div class="text-muted small text-uppercase">Estimated Revenue</div>
                            <div class="fs-5 fw-semibold">${{ number_format((float) ($chartOverview['estRevenue'] ?? 0), 0) }}</div>
                        </div>
                    </div>
                    <div class="col-6 col-xl-2">
                        <div class="border rounded p-3 h-100">
                            <div class="text-muted small text-uppercase">Estimated Profit</div>
                            <div class="fs-5 fw-semibold {{ ((float) ($chartOverview['estProfit'] ?? 0)) < 0 ? 'text-danger' : 'text-success' }}">${{ number_format((float) ($chartOverview['estProfit'] ?? 0), 0) }}</div>
                        </div>
                    </div>
                    <div class="col-6 col-xl-2">
                        <div class="border rounded p-3 h-100">
                            <div class="text-muted small text-uppercase">Revenue Per Lead</div>
                            <div class="fs-5 fw-semibold">${{ number_format((float) ($chartOverview['revenuePerLead'] ?? 0), 2) }}</div>
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
                                            $currencyCols2 = ['Drop Cost','CPA','Price Per Drop','Cost Per Call','Est Revenue','Est Profit','Cost Per Lead','Revenue Per Lead'];
                                            if ($val !== null) {
                                                if (in_array($col, $currencyCols2, true)) {
                                                    $out = '$' . number_format((float) $val, 2);
                                                } elseif (in_array($col, ['Amount Dropped', 'Amount Per Rep'], true)) {
                                                    $out = number_format((float) $val, 0);
                                                } elseif (in_array($col, ['Enrolled Debt','Average Debt'], true)) {
                                                    $out = '$' . number_format((float) $val, 0);
                                                } elseif ($col === 'Lead Rate') {
                                                    $out = number_format(((float) $val) * 100, 4) . '%';
                                                } elseif (in_array($col, ['Conversion Rate %', 'Retention Rate %'], true)) {
                                                    $out = number_format((float) $val, 2) . '%';
                                                } elseif ($col === 'ROI Ratio') {
                                                    $out = number_format((float) $val, 2) . 'x';
                                                } elseif (in_array($col, ['Veritas Enrollment', 'Veritas Monthly'], true)) {
                                                    $out = '$' . number_format((float) $val, 2);
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
                <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
                    <div class="d-flex align-items-center gap-2">
                        <h6 class="mb-0 fw-semibold">Charts</h6>
                        <span class="badge bg-light text-dark border" data-bs-toggle="tooltip" title="ROI = 25% of enrolled debt. Qualified = debt ≥ $7,500. Retention = net/total enrollments.">ℹ️ Metrics Info</span>
                    </div>
                    <div class="btn-group btn-group-sm" role="group">
                        <button type="button" class="btn btn-outline-primary active" onclick="showChart('trend', this)">Trend</button>
                        <button type="button" class="btn btn-outline-primary" onclick="showChart('tier', this)">Veritas ROI</button>
                        <button type="button" class="btn btn-outline-primary" onclick="showChart('drops', this)">Top Drops</button>
                        <button type="button" class="btn btn-outline-primary" onclick="showChart('funnel', this)">Funnel</button>
                    </div>
                </div>

                {{-- TREND CHART with dedicated filters --}}
                <div id="chart-trend" style="display:block;">
                    <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
                        <span class="text-muted small">Trend View:</span>
                        <select id="trend-period" class="form-select form-select-sm" style="width:auto;" onchange="rebuildTrend()">
                            <option value="weekly" selected>Weekly</option>
                            <option value="monthly">Monthly</option>
                        </select>
                        <select id="trend-metric" class="form-select form-select-sm" style="width:auto;" onchange="rebuildTrend()">
                            <option value="leads" selected>Leads</option>
                            <option value="enrollments">Enrollments</option>
                            <option value="profit">Est. Profit ($)</option>
                            <option value="conversionRate">Conversion Rate (%)</option>
                            <option value="roiRatio">ROI Ratio</option>
                        </select>
                    </div>
                    <div style="position:relative;height:320px;"><canvas id="cvTrend"></canvas></div>
                </div>

                {{-- VERITAS ROI CHART with dedicated filters --}}
                <div id="chart-tier" style="display:none;">
                    <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
                        <span class="text-muted small">Veritas View:</span>
                        <select id="veritas-metric" class="form-select form-select-sm" style="width:auto;" onchange="rebuildTier()">
                            <option value="totalFee" selected>Total Fees ($)</option>
                            <option value="roiRatio">ROI Ratio</option>
                            <option value="enrollmentFee">Enrollment Fee ($)</option>
                            <option value="monthlyFee">Monthly Fee ($)</option>
                        </select>
                    </div>
                    <div style="position:relative;height:320px;"><canvas id="cvTier"></canvas></div>
                </div>

                {{-- TOP DROPS CHART with dedicated filters --}}
                <div id="chart-drops" style="display:none;">
                    <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
                        <span class="text-muted small">Rank By:</span>
                        <select id="drops-metric" class="form-select form-select-sm" style="width:auto;" onchange="rebuildDrops()">
                            <option value="profit" selected>Est. Profit ($)</option>
                            <option value="revenue">Est. Revenue ($)</option>
                            <option value="leads">Total Leads</option>
                            <option value="enrollments">Total Enrollments</option>
                            <option value="roiRatio">ROI Ratio</option>
                            <option value="conversionRate">Conversion Rate (%)</option>
                            <option value="veritasTotalFee">Veritas Fees ($)</option>
                        </select>
                        <select id="drops-count" class="form-select form-select-sm" style="width:auto;" onchange="rebuildDrops()">
                            <option value="5">Top 5</option>
                            <option value="10" selected>Top 10</option>
                            <option value="15">Top 15</option>
                            <option value="20">Top 20</option>
                        </select>
                    </div>
                    <div style="position:relative;height:320px;"><canvas id="cvDrops"></canvas></div>
                </div>

                {{-- FUNNEL CHART with dedicated filters --}}
                <div id="chart-funnel" style="display:none;">
                    <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
                        <span class="text-muted small">Funnel View:</span>
                        <select id="funnel-mode" class="form-select form-select-sm" style="width:auto;" onchange="rebuildFunnel()">
                            <option value="percent" selected>As % of Total Leads</option>
                            <option value="absolute">Absolute Counts</option>
                        </select>
                    </div>
                    <div style="position:relative;height:320px;"><canvas id="cvFunnel"></canvas></div>
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
<script type="application/json" id="marketing-admin-chart-data">{!! $chartJson !!}</script>
<script>
(function () {
    var chartDataElement = document.getElementById('marketing-admin-chart-data');
    var chartData = chartDataElement ? JSON.parse(chartDataElement.textContent || '{}') : {};
    var charts = {};

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

    function fmt(v, type) {
        if (type === '$') return '$' + Number(v).toLocaleString(undefined, {maximumFractionDigits: 0});
        if (type === '%') return Number(v).toFixed(2) + '%';
        if (type === 'x') return Number(v).toFixed(2) + 'x';
        return Number(v).toLocaleString();
    }

    // ========== FUNNEL ==========
    window.rebuildFunnel = function () {
        var mode = document.getElementById('funnel-mode').value;
        var labels = (chartData.funnel && chartData.funnel.labels) ? chartData.funnel.labels : [];
        var values = (chartData.funnel && chartData.funnel.values) ? chartData.funnel.values : [];
        var base = values.length > 0 ? values[0] : 0;
        var data = mode === 'percent' ? values.map(function(v){ return base > 0 ? (v/base)*100 : 0; }) : values;
        var yLabel = mode === 'percent' ? 'Share of Total Leads (%)' : 'Count';
        var tickCb = mode === 'percent' ? function(v){ return v + '%'; } : function(v){ return v; };

        var funnelDescriptions = {
            'Total Leads': 'All contacts who responded to marketing drops (initial funnel entry)',
            'Qualified Leads': 'Contacts with debt ≥ $7,500 - meets minimum threshold for debt settlement',
            'Unqualified Leads': 'Contacts below $7,500 debt - not viable for standard debt settlement programs',
            'Total Enrollments': 'Leads who signed up for the debt settlement program',
            'Net Enrollments': 'Active clients after removing cancellations and NSFs (payment failures)'
        };

        makeChart('cvFunnel', {
            type: 'bar',
            data: { labels: labels, datasets: [{ label: mode === 'percent' ? '% of Total Leads' : 'Count', data: data, backgroundColor: ['#4e73df','#36b9cc','#1cc88a','#f6c23e','#858796'] }] },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false }, title: { display: true, text: 'Lead Funnel Stages - Track conversion from initial contact to active client' },
                    tooltip: { 
                        callbacks: { 
                            title: function(ctx) { return ctx[0].label + ' Stage'; },
                            label: function(ctx) { 
                                var lines = [];
                                if (mode === 'percent') {
                                    lines.push('Share: ' + ctx.parsed.y.toFixed(1) + '% of total leads');
                                    lines.push('Count: ' + values[ctx.dataIndex].toLocaleString() + ' contacts');
                                } else {
                                    lines.push('Count: ' + ctx.parsed.y.toLocaleString() + ' contacts');
                                    var pct = base > 0 ? ((ctx.parsed.y / base) * 100).toFixed(1) : 0;
                                    lines.push('Share: ' + pct + '% of total leads');
                                }
                                return lines;
                            },
                            afterLabel: function(ctx) {
                                var desc = funnelDescriptions[ctx.label] || '';
                                return desc ? ['', '📋 ' + desc] : [];
                            }
                        }
                    }
                },
                scales: { y: { beginAtZero: true, ticks: { callback: tickCb }, title: { display: true, text: yLabel } } }
            }
        });
    };

    // ========== VERITAS / TIER ==========
    window.rebuildTier = function () {
        var metric = document.getElementById('veritas-metric').value;
        // Use tier data (grouped by debt tier) instead of veritas (per-drop)
        var tierData = chartData.tier || {};
        var labels = tierData.labels || [];
        var metricMap = { 
            totalFee: tierData.veritasTotalFee || [], 
            roiRatio: tierData.veritasRoiRatio || [], 
            enrollmentFee: tierData.veritasEnrollmentFee || [], 
            monthlyFee: tierData.veritasMonthlyFee || [] 
        };
        var data = metricMap[metric] || [];
        var titleMap = { totalFee: 'Total Veritas Fees ($)', roiRatio: 'Veritas ROI Ratio (x)', enrollmentFee: 'Enrollment Fees ($)', monthlyFee: 'Monthly Fees ($)' };
        var isMoney = metric !== 'roiRatio';

        var tierDescriptions = {
            'T1': 'Tier 1: Highest debt level ($75K+) - Premium clients with largest settlement potential',
            'T2': 'Tier 2: High debt ($50K-75K) - Strong revenue potential per client',
            'T3': 'Tier 3: Upper-mid debt ($35K-50K) - Solid program candidates',
            'T4': 'Tier 4: Mid debt ($25K-35K) - Standard settlement range',
            'T5': 'Tier 5: Lower-mid debt ($15K-25K) - Moderate settlement potential',
            'T6': 'Tier 6: Entry-level debt ($10K-15K) - Minimum viable settlement range',
            'T7': 'Tier 7: Low debt ($7.5K-10K) - Near qualification threshold',
            'T8': 'Tier 8: Sub-threshold debt - Below standard minimums',
            'T9': 'Tier 9: Minimal debt - Special case handling required'
        };

        makeChart('cvTier', {
            type: 'bar',
            data: { labels: labels, datasets: [{ label: titleMap[metric], data: data, backgroundColor: '#4e73df' }] },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { title: { display: true, text: titleMap[metric] + ' by Debt Tier - Compare Veritas economics across client segments' },
                    tooltip: { 
                        callbacks: { 
                            title: function(ctx) { return 'Debt ' + ctx[0].label; },
                            label: function(ctx) { 
                                var lines = [];
                                var val = ctx.parsed.y;
                                if (metric === 'totalFee') {
                                    lines.push('Total Veritas Fees: $' + val.toLocaleString());
                                    lines.push('(Enrollment Fee + Monthly Fees combined)');
                                } else if (metric === 'roiRatio') {
                                    lines.push('ROI Ratio: ' + val.toFixed(2) + 'x');
                                    lines.push('(Veritas fees ÷ marketing cost)');
                                    if (val >= 1) lines.push('✅ Profitable - fees exceed marketing spend');
                                    else lines.push('⚠️ Below breakeven on Veritas fees alone');
                                } else if (metric === 'enrollmentFee') {
                                    lines.push('Enrollment Fees: $' + val.toLocaleString());
                                    lines.push('(15% of enrolled debt for active clients)');
                                } else if (metric === 'monthlyFee') {
                                    lines.push('Monthly Fees: $' + val.toLocaleString());
                                    lines.push('(Recurring program payments from active clients)');
                                }
                                return lines;
                            },
                            afterLabel: function(ctx) {
                                var desc = tierDescriptions[ctx.label] || '';
                                if (desc) {
                                    var leads = (tierData.leads && tierData.leads[ctx.dataIndex]) || 0;
                                    var enrollments = (tierData.netEnrollments && tierData.netEnrollments[ctx.dataIndex]) || 0;
                                    return ['', '📊 ' + leads.toLocaleString() + ' leads → ' + enrollments.toLocaleString() + ' net enrollments', '📋 ' + desc];
                                }
                                return [];
                            }
                        }
                    }
                },
                scales: { y: { beginAtZero: true, ticks: { callback: function(v) { return isMoney ? '$' + v.toLocaleString() : v + 'x'; } } } }
            }
        });
    };

    // ========== TOP DROPS ==========
    window.rebuildDrops = function () {
        var metric = document.getElementById('drops-metric').value;
        var count = parseInt(document.getElementById('drops-count').value, 10) || 10;
        var stats = chartData.dropStats || [];
        var sorted = stats.slice().sort(function(a, b) { return (b[metric] || 0) - (a[metric] || 0); }).slice(0, count);
        var labels = sorted.map(function(d) { return d.drop; });
        var data = sorted.map(function(d) { return d[metric] || 0; });
        var titleMap = { profit: 'Est. Profit ($)', revenue: 'Est. Revenue ($)', leads: 'Total Leads', enrollments: 'Total Enrollments', roiRatio: 'ROI Ratio (x)', conversionRate: 'Conversion Rate (%)', veritasTotalFee: 'Veritas Fees ($)' };
        var isMoney = ['profit','revenue','veritasTotalFee'].indexOf(metric) >= 0;
        var isPercent = metric === 'conversionRate';
        var isRatio = metric === 'roiRatio';

        makeChart('cvDrops', {
            type: 'bar',
            data: { labels: labels, datasets: [{ label: titleMap[metric], data: data, backgroundColor: '#1cc88a' }] },
            options: {
                responsive: true, maintainAspectRatio: false, indexAxis: 'y',
                plugins: { title: { display: true, text: 'Top ' + count + ' Marketing Drops - Ranked by ' + titleMap[metric] },
                    tooltip: { 
                        callbacks: { 
                            title: function(ctx) { return '📬 ' + ctx[0].label; },
                            label: function(ctx) { 
                                var dropData = sorted[ctx.dataIndex];
                                var lines = [];
                                var val = ctx.parsed.x;
                                
                                // Primary metric
                                if (metric === 'profit') {
                                    lines.push('Est. Profit: $' + val.toLocaleString());
                                    lines.push('(Revenue minus marketing cost - projected over program lifecycle)');
                                } else if (metric === 'revenue') {
                                    lines.push('Est. Revenue: $' + val.toLocaleString());
                                    lines.push('(25% of enrolled debt - realizes over 24-48 months)');
                                } else if (metric === 'leads') {
                                    lines.push('Total Leads: ' + val.toLocaleString());
                                    lines.push('(All contacts who responded to this mail drop)');
                                } else if (metric === 'enrollments') {
                                    lines.push('Total Enrollments: ' + val.toLocaleString());
                                    lines.push('(Leads who signed up for the program)');
                                } else if (metric === 'roiRatio') {
                                    lines.push('ROI Ratio: ' + val.toFixed(2) + 'x');
                                    lines.push('(Profit ÷ marketing cost)');
                                    if (val >= 2) lines.push('🌟 Excellent - 2x+ return on marketing spend');
                                    else if (val >= 1) lines.push('✅ Profitable - positive return on investment');
                                    else lines.push('⚠️ Below breakeven - costs exceed projected revenue');
                                } else if (metric === 'conversionRate') {
                                    lines.push('Conversion Rate: ' + val.toFixed(2) + '%');
                                    lines.push('(Enrollments ÷ Leads × 100)');
                                    if (val >= 5) lines.push('🌟 Strong conversion - above industry average');
                                    else if (val >= 2) lines.push('✅ Average conversion rate');
                                    else lines.push('📉 Low conversion - review targeting or offer');
                                } else if (metric === 'veritasTotalFee') {
                                    lines.push('Veritas Fees: $' + val.toLocaleString());
                                    lines.push('(Enrollment + Monthly fees from active clients)');
                                }
                                return lines;
                            },
                            afterLabel: function(ctx) {
                                var dropData = sorted[ctx.dataIndex];
                                if (!dropData) return [];
                                var lines = [''];
                                lines.push('📊 Performance Summary:');
                                lines.push('   • ' + (dropData.leads || 0).toLocaleString() + ' leads → ' + (dropData.enrollments || 0).toLocaleString() + ' enrollments → ' + (dropData.netEnrollments || 0).toLocaleString() + ' net');
                                lines.push('   • Marketing Cost: $' + (dropData.cost || 0).toLocaleString());
                                lines.push('   • Conversion: ' + (dropData.conversionRate || 0).toFixed(1) + '% | ROI: ' + (dropData.roiRatio || 0).toFixed(2) + 'x');
                                return lines;
                            }
                        }
                    }
                },
                scales: { x: { beginAtZero: true, ticks: { callback: function(v) { if (isMoney) return '$' + v.toLocaleString(); if (isPercent) return v + '%'; if (isRatio) return v + 'x'; return v; } } } }
            }
        });
    };

    // ========== TREND ==========
    window.rebuildTrend = function () {
        var period = document.getElementById('trend-period').value;
        var metric = document.getElementById('trend-metric').value;
        var src = period === 'monthly' ? chartData.trendMonthly : chartData.trend;
        if (!src) src = { labels: [], leads: [], enrollments: [], profit: [], conversionRate: [], roiRatio: [], cost: [], revenue: [] };
        var labels = src.labels || [];
        var data = src[metric] || [];
        var titleMap = { leads: 'Total Leads', enrollments: 'Total Enrollments', profit: 'Est. Profit ($)', conversionRate: 'Conversion Rate (%)', roiRatio: 'ROI Ratio (x)' };
        var isMoney = metric === 'profit';
        var isPercent = metric === 'conversionRate';
        var isRatio = metric === 'roiRatio';
        var periodLabel = period === 'monthly' ? 'Month' : 'Week';

        makeChart('cvTrend', {
            type: 'line',
            data: { labels: labels, datasets: [{ label: titleMap[metric], data: data, borderColor: '#4e73df', backgroundColor: 'rgba(78,115,223,0.1)', tension: 0.3, fill: true }] },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { title: { display: true, text: titleMap[metric] + ' Over Time (' + (period === 'monthly' ? 'Monthly' : 'Weekly') + ') - Identify trends and seasonal patterns' },
                    tooltip: { 
                        callbacks: { 
                            title: function(ctx) { 
                                var dateStr = ctx[0].label;
                                if (period === 'monthly') {
                                    var parts = dateStr.split('-');
                                    var monthNames = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
                                    return '📅 ' + monthNames[parseInt(parts[1])-1] + ' ' + parts[0];
                                }
                                return '📅 Week of ' + dateStr;
                            },
                            label: function(ctx) { 
                                var val = ctx.parsed.y;
                                var idx = ctx.dataIndex;
                                var lines = [];
                                
                                if (metric === 'leads') {
                                    lines.push('Total Leads: ' + val.toLocaleString());
                                    lines.push('(Contacts who responded to marketing this ' + periodLabel.toLowerCase() + ')');
                                } else if (metric === 'enrollments') {
                                    lines.push('Total Enrollments: ' + val.toLocaleString());
                                    lines.push('(New clients who signed up this ' + periodLabel.toLowerCase() + ')');
                                } else if (metric === 'profit') {
                                    lines.push('Est. Profit: $' + val.toLocaleString());
                                    lines.push('(Projected revenue minus marketing costs)');
                                    if (val > 0) lines.push('✅ Profitable ' + periodLabel.toLowerCase());
                                    else lines.push('⚠️ Negative return this ' + periodLabel.toLowerCase());
                                } else if (metric === 'conversionRate') {
                                    lines.push('Conversion Rate: ' + val.toFixed(2) + '%');
                                    lines.push('(% of leads that became enrollments)');
                                    if (val >= 5) lines.push('🌟 Excellent conversion this ' + periodLabel.toLowerCase());
                                    else if (val >= 2) lines.push('✅ Average performance');
                                    else lines.push('📉 Below average - investigate drop quality');
                                } else if (metric === 'roiRatio') {
                                    lines.push('ROI Ratio: ' + val.toFixed(2) + 'x');
                                    lines.push('(Return on marketing investment)');
                                    if (val >= 2) lines.push('🌟 Excellent ROI - 2x+ return');
                                    else if (val >= 1) lines.push('✅ Positive return on investment');
                                    else lines.push('⚠️ Below breakeven');
                                }
                                return lines;
                            },
                            afterLabel: function(ctx) {
                                var idx = ctx.dataIndex;
                                var lines = [''];
                                lines.push('📊 ' + periodLabel + ' Summary:');
                                var leads = (src.leads && src.leads[idx]) || 0;
                                var enrollments = (src.enrollments && src.enrollments[idx]) || 0;
                                var cost = (src.cost && src.cost[idx]) || 0;
                                var revenue = (src.revenue && src.revenue[idx]) || 0;
                                lines.push('   • Leads: ' + leads.toLocaleString() + ' | Enrollments: ' + enrollments.toLocaleString());
                                lines.push('   • Cost: $' + cost.toLocaleString() + ' | Revenue: $' + revenue.toLocaleString());
                                
                                // Show trend vs previous period
                                if (idx > 0 && data[idx-1] !== undefined) {
                                    var prev = data[idx-1];
                                    var curr = data[idx];
                                    if (prev > 0) {
                                        var change = ((curr - prev) / prev * 100).toFixed(1);
                                        var arrow = change >= 0 ? '📈' : '📉';
                                        lines.push('   • ' + arrow + ' ' + (change >= 0 ? '+' : '') + change + '% vs previous ' + periodLabel.toLowerCase());
                                    }
                                }
                                return lines;
                            }
                        }
                    }
                },
                scales: { y: { beginAtZero: true, ticks: { callback: function(v) { if (isMoney) return '$' + v.toLocaleString(); if (isPercent) return v + '%'; if (isRatio) return v + 'x'; return v; } } } }
            }
        });
    };

    window.showChart = function (name, btn) {
        ['funnel','tier','drops','trend'].forEach(function (n) {
            document.getElementById('chart-' + n).style.display = n === name ? 'block' : 'none';
        });
        document.querySelectorAll('#marketing-charts-wrapper .btn-group .btn').forEach(function (b) { b.classList.remove('active'); });
        btn.classList.add('active');
        if (name === 'funnel') rebuildFunnel();
        if (name === 'tier') rebuildTier();
        if (name === 'drops') rebuildDrops();
        if (name === 'trend') rebuildTrend();
    };

    function initCharts() {
        if (typeof Chart === 'undefined') { showError(); return; }
        try { rebuildTrend(); } catch (e) { showError(); }
        // Init Bootstrap tooltips if available
        if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
            document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function(el) { new bootstrap.Tooltip(el); });
        }
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
