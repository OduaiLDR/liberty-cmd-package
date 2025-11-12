@use('Illuminate\Support\Str')

@extends('layouts.app')

@section('content')
    @php
        $formatDate = static fn($value) => $value ? \Carbon\Carbon::parse($value)->format('m/d/Y') : '';
        $formatMoney = static fn($value) => $value === null ? '' : '$' . number_format((float) $value, 2);
        $totalRecords = method_exists($reports, 'total') ? $reports->total() : count($reports);
        $perPageValue = $perPage ?? (int) request('per_page', 25);
        $currentRange = $range ?? request('range');
        $currentRange = $currentRange ?: (request('from') || request('to') ? 'custom' : 'all');
        $selectedStatus = $filters['status_type'] ?? request('status_type', 'all');
    @endphp

    <div class="card">
        <div class="card-header d-flex align-items-center py-0">
            <h6 class="py-3 mb-0">Lead Report</h6>
        </div>
        <div class="card-body">
            <form method="get" action="{{ route('cmd.reports.lead_report') }}" class="bg-light rounded-3 p-2 p-md-3 mb-3" id="lead-report-form">
                <input type="hidden" name="range" id="range" value="{{ $currentRange }}">
                <input type="hidden" name="export" id="export" value="">

                <div class="row g-2 align-items-center">
                    <div class="col-auto fw-semibold">Leads For:</div>
                    <div class="col">
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <div class="btn-group btn-group-sm gap-1" role="group" aria-label="Quick ranges">
                                @php
                                    $ranges = [
                                        'all' => 'All',
                                        'today' => 'Today',
                                        '7' => '7d',
                                        '30' => '30d',
                                        'this_month' => 'This Month',
                                        'last_month' => 'Last Month',
                                    ];
                                @endphp
                                @foreach ($ranges as $value => $label)
                                    <button type="submit" name="range" value="{{ $value }}" class="btn btn-outline-primary {{ $currentRange === $value ? 'active' : '' }}">
                                        {{ $label }}
                                    </button>
                                @endforeach
                            </div>
                            <button type="submit" name="export" value="csv" class="btn btn-secondary btn-sm">Export CSV</button>
                        </div>
                    </div>
                </div>

                <div class="row g-2 align-items-center justify-content-end mt-2">
                    <div class="col-auto">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text">From</span>
                            <input type="date" name="from" id="from" value="{{ old('from', request('from')) }}" class="form-control">
                        </div>
                    </div>
                    <div class="col-auto">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text">To</span>
                            <input type="date" name="to" id="to" value="{{ old('to', request('to')) }}" class="form-control">
                        </div>
                    </div>
                </div>

                <details class="mt-2 mb-2">
                    <summary class="small fw-semibold">Filters</summary>
                    <div class="row g-2 mt-2">
                        <div class="col-md-3">
                            <label class="form-label form-label-sm">Agent (contains)</label>
                            <input type="text" name="agent" value="{{ request('agent', $filters['agent'] ?? '') }}" list="agents-list" class="form-control form-control-sm" autocomplete="off">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label form-label-sm">Data Source (contains)</label>
                            <input type="text" name="data_source" value="{{ request('data_source', $filters['data_source'] ?? '') }}" list="data-sources-list" class="form-control form-control-sm" autocomplete="off">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label form-label-sm">Debt Tier</label>
                            <select name="debt_tier" class="form-select form-select-sm">
                                <option value="">All Debt Tier</option>
                                @foreach (($opts['debt_tiers'] ?? []) as $tier)
                                    <option value="{{ $tier }}" {{ request('debt_tier', $filters['debt_tier'] ?? '') == $tier ? 'selected' : '' }}>Tier {{ $tier }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label form-label-sm">Status</label>
                            @php
                                $statusOptions = [
                                    'all' => 'All Leads',
                                    'active' => 'All Active Deals',
                                    'cancels' => 'Cancels',
                                    'nsfs' => 'NSFs',
                                    'not_closed' => 'Not Closed',
                                ];
                            @endphp
                            <select name="status_type" class="form-select form-select-sm">
                                @foreach ($statusOptions as $value => $label)
                                    <option value="{{ $value }}" {{ $selectedStatus === $value ? 'selected' : '' }}>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-auto d-flex align-items-end">
                            <button type="submit" id="btn-filter" class="btn btn-primary btn-sm me-2">Filter</button>
                            <a href="{{ route('cmd.reports.lead_report') }}" class="btn btn-light btn-sm">Reset</a>
                        </div>
                        <div class="col-auto d-flex align-items-end">
                            <select name="per_page" class="form-select form-select-sm">
                                @foreach ([25, 50, 100, 250, 500] as $n)
                                    <option value="{{ $n }}" {{ $perPageValue === $n ? 'selected' : '' }}>{{ $n }} / page</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </details>

                <datalist id="agents-list">
                    @foreach ($opts['agents'] ?? [] as $value)
                        <option value="{{ $value }}"></option>
                    @endforeach
                </datalist>
                <datalist id="data-sources-list">
                    @foreach ($opts['data_sources'] ?? [] as $value)
                        <option value="{{ $value }}"></option>
                    @endforeach
                </datalist>
            </form>

            <div class="d-flex justify-content-center align-items-center mb-2">
                <span class="border p-2 rounded small text-warning">Results: {{ $totalRecords }} {{ $totalRecords === 1 ? 'Record' : 'Records' }}</span>
            </div>

            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th class="text-nowrap">Created Date</th>
                            <th class="text-nowrap">Assigned Date</th>
                            <th class="text-nowrap">CID</th>
                            <th>Campaign</th>
                            <th>Data Source</th>
                            <th>Agent</th>
                            <th>Client</th>
                            <th>Phone</th>
                            <th>Email</th>
                            <th>State</th>
                            <th>Stage</th>
                            <th>Status</th>
                            <th class="text-end">Lead Debt Amount</th>
                            <th class="text-center">Debt Tier</th>
                            <th class="text-end">Enrolled Debt</th>
                            <th class="text-nowrap">Submitted Date</th>
                            <th class="text-nowrap">Welcome Call Date</th>
                            <th class="text-nowrap">Payment Date</th>
                            <th class="text-nowrap">Cancel Date</th>
                            <th class="text-nowrap">NSF Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($reports as $report)
                            <tr>
                                <td>{{ $formatDate($report->Created_Date) }}</td>
                                <td>{{ $formatDate($report->Assigned_Date) }}</td>
                                <td>{{ preg_replace('/\D+/', '', (string) $report->LLG_ID) }}</td>
                                <td>{{ $report->Campaign }}</td>
                                <td>{{ $report->Data_Source }}</td>
                                <td>{{ $report->Agent }}</td>
                                <td>{{ $report->Client }}</td>
                                <td>{{ $report->Phone }}</td>
                                <td>{{ $report->Email }}</td>
                                <td>{{ $report->State }}</td>
                                <td>{{ $report->Stage }}</td>
                                <td>{{ $report->Status }}</td>
                                <td class="text-end">{{ $formatMoney($report->Debt_Amount) }}</td>
                                <td class="text-center">{{ $report->Debt_Tier }}</td>
                                <td class="text-end">{{ $formatMoney($report->Enrolled_Debt) }}</td>
                                <td>{{ $formatDate($report->Submitted_Date) }}</td>
                                <td>{{ $formatDate($report->Welcome_Call_Date) }}</td>
                                <td>{{ $formatDate($report->Payment_Date) }}</td>
                                <td>{{ $formatDate($report->Cancel_Date) }}</td>
                                <td>{{ $formatDate($report->NSF_Date) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="20" class="text-center text-muted">No records found.</td>
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

    {{-- No JS needed; buttons submit with range/export params --}}
@endsection
