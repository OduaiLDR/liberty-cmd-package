@use('Illuminate\Support\Str')
@extends('layouts.app')

@section('content')
    <div class="card">
        <div class="card-header d-flex align-items-center py-0">
            <h6 class="py-3 mb-0">Enrollment Report</h6>
        </div>
        <div class="card-body">
            <form method="get" action="{{ route('cmd.reports.enrollment_report') }}" class="bg-light rounded-3 p-2 p-md-3 mb-3">
                <input type="hidden" name="range" id="range" value="{{ request('range', $range) }}">
                <input type="hidden" name="export" id="export" value="">
                <div class="row g-2 align-items-center">
                    <div class="cg-2 align-items-center justify-content-end">
                        <span class="fw-semibold">Enrollments For:</span>
                    </div>
                    
                    <div class="d-flex justify-content-between">
                        <div class="btn-group btn-group-sm gap-1" role="group" aria-label="Quick ranges">
                            <button type="button" class="btn btn-outline-primary range-btn active" data-range="all"
                                id="btn-range-all">All</button>
                            <button type="button" class="btn btn-outline-primary range-btn" data-range="today"
                                id="btn-range-today">Today</button>
                            <button type="button" class="btn btn-outline-primary range-btn" data-range="7"
                                id="btn-range-7">7d</button>
                            <button type="button" class="btn btn-outline-primary range-btn" data-range="30"
                                id="btn-range-30">30d</button>
                            <button type="button" class="btn btn-outline-primary range-btn" data-range="this_month"
                                id="btn-range-this-month">This Month</button>
                            <button type="button" class="btn btn-outline-primary range-btn" data-range="last_month"
                                id="btn-range-last-month">Last Month</button>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <div class="input-group input-group-sm" style="width:auto;">
                                <span class="input-group-text">Filter By</span>
                                <select class="form-select form-select-sm" name="date_by" id="date_by">
                                    <option value="submitted" {{ ($dateBy ?? request('date_by','submitted')) === 'submitted' ? 'selected' : '' }}>Submitted Date</option>
                                    <option value="welcome_call" {{ ($dateBy ?? request('date_by')) === 'welcome_call' ? 'selected' : '' }}>Welcome Call Date</option>
                                    <option value="payment" {{ ($dateBy ?? request('date_by')) === 'payment' ? 'selected' : '' }}>Payment Date</option>
                                </select>
                            </div>
                            <button type="button" id="btn-export" class="btn btn-secondary btn-sm">Export CSV</button>
                        </div>
                    </div>

                    <div class="row g-2 align-items-center justify-content-end">
                        <div class="col-auto">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text">From</span>
                                <input type="date" name="from" id="from" value="{{ old('from', request('from')) }}"
                                    class="form-control" />
                            </div>
                        </div>
                        <div class="col-auto">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text">To</span>
                                <input type="date" name="to" id="to" value="{{ old('to', request('to')) }}"
                                    class="form-control" />
                            </div>
                        </div>
                        <div class="col-auto">
                            <button type="button" id="btn-reset" class="btn btn-outline-secondary btn-sm">Reset</button>
                        </div>
                    </div>
                </div>
                <!-- Advanced Filters -->
                <details class="mt-2 mb-2">
                    <summary class="small fw-semibold">Advanced Filters</summary>
                    <div class="row g-2 mt-2">
                        <div class="col-md-2">
                            <label class="form-label form-label-sm">Agent (contains)</label>
                            <input type="text" name="agent" value="{{ request('agent') }}" list="agents-list"
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
                                <option value="">Any</option>
                                @foreach ($opts['states'] ?? [] as $st)
                                    <option value="{{ $st }}" {{ request('state') === $st ? 'selected' : '' }}>
                                        {{ $st }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label form-label-sm">Enrollment Status (exact)</label>
                            <select name="enrollment_status" class="form-select form-select-sm">
                                <option value="">Any</option>
                                @foreach ($opts['enrollment_status'] ?? [] as $st)
                                    <option value="{{ $st }}" {{ request('enrollment_status') === $st ? 'selected' : '' }}>
                                        {{ $st }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label form-label-sm">Debt Amount</label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text">Min</span>
                                <input type="number" step="0.01" name="debt_min" value="{{ request('debt_min') }}"
                                    class="form-control form-control-sm" placeholder="0">
                                <span class="input-group-text">Max</span>
                                <input type="number" step="0.01" name="debt_max" value="{{ request('debt_max') }}"
                                    class="form-control form-control-sm" placeholder="1000000">
                            </div>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label form-label-sm">Program Length</label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text">Min</span>
                                <input type="number" name="length_min" value="{{ request('length_min') }}"
                                    class="form-control form-control-sm" placeholder="0">
                                <span class="input-group-text">Max</span>
                                <input type="number" name="length_max" value="{{ request('length_max') }}"
                                    class="form-control form-control-sm" placeholder="99">
                            </div>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label form-label-sm">Company</label>
                            <select name="company" class="form-select form-select-sm">
                                <option value="">Any</option>
                                <option value="progress" {{ request('company') === 'progress' ? 'selected' : '' }}>Progress Law</option>
                                <option value="ldr" {{ request('company') === 'ldr' ? 'selected' : '' }}>LDR</option>
                            </select>
                        </div>
                    </div>
                </details>

                <datalist id="agents-list">
                    @foreach ($opts['agents'] ?? [] as $v)
                        <option value="{{ $v }}"></option>
                    @endforeach
                </datalist>
                <datalist id="negotiators-list">
                    @foreach ($opts['negotiators'] ?? [] as $v)
                        <option value="{{ $v }}"></option>
                    @endforeach
                </datalist>
                <div class="col-auto">
                    <div class="d-flex gap-2 justify-content-end">
                        <button type="submit" id="btn-filter" class="btn btn-primary btn-sm">Filter</button>
                        <a href="{{ route('cmd.reports.enrollment_report') }}" class="btn btn-light btn-sm">Reset</a>
                        <div class="col-auto">
                            <select name="per_page" class="form-select form-select-sm">
                                @foreach ([25, 50, 100, 250, 500] as $n)
                                    <option value="{{ $n }}" {{ (int) request('per_page', $perPage ?? 25) === $n ? 'selected' : '' }}>{{ $n }} / page</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>
            </form>

            <div class="d-flex justify-content-center align-items-center mb-2">
                <span class="border p-2 rounded small text-warning">
                    Results:
                    {{ method_exists($reports, 'total') ? $reports->total() . ' Records' : count($reports) . ' Record' }}
                </span>
            </div>

            <div class="table-responsive">
                <table class="table table-striped table-bordered table-hover">
                    <thead>
                        <tr>
                            <th>PK</th>
                            <th>Drop Name</th>
                            <th>CID</th>
                            <th>Category</th>
                            <th>State</th>
                            <th>Agent</th>
                            <th>Negotiator</th>
                            <th>Client</th>
                            <th class="text-end">Debt Amount</th>
                            <th class="text-nowrap">Welcome Call Date</th>
                            <th class="text-nowrap">Submitted Date</th>
                            <th class="text-nowrap">Payment Date 1</th>
                            <th class="text-nowrap">Payment Date 2</th>
                            <th class="text-nowrap">First Payment Cleared Date</th>
                            <th class="text-nowrap">Cancel Date</th>
                            <th class="text-nowrap">NSF Date</th>
                            <th class="text-end">Payments</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($reports as $report)
                            <tr>
                                <td>{{ $report->PK }}</td>
                                <td>{{ $report->Drop_Name }}</td>
                                <td>{{ preg_replace('/\D+/', '', (string) $report->LLG_ID) }}</td>
                                <td>{{ $report->Category }}</td>
                                <td>{{ $report->State }}</td>
                                <td>{{ $report->Agent }}</td>
                                <td>{{ $report->Negotiator }}</td>
                                <td>{{ $report->Client }}</td>
                                <td class="text-end">${{ number_format(format_currency_string($report->Debt_Amount), 2) }}</td>

                                <td data-order="{{ $report->Welcome_Call_Date ? \Illuminate\Support\Carbon::parse($report->Welcome_Call_Date)->format('Y-m-d') : '' }}">
                                    {{ $report->Welcome_Call_Date ? \Illuminate\Support\Carbon::parse($report->Welcome_Call_Date)->format('m/d/Y') : '' }}
                                </td>
                                <td data-order="{{ $report->Submitted_Date ? \Illuminate\Support\Carbon::parse($report->Submitted_Date)->format('Y-m-d') : '' }}">
                                    {{ $report->Submitted_Date ? \Illuminate\Support\Carbon::parse($report->Submitted_Date)->format('m/d/Y') : '' }}
                                </td>
                                <td data-order="{{ $report->Payment_Date_1 ? \Illuminate\Support\Carbon::parse($report->Payment_Date_1)->format('Y-m-d') : '' }}">
                                    {{ $report->Payment_Date_1 ? \Illuminate\Support\Carbon::parse($report->Payment_Date_1)->format('m/d/Y') : '' }}
                                </td>
                                <td data-order="{{ $report->Payment_Date_2 ? \Illuminate\Support\Carbon::parse($report->Payment_Date_2)->format('Y-m-d') : '' }}">
                                    {{ $report->Payment_Date_2 ? \Illuminate\Support\Carbon::parse($report->Payment_Date_2)->format('m/d/Y') : '' }}
                                </td>
                                <td data-order="{{ $report->First_Payment_Cleared_Date ? \Illuminate\Support\Carbon::parse($report->First_Payment_Cleared_Date)->format('Y-m-d') : '' }}">
                                    {{ $report->First_Payment_Cleared_Date ? \Illuminate\Support\Carbon::parse($report->First_Payment_Cleared_Date)->format('m/d/Y') : '' }}
                                </td>
                                <td data-order="{{ $report->Cancel_Date ? \Illuminate\Support\Carbon::parse($report->Cancel_Date)->format('Y-m-d') : '' }}">
                                    {{ $report->Cancel_Date ? \Illuminate\Support\Carbon::parse($report->Cancel_Date)->format('m/d/Y') : '' }}
                                </td>
                                <td data-order="{{ $report->NSF_Date ? \Illuminate\Support\Carbon::parse($report->NSF_Date)->format('Y-m-d') : '' }}">
                                    {{ $report->NSF_Date ? \Illuminate\Support\Carbon::parse($report->NSF_Date)->format('m/d/Y') : '' }}
                                </td>
                                <td class="text-end">{{ number_format((int) $report->Payments) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="17" class="text-center text-muted">No data</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card-footer">
            {{ $reports->onEachSide(3)->appends(request()->all())->links() }}
        </div>
    </div>
@endsection
@push('scripts')
    <script src="{{ asset(config('app.limitless_template_path') . 'assets/js/vendor/tables/datatables/datatables.min.js') }}"></script>
    <script src="{{ asset('assets/js/datatables-init.js') }}"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            $('<style>.range-btn{min-width:90px}</style>').appendTo(document.head);
            (function() {
                const $from = $('#from');
                const $to = $('#to');
                const $form = $from.closest('form');
                const $range = $('#range');
                const $export = $('#export');

                function fmt(d) {
                    const yyyy = d.getFullYear();
                    const mm = String(d.getMonth() + 1).padStart(2, '0');
                    const dd = String(d.getDate()).padStart(2, '0');
                    return `${yyyy}-${mm}-${dd}`;
                }

                function setActive(value) {
                    $('[data-range]').removeClass('active');
                    if (value) {
                        $(`[data-range="${value}"]`).addClass('active');
                    }
                }

                (function initActive() {
                    const f = $from.val();
                    const t = $to.val();
                    const today = new Date();
                    const todayS = fmt(today);

                    function isRange(days) {
                        const start = new Date(today);
                        start.setDate(start.getDate() - (days - 1));
                        return f === fmt(start) && t === todayS;
                    }

                    if ($range.val()) {
                        setActive($range.val());
                        return;
                    }
                    if (!f && !t) {
                        setActive('all');
                        return;
                    }
                    if (isRange(7)) {
                        setActive('7');
                        return;
                    }
                    if (isRange(30)) {
                        setActive('30');
                        return;
                    }
                    setActive('');
                })();

                $('[data-range]').on('click', function() {
                    const v = $(this).data('range').toString();
                    const today = new Date();
                    const startOfMonth = new Date(today.getFullYear(), today.getMonth(), 1);
                    const endOfMonth = new Date(today.getFullYear(), today.getMonth() + 1, 0);
                    const startOfLastMonth = new Date(today.getFullYear(), today.getMonth() - 1, 1);
                    const endOfLastMonth = new Date(today.getFullYear(), today.getMonth(), 0);
                    if (v === 'all') {
                        $from.val('');
                        $to.val('');
                        $range.val('all');
                        setActive('all');
                        $form.trigger('submit');
                        return;
                    }
                    if (v === 'today') {
                        $from.val(fmt(today));
                        $to.val(fmt(today));
                        $range.val('today');
                        setActive('today');
                        $form.trigger('submit');
                        return;
                    }
                    if (v === '7' || v === '30') {
                        const days = parseInt(v, 10);
                        const from = new Date(today);
                        from.setDate(from.getDate() - (days - 1));
                        $from.val(fmt(from));
                        $to.val(fmt(today));
                        $range.val(v);
                        setActive(v);
                        $form.trigger('submit');
                        return;
                    }
                    if (v === 'this_month') {
                        $from.val(fmt(startOfMonth));
                        $to.val(fmt(endOfMonth));
                        $range.val('this_month');
                        setActive('this_month');
                        $form.trigger('submit');
                        return;
                    }
                    if (v === 'last_month') {
                        $from.val(fmt(startOfLastMonth));
                        $to.val(fmt(endOfLastMonth));
                        $range.val('last_month');
                        setActive('last_month');
                        $form.trigger('submit');
                        return;
                    }
                });

                $from.on('change input', function() { $range.val(''); });
                $to.on('change input', function() { $range.val(''); });

                $('#btn-export').on('click', function() {
                    $export.val('csv');
                    $form.trigger('submit');
                    setTimeout(function() { $export.val(''); }, 100);
                });
                $('#btn-filter').on('click', function() {
                    $export.val('');
                });
                $('#btn-reset').on('click', function() {
                    // Clear all fields in the form
                    $from.val('');
                    $to.val('');
                    $range.val('all');
                    $('#date_by').val('submitted');
                    $("input[name='agent']").val('');
                    $("input[name='negotiator']").val('');
                    $("select[name='state']").val('');
                    $("select[name='enrollment_status']").val('');
                    $("input[name='debt_min']").val('');
                    $("input[name='debt_max']").val('');
                    $("input[name='length_min']").val('');
                    $("input[name='length_max']").val('');
                    $("select[name='company']").val('');
                    setActive('all');
                    $form.trigger('submit');
                });
            })();
        });
    </script>
@endpush
