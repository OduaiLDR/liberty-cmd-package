@extends('layouts.app')

@section('content')
    @php
        $totalRecords = method_exists($reports, 'total') ? $reports->total() : count($reports);
        $perPageValue = (int) ($perPage ?? request('per_page', 25));
        $currentRange = $range ?? request('range');
        if (!$currentRange) {
            $currentRange = request('from') || request('to') ? 'custom' : 'all';
        }

        $isCurrencyKey = static function (string $key): bool {
            $k = strtolower($key);
            return $k === 'amount' || str_contains($k, 'amount') || str_contains($k, 'cost');
        };

        // JOD currency format
        $fmtMoney = static fn($v) => $v === null || $v === '' ? '' : ('JOD ' . number_format((float) $v, 2, '.', ','));
        $fmtDate = static fn($v) => $v ? \Carbon\Carbon::parse($v)->format('m/d/Y') : '';
    @endphp

    <div class="card">
        <div class="card-header d-flex align-items-center py-0">
            <h6 class="py-3 mb-0">Jordan Expenses</h6>
        </div>
        <div class="card-body">
            <form method="get" action="{{ route('cmd.reports.jordan_expenses_report') }}" class="bg-light rounded-3 p-2 p-md-3 mb-3" id="jordan-expenses-form">
                <input type="hidden" name="range" id="range" value="{{ $currentRange }}">
                <input type="hidden" name="export" id="export" value="">

                <div class="row g-2 align-items-center">
                    <div class="cg-2 align-items-center justify-content-end">
                        <span class="fw-semibold">Jordan Expenses For :</span>
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
                            <label class="form-label form-label-sm">Category (contains)</label>
                            <input type="text" name="category" value="{{ request('category') }}" class="form-control form-control-sm" autocomplete="off">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label form-label-sm">Company (contains)</label>
                            <input type="text" name="company" value="{{ request('company') }}" class="form-control form-control-sm" autocomplete="off">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label form-label-sm">Description (contains)</label>
                            <input type="text" name="description" value="{{ request('description') }}" class="form-control form-control-sm" autocomplete="off">
                        </div>
                    </div>
                </details>

                <div class="col-auto">
                    <div class="d-flex gap-2 justify-content-end">
                        <button type="submit" id="btn-filter" class="btn btn-primary btn-sm">Filter</button>
                        <a href="{{ route('cmd.reports.jordan_expenses_report') }}" class="btn btn-light btn-sm">Reset</a>
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

            @php
                // Calculate total amount for summary from current page data
                $pageTotal = 0;
                foreach ($reports as $report) {
                    $pageTotal += (float) ($report->amount ?? 0);
                }
                // Use totalAmount from controller if available, otherwise use page total
                $displayTotal = $totalAmount ?? $pageTotal;
            @endphp

            <div class="alert alert-warning mb-3">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <strong>Total Summary</strong>
                        <span class="ms-3">Records: {{ $totalRecords }}</span>
                    </div>
                    <div class="fs-5 fw-bold">
                        Total Amount: {{ $fmtMoney($displayTotal) }}
                    </div>
                </div>
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
                                    @if (str_contains(strtolower($key), 'date') || strtolower($key) === 'date')
                                        <td>{{ $fmtDate($value) }}</td>
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
            const form = document.getElementById('jordan-expenses-form');
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
