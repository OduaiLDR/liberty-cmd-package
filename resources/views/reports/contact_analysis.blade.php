@extends('layouts.app')

@section('content')
    @php
        $totalRecords = method_exists($reports, 'total') ? $reports->total() : count($reports);
        $currentRange = $range ?? request('range');
        if (!$currentRange) {
            $currentRange = request('from') || request('to') ? 'custom' : 'all';
        }

        $fmtDate = static fn($v) => $v ? \Carbon\Carbon::parse($v)->format('n/j/Y') : '';
        $fmtMoney = static fn($v) => $v === null || $v === '' ? '' : '$' . number_format((float) $v, 2, '.', ',');
        $fmtPercent = static fn($v) => $v === null || $v === '' ? '' : number_format((float) $v * 100, 2) . '%';
        $fmtNumber = static fn($v) => $v === null || $v === '' ? '' : number_format((float) $v, 0, '.', ',');

        $pageFrom = method_exists($reports, 'firstItem') ? $reports->firstItem() : 1;
        $pageTo = method_exists($reports, 'lastItem') ? $reports->lastItem() : $totalRecords;
    @endphp

    <div class="card shadow-lg border-0 mb-4">
        <div class="card-header bg-gradient" style="background: linear-gradient(135deg, #1e3a5f 0%, #2d5a87 50%, #3d7ab5 100%);">
            <div class="d-flex align-items-center justify-content-between text-white">
                <div>
                    <h5 class="mb-0 fw-bold"><i class="fas fa-chart-line me-2"></i>Contact Analysis Report</h5>
                    <small class="opacity-75">Weekly aggregated contact and enrollment data with trend analysis</small>
                </div>
                <div class="badge bg-light text-dark px-3 py-2 fs-6">
                    {{ $totalRecords }} {{ $totalRecords === 1 ? 'Week' : 'Weeks' }}
                </div>
            </div>
        </div>

        <div class="card-body">
            <form method="get" action="{{ route('cmd.reports.contact_analysis_report') }}" id="contact-analysis-form" class="p-3 rounded-3 bg-light border mb-4">
                <input type="hidden" name="range" id="range" value="{{ $currentRange }}">
                <input type="hidden" name="export" id="export" value="">

                <div class="row g-3 align-items-end">
                    <div class="col-md-2">
                        <label class="form-label small text-muted mb-1">Start Date</label>
                        <input type="date" name="from" id="from" value="{{ $from ?? '' }}" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small text-muted mb-1">End Date</label>
                        <input type="date" name="to" id="to" value="{{ $to ?? '' }}" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small text-muted mb-1">Data Source</label>
                        <select name="data_source" class="form-select form-select-sm">
                            <option value="">All Data Sources</option>
                            @foreach ($dataSources as $source)
                                <option value="{{ $source }}" {{ ($filters['data_source'] ?? '') === $source ? 'selected' : '' }}>{{ $source }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-5">
                        <div class="d-flex gap-2 flex-wrap">
                            <div class="btn-group btn-group-sm" role="group">
                                @foreach (['30' => '30d', '60' => '60d', '90' => '90d', 'this_quarter' => 'This Qtr', 'last_quarter' => 'Last Qtr', 'all' => 'All'] as $val => $label)
                                    <button type="button" class="btn btn-outline-primary range-btn {{ $currentRange === (string)$val ? 'active' : '' }}" data-range="{{ $val }}">{{ $label }}</button>
                                @endforeach
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-filter me-1"></i> Filter</button>
                            <a href="{{ route('cmd.reports.contact_analysis_report') }}" class="btn btn-light btn-sm border"><i class="fas fa-undo me-1"></i> Reset</a>
                            <button type="button" id="btn-export" class="btn btn-success btn-sm"><i class="fas fa-file-csv me-1"></i> CSV</button>
                        </div>
                    </div>
                </div>

            </form>

            <div class="table-responsive mb-3">
                <table class="table table-sm table-hover table-bordered align-middle mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th class="text-nowrap">Start Date</th>
                            <th class="text-nowrap">End Date</th>
                            <th class="text-nowrap text-end">Contacts</th>
                            <th class="text-nowrap text-end">Enrolled Debt</th>
                            <th class="text-nowrap text-end">WCC</th>
                            <th class="text-nowrap text-end">Cancels</th>
                            <th class="text-nowrap text-end">NSFs</th>
                            <th class="text-nowrap text-end">Active Deals</th>
                            <th class="text-nowrap text-end">Avg Debt</th>
                            <th class="text-nowrap">Mail Date</th>
                            <th class="text-nowrap text-end">Mailers</th>
                            <th class="text-nowrap text-end">Response Rate</th>
                        </tr>
                    </thead>
                    <tbody>
                        @if ($totalRecords > 0)
                            <tr class="table-warning fw-bold">
                                <td colspan="2" class="text-center">TOTALS</td>
                                <td class="text-end">{{ $fmtNumber($totals['contacts']) }}</td>
                                <td class="text-end">{{ $fmtMoney($totals['enrolled_debt']) }}</td>
                                <td class="text-end">{{ $fmtNumber($totals['wcc']) }}</td>
                                <td class="text-end">{{ $fmtNumber($totals['cancels']) }}</td>
                                <td class="text-end">{{ $fmtNumber($totals['nsfs']) }}</td>
                                <td class="text-end">{{ $fmtNumber($totals['active_deals']) }}</td>
                                <td class="text-end">{{ $fmtMoney($totals['avg_debt']) }}</td>
                                <td>-</td>
                                <td class="text-end">{{ $fmtNumber($totals['mailers']) }}</td>
                                <td class="text-end">{{ $fmtPercent($totals['response_rate']) }}</td>
                            </tr>
                        @endif
                        @forelse ($reports as $row)
                            <tr>
                                <td class="text-nowrap">{{ $fmtDate($row->start_date) }}</td>
                                <td class="text-nowrap">{{ $fmtDate($row->end_date) }}</td>
                                <td class="text-end">{{ $fmtNumber($row->contacts) }}</td>
                                <td class="text-end">{{ $fmtMoney($row->enrolled_debt) }}</td>
                                <td class="text-end">{{ $fmtNumber($row->wcc) }}</td>
                                <td class="text-end">{{ $fmtNumber($row->cancels) }}</td>
                                <td class="text-end">{{ $fmtNumber($row->nsfs) }}</td>
                                <td class="text-end">{{ $fmtNumber($row->active_deals) }}</td>
                                <td class="text-end">{{ $fmtMoney($row->avg_debt) }}</td>
                                <td class="text-nowrap">{{ $fmtDate($row->mail_date) }}</td>
                                <td class="text-end">{{ $fmtNumber($row->mailers) }}</td>
                                <td class="text-end">{{ $fmtPercent($row->response_rate) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="12" class="text-center text-muted py-4">
                                    <i class="fas fa-search fa-2x mb-2"></i><br>
                                    No records found for the selected criteria.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if (method_exists($reports, 'links'))
                <div class="d-flex justify-content-between flex-wrap gap-2 align-items-center mb-4">
                    <div class="text-muted small">
                        Showing {{ $pageFrom ?? 0 }} to {{ $pageTo ?? 0 }} of {{ $totalRecords }} weeks
                    </div>
                    {{ $reports->appends(request()->query())->links() }}
                </div>
            @endif

            @if ($totalRecords > 0)
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-body p-3">
                        <form method="get" action="{{ route('cmd.reports.contact_analysis_report') }}" class="row g-3 align-items-end">
                            <input type="hidden" name="range" value="{{ $currentRange }}">
                            <input type="hidden" name="from" value="{{ $from }}">
                            <input type="hidden" name="to" value="{{ $to }}">
                            <input type="hidden" name="data_source" value="{{ $filters['data_source'] ?? '' }}">
                            <input type="hidden" name="per_page" value="{{ request('per_page', 15) }}">
                            <div class="col-md-2">
                                <label class="form-label small text-muted mb-1">Chart Year</label>
                                <input type="number" name="chart_year" value="{{ $chartFilters['chart_year'] ?? '' }}" class="form-control form-control-sm" placeholder="e.g. 2025">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small text-muted mb-1">Chart Month</label>
                                <select name="chart_month" class="form-select form-select-sm">
                                    <option value="">All Months</option>
                                    @foreach ([
                                        1=>'Jan',2=>'Feb',3=>'Mar',4=>'Apr',5=>'May',6=>'Jun',
                                        7=>'Jul',8=>'Aug',9=>'Sep',10=>'Oct',11=>'Nov',12=>'Dec'
                                    ] as $m => $label)
                                        <option value="{{ $m }}" {{ (string)($chartFilters['chart_month'] ?? '') === (string)$m ? 'selected' : '' }}>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small text-muted mb-1">Chart Min Debt</label>
                                <input type="number" step="0.01" name="chart_min_debt" value="{{ $chartFilters['chart_min_debt'] ?? '' }}" class="form-control form-control-sm" placeholder="0">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small text-muted mb-1">Chart Max Debt</label>
                                <input type="number" step="0.01" name="chart_max_debt" value="{{ $chartFilters['chart_max_debt'] ?? '' }}" class="form-control form-control-sm" placeholder="100000">
                            </div>
                            <div class="col-md-4 d-flex align-items-end gap-2">
                                <button type="submit" class="btn btn-info btn-sm text-white"><i class="fas fa-chart-line me-1"></i> Apply Chart Filters</button>
                                <a href="{{ route('cmd.reports.contact_analysis_report', array_merge(request()->except(['chart_year','chart_month','chart_min_debt','chart_max_debt']), [])) }}" class="btn btn-light btn-sm border">Reset Charts</a>
                                <span class="text-muted small ms-2">Charts & totals honor these filters; table uses date/data source above.</span>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="row g-4">
                    <div class="col-12">
                        <div class="card shadow-sm">
                            <div class="card-header bg-white py-3">
                                <h6 class="mb-0 fw-bold text-primary"><i class="fas fa-users me-2"></i>Contacts</h6>
                            </div>
                            <div class="card-body">
                                <canvas id="contactsChart" height="120"></canvas>
                            </div>
                        </div>
                    </div>

                    <div class="col-12">
                        <div class="card shadow-sm">
                            <div class="card-header bg-white py-3">
                                <h6 class="mb-0 fw-bold text-success"><i class="fas fa-dollar-sign me-2"></i>Enrolled Debt</h6>
                            </div>
                            <div class="card-body">
                                <canvas id="enrolledDebtChart" height="120"></canvas>
                            </div>
                        </div>
                    </div>

                    <div class="col-12">
                        <div class="card shadow-sm">
                            <div class="card-header bg-white py-3">
                                <h6 class="mb-0 fw-bold text-info"><i class="fas fa-percentage me-2"></i>Response Rate</h6>
                            </div>
                            <div class="card-body">
                                <canvas id="responseRateChart" height="120"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </div>
@endsection

@push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const form = document.getElementById('contact-analysis-form');
            const rangeInput = document.getElementById('range');
            const fromInput = document.getElementById('from');
            const toInput = document.getElementById('to');
            const exportInput = document.getElementById('export');

            document.querySelectorAll('.range-btn').forEach(btn => {
                btn.addEventListener('click', () => {
                    if (rangeInput) rangeInput.value = btn.dataset.range || 'all';
                    if (fromInput) fromInput.value = '';
                    if (toInput) toInput.value = '';
                    form.submit();
                });
            });

            if (fromInput) fromInput.addEventListener('change', () => { if (rangeInput) rangeInput.value = 'custom'; });
            if (toInput) toInput.addEventListener('change', () => { if (rangeInput) rangeInput.value = 'custom'; });

            const exportBtn = document.getElementById('btn-export');
            if (exportBtn) {
                exportBtn.addEventListener('click', () => {
                    if (exportInput) exportInput.value = 'csv';
                    form.submit();
                    setTimeout(() => { if (exportInput) exportInput.value = ''; }, 0);
                });
            }

            @if ($totalRecords > 0)
                const contactsData = @json($contactsChart);
                const enrolledDebtData = @json($enrolledDebtChart);
                const responseRateData = @json($responseRateChart);

                new Chart(document.getElementById('contactsChart'), {
                    type: 'line',
                    data: contactsData,
                    options: {
                        responsive: true,
                        plugins: { legend: { position: 'bottom' } },
                        scales: {
                            y: { beginAtZero: true, title: { display: true, text: 'Contacts' } },
                            x: { title: { display: false } }
                        },
                        elements: { line: { tension: 0.3 } }
                    }
                });

                new Chart(document.getElementById('enrolledDebtChart'), {
                    type: 'line',
                    data: enrolledDebtData,
                    options: {
                        responsive: true,
                        plugins: { legend: { position: 'bottom' } },
                        scales: {
                            y: { beginAtZero: true, position: 'left', title: { display: true, text: 'Enrolled Debt ($)' } },
                            y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, title: { display: true, text: 'Mailers' } },
                            x: { title: { display: false } }
                        },
                        elements: { line: { tension: 0.3 } }
                    }
                });

                new Chart(document.getElementById('responseRateChart'), {
                    type: 'line',
                    data: responseRateData,
                    options: {
                        responsive: true,
                        plugins: { legend: { position: 'bottom' } },
                        scales: {
                            y: { beginAtZero: true, position: 'left', title: { display: true, text: 'Response Rate (%)' }, max: 100 },
                            y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, title: { display: true, text: 'Mailers' } },
                            x: { title: { display: false } }
                        },
                        elements: { line: { tension: 0.3 } }
                    }
                });
            @endif
        });
    </script>
@endpush
