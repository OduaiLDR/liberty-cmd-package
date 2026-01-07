@extends('layouts.app')

@section('content')
    @php
        $currentRange = $range ?? request('range');
        if (!$currentRange) {
            $currentRange = request('from') || request('to') ? 'custom' : 'all';
        }
        $fmtNumber = static fn($v) => $v === null || $v === '' ? '' : number_format((float) $v, 0, '.', ',');
        $totalLeads = $summary['Total'] ?? 0;
    @endphp

    <div class="card shadow-lg border-0 mb-4">
        <div class="card-header bg-gradient" style="background: linear-gradient(135deg, #1e3a5f 0%, #2d5a87 50%, #3d7ab5 100%);">
            <div class="d-flex align-items-center justify-content-between text-white">
                <div>
                    <h5 class="mb-0 fw-bold"><i class="fas fa-chart-bar me-2"></i>Lead Summary Report</h5>
                    <small class="opacity-75">Hourly lead distribution by data source</small>
                </div>
                <div class="badge bg-light text-dark px-3 py-2 fs-6">
                    {{ $fmtNumber($totalLeads) }} Total Leads
                </div>
            </div>
        </div>

        <div class="card-body">
            <form method="get" action="{{ route('cmd.reports.lead_summary_report') }}" id="lead-summary-form" class="p-3 rounded-3 bg-light border mb-4">
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
                    <div class="col-md-2">
                        <label class="form-label small text-muted mb-1">Debt Tier</label>
                        <select name="debt_tier" class="form-select form-select-sm">
                            <option value="">All Tiers</option>
                            <option value="1" {{ ($filters['debt_tier'] ?? '') === '1' ? 'selected' : '' }}>Tier 1 ($0-$12K)</option>
                            <option value="2" {{ ($filters['debt_tier'] ?? '') === '2' ? 'selected' : '' }}>Tier 2 ($12K-$15K)</option>
                            <option value="3" {{ ($filters['debt_tier'] ?? '') === '3' ? 'selected' : '' }}>Tier 3 ($15K-$19K)</option>
                            <option value="4" {{ ($filters['debt_tier'] ?? '') === '4' ? 'selected' : '' }}>Tier 4 ($19K-$26K)</option>
                            <option value="5" {{ ($filters['debt_tier'] ?? '') === '5' ? 'selected' : '' }}>Tier 5 ($26K-$35K)</option>
                            <option value="6" {{ ($filters['debt_tier'] ?? '') === '6' ? 'selected' : '' }}>Tier 6 ($35K-$50K)</option>
                            <option value="7" {{ ($filters['debt_tier'] ?? '') === '7' ? 'selected' : '' }}>Tier 7 ($50K-$65K)</option>
                            <option value="8" {{ ($filters['debt_tier'] ?? '') === '8' ? 'selected' : '' }}>Tier 8 ($65K-$80K)</option>
                            <option value="9" {{ ($filters['debt_tier'] ?? '') === '9' ? 'selected' : '' }}>Tier 9 ($80K+)</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <div class="d-flex gap-2 flex-wrap">
                            <div class="btn-group btn-group-sm" role="group">
                                @foreach (['7' => '7d', '30' => '30d', 'this_month' => 'This Mo', 'last_month' => 'Last Mo', 'all' => 'All'] as $val => $label)
                                    <button type="button" class="btn btn-outline-primary range-btn {{ $currentRange === (string)$val ? 'active' : '' }}" data-range="{{ $val }}">{{ $label }}</button>
                                @endforeach
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-filter me-1"></i> Filter</button>
                            <a href="{{ route('cmd.reports.lead_summary_report') }}" class="btn btn-light btn-sm border"><i class="fas fa-undo me-1"></i> Reset</a>
                            <button type="button" id="btn-export" class="btn btn-success btn-sm"><i class="fas fa-file-csv me-1"></i> CSV</button>
                        </div>
                    </div>
                </div>
            </form>

            <div class="table-responsive">
                <table class="table table-sm table-hover table-bordered align-middle mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th class="text-nowrap">Hour Bucket</th>
                            <th class="text-nowrap text-end">Call Center</th>
                            <th class="text-nowrap text-end">Apply Online</th>
                            <th class="text-nowrap text-end">Manual Entry</th>
                            <th class="text-nowrap text-end">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @if (!empty($hourlyData))
                            <tr class="table-warning fw-bold">
                                <td class="text-center">TOTALS</td>
                                <td class="text-end">{{ $fmtNumber($summary['Call Center'] ?? 0) }}</td>
                                <td class="text-end">{{ $fmtNumber($summary['Apply Online'] ?? 0) }}</td>
                                <td class="text-end">{{ $fmtNumber($summary['Manual Entry'] ?? 0) }}</td>
                                <td class="text-end">{{ $fmtNumber($totalLeads) }}</td>
                            </tr>
                        @endif
                        @forelse ($hourlyData as $bucket => $sources)
                            @php $rowTotal = array_sum($sources); @endphp
                            <tr>
                                <td class="text-nowrap fw-medium">{{ $bucket }}</td>
                                <td class="text-end">{{ $fmtNumber($sources['Call Center'] ?? 0) }}</td>
                                <td class="text-end">{{ $fmtNumber($sources['Apply Online'] ?? 0) }}</td>
                                <td class="text-end">{{ $fmtNumber($sources['Manual Entry'] ?? 0) }}</td>
                                <td class="text-end fw-bold">{{ $fmtNumber($rowTotal) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-center text-muted py-4">
                                    <i class="fas fa-search fa-2x mb-2"></i><br>
                                    No data found for the selected criteria.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="card shadow-sm my-4">
                <div class="card-header bg-white py-3">
                    <h6 class="mb-0 fw-bold text-primary"><i class="fas fa-chart-bar me-2"></i>Leads by Hour (PST)</h6>
                </div>
                <div class="card-body">
                    <canvas id="leadSummaryChart" height="100"></canvas>
                </div>
            </div>

            <div class="row g-4 mb-2">
                <div class="col-md-3">
                    <div class="card border-0 shadow-sm h-100" style="background: linear-gradient(135deg, rgba(54, 162, 235, 0.1), rgba(54, 162, 235, 0.2));">
                        <div class="card-body text-center">
                            <h6 class="text-muted mb-1">Call Center</h6>
                            <h2 class="mb-0 fw-bold" style="color: rgba(54, 162, 235, 1);">{{ $fmtNumber($summary['Call Center'] ?? 0) }}</h2>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card border-0 shadow-sm h-100" style="background: linear-gradient(135deg, rgba(75, 192, 92, 0.1), rgba(75, 192, 92, 0.2));">
                        <div class="card-body text-center">
                            <h6 class="text-muted mb-1">Apply Online</h6>
                            <h2 class="mb-0 fw-bold" style="color: rgba(75, 192, 92, 1);">{{ $fmtNumber($summary['Apply Online'] ?? 0) }}</h2>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card border-0 shadow-sm h-100" style="background: linear-gradient(135deg, rgba(255, 206, 86, 0.1), rgba(255, 206, 86, 0.2));">
                        <div class="card-body text-center">
                            <h6 class="text-muted mb-1">Manual Entry</h6>
                            <h2 class="mb-0 fw-bold" style="color: rgba(200, 160, 50, 1);">{{ $fmtNumber($summary['Manual Entry'] ?? 0) }}</h2>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card border-0 shadow-sm h-100 bg-dark text-white">
                        <div class="card-body text-center">
                            <h6 class="text-white-50 mb-1">Total</h6>
                            <h2 class="mb-0 fw-bold">{{ $fmtNumber($totalLeads) }}</h2>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const form = document.getElementById('lead-summary-form');
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

            const chartData = @json($chartData);
            new Chart(document.getElementById('leadSummaryChart'), {
                type: 'bar',
                data: chartData,
                options: {
                    responsive: true,
                    plugins: {
                        legend: { position: 'bottom' },
                        tooltip: { mode: 'index', intersect: false }
                    },
                    scales: {
                        x: { stacked: false, title: { display: true, text: 'Hour (PST)' } },
                        y: { beginAtZero: true, title: { display: true, text: 'Lead Count' } }
                    }
                }
            });
        });
    </script>
@endpush
