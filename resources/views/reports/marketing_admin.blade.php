@extends('layouts.app')

@push('styles')
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css">
<style>
.mar-card        { border:none; box-shadow:0 1px 6px rgba(0,0,0,.08); margin-bottom:18px; }
.mar-card .card-header {
  border:none; padding:10px 16px; font-size:13px; font-weight:600; color:#fff;
  display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:6px;
}
.mar-hd-filter  { background:linear-gradient(90deg,#2563eb,#7c3aed); }
.mar-hd-summary { background:linear-gradient(90deg,#0f172a,#1e293b); }
.mar-hd-chart   { background:linear-gradient(90deg,#0f172a,#1e3a5f); }
.mar-hd-table   { background:linear-gradient(90deg,#374151,#4b5563); }

.mar-label { font-size:10.5px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:#6b7280; margin-bottom:3px; }

.mar-pill { font-size:10px; background:rgba(255,255,255,.2); color:#fff; padding:2px 9px; border-radius:12px; white-space:nowrap; }

/* Summary table */
.mar-summary-table { font-size:12.5px; width:100%; }
.mar-summary-table td { padding:4px 14px; }
.mar-summary-table td:first-child { color:#64748b; width:55%; }
.mar-summary-table td:last-child  { font-weight:600; text-align:right; font-variant-numeric:tabular-nums; }
.mar-summary-table .row-lead td { background:#eff6ff; color:#1d4ed8; font-weight:700; }
.mar-summary-table .row-roi  td { background:#f0fdf4; }
.mar-summary-table .row-div  td { border-top:1px solid #f1f5f9; padding-top:6px; }
.mar-summary-cols { column-count:2; column-gap:0; }
@media(max-width:1199px){ .mar-summary-cols { column-count:1; } }
.mar-summary-cols table { break-inside:avoid; width:100%; }
.mar-summary-panel { height:100%; display:flex; flex-direction:column; }
.mar-summary-scroll { flex:1; overflow-y:auto; max-height:480px; }

/* Chart */
.mar-chart-wrap { position:relative; min-height:220px; }
.mar-chart-overlay {
  display:none; position:absolute; inset:0; background:rgba(15,23,42,.5);
  border-radius:4px; align-items:center; justify-content:center;
  color:#fff; font-size:13px; font-weight:600; z-index:10; backdrop-filter:blur(2px);
}
.mar-chart-overlay.show { display:flex; }
.mar-chart-empty { display:none; text-align:center; padding:60px 20px; color:#94a3b8; font-size:14px; }
.mar-chart-empty.show { display:block; }

/* Skeleton */
.mar-skeleton .sk-row { height:13px; border-radius:4px; margin-bottom:9px;
  background:linear-gradient(90deg,#eef2f7 25%,#e2e8f0 37%,#eef2f7 63%);
  background-size:400% 100%; animation:sk 1.4s ease infinite; }
.mar-skeleton .sk-row:nth-child(even){ width:78%; }
@keyframes sk{ 0%{background-position:100% 50%} 100%{background-position:0 50%} }

/* Select2 overrides */
.select2-container--default .select2-selection--multiple {
  border:1px solid #dee2e6; border-radius:6px; min-height:34px; background:#fff; padding:2px 4px;
}
.select2-container--default.select2-container--focus .select2-selection--multiple { border-color:#86b7fe; box-shadow:0 0 0 .25rem rgba(13,110,253,.25); }
.select2-container--default .select2-selection--multiple .select2-selection__choice {
  background:#eff6ff; border:1px solid #bfdbfe; border-radius:4px; color:#2563eb;
  padding:1px 6px; font-size:12px; margin:2px 2px;
}
.select2-container--default .select2-selection--multiple .select2-selection__choice__remove { color:#93c5fd; margin-right:4px; }
.select2-container--default .select2-selection--multiple .select2-selection__choice__remove:hover { color:#2563eb; }
.select2-container--default .select2-selection--multiple .select2-selection__placeholder { color:#9ca3af; font-size:13px; }
.select2-dropdown { border:1px solid #dee2e6; border-radius:6px; font-size:13px; box-shadow:0 4px 16px rgba(0,0,0,.12); z-index:9999; }
.select2-search--dropdown .select2-search__field { border:1px solid #dee2e6; border-radius:4px; padding:6px 10px; font-size:13px; outline:none; }
.select2-results__option { padding:7px 12px; }
.select2-results__option[aria-selected="true"] { background:#eff6ff; color:#2563eb; }
.select2-results__option--highlighted[aria-selected] { background:#2563eb !important; color:#fff !important; }
.select2-container { width:100% !important; }

#mar-table-wrap { display:none; }

/* Category metric pills (inside filters card) */
.mar-cat-strip { display:flex; flex-wrap:wrap; gap:4px; }
.mar-cat-pill {
  background:#f8fafc; border:1px solid #e2e8f0; border-radius:20px;
  font-size:11px; color:#64748b; padding:3px 10px; cursor:pointer;
  display:inline-flex; align-items:center; gap:5px; transition:all .15s; white-space:nowrap;
}
.mar-cat-pill.active { background:#eff6ff; border-color:#93c5fd; color:#2563eb; font-weight:600; }
.mar-cat-pill:hover  { background:#f1f5f9; color:#374151; }
.mar-cat-dot { width:7px; height:7px; border-radius:50%; flex-shrink:0; }
</style>
@endpush

@section('content')
<div class="content">

  {{-- ═══ PAGE HEADER ═══ --}}
  <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <h5 class="mb-0 fw-bold">Marketing Admin Report</h5>
    <div class="d-flex gap-2">
      <a href="/cmd/reports/marketing-admin" class="btn btn-sm btn-outline-secondary">Reset</a>
      <button type="button" id="mar-export-btn" class="btn btn-sm btn-success">Export CSV</button>
    </div>
  </div>

  {{-- ═══ ROW: FILTERS (left) + DROP SUMMARY (right) ═══ --}}
  <div class="row g-3 mb-0" style="align-items:stretch;">

    {{-- LEFT: Filters --}}
    <div class="col-xl-7 col-lg-6">
      <div class="card mar-card h-100" style="margin-bottom:0;">
        <div class="card-header mar-hd-filter">
          <span>Filters</span>
          <span class="mar-pill">Updates as you filter</span>
        </div>
        <div class="card-body py-3">
          <form id="mar-filter-form" method="get" action="/cmd/reports/marketing-admin">

            {{-- Row 1: dates + intent + period --}}
            <div class="row g-2 mb-2">
              <div class="col-sm-3 col-6">
                <div class="mar-label">Date From</div>
                <input type="date" name="send_start" value="{{ $filters['send_start'] ?? '' }}" class="form-control form-control-sm">
              </div>
              <div class="col-sm-3 col-6">
                <div class="mar-label">Date To</div>
                <input type="date" name="send_end" value="{{ $filters['send_end'] ?? '' }}" class="form-control form-control-sm">
              </div>
              <div class="col-sm-3 col-6">
                <div class="mar-label">Intent</div>
                <select name="intent" class="form-select form-select-sm">
                  <option value="all" @selected(($filters['intent'] ?? 'all') === 'all')>All</option>
                  <option value="yes" @selected(($filters['intent'] ?? 'all') === 'yes')>Has Intent</option>
                  <option value="no"  @selected(($filters['intent'] ?? 'all') === 'no')>No Intent</option>
                </select>
              </div>
              <div class="col-sm-3 col-6">
                <div class="mar-label">Chart Period</div>
                <select name="chart_period" id="f-chart-period" class="form-select form-select-sm">
                  <option value="weekly"  @selected(($filters['chart_period'] ?? 'weekly') === 'weekly')>Weekly</option>
                  <option value="monthly" @selected(($filters['chart_period'] ?? 'weekly') === 'monthly')>Monthly</option>
                </select>
              </div>
            </div>

            {{-- Row 2: multi-selects (3 per row) --}}
            <div class="row g-2 mb-2">
              <div class="col-sm-4 col-6">
                <div class="mar-label">Month</div>
                <select name="months[]" id="f-months" multiple class="form-select form-select-sm">
                  @php $monthNames = [1=>'January',2=>'February',3=>'March',4=>'April',5=>'May',6=>'June',7=>'July',8=>'August',9=>'September',10=>'October',11=>'November',12=>'December']; @endphp
                  @foreach($monthNames as $num => $mname)
                    <option value="{{ $num }}" @selected(in_array((string)$num, array_map('strval', $filters['months'] ?? [])))>{{ $mname }}</option>
                  @endforeach
                </select>
              </div>
              <div class="col-sm-4 col-6">
                <div class="mar-label">Year</div>
                <select name="years[]" id="f-years" multiple class="form-select form-select-sm">
                  @foreach(($allYears ?? []) as $yr)
                    <option value="{{ $yr }}" @selected(in_array((string)$yr, array_map('strval', $filters['years'] ?? [])))>{{ $yr }}</option>
                  @endforeach
                </select>
              </div>
              <div class="col-sm-4 col-6">
                <div class="mar-label">Tier</div>
                <select name="tiers[]" id="f-tiers" multiple class="form-select form-select-sm">
                  @foreach(($allTiers ?? []) as $t)
                    <option value="{{ $t }}" @selected(in_array($t, $filters['tiers'] ?? []))>{{ $t }}</option>
                  @endforeach
                </select>
              </div>
              <div class="col-sm-4 col-6">
                <div class="mar-label">State</div>
                <select name="states[]" id="f-states" multiple class="form-select form-select-sm">
                  @foreach(($allStates ?? []) as $st)
                    <option value="{{ $st }}" @selected(in_array($st, $filters['states'] ?? []))>{{ $st }}</option>
                  @endforeach
                </select>
              </div>
              <div class="col-sm-4 col-6">
                <div class="mar-label">Vendor</div>
                <select name="vendors[]" id="f-vendors" multiple class="form-select form-select-sm">
                  @foreach(($allVendors ?? []) as $v)
                    <option value="{{ $v }}" @selected(in_array($v, $filters['vendors'] ?? []))>{{ $v }}</option>
                  @endforeach
                </select>
              </div>
              <div class="col-sm-4 col-6">
                <div class="mar-label">Data Provider</div>
                <select name="data_providers[]" id="f-providers" multiple class="form-select form-select-sm">
                  @foreach(($allDataProviders ?? []) as $dp)
                    <option value="{{ $dp }}" @selected(in_array($dp, $filters['data_providers'] ?? []))>{{ $dp }}</option>
                  @endforeach
                </select>
              </div>
            </div>

            {{-- Row 3: type + style + drop + clear --}}
            <div class="row g-2 align-items-end mb-3">
              <div class="col-sm-4 col-6">
                <div class="mar-label">Marketing Type</div>
                <select name="marketing_types[]" id="f-types" multiple class="form-select form-select-sm">
                  @foreach(($allDropTypes ?? []) as $dt)
                    <option value="{{ $dt }}" @selected(in_array($dt, $filters['marketing_types'] ?? []))>{{ $dt }}</option>
                  @endforeach
                </select>
              </div>
              <div class="col-sm-4 col-6">
                <div class="mar-label">Mail Style</div>
                <select name="mail_styles[]" id="f-styles" multiple class="form-select form-select-sm">
                  @foreach(($allMailStyles ?? []) as $ms)
                    <option value="{{ $ms }}" @selected(in_array($ms, $filters['mail_styles'] ?? []))>{{ $ms }}</option>
                  @endforeach
                </select>
              </div>
              <div class="col-sm-4 col-12">
                <a href="/cmd/reports/marketing-admin" class="btn btn-outline-secondary btn-sm w-100">Clear All</a>
              </div>
              <div class="col-12">
                <div class="mar-label">Drop Name</div>
                <select name="drops[]" id="f-drops" multiple class="form-select form-select-sm">
                  @foreach(($allDrops ?? []) as $drop)
                    <option value="{{ $drop }}" @selected(in_array($drop, $filters['drops'] ?? []))>{{ $drop }}</option>
                  @endforeach
                </select>
              </div>
            </div>

            {{-- Row 4: chart metric toggles --}}
            <div>
              <div class="mar-label mb-1">Chart Metrics <span style="font-weight:400;text-transform:none;letter-spacing:0;color:#9ca3af;font-size:10px;">— click to show/hide on chart</span></div>
              <div id="mar-cat-strip" class="mar-cat-strip">{{-- populated by JS after chart init --}}</div>
            </div>

          </form>
        </div>
      </div>
    </div>

    {{-- RIGHT: Drop Summary --}}
    <div class="col-xl-5 col-lg-6">
      <div class="card mar-card h-100" style="margin-bottom:0;">
        <div class="card-header mar-hd-summary">
          <span>Drop Summary</span>
          <span class="mar-pill" id="mar-summary-badge" style="display:none;">live</span>
        </div>
        <div class="mar-summary-panel">
          <div class="mar-summary-scroll" id="mar-summary-body">
            <div class="mar-skeleton p-3">
              <div class="sk-row"></div><div class="sk-row"></div><div class="sk-row"></div>
              <div class="sk-row"></div><div class="sk-row"></div><div class="sk-row"></div>
              <div class="sk-row"></div><div class="sk-row"></div>
            </div>
          </div>
        </div>
      </div>
    </div>

  </div>{{-- /row --}}
  <div style="margin-bottom:18px;"></div>

  {{-- ═══ 3. CHART ═══ --}}
  <div class="card mar-card">
    <div class="card-header mar-hd-chart">
      <span id="mar-chart-title">Trend</span>
      <div class="d-flex align-items-center gap-2">
        <span class="mar-pill" id="mar-chart-badge" style="display:none;"></span>
        <span class="mar-pill" id="mar-snapshot-badge" style="font-size:10px;">
          @if($snapshotAt) Data: {{ $snapshotAt->diffForHumans() }} @else No cache — click Refresh @endif
        </span>
        <button type="button" class="btn btn-sm btn-outline-light" id="mar-refresh-btn" style="font-size:11px;padding:2px 10px;">Refresh Data</button>
      </div>
    </div>
    <div class="card-body">
      <div class="mar-chart-wrap">
        <div class="mar-chart-overlay" id="mar-chart-overlay">Loading chart...</div>
        <div class="mar-chart-empty" id="mar-chart-empty">Loading...</div>
        <div style="display:none;" id="mar-chart-container">
          <canvas id="marChart" style="max-height:420px;"></canvas>
        </div>
      </div>
    </div>
  </div>

  {{-- ═══ 4. TABLE (hidden — no DB query until toggled) ═══ --}}
  <div class="card mar-card">
    <div class="card-header mar-hd-table">
      <span>Drop Data Table</span>
      <button class="btn btn-sm btn-light" id="mar-toggle-table" type="button">Show Table</button>
    </div>
    <div id="mar-table-wrap">
      <div id="mar-table-body">
        <div class="card-body text-muted" style="font-size:13px;">Toggle the table above to load data.</div>
      </div>
    </div>
  </div>

</div>
@push('scripts_bottom')
<script src="{{ asset('assets/js/plugins/forms/selects/select2.min.js') }}"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function () {

  var form = document.getElementById('mar-filter-form');

  /* ── Select2 ── */
  var s2 = { closeOnSelect: false, placeholder: 'Any (all)', allowClear: true, width: '100%' };
  ['f-months','f-years','f-tiers','f-states','f-vendors','f-providers','f-types','f-styles'].forEach(function (id) {
    var el = $('#' + id);
    if (el.length) { el.select2(s2); el.on('change.select2', refreshAll); }
  });
  var $drops = $('#f-drops');
  if ($drops.length) {
    $drops.select2($.extend({}, s2, { placeholder: 'Search drop names…' }));
    $drops.on('change.select2', refreshAll);
  }

  /* ── Chart.js setup ── */
  var canvas    = document.getElementById('marChart');
  var overlay   = document.getElementById('mar-chart-overlay');
  var emptyMsg  = document.getElementById('mar-chart-empty');
  var container = document.getElementById('mar-chart-container');
  var titleEl   = document.getElementById('mar-chart-title');
  var badge     = document.getElementById('mar-chart-badge');

  /* on = visible by default, off = hidden by default */
  var DATASETS = [
    { key: 'response',        label: 'Response Rate',     color: '#22c55e', axis: 'yPercent', bw: 2,   on: true  },
    { key: 'amount',          label: 'Amount Dropped',    color: '#3b82f6', axis: 'yMoney',   bw: 2,   on: true  },
    { key: 'cost',            label: 'Drop Cost',         color: '#ef4444', axis: 'yMoney',   bw: 2,   on: true  },
    { key: 'calls',           label: 'Calls',             color: '#a855f7', axis: 'yCount',   bw: 2,   on: true  },
    { key: '_roi',            label: 'ROI',               color: '#0891b2', axis: 'yMoney',   bw: 2,   on: true  },
    { key: '_revenue',        label: 'Revenue',           color: '#059669', axis: 'yMoney',   bw: 2,   on: false },
    { key: 'avgReps',         label: 'Average Reps',      color: '#f59e0b', axis: 'yCount',   bw: 1.5, on: false },
    { key: '_ppd',            label: 'Price Per Drop',    color: '#06b6d4', axis: 'yMoney',   bw: 1.5, on: false },
    { key: '_cpc',            label: 'Cost Per Call',     color: '#ec4899', axis: 'yMoney',   bw: 1.5, on: false },
    { key: '_apr',            label: 'Amount Per Rep',    color: '#10b981', axis: 'yMoney',   bw: 1.5, on: false },
    { key: '_cpr',            label: 'Calls Per Rep',     color: '#8b5cf6', axis: 'yCount',   bw: 1.5, on: false },
    { key: 'enrollments',     label: 'Total Enrollments', color: '#0ea5e9', axis: 'yCount',   bw: 2,   on: false },
    { key: 'netEnrollments',  label: 'Active Deals',      color: '#14b8a6', axis: 'yCount',   bw: 2,   on: false },
    { key: 'cancels',         label: 'Cancels',           color: '#f97316', axis: 'yCount',   bw: 1.5, on: false },
    { key: 'nsfs',            label: 'NSFs',              color: '#dc2626', axis: 'yCount',   bw: 1.5, on: false },
    { key: '_avgDebt',        label: 'Average Debt',      color: '#7c3aed', axis: 'yMoney',   bw: 1.5, on: false },
    { key: '_convRate',       label: 'Conversion Rate',   color: '#16a34a', axis: 'yPercent', bw: 1.5, on: false },
    { key: '_cpa',            label: 'CPA',               color: '#d97706', axis: 'yMoney',   bw: 1.5, on: false },
    { key: '_retentionRatio', label: 'Retention Ratio',   color: '#be185d', axis: 'yPercent', bw: 1.5, on: false },
    { key: '_pproi',          label: 'Per Piece ROI',     color: '#6366f1', axis: 'yMoney',   bw: 1.5, on: false }
  ];

  var marChart = new Chart(canvas, {
    type: 'line',
    data: { labels: [], datasets: DATASETS.map(function (d) {
      return {
        label: d.label, data: [], borderColor: d.color, backgroundColor: 'transparent',
        yAxisID: d.axis, tension: 0.2, borderWidth: d.bw, pointRadius: 2, fill: false,
        hidden: !d.on
      };
    })},
    options: {
      responsive: true, maintainAspectRatio: false,
      interaction: { mode: 'nearest', axis: 'x', intersect: false },
      scales: {
        yPercent: { type:'linear', position:'left',  min:0, ticks:{ callback: function(v){ return v+'%'; } }, title:{ display:true, text:'%' } },
        yMoney:   { type:'linear', position:'right', grid:{ drawOnChartArea:false }, ticks:{ callback: function(v){ return '$'+new Intl.NumberFormat().format(v); } }, title:{ display:true, text:'Amount ($)' } },
        yCount:   { type:'linear', position:'right', offset:true, grid:{ drawOnChartArea:false }, title:{ display:true, text:'Count' } },
        x: { title:{ display:true, text:'Date' } }
      },
      plugins: {
        legend: { display: false },
        tooltip: { callbacks: { label: function(ctx) {
          var val = ctx.parsed.y || 0, lbl = ctx.dataset.label;
          if (ctx.dataset.yAxisID === 'yPercent') return lbl+': '+val.toFixed(2)+'%';
          if (ctx.dataset.yAxisID === 'yMoney')   return lbl+': $'+new Intl.NumberFormat().format(Math.round(val));
          return lbl+': '+new Intl.NumberFormat().format(Math.round(val));
        }}}
      }
    }
  });

  /* ── Category pills (built from DATASETS) ── */
  (function() {
    var strip = document.getElementById('mar-cat-strip');
    DATASETS.forEach(function(d, i) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'mar-cat-pill' + (d.on ? ' active' : '');
      btn.dataset.idx = i;
      btn.innerHTML = '<span class="mar-cat-dot" style="background:'+d.color+';"></span>'+d.label;
      btn.addEventListener('click', function() {
        var meta = marChart.getDatasetMeta(i);
        var nowHidden = !meta.hidden;
        if (nowHidden) { marChart.hide(i); } else { marChart.show(i); }
        btn.classList.toggle('active', !nowHidden);
      });
      strip.appendChild(btn);
    });
  })();

  function buildSeriesData(raw) {
    var a  = raw.amount        || [];
    var c  = raw.cost          || [];
    var ca = raw.calls         || [];
    var r  = raw.avgReps       || [];
    var en = raw.enrollments   || [];
    var nc = raw.netEnrollments|| [];
    var cc = raw.cancels       || [];
    var ns = raw.nsfs          || [];
    var rd = raw.retainedDebt  || [];
    var tl = raw.totalLeads    || [];
    return {
      response: raw.response||[], amount: a, cost: c, calls: ca, avgReps: r,
      enrollments: en, netEnrollments: nc, cancels: cc, nsfs: ns,
      _ppd:          a.map(function(v,i){ return v>0  ? c[i]/v      : null; }),
      _cpc:          ca.map(function(v,i){ return v>0 ? c[i]/v      : null; }),
      _apr:          a.map(function(v,i){ return (v>0&&r[i]>0) ? v/r[i]    : null; }),
      _cpr:          ca.map(function(v,i){ return (v>0&&r[i]>0) ? v/r[i]   : null; }),
      _revenue:      rd.map(function(v){ return v * 0.25; }),
      _roi:          rd.map(function(v,i){ return (v * 0.25) - c[i]; }),
      _pproi:        a.map(function(v,i){ return v>0 ? ((rd[i]*0.25)-c[i])/v : null; }),
      _avgDebt:      en.map(function(v,i){ return v>0 ? raw.enrolledDebt[i]/v : null; }),
      _convRate:     tl.map(function(v,i){ return v>0 ? (en[i]/v)*100 : null; }),
      _cpa:          en.map(function(v,i){ return v>0 ? c[i]/v : null; }),
      _retentionRatio: en.map(function(v,i){ return v>0 ? (nc[i]/v)*100 : null; })
    };
  }

  function applyToChart(raw, label) {
    if (!raw || !raw.labels || raw.labels.length === 0) {
      container.style.display = 'none'; emptyMsg.classList.add('show');
      badge.style.display = 'none'; titleEl.textContent = 'Trend'; return;
    }
    var series = buildSeriesData(raw);
    marChart.data.labels = raw.labels;
    DATASETS.forEach(function(d,i){ marChart.data.datasets[i].data = series[d.key]||[]; });
    marChart.update('none');
    container.style.display = ''; emptyMsg.classList.remove('show');
    badge.style.display = ''; badge.textContent = raw.labels.length+' data points';
    titleEl.textContent = label + ' Trend';
  }

  function buildFilterLabel() {
    var parts = [], period = (form.querySelector('[name="chart_period"]')||{}).value||'weekly';
    var chk = function(name, lbl) {
      var sel = form.querySelector('[name="'+name+'[]"]');
      if (!sel) return;
      var vals = Array.from(sel.selectedOptions).filter(function(o){ return o.value; }).map(function(o){ return o.text; });
      if (vals.length) parts.push(lbl+': '+vals.slice(0,3).join(', ')+(vals.length>3?'…':''));
    };
    var df = (form.querySelector('[name="send_start"]')||{}).value;
    var dt = (form.querySelector('[name="send_end"]')||{}).value;
    if (df||dt) parts.push((df||'…')+' to '+(dt||'now'));
    chk('months','Month'); chk('years','Year'); chk('tiers','Tier');
    chk('states','State'); chk('vendors','Vendor'); chk('data_providers','Provider');
    chk('marketing_types','Type'); chk('mail_styles','Style'); chk('drops','Drop');
    return (period==='monthly' ? 'Monthly' : 'Weekly') + (parts.length ? ' · '+parts.join(' · ') : ' (all data)');
  }

  function serializeFilters() {
    var p = new URLSearchParams();
    form.querySelectorAll('input, select').forEach(function(el) {
      if (!el.name || el.disabled) return;
      if (el.tagName === 'SELECT' && el.multiple) {
        Array.from(el.selectedOptions).forEach(function(o){ if (o.value) p.append(el.name, o.value); });
      } else if (el.type !== 'submit' && el.type !== 'button') {
        if (el.value !== '') p.append(el.name, el.value);
      }
    });
    return p;
  }

  /* ── AJAX: chart ── */
  var chartCtrl = null;
  function fetchChart() {
    overlay.classList.add('show');
    if (chartCtrl) chartCtrl.abort();
    chartCtrl = new AbortController();
    fetch('/cmd/reports/marketing-admin/chart-data?' + serializeFilters().toString(), { signal: chartCtrl.signal })
      .then(function(r){
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
      })
      .then(function(d){ overlay.classList.remove('show'); applyToChart(d, buildFilterLabel()); })
      .catch(function(e){
        if (e.name === 'AbortError') return;
        overlay.classList.remove('show');
        container.style.display = 'none';
        emptyMsg.classList.add('show');
        emptyMsg.textContent = 'Error loading chart: ' + e.message;
      });
  }

  /* ── AJAX: summary ── */
  var sumCtrl = null;
  var sumBody  = document.getElementById('mar-summary-body');
  var sumBadge = document.getElementById('mar-summary-badge');
  function skeletonHtml() {
    return '<div class="mar-skeleton p-3"><div class="row">' +
      '<div class="col-md-4">' + '<div class="sk-row"></div>'.repeat(6) + '</div>' +
      '<div class="col-md-4">' + '<div class="sk-row"></div>'.repeat(6) + '</div>' +
      '<div class="col-md-4">' + '<div class="sk-row"></div>'.repeat(6) + '</div>' +
      '</div></div>';
  }
  function fetchSummary() {
    sumBody.innerHTML = skeletonHtml();
    sumBadge.style.display = 'none';
    if (sumCtrl) sumCtrl.abort();
    sumCtrl = new AbortController();
    fetch('/cmd/reports/marketing-admin/summary-data?' + serializeFilters().toString(), { signal: sumCtrl.signal })
      .then(function(r){
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.text();
      })
      .then(function(html){ sumBody.innerHTML = html; sumBadge.style.display = ''; })
      .catch(function(e){
        if (e.name === 'AbortError') return;
        sumBody.innerHTML = '<div class="card-body text-danger" style="font-size:13px;">Failed to load summary: ' + e.message + '</div>';
      });
  }

  /* ── AJAX: table (lazy) ── */
  var tableCtrl = null, tableLoaded = false;
  var tableWrap = document.getElementById('mar-table-wrap');
  var tableBody = document.getElementById('mar-table-body');
  var toggleBtn = document.getElementById('mar-toggle-table');

  function fetchTable(page) {
    var p = serializeFilters(); p.set('page', page||1);
    tableBody.innerHTML = '<div class="card-body text-muted" style="font-size:13px;">Loading table...</div>';
    if (tableCtrl) tableCtrl.abort();
    tableCtrl = new AbortController();
    fetch('/cmd/reports/marketing-admin/table-data?' + p.toString(), { signal: tableCtrl.signal })
      .then(function(r){
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.text();
      })
      .then(function(html){ tableBody.innerHTML = html; tableLoaded = true; })
      .catch(function(e){
        if (e.name === 'AbortError') return;
        tableBody.innerHTML = '<div class="card-body text-danger" style="font-size:13px;">Failed to load table: ' + e.message + '</div>';
      });
  }

  toggleBtn.addEventListener('click', function() {
    var open = tableWrap.style.display === 'block';
    tableWrap.style.display = open ? 'none' : 'block';
    toggleBtn.textContent   = open ? 'Show Table' : 'Hide Table';
    if (!open && !tableLoaded) fetchTable(1);
  });

  tableBody.addEventListener('click', function(e) {
    var a = e.target.closest('a.page-link[data-page]');
    if (!a) return;
    e.preventDefault();
    if (a.closest('.page-item') && a.closest('.page-item').classList.contains('disabled')) return;
    fetchTable(parseInt(a.getAttribute('data-page'), 10) || 1);
  });

  /* ── Debounced refresh ── */
  var refreshTimer = null;
  function refreshAll() {
    clearTimeout(refreshTimer);
    tableLoaded = false; // always invalidate so table reloads with new filters when opened
    refreshTimer = setTimeout(function() {
      fetchChart();
      fetchSummary();
      if (tableWrap.style.display === 'block') { fetchTable(1); }
    }, 600);
  }

  form.addEventListener('change', function(e) {
    // skip sort/pagination controls and select2-managed selects (they bind change.select2 directly)
    if (['sort','dir','per_page'].indexOf(e.target.name) >= 0) return;
    if (e.target.classList && e.target.classList.contains('select2-hidden-accessible')) return;
    refreshAll();
  });
  form.addEventListener('input', function(e) {
    if (['send_start','send_end'].indexOf(e.target.name) >= 0) refreshAll();
  });
  form.addEventListener('submit', function(e) { e.preventDefault(); refreshAll(); });

  document.getElementById('mar-export-btn').addEventListener('click', function() {
    window.location = '/cmd/reports/marketing-admin/export?' + serializeFilters().toString();
  });

  /* ── Refresh snapshot ── */
  document.getElementById('mar-refresh-btn').addEventListener('click', function() {
    var btn = this, snBadge = document.getElementById('mar-snapshot-badge');
    btn.disabled = true; btn.textContent = 'Refreshing...'; snBadge.textContent = 'Rebuilding cache...';
    fetch('/cmd/reports/marketing-admin/refresh', {
      method: 'POST',
      headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' }
    })
    .then(function(r){ return r.json(); })
    .then(function(d){ snBadge.textContent = 'Data: '+d.snapshot_at; btn.textContent = 'Refresh Data'; btn.disabled = false; fetchChart(); fetchSummary(); })
    .catch(function(){ snBadge.textContent = 'Refresh failed'; btn.textContent = 'Refresh Data'; btn.disabled = false; });
  });

  /* ── Initial load ── */
  fetchChart();
  fetchSummary();

})();
</script>
@endpush

@endsection
