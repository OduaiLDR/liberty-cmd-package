@extends('layouts.app')

@section('content')
    @php
        $totalRecords = method_exists($reports, 'total') ? $reports->total() : count($reports);
        $perPageValue = (int) ($perPage ?? request('per_page', 25));
        $currentRange = $range ?? request('range');
        if (!$currentRange) {
            $currentRange = request('from') || request('to') ? 'custom' : 'all';
        }

        $isDateKey = static fn(string $key): bool => str_contains(strtolower($key), 'date');
        $isPercentKey = static function (string $key): bool {
            $k = strtolower($key);
            return str_contains($k, 'rate') || str_contains($k, 'percent') || str_contains($k, 'pct');
        };
        $isCurrencyKey = static function (string $key) use ($isPercentKey, $isDateKey): bool {
            $k = strtolower($key);
            if ($isDateKey($key) || $isPercentKey($key)) return false;
            if ($k === 'llg_id' || str_contains($k, 'number') || str_contains($k, 'id')) return false;
            return (bool) preg_match('/(amount|payment|debt|settlement|epf)/', $k);
        };

        $fmtDate = static fn($v) => $v ? \Carbon\Carbon::parse($v)->format('m/d/Y') : '';
        $fmtMoney = static fn($v) => $v === null || $v === '' ? '' : ('$' . number_format((float) $v, 2, '.', ','));
        $fmtPercent = static function ($v): string {
            if ($v === null || $v === '') return '';
            $num = (float) $v;
            $pct = $num <= 1 ? ($num * 100) : $num;
            return number_format($pct, 2, '.', ',') . '%';
        };
    @endphp

    <div class="card">
        <div class="card-header d-flex align-items-center py-0">
            <h6 class="py-3 mb-0">EPF Due</h6>
        </div>
        <div class="card-body">
            <form method="get" action="{{ route('cmd.reports.epf_due_report') }}" class="bg-light rounded-3 p-2 p-md-3 mb-3" id="epf-due-form">
                <input type="hidden" name="range" id="range" value="{{ $currentRange }}">
                <input type="hidden" name="export" id="export" value="">

                <div class="row g-2 align-items-center">
                    <div class="cg-2 align-items-center justify-content-end">
                        <span class="fw-semibold">EPF Due For :</span>
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
                        <div class="col-md-2">
                            <label class="form-label form-label-sm">LLG ID</label>
                            <input type="text" name="llg_id" value="{{ request('llg_id') }}" class="form-control form-control-sm" autocomplete="off">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label form-label-sm">State</label>
                            <input type="text" name="state" value="{{ request('state') }}" class="form-control form-control-sm" autocomplete="off">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label form-label-sm">Tranche</label>
                            <input type="text" name="tranche" value="{{ request('tranche') }}" class="form-control form-control-sm" autocomplete="off">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label form-label-sm">Creditor</label>
                            <input type="text" name="creditor" value="{{ request('creditor') }}" class="form-control form-control-sm" autocomplete="off">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label form-label-sm">Settlement ID</label>
                            <input type="text" name="settlement_id" value="{{ request('settlement_id') }}" class="form-control form-control-sm" autocomplete="off">
                        </div>
                    </div>

                    <div class="row g-2 mt-2">
                        <div class="col-md-3">
                            <label class="form-label form-label-sm">Payment Number</label>
                            <input type="text" name="payment_number" value="{{ request('payment_number') }}" class="form-control form-control-sm" autocomplete="off">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label form-label-sm">Confirmation</label>
                            <input type="text" name="confirmation" value="{{ request('confirmation') }}" class="form-control form-control-sm" autocomplete="off">
                        </div>
                    </div>
                </details>

                <div class="col-auto">
                    <div class="d-flex gap-2 justify-content-end">
                        <button type="submit" id="btn-filter" class="btn btn-primary btn-sm">Filter</button>
                        <a href="{{ route('cmd.reports.epf_due_report') }}" class="btn btn-light btn-sm">Reset</a>
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

            <div class="table-responsive">
                <table class="table table-striped table-bordered align-middle">
                    <thead class="table-light">
                        <tr>
                            @foreach ($columns as $label)
                                <th>{{ $label }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($reports as $report)
                            <tr>
                                @foreach (array_keys($columns) as $key)
                                    @php $value = $report->{$key} ?? null; @endphp
                                    @if ($isDateKey($key))
                                        <td>{{ $fmtDate($value) }}</td>
                                    @elseif ($isPercentKey($key))
                                        <td class="text-end">{{ $fmtPercent($value) }}</td>
                                    @elseif ($isCurrencyKey($key))
                                        <td class="text-end">{{ $fmtMoney($value) }}</td>
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
            const form = document.getElementById('epf-due-form');
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
