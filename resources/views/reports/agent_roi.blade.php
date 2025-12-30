@extends('layouts.app')

@section('content')
    @php
        $totalRecords = method_exists($reports, 'total') ? $reports->total() : count($reports);
        $perPageValue = (int) ($perPage ?? request('per_page', 25));
        $currentRange = $range ?? request('range');
        if (!$currentRange) {
            $currentRange = request('from') || request('to') ? 'custom' : 'all';
        }

        $isDateKey = static function (string $key, string $label): bool {
            $k = strtolower($key);
            $l = strtolower($label);
            return str_contains($k, 'date') || str_contains($l, 'date');
        };

        $isPercentKey = static function (string $key, string $label): bool {
            $k = strtolower($key);
            $l = strtolower($label);
            return str_contains($k, 'roi') || str_contains($k, 'rate') || str_contains($k, 'percent') || str_contains($k, 'pct')
                || str_contains($l, 'roi') || str_contains($l, 'rate') || str_contains($l, 'percent') || str_contains($l, 'pct');
        };

        $isCurrencyKey = static function (string $key, string $label) use ($isDateKey, $isPercentKey): bool {
            $k = strtolower($key);
            $l = strtolower($label);

            if ($isDateKey($key, $label) || $isPercentKey($key, $label)) return false;
            if (str_contains($k, 'id') || str_contains($k, 'number')) return false;

            return (bool) preg_match('/(cost|fee|revenue|commission|cpa|drop|amount|total)/', $k)
                || (bool) preg_match('/(cost|fee|revenue|commission|cpa|drop|amount|total)/', $l);
        };

        $fmtDate = static fn($v) => $v ? \Carbon\Carbon::parse($v)->format('m/d/Y') : '';
        $fmtMoney = static fn($v) => $v === null || $v === '' ? '' : ('$' . number_format((float) $v, 2, '.', ','));
        $fmtPercent = static function ($v): string {
            if ($v === null || $v === '') return '';
            $num = (float) $v;
            $pct = $num <= 1 ? ($num * 100) : $num;
            return number_format($pct, 2, '.', ',') . '%';
        };
        $fmtNumber = static fn($v) => $v === null || $v === '' ? '' : number_format((float) $v, 0, '.', ',');

        $summaryKeys = collect($summary ?? [])
            ->flatMap(fn($row) => array_keys($row ?? []))
            ->unique()
            ->values()
            ->all();
    @endphp

    <div class="card">
        <div class="card-header d-flex align-items-center py-0">
            <h6 class="py-3 mb-0">Agent ROI Report</h6>
        </div>
        <div class="card-body">
            <form method="get" action="{{ route('cmd.reports.agent_roi_report') }}" class="bg-light rounded-3 p-2 p-md-3 mb-3" id="agent-roi-form">
                <input type="hidden" name="range" id="range" value="{{ $currentRange }}">
                <input type="hidden" name="export" id="export" value="">

                <div class="row g-2 align-items-center">
                    <div class="cg-2 align-items-center justify-content-end">
                        <span class="fw-semibold">Agent ROI For :</span>
                    </div>

                    <div class="d-flex justify-content-between flex-wrap gap-2">
                        <div class="btn-group btn-group-sm gap-1" role="group" aria-label="Quick ranges">
                            @foreach (['all', 'today', '7', '30', 'this_month', 'last_month'] as $quick)
                                <button type="button" class="btn btn-outline-primary range-btn {{ $currentRange === $quick ? 'active' : '' }}" data-range="{{ $quick }}">
                                    @switch($quick)
                                        @case('all') All @break
                                        @case('today') Today @break
                                        @case('7') 7d @break
                                        @case('30') 30d @break
                                        @case('this_month') This Month @break
                                        @case('last_month') Last Month @break
                                    @endswitch
                                </button>
                            @endforeach
                        </div>
                        <button type="button" id="btn-export" class="btn btn-secondary btn-sm">Export CSV</button>
                    </div>

                    <div class="row g-2 align-items-center justify-content-end">
                        <div class="col-auto">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text">From</span>
                                <input type="date" name="from" id="from" value="{{ old('from', $from ?? request('from')) }}" class="form-control">
                            </div>
                        </div>
                        <div class="col-auto">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text">To</span>
                                <input type="date" name="to" id="to" value="{{ old('to', $to ?? request('to')) }}" class="form-control">
                            </div>
                        </div>
                    </div>
                </div>

                <details class="mt-2 mb-2">
                    <summary class="small fw-semibold">Advanced Filters</summary>

                    <div class="row g-2 mt-2">
                        <div class="col-md-3">
                            <label class="form-label form-label-sm">Agent (contains)</label>
                            <input type="text" name="agent" value="{{ request('agent') }}" class="form-control form-control-sm" autocomplete="off">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label form-label-sm">Tier (contains)</label>
                            <input type="text" name="tier" value="{{ request('tier') }}" class="form-control form-control-sm" autocomplete="off">
                        </div>
                    </div>
                </details>

                <div class="col-auto">
                    <div class="d-flex gap-2 justify-content-end">
                        <button type="submit" id="btn-filter" class="btn btn-primary btn-sm">Filter</button>
                        <a href="{{ route('cmd.reports.agent_roi_report') }}" class="btn btn-light btn-sm">Reset</a>
                        <div class="col-auto">
                            <select name="per_page" class="form-select form-select-sm">
                                @foreach ([25, 50, 100, 250, 500] as $n)
                                    <option value="{{ $n }}" {{ $perPageValue === $n ? 'selected' : '' }}>{{ $n }} / page</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>
            </form>

            <div class="d-flex justify-content-center align-items-center mb-2">
                <span class="border p-2 rounded small text-warning">
                    Results: {{ $totalRecords }} {{ $totalRecords === 1 ? 'Record' : 'Records' }}
                </span>
            </div>

            @php
                // Calculate summary totals
                $totalCalls = 0;
                $totalCostPerCall = 0;
                $totalDropCost = 0;
                $totalDeals = 0;
                $totalRevenueDeals = 0;
                $totalDebtAmount = 0;
                $totalRevenue = 0;
                $totalCommission = 0;
                foreach ($reports as $report) {
                    $totalCalls += (int) ($report->calls ?? 0);
                    $totalCostPerCall += (float) ($report->cost_per_call ?? 0);
                    $totalDropCost += (float) ($report->drop_cost ?? 0);
                    $totalDeals += (int) ($report->deals ?? 0);
                    $totalRevenueDeals += (int) ($report->revenue_deals ?? 0);
                    $totalDebtAmount += (float) ($report->debt_amount ?? 0);
                    $totalRevenue += (float) ($report->revenue ?? 0);
                    $totalCommission += (float) ($report->commission ?? 0);
                }
                $avgRoi = $totalDeals > 0 ? ($totalRevenueDeals / $totalDeals * 100) : 0;
                $avgCpa = $totalDeals > 0 ? ($totalRevenue / $totalDeals) : 0;
            @endphp

            <div class="table-responsive mb-3">
                <table class="table table-striped table-bordered align-middle">
                    <thead class="table-light">
                        <tr>
                            @foreach ($columns as $label)
                                <th>{{ $label }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="table-warning fw-bold">
                            <td>TOTAL</td>
                            <td>-</td>
                            <td class="text-end">{{ $fmtNumber($totalCalls) }}</td>
                            <td class="text-end">{{ $fmtMoney($totalCostPerCall) }}</td>
                            <td class="text-end">{{ $fmtMoney($totalDropCost) }}</td>
                            <td class="text-end">{{ $fmtNumber($totalDeals) }}</td>
                            <td class="text-end">{{ $fmtNumber($totalRevenueDeals) }}</td>
                            <td class="text-end">{{ $fmtMoney($totalDebtAmount) }}</td>
                            <td class="text-end">{{ $fmtMoney($totalDebtAmount / max(1, count($reports))) }}</td>
                            <td class="text-end">{{ $fmtMoney($totalRevenue) }}</td>
                            <td class="text-end">{{ $fmtMoney($totalCommission) }}</td>
                            <td class="text-end">{{ $fmtMoney($avgCpa) }}</td>
                            <td class="text-end">{{ number_format($avgRoi, 2) }}%</td>
                        </tr>
                        @forelse ($reports as $report)
                            <tr>
                                @foreach ($columns as $key => $label)
                                    @php $value = $report->{$key} ?? null; @endphp
                                    @if ($isDateKey($key, $label))
                                        <td>{{ $fmtDate($value) }}</td>
                                    @elseif ($isPercentKey($key, $label))
                                        <td class="text-end">{{ $fmtPercent($value) }}</td>
                                    @elseif ($isCurrencyKey($key, $label))
                                        <td class="text-end">{{ $fmtMoney($value) }}</td>
                                    @elseif (is_numeric($value))
                                        <td class="text-end">{{ $fmtNumber($value) }}</td>
                                    @else
                                        <td>{{ $value ?? '' }}</td>
                                    @endif
                                @endforeach
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ max(1, count($columns)) }}" class="text-center text-muted">No records found.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if (!empty($summary) && !empty($summaryKeys))
                <div class="card mb-3">
                    <div class="card-header py-2">
                        <strong>Summary (High / Average / Low)</strong>
                    </div>
                    <div class="card-body p-2 p-md-3">
                        <div class="table-responsive">
                            <table class="table table-bordered table-sm align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th class="text-nowrap">&nbsp;</th>
                                        @foreach ($summaryKeys as $key)
                                            <th class="text-nowrap">{{ ucwords(str_replace('_', ' ', $key)) }}</th>
                                        @endforeach
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach (['high' => 'High', 'average' => 'Average', 'low' => 'Low'] as $rowKey => $label)
                                        <tr>
                                            <th class="text-nowrap">{{ $label }}</th>
                                            @foreach ($summaryKeys as $key)
                                                @php
                                                    $val = $summary[$rowKey][$key] ?? null;
                                                    $out = '';
                                                    if ($val !== null) {
                                                        if ($isPercentKey($key, $key)) {
                                                            $out = $fmtPercent($val);
                                                        } elseif ($isCurrencyKey($key, $key) || in_array($key, ['cpa', 'revenue', 'commission', 'drop_cost', 'cost_per_call'], true)) {
                                                            $out = $fmtMoney($val);
                                                        } elseif (is_numeric($val)) {
                                                            $out = $fmtNumber($val);
                                                        } else {
                                                            $out = $val;
                                                        }
                                                    }
                                                @endphp
                                                <td class="text-end">{{ $out }}</td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            @endif

            @if (method_exists($reports, 'links'))
                <div class="d-flex justify-content-center mt-3">
                    {{ $reports->appends(request()->query())->links() }}
                </div>
            @endif
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('agent-roi-form');
            if (!form) return;

            const fromInput = document.getElementById('from');
            const toInput = document.getElementById('to');
            const rangeInput = document.getElementById('range');
            const exportInput = document.getElementById('export');

            document.querySelectorAll('.range-btn').forEach(btn => {
                btn.addEventListener('click', () => {
                    if (rangeInput) rangeInput.value = btn.dataset.range || 'all';
                    if (fromInput) fromInput.value = '';
                    if (toInput) toInput.value = '';
                    form.submit();
                });
            });

            if (fromInput) {
                fromInput.addEventListener('change', () => {
                    if (rangeInput) rangeInput.value = 'custom';
                });
            }
            if (toInput) {
                toInput.addEventListener('change', () => {
                    if (rangeInput) rangeInput.value = 'custom';
                });
            }

            const exportButton = document.getElementById('btn-export');
            if (exportButton) {
                exportButton.addEventListener('click', () => {
                    if (exportInput) exportInput.value = 'csv';
                    form.submit();
                    setTimeout(() => {
                        if (exportInput) exportInput.value = '';
                    }, 0);
                });
            }
        });
    </script>
@endpush
