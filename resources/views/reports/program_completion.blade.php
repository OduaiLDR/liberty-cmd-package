@extends('layouts.app')

@section('content')
    @php
        $formatMoney = static fn($value) => $value !== null ? '$' . number_format(format_currency_string($value), 2) : '';
        $formatPercent = static fn($value) => $value !== null ? number_format((float) $value * 100, 2) . '%' : '';
        $formatDate = static fn($value) => $value ? \Carbon\Carbon::parse($value)->format('m/d/Y') : '';
        $currentRange = $range ?? 'all';
        $perPageValue = (int) ($perPage ?? request('per_page', 25));
        $totalRecords = method_exists($reports, 'total') ? $reports->total() : count($reports);
    @endphp

    <div class="card">
        <div class="card-header d-flex align-items-center py-0">
            <h6 class="py-3 mb-0">Program Completion Report</h6>
        </div>
        <div class="card-body">
            <form method="get" action="{{ route('cmd.reports.program_completion') }}" class="bg-light rounded-3 p-2 p-md-3 mb-3">
                <div class="row g-2 align-items-center">
                    <div class="col-auto fw-semibold">
                        Settlement Date:
                    </div>
                    <div class="col d-flex flex-wrap gap-2 align-items-center">
                        <div class="btn-group btn-group-sm" role="group" aria-label="Quick date ranges">
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
                                <button type="submit" name="range" value="{{ $value }}"
                                    class="btn btn-outline-primary {{ $currentRange === $value ? 'active' : '' }}">
                                    {{ $label }}
                                </button>
                            @endforeach
                        </div>
                        <button type="submit" name="export" value="csv" class="btn btn-secondary btn-sm">Export CSV</button>
                    </div>
                </div>

                <div class="row g-2 align-items-center justify-content-end mt-2">
                    <div class="col-auto">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text">From</span>
                            <input type="date" name="from" id="from" value="{{ old('from', $from) }}" class="form-control">
                        </div>
                    </div>
                    <div class="col-auto">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text">To</span>
                            <input type="date" name="to" id="to" value="{{ old('to', $to) }}" class="form-control">
                        </div>
                    </div>
                </div>

                <details class="mt-2 mb-2">
                    <summary class="small fw-semibold">Filters</summary>
                    <div class="row g-2 mt-2">
                        <div class="col-md-3">
                            <label class="form-label form-label-sm">CID (contains)</label>
                            <input type="text" name="llg_id" value="{{ request('llg_id', $filters['llg_id'] ?? '') }}" class="form-control form-control-sm"
                                autocomplete="off">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label form-label-sm">Client (contains)</label>
                            <input type="text" name="client" value="{{ request('client', $filters['client'] ?? '') }}" class="form-control form-control-sm"
                                autocomplete="off">
                        </div>
                    </div>
                </details>

                <div class="row g-2 align-items-center mt-2">
                    <div class="col-auto">
                        <button type="submit" name="range" value="custom" class="btn btn-primary btn-sm">Filter</button>
                    </div>
                    <div class="col-auto">
                        <a href="{{ route('cmd.reports.program_completion') }}" class="btn btn-light btn-sm">Reset</a>
                    </div>
                    <div class="col-auto ms-auto">
                        <div class="input-group input-group-sm" style="width: auto;">
                            <span class="input-group-text">Per Page</span>
                            <select name="per_page" id="per_page" class="form-select form-select-sm">
                                @foreach ([25, 50, 100, 250, 500] as $n)
                                    <option value="{{ $n }}" {{ $perPageValue === $n ? 'selected' : '' }}>{{ $n }}</option>
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
                <table class="table table-striped table-bordered table-hover align-middle">
                    <thead>
                        <tr>
                            <th>CID</th>
                            <th>Client</th>
                            <th>Welcome Call Date</th>
                            <th class="text-end">Total Settlement Amounts Accepted</th>
                            <th class="text-end">Original Debt Amount Settled</th>
                            <th class="text-end">Enrolled Debt</th>
                            <th class="text-end">Settlement Rate</th>
                            <th class="text-end">Program Completion</th>
                            <th>Latest Settlement Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($reports as $report)
                            <tr>
                                <td>{{ preg_replace('/\D+/', '', (string) $report->LLG_ID) }}</td>
                                <td>{{ $report->Client }}</td>
                                <td>{{ $formatDate($report->Welcome_Call_Date) }}</td>
                                <td class="text-end">{{ $formatMoney($report->Total_Settlement_Amounts_Accepted) }}</td>
                                <td class="text-end">{{ $formatMoney($report->Original_Debt_Amount_Settled) }}</td>
                                <td class="text-end">{{ $formatMoney($report->Enrolled_Debt) }}</td>
                                <td class="text-end">{{ $formatPercent($report->Settlement_Rate) }}</td>
                                <td class="text-end">{{ $formatPercent($report->Program_Completion) }}</td>
                                <td>{{ $formatDate($report->Latest_Settlement_Date) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="text-center text-muted">No records found.</td>
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
