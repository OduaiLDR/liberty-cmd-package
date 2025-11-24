@extends('layouts.app')

@section('content')
<div class="content">
    <div class="card">
        <div class="card-header">
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
                <h5 class="mb-0">Drop Summary & Charts</h5>
            </div>

            <form id="summaryFilters" method="get" action="/cmd/reports/drop-summary">
                <div class="row g-3 align-items-end">
                    <div class="col-12 col-md-3">
                        <label class="form-label">Send Date From</label>
                        <input type="date" name="send_start" value="{{ $sendStart ?? '' }}" class="form-control">
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label">Send Date To</label>
                        <input type="date" name="send_end" value="{{ $sendEnd ?? '' }}" class="form-control">
                    </div>
                    <div class="col-12 col-md-2">
                        <label class="form-label">Month</label>
                        <select name="month" class="form-select">
                            <option value="">All</option>
                            @for ($m = 1; $m <= 12; $m++)
                                <option value="{{ $m }}" @if(($month ?? 0) == $m) selected @endif>{{ $m }}</option>
                            @endfor
                        </select>
                    </div>
                    <div class="col-12 col-md-2">
                        <label class="form-label">Year</label>
                        <select name="year" class="form-select">
                            <option value="">All</option>
                            @for ($y = 2020; $y <= 2026; $y++)
                                <option value="{{ $y }}" @if(($year ?? 0) == $y) selected @endif>{{ $y }}</option>
                            @endfor
                        </select>
                    </div>
                </div>

                <div class="row g-3 mt-1">
                    <div class="col-12 col-md-3">
                        <label class="form-label">Tier</label>
                        <select name="tier" class="form-select">
                            <option value="">All</option>
                            @foreach (['T1','T2','T3','T4','T5','T6','T7','T8','T9'] as $t)
                                <option value="{{ $t }}" @if(($tier ?? '') === $t) selected @endif>{{ $t }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label">Vendor</label>
                        <select name="vendor" class="form-select">
                            <option value="">All</option>
                            @foreach (($allVendors ?? []) as $v)
                                <option value="{{ (string)($v->Vendor ?? $v) }}" @if(($vendor ?? '') === (string)($v->Vendor ?? $v)) selected @endif>{{ $v->Vendor ?? $v }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label">Data Provider</label>
                        <select name="data_provider" class="form-select">
                            <option value="">All</option>
                            @foreach (($allDataProviders ?? []) as $dp)
                                <option value="{{ (string)($dp->Data_Type ?? $dp) }}" @if(($dataProvider ?? '') === (string)($dp->Data_Type ?? $dp)) selected @endif>{{ $dp->Data_Type ?? $dp }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <input type="hidden" name="chart_start" value="{{ $chartStart ?? '' }}">
                <input type="hidden" name="chart_end" value="{{ $chartEnd ?? '' }}">
                <input type="hidden" name="chart_period" value="{{ $chartPeriod ?? 'weekly' }}">
                <div class="row mt-2">
                    <div class="col-12 d-flex gap-2 justify-content-end">
                        <button type="submit" class="btn btn-outline-secondary">Apply Filters</button>
                        <button id="btnResetSummary" type="button" class="btn btn-outline-danger">Reset Summary</button>
                    </div>
                </div>
            </form>
        </div>

        <div class="card-body">
            <div class="mb-3">
                <h6 class="mb-2">Drop Summary</h6>
                <div class="table-responsive drop-summary-table">
                    <table class="table table-sm align-middle" style="font-size:0.9rem;">
                        <thead>
                        <tr>
                            <th class="text-nowrap">Metric</th>
                            <th class="text-nowrap">Summary</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach(($summary ?? []) as $row)
                            @php
                                $metric = $row['Metric'];
                                $val = $row['Summary'];
                                $out = $val;
                                $money = ['Drop Cost','Cost Per Call','Price Per Drop','CPA','Enrolled Debt','Average Debt'];
                                $plainCounts = ['Amount Dropped','Amount Per Rep'];
                                $percent = ['Response Rate'];
                                $counts = ['Total Leads','Qualified Leads','Calls','Total Enrollments','Cancels','NSFs','Net Enrollments'];
                                if (in_array($metric, $plainCounts, true)) {
                                    $out = number_format((float)$val, 0);
                                } elseif (in_array($metric, $money, true)) {
                                    $dec = in_array($metric, ['Enrolled Debt','Average Debt'], true) ? 0 : 2;
                                    $out = '$' . number_format((float)$val, $dec);
                                } elseif (in_array($metric, $percent, true)) {
                                    $out = number_format(((float)$val) * 100, 2) . '%';
                                } elseif (in_array($metric, $counts, true)) {
                                    $out = number_format((float)$val, 0);
                                } else {
                                    $out = number_format((float)$val, 2);
                                }
                            @endphp
                            <tr>
                                <td class="text-nowrap">{{ $metric }}</td>
                                <td>{{ $out }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <hr class="my-3">

            <div>
                <h6 class="mb-2">Chart</h6>
                <form method="get" action="/cmd/reports/drop-summary" class="row g-2 align-items-end mb-2">
                    <input type="hidden" name="send_start" value="{{ $sendStart ?? '' }}">
                    <input type="hidden" name="send_end" value="{{ $sendEnd ?? '' }}">
                    <input type="hidden" name="month" value="{{ $month ?? '' }}">
                    <input type="hidden" name="year" value="{{ $year ?? '' }}">
                    
                    <input type="hidden" name="tier" value="{{ $tier ?? '' }}">
                    <input type="hidden" name="vendor" value="{{ $vendor ?? '' }}">
                    <input type="hidden" name="data_provider" value="{{ $dataProvider ?? '' }}">
                    <input type="hidden" name="chart_period" value="{{ $chartPeriod ?? 'weekly' }}">
                    <div class="col-6 col-md-2">
                        <label class="form-label">Period</label>
                        <select name="chart_period" class="form-select">
                            <option value="weekly" @if(($chartPeriod ?? 'weekly') === 'weekly') selected @endif>Weekly</option>
                            <option value="monthly" @if(($chartPeriod ?? 'weekly') === 'monthly') selected @endif>Monthly</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label">Chart Start</label>
                        <input type="date" name="chart_start" value="{{ $chartStart ?? '' }}" class="form-control">
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label">Chart End</label>
                        <input type="date" name="chart_end" value="{{ $chartEnd ?? '' }}" class="form-control">
                    </div>
                    <div class="col-12 col-md-4 d-flex gap-2">
                        <button class="btn btn-outline-primary flex-fill" type="submit">Update Chart</button>
                        <button id="btnResetChart" type="button" class="btn btn-outline-danger flex-fill">Reset Chart</button>
                    </div>
                </form>
                <div class="drop-chart-wrapper" style="overflow-x:auto; overflow-y:hidden; border:1px solid #dee2e6; border-radius:4px; padding:8px; background:#fff; max-width:100%;">
                    <canvas id="dropChart"
                        height="500"
                        style="max-height:600px; min-width:100%; width:100%;"
                        data-series-count="{{ count($labels ?? []) }}"
                        data-labels='@json($labels ?? [])'
                        data-amount='@json($seriesAmount ?? [])'
                        data-cost='@json($seriesCost ?? [])'
                        data-calls='@json($seriesCalls ?? [])'
                        data-avgreps='@json($seriesAvgReps ?? [])'
                        data-response='@json($seriesResponse ?? [])'></canvas>
                </div>
                <div id="dropChartEmptyMsg" class="text-muted mt-2" style="display:none">No chart data available for the selected filters.</div>
                <small class="text-muted d-block mt-2">Left axis: Response Rate (%). Right axes: Amount ($) and Count.</small>
            </div>
        </div>
    </div>
</div>

@push('scripts_bottom')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
// Form reset handlers without inline JS
document.addEventListener('DOMContentLoaded', function(){
    var btnSum = document.getElementById('btnResetSummary');
    if (btnSum) {
        btnSum.addEventListener('click', function(){
            var f = document.getElementById('summaryFilters');
            if (!f) return;
            ['send_start','send_end','month','year','tier','vendor','data_provider'].forEach(function(n){
                var el = f.querySelector('[name="'+n+'"]');
                if (!el) return;
                if (el.tagName === 'SELECT') { el.selectedIndex = 0; } else { el.value = ''; }
            });
            f.submit();
        });
    }
    var btnChart = document.getElementById('btnResetChart');
    if (btnChart) {
        btnChart.addEventListener('click', function(){
            var f = btnChart.closest('form');
            if (!f) return;
            ['chart_start','chart_end'].forEach(function(n){ var el = f.querySelector('[name="'+n+'"]'); if (el) el.value=''; });
            f.submit();
        });
    }
});
</script>
<script>
(function(){
    const canvas = document.getElementById('dropChart');
    if (!canvas) return;
    const labels = JSON.parse(canvas.dataset.labels || '[]');
    const amount = JSON.parse(canvas.dataset.amount || '[]');
    const cost = JSON.parse(canvas.dataset.cost || '[]');
    const calls = JSON.parse(canvas.dataset.calls || '[]');
    const avgreps = JSON.parse(canvas.dataset.avgreps || '[]');
    const response = JSON.parse(canvas.dataset.response || '[]');
    
    if (labels.length === 0) {
        canvas.style.display = 'none';
        const noDataMsg = document.createElement('div');
        noDataMsg.className = 'alert alert-info text-center my-3';
        noDataMsg.textContent = 'No chart data available for the selected filters.';
        canvas.parentNode.insertBefore(noDataMsg, canvas);
        return;
    }
    
    const minWidthPerPoint = 80;
    const calculatedWidth = Math.max(canvas.parentElement.clientWidth, labels.length * minWidthPerPoint);
    canvas.style.width = calculatedWidth + 'px';
    canvas.width = calculatedWidth;

    const pricePerDrop = amount.map((v,i) => (v > 0 && cost[i] > 0) ? cost[i] / v : null);
    const costPerCall = amount.map((v,i) => (v > 0 && calls[i] > 0) ? cost[i] / calls[i] : null);
    const amountPerRep = amount.map((v,i) => (v > 0 && avgreps[i] > 0) ? v / avgreps[i] : null);
    const callsPerRep = calls.map((v,i) => (v > 0 && avgreps[i] > 0) ? v / avgreps[i] : null);

    new Chart(canvas, {
        type: 'line',
        data: {
            labels,
            datasets: [
                { label: 'Response Rate', data: response, borderColor: '#22c55e', backgroundColor: 'transparent', yAxisID: 'yPercent', tension: 0.2, borderWidth: 1.5, pointRadius: 1, fill: false },
                { label: 'Amount Dropped', data: amount, borderColor: '#3b82f6', backgroundColor: 'transparent', yAxisID: 'yMoney', tension: 0.2, borderWidth: 1.5, pointRadius: 1, fill: false },
                { label: 'Drop Cost', data: cost, borderColor: '#ef4444', backgroundColor: 'transparent', yAxisID: 'yMoney', tension: 0.2, borderWidth: 1.5, pointRadius: 1, fill: false },
                { label: 'Calls', data: calls, borderColor: '#a855f7', backgroundColor: 'transparent', yAxisID: 'yCount', tension: 0.2, borderWidth: 1.5, pointRadius: 1, fill: false },
                { label: 'Average Reps', data: avgreps, borderColor: '#f59e0b', backgroundColor: 'transparent', yAxisID: 'yCount', tension: 0.2, borderWidth: 1.5, pointRadius: 1, fill: false },
                { label: 'Price Per Drop', data: pricePerDrop, borderColor: '#06b6d4', backgroundColor: 'transparent', yAxisID: 'yMoney', tension: 0.2, borderWidth: 1.5, pointRadius: 1, fill: false, hidden: false },
                { label: 'Cost Per Call', data: costPerCall, borderColor: '#ec4899', backgroundColor: 'transparent', yAxisID: 'yMoney', tension: 0.2, borderWidth: 1.5, pointRadius: 1, fill: false, hidden: false },
                { label: 'Amount Per Rep', data: amountPerRep, borderColor: '#10b981', backgroundColor: 'transparent', yAxisID: 'yMoney', tension: 0.2, borderWidth: 1.5, pointRadius: 1, fill: false, hidden: false },
                { label: 'Calls Per Rep', data: callsPerRep, borderColor: '#8b5cf6', backgroundColor: 'transparent', yAxisID: 'yCount', tension: 0.2, borderWidth: 1.5, pointRadius: 1, fill: false, hidden: false }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'nearest', axis: 'x', intersect: false },
            stacked: false,
            scales: {
                yPercent: { type: 'linear', position: 'left', min: 0, max: 100, ticks: { callback: (v) => v + '%' }, title: { display: true, text: 'Percent' } },
                yMoney: { type: 'linear', position: 'right', grid: { drawOnChartArea: false }, ticks: { callback: (v) => '$' + new Intl.NumberFormat().format(v) }, title: { display: true, text: 'Amount' } },
                yCount: { type: 'linear', position: 'right', offset: true, grid: { drawOnChartArea: false }, title: { display: true, text: 'Count' } },
                x: { title: { display: true, text: 'Date' } }
            },
            plugins: {
                legend: { labels: { filter: (item) => true } },
                tooltip: {
                    position: 'nearest',
                    callbacks: {
                        label: (ctx) => {
                            const ds = ctx.dataset;
                            const val = ctx.parsed.y ?? 0;
                            if (ds.yAxisID === 'yPercent') return `${ds.label}: ${val.toFixed(2)}%`;
                            const moneyLabels = ['Amount Dropped','Drop Cost','Amount Per Rep','Price Per Drop','Cost Per Call'];
                            if (moneyLabels.includes(ds.label)) {
                                return `${ds.label}: $${new Intl.NumberFormat().format(Math.round(val))}`;
                            }
                            return `${ds.label}: ${new Intl.NumberFormat().format(Math.round(val))}`;
                        }
                    }
                }
            }
        }
    });
})();
</script>
@endpush
@endsection
