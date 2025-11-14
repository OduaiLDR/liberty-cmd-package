@use('Illuminate\Support\Str')
@extends('layouts.app')

@section('content')
    <div class="card">
        <div class="card-header d-flex align-items-center py-0">
            <h6 class="py-3 mb-0">List of Cancel Orders</h6>
            <div class="ms-auto my-auto">
                {{-- Intentionally left for future "New" actions --}}
            </div>
        </div>
        <div class="card-body">
            <form method="get" action="{{ route('cmd.reports.cancel_report') }}" class="bg-light rounded-3 p-2 p-md-3 mb-3"
                id="cancel-report-form">
                <input type="hidden" name="range" id="range" value="{{ request('range', $range) }}">
                <input type="hidden" name="export" id="export" value="">
                <div class="row g-2 align-items-center">
                    <div class="cg-2 align-items-center justify-content-end">
                        <span class="fw-semibold">Cancel Orders For :</span>
                    </div>

                    <div class="d-flex justify-content-between flex-wrap gap-2">
                        <div class="btn-group btn-group-sm gap-1" role="group" aria-label="Quick ranges">
                            @php
                                $ranges = ['all', 'today', '7', '30', 'this_month', 'last_month'];
                            @endphp
                            @foreach ($ranges as $value)
                                <button type="button" class="btn btn-outline-primary range-btn"
                                    data-range="{{ $value }}">@switch($value)
                                        @case('all')
                                            All
                                        @break
                                        @case('today')
                                            Today
                                        @break
                                        @case('7')
                                            7d
                                        @break
                                        @case('30')
                                            30d
                                        @break
                                        @case('this_month')
                                            This Month
                                        @break
                                        @case('last_month')
                                            Last Month
                                        @break
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
                                <input type="date" name="from" id="from" value="{{ old('from', $from ?? request('from')) }}"
                                    class="form-control">
                            </div>
                        </div>
                        <div class="col-auto">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text">To</span>
                                <input type="date" name="to" id="to" value="{{ old('to', $to ?? request('to')) }}"
                                    class="form-control">
                            </div>
                        </div>
                    </div>
                </div>

                <details class="mt-2 mb-2">
                    <summary class="small fw-semibold">Advanced Filters</summary>

                    <div class="row g-2 mt-2">
                        <div class="col-md-2">
                            <label class="form-label form-label-sm">Agent (contains)</label>
                            <input type="text" name="agent" value="{{ request('agent') }}" list="agents-list"
                                class="form-control form-control-sm" autocomplete="off">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label form-label-sm">Client (contains)</label>
                            <input type="text" name="client" value="{{ request('client') }}" list="clients-list"
                                class="form-control form-control-sm" autocomplete="off">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label form-label-sm">Negotiator (contains)</label>
                            <input type="text" name="negotiator" value="{{ request('negotiator') }}"
                                list="negotiators-list" class="form-control form-control-sm" autocomplete="off">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label form-label-sm">State (exact)</label>
                            <select name="state" class="form-select form-select-sm">
                                <option value="">All</option>
                                @foreach ($opts['states'] ?? [] as $state)
                                    <option value="{{ $state }}" {{ request('state') === $state ? 'selected' : '' }}>
                                        {{ $state }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label form-label-sm">Enrollment Status (exact)</label>
                            <select name="enrollment_status" class="form-select form-select-sm">
                                <option value="">All</option>
                                @foreach ($opts['enrollment_status'] ?? [] as $status)
                                    <option value="{{ $status }}"
                                        {{ request('enrollment_status') === $status ? 'selected' : '' }}>
                                        {{ $status }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label form-label-sm">Company</label>
                            <select name="company" class="form-select form-select-sm">
                                <option value="">All</option>
                                <option value="progress" {{ request('company') === 'progress' ? 'selected' : '' }}>
                                    Progress Law</option>
                                <option value="ldr" {{ request('company') === 'ldr' ? 'selected' : '' }}>LDR</option>
                            </select>
                        </div>
                    </div>

                    <div class="row g-2 mt-2">
                        <div class="col-md-2">
                            <label class="form-label form-label-sm">Debt Min</label>
                            <input type="number" name="debt_min" value="{{ request('debt_min') }}"
                                class="form-control form-control-sm">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label form-label-sm">Debt Max</label>
                            <input type="number" name="debt_max" value="{{ request('debt_max') }}"
                                class="form-control form-control-sm">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label form-label-sm">Program Length Min</label>
                            <input type="number" name="length_min" value="{{ request('length_min') }}"
                                class="form-control form-control-sm">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label form-label-sm">Program Length Max</label>
                            <input type="number" name="length_max" value="{{ request('length_max') }}"
                                class="form-control form-control-sm">
                        </div>
                    </div>
                </details>

                <datalist id="agents-list">
                    @foreach ($opts['agents'] ?? [] as $value)
                        <option value="{{ $value }}"></option>
                    @endforeach
                </datalist>
                <datalist id="clients-list">
                    @foreach ($opts['clients'] ?? [] as $value)
                        <option value="{{ $value }}"></option>
                    @endforeach
                </datalist>
                <datalist id="negotiators-list">
                    @foreach ($opts['negotiators'] ?? [] as $value)
                        <option value="{{ $value }}"></option>
                    @endforeach
                </datalist>

                <div class="col-auto">
                    <div class="d-flex gap-2 justify-content-end">
                        <button type="submit" id="btn-filter" class="btn btn-primary btn-sm">Filter</button>
                        <a href="{{ route('cmd.reports.cancel_report') }}" class="btn btn-light btn-sm">Reset</a>
                        <div class="col-auto">
                            <select name="per_page" class="form-select form-select-sm">
                                @foreach ([25, 50, 100, 250, 500] as $n)
                                    <option value="{{ $n }}"
                                        {{ (int) request('per_page', $perPage ?? 25) === $n ? 'selected' : '' }}>
                                        {{ $n }} / page</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>
            </form>

            <div class="d-flex justify-content-center align-items-center mb-2">
                @php
                    $totalRecords = method_exists($reports, 'total') ? $reports->total() : count($reports);
                @endphp
                <span class="border p-2 rounded small text-warning">
                    Results: {{ $totalRecords }} {{ $totalRecords === 1 ? 'Record' : 'Records' }}
                </span>
            </div>

            <div class="table-responsive">
                @php
                    $formatDate = static fn($value) => $value ? \Carbon\Carbon::parse($value)->format('m/d/Y') : '';
                    $formatMoney = static fn($value) => $value ? '$' . number_format(format_currency_string($value), 2) : '';
                @endphp
                <table class="table datatable">
                    <thead>
                        <tr>
                            <th>Drop Name</th>
                            <th>Company</th>
                            <th>CID</th>
                            <th>State</th>
                            <th>Agent</th>
                            <th>Client</th>
                            <th class="text-end">Debt Amount</th>
                            <th class="text-nowrap">Welcome Call Date</th>
                            <th class="text-nowrap">Payment Date 1</th>
                            <th class="text-nowrap">Payment Date 2</th>
                            <th class="text-nowrap">Cancel Date</th>
                            <th class="text-nowrap">NSF Date</th>
                            <th class="text-end">Payments</th>
                            <th>Negotiator</th>
                            <th class="text-nowrap">Negotiator Assigned Date</th>
                            <th class="text-nowrap">First Payment Date</th>
                            <th class="text-nowrap">First Payment Cleared Date</th>
                            <th class="text-end">Enrolled Debt Accounts</th>
                            <th>Enrollment Status</th>
                            <th>Enrollment Plan</th>
                            <th class="text-end">Program Payment</th>
                            <th class="text-end">Program Length</th>
                            <th>First Payment Status</th>
                            <th class="text-nowrap">Submitted Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($reports as $report)
                            <tr>
                                <td>{{ $report->Drop_Name }}</td>
                                <td>{{ Str::contains($report->Enrollment_Plan, 'Progress') ? 'Progress Law' : 'LDR' }}</td>
                                <td>{{ preg_replace('/\D+/', '', (string) $report->LLG_ID) }}</td>
                                <td>{{ $report->State }}</td>
                                <td>{{ $report->Agent }}</td>
                                <td>{{ $report->Client }}</td>
                                <td class="text-end">{{ $formatMoney($report->Debt_Amount) }}</td>
                                <td>{{ $formatDate($report->Welcome_Call_Date) }}</td>
                                <td>{{ $formatDate($report->Payment_Date_1) }}</td>
                                <td>{{ $formatDate($report->Payment_Date_2) }}</td>
                                <td>{{ $formatDate($report->Cancel_Date) }}</td>
                                <td>{{ $formatDate($report->NSF_Date) }}</td>
                                <td class="text-end">{{ $formatMoney($report->Payments) }}</td>
                                <td>{{ $report->Negotiator }}</td>
                                <td>{{ $formatDate($report->Negotiator_Assigned_Date) }}</td>
                                <td>{{ $formatDate($report->First_Payment_Date) }}</td>
                                <td>{{ $formatDate($report->First_Payment_Cleared_Date) }}</td>
                                <td class="text-end">{{ $report->Enrolled_Debt_Accounts }}</td>
                                <td>{{ $report->Enrollment_Status }}</td>
                                <td>{{ $report->Enrollment_Plan }}</td>
                                <td class="text-end">{{ $formatMoney($report->Program_Payment) }}</td>
                                <td class="text-end">{{ $report->Program_Length }}</td>
                                <td>{{ $report->First_Payment_Status }}</td>
                                <td>{{ $formatDate($report->Submitted_Date) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="24" class="text-center text-muted">No records found.</td>
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
            (function() {
                const form = document.getElementById('cancel-report-form');
                if (!form) return;

                const fromInput = document.getElementById('from');
                const toInput = document.getElementById('to');
                const rangeInput = document.getElementById('range');
                const exportInput = document.getElementById('export');

                function formatDate(date) {
                    const yyyy = date.getFullYear();
                    const mm = String(date.getMonth() + 1).padStart(2, '0');
                    const dd = String(date.getDate()).padStart(2, '0');
                    return `${yyyy}-${mm}-${dd}`;
                }

                function setActive(range) {
                    document.querySelectorAll('[data-range]').forEach(button => {
                        button.classList.toggle('active', button.dataset.range === range);
                    });
                }

                (function initActive() {
                    const currentRange = rangeInput.value;
                    if (currentRange) {
                        setActive(currentRange);
                        return;
                    }

                    const fromValue = fromInput.value;
                    const toValue = toInput.value;
                    if (!fromValue && !toValue) {
                        setActive('all');
                        return;
                    }

                    const today = new Date();
                    const todayFormatted = formatDate(today);

                    function matchesRange(days) {
                        const start = new Date(today);
                        start.setDate(start.getDate() - (days - 1));
                        return fromValue === formatDate(start) && toValue === todayFormatted;
                    }

                    if (matchesRange(7)) {
                        setActive('7');
                        return;
                    }
                    if (matchesRange(30)) {
                        setActive('30');
                        return;
                    }

                    setActive('');
                })();

                document.querySelectorAll('[data-range]').forEach(button => {
                    button.addEventListener('click', () => {
                        const value = button.dataset.range;
                        const today = new Date();

                        const monthStart = new Date(today.getFullYear(), today.getMonth(), 1);
                        const monthEnd = new Date(today.getFullYear(), today.getMonth() + 1, 0);
                        const lastMonthStart = new Date(today.getFullYear(), today.getMonth() - 1, 1);
                        const lastMonthEnd = new Date(today.getFullYear(), today.getMonth(), 0);

                        switch (value) {
                            case 'all':
                                fromInput.value = '';
                                toInput.value = '';
                                rangeInput.value = 'all';
                                break;
                            case 'today':
                                fromInput.value = formatDate(today);
                                toInput.value = formatDate(today);
                                rangeInput.value = 'today';
                                break;
                            case '7':
                            case '30':
                                const days = parseInt(value, 10);
                                const start = new Date(today);
                                start.setDate(start.getDate() - (days - 1));
                                fromInput.value = formatDate(start);
                                toInput.value = formatDate(today);
                                rangeInput.value = value;
                                break;
                            case 'this_month':
                                fromInput.value = formatDate(monthStart);
                                toInput.value = formatDate(monthEnd);
                                rangeInput.value = 'this_month';
                                break;
                            case 'last_month':
                                fromInput.value = formatDate(lastMonthStart);
                                toInput.value = formatDate(lastMonthEnd);
                                rangeInput.value = 'last_month';
                                break;
                        }

                        setActive(value);
                        form.submit();
                    });
                });

                fromInput.addEventListener('input', () => rangeInput.value = '');
                toInput.addEventListener('input', () => rangeInput.value = '');

                document.getElementById('btn-export').addEventListener('click', () => {
                    exportInput.value = 'csv';
                    form.submit();
                    setTimeout(() => exportInput.value = '', 100);
                });

                document.getElementById('btn-filter').addEventListener('click', () => {
                    exportInput.value = '';
                });
            })();
        });
    </script>
@endpush
