@extends('layouts.app')

@section('content')
    @php
        $formatDate = static fn($value) => $value ? \Carbon\Carbon::parse($value)->format('m/d/Y') : '';
        $totalRecords = method_exists($reports, 'total') ? $reports->total() : count($reports);
        $perPageValue = (int) ($perPage ?? request('per_page', 25));
        $currentRange = $range ?? request('range');
        if (!$currentRange) {
            $currentRange = request('from') || request('to') ? 'custom' : 'all';
        }
    @endphp

    <div class="card">
        <div class="card-header d-flex align-items-center py-0">
            <h6 class="py-3 mb-0">Mailer Data</h6>
        </div>
        <div class="card-body">
            <form method="get" action="{{ route('cmd.reports.mailer_data_report') }}" class="bg-light rounded-3 p-2 p-md-3 mb-3" id="mailer-data-form">
                <input type="hidden" name="range" id="range" value="{{ $currentRange }}">
                <input type="hidden" name="export" id="export" value="">

                <div class="row g-2 align-items-center">
                    <div class="cg-2 align-items-center justify-content-end">
                        <span class="fw-semibold">Mailer Data For :</span>
                    </div>

                    <div class="d-flex justify-content-between flex-wrap gap-2">
                        <div class="btn-group btn-group-sm gap-1" role="group" aria-label="Quick ranges">
                            @foreach (['all', 'today', '7', '30', 'this_month', 'last_month'] as $quick)
                                <button type="button" class="btn btn-outline-primary range-btn {{ $currentRange === $quick ? 'active' : '' }}" data-range="{{ $quick }}">
                                    @switch($quick)
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
                            <label class="form-label form-label-sm">Drop Name (contains)</label>
                            <input type="text" name="drop_name" value="{{ request('drop_name') }}" list="drops-list" class="form-control form-control-sm" autocomplete="off">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label form-label-sm">Debt Tier</label>
                            <select name="debt_tier" class="form-select form-select-sm">
                                <option value="">Any</option>
                                @foreach (($opts['debt_tiers'] ?? []) as $value)
                                    <option value="{{ $value }}" {{ request('debt_tier') === $value ? 'selected' : '' }}>{{ $value }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label form-label-sm">State (exact)</label>
                            <select name="state" class="form-select form-select-sm">
                                <option value="">Any</option>
                                @foreach (($opts['states'] ?? []) as $value)
                                    <option value="{{ $value }}" {{ request('state') === $value ? 'selected' : '' }}>{{ $value }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label form-label-sm">Month</label>
                            <select name="month" class="form-select form-select-sm">
                                <option value="">Any</option>
                                @foreach (range(1, 12) as $m)
                                    <option value="{{ $m }}" {{ (int) request('month') === $m ? 'selected' : '' }}>{{ $m }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label form-label-sm">Year</label>
                            <select name="year" class="form-select form-select-sm">
                                <option value="">Any</option>
                                @foreach (range((int) date('Y'), 2020) as $y)
                                    <option value="{{ $y }}" {{ (int) request('year') === $y ? 'selected' : '' }}>{{ $y }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="row g-2 mt-2">
                        <div class="col-md-3">
                            <label class="form-label form-label-sm">Drop Type</label>
                            <select name="drop_type" class="form-select form-select-sm">
                                <option value="">Any</option>
                                @foreach (($opts['drop_types'] ?? []) as $value)
                                    <option value="{{ $value }}" {{ request('drop_type') === $value ? 'selected' : '' }}>{{ $value }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label form-label-sm">Data Type</label>
                            <select name="data_type" class="form-select form-select-sm">
                                <option value="">Any</option>
                                @foreach (($opts['data_types'] ?? []) as $value)
                                    <option value="{{ $value }}" {{ request('data_type') === $value ? 'selected' : '' }}>{{ $value }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label form-label-sm">Mail Style</label>
                            <select name="mail_style" class="form-select form-select-sm">
                                <option value="">Any</option>
                                @foreach (($opts['mail_styles'] ?? []) as $value)
                                    <option value="{{ $value }}" {{ request('mail_style') === $value ? 'selected' : '' }}>{{ $value }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label form-label-sm">Vendor</label>
                            <select name="vendor" class="form-select form-select-sm">
                                <option value="">Any</option>
                                @foreach (($opts['vendors'] ?? []) as $value)
                                    <option value="{{ $value }}" {{ request('vendor') === $value ? 'selected' : '' }}>{{ $value }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="row g-2 mt-2">
                        <div class="col-md-3">
                            <label class="form-label form-label-sm">Send Date (exact)</label>
                            <input type="date" name="send_date" value="{{ request('send_date') }}" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label form-label-sm">Visible</label>
                            <select name="visible" class="form-select form-select-sm">
                                <option value="">Any</option>
                                <option value="1" {{ request('visible') === '1' ? 'selected' : '' }}>Yes</option>
                                <option value="0" {{ request('visible') === '0' ? 'selected' : '' }}>No</option>
                            </select>
                        </div>
                    </div>
                </details>

                <datalist id="drops-list">
                    @foreach (($opts['drops'] ?? []) as $value)
                        <option value="{{ $value }}"></option>
                    @endforeach
                </datalist>

                <div class="col-auto">
                    <div class="d-flex gap-2 justify-content-end">
                        <button type="submit" id="btn-filter" class="btn btn-primary btn-sm">Filter</button>
                        <a href="{{ route('cmd.reports.mailer_data_report') }}" class="btn btn-light btn-sm">Reset</a>
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
                            <th>Drop Name</th>
                            <th>Send Date</th>
                            <th>Debt Tier</th>
                            <th>State</th>
                            <th class="text-end">Count</th>
                            <th class="text-end">Month</th>
                            <th class="text-end">Year</th>
                            <th>Drop Type</th>
                            <th>Data Type</th>
                            <th>Mail Style</th>
                            <th>Vendor</th>
                            <th class="text-end">Total Leads</th>
                            <th class="text-end">Qualified Leads</th>
                            <th class="text-end">Unqualified Leads</th>
                            <th class="text-end">Assigned Leads</th>
                            <th>Visible</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($reports as $report)
                            <tr>
                                <td>{{ $report->Drop_Name }}</td>
                                <td>{{ $formatDate($report->Send_Date) }}</td>
                                <td>{{ $report->Debt_Tier }}</td>
                                <td>{{ $report->State }}</td>
                                <td class="text-end">{{ number_format((float) ($report->Count ?? 0)) }}</td>
                                <td class="text-end">{{ $report->Month }}</td>
                                <td class="text-end">{{ $report->Year }}</td>
                                <td>{{ $report->Drop_Type }}</td>
                                <td>{{ $report->Data_Type }}</td>
                                <td>{{ $report->Mail_Style }}</td>
                                <td>{{ $report->Vendor }}</td>
                                <td class="text-end">{{ number_format((float) ($report->Total_Leads ?? 0)) }}</td>
                                <td class="text-end">{{ number_format((float) ($report->Qualified_Leads ?? 0)) }}</td>
                                <td class="text-end">{{ number_format((float) ($report->Unqualified_Leads ?? 0)) }}</td>
                                <td class="text-end">{{ number_format((float) ($report->Assigned_Leads ?? 0)) }}</td>
                                <td>{{ (int) ($report->Visible ?? 0) === 1 ? 'Yes' : 'No' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="16" class="text-center text-muted">No records found.</td>
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
                const form = document.getElementById('mailer-data-form');
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
