@extends('layouts.app')

@section('content')
<div class="content">
    <div class="card">
        <div class="card-header">
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
                <h5 class="mb-0">EPF Audit</h5>
                <div class="d-flex align-items-center gap-2">
                    <a href="{{ url()->current() }}" class="btn btn-outline-danger btn-sm">Reset</a>
                </div>
            </div>

            <form method="get" action="{{ url('/cmd/reports/epf-audit-report') }}" id="epf-audit-form">
                <input type="hidden" name="tab" value="{{ $tab }}" id="tab-input">
                <div class="row g-3 align-items-end">
                    <div class="col-12 col-md-3">
                        <label class="form-label">From Date</label>
                        <input type="date" name="from_date" value="{{ $fromDate }}" class="form-control">
                        <small class="text-muted">Defaults to first day of previous month.</small>
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label">Cutoff Date</label>
                        <input type="date" name="cutoff" value="{{ $cutoff }}" class="form-control">
                        <small class="text-muted">Records before this date are included. Defaults to first day of next month (so current month is included).</small>
                    </div>
                    <div class="col-12 col-md-2">
                        <label class="form-label">Per page</label>
                        <select name="per_page" class="form-select">
                            @foreach([25, 50, 100, 250, 500] as $opt)
                                <option value="{{ $opt }}" @if(($perPage ?? 50) == $opt) selected @endif>{{ $opt }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-12 col-md-7 d-flex justify-content-end gap-2">
                        <button type="submit" class="btn btn-primary">Apply</button>
                        @php
                            $exportQuery = array_merge(request()->except(['_token','export','page']), ['export' => 'xlsx']);
                        @endphp
                        <a href="{{ url('/cmd/reports/epf-audit-report') . '?' . http_build_query($exportQuery) }}"
                           class="btn btn-outline-success">Export Excel</a>
                    </div>
                </div>
            </form>
        </div>

        <div class="card-body">
            @php
                $tabs = ['epfs' => 'EPFs', 'advances' => 'Advances', 'summary' => 'Summary'];
                $baseTabQuery = array_merge(request()->except(['_token','export','page','tab']), []);
            @endphp
            <ul class="nav nav-tabs mb-3">
                @foreach($tabs as $tabKey => $tabLabel)
                    @php
                        $tabUrl = url('/cmd/reports/epf-audit-report') . '?' . http_build_query(array_merge($baseTabQuery, ['tab' => $tabKey]));
                    @endphp
                    <li class="nav-item">
                        <a class="nav-link {{ $tab === $tabKey ? 'active' : '' }}" href="{{ $tabUrl }}">{{ $tabLabel }}</a>
                    </li>
                @endforeach
            </ul>

            <div class="d-flex align-items-center justify-content-between mb-2 flex-wrap gap-2">
                <div class="text-muted small">Total: {{ number_format($total) }} record(s)</div>
            </div>

            <div class="table-responsive">
                <table class="table table-striped table-hover align-middle">
                    <thead>
                        <tr>
                            @foreach($columns as $col)
                                <th class="text-nowrap">{{ $col }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($rows as $row)
                            @php $vals = array_values($row); @endphp
                            <tr>
                                @foreach($columns as $i => $col)
                                    @php
                                        $val = $vals[$i] ?? '';
                                        $out = $val;
                                        if ($val !== null && $val !== '') {
                                            $lower = strtolower((string) $col);
                                            if (str_contains($lower, 'date')) {
                                                $ts = strtotime((string) $val);
                                                $out = $ts !== false ? date('Y-m-d', $ts) : $val;
                                            } elseif ($col === 'Amount' || $col === 'Original Debt Amount') {
                                                $out = '$' . number_format((float) $val, 2);
                                            } elseif ($col === 'EPF Rate') {
                                                $num = (float) $val;
                                                $pct = $num <= 1 ? $num * 100 : $num;
                                                $out = number_format($pct, 2) . '%';
                                            }
                                        }
                                    @endphp
                                    <td class="text-nowrap">{{ $out }}</td>
                                @endforeach
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ count($columns) }}" class="text-center text-muted">No records.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @php
                $lastPage = max(1, (int) ceil($total / max(1, $perPage)));
                $baseQuery = request()->except('page');
                $baseUrl = url('/cmd/reports/epf-audit-report');
                $current = $page;
                $window = 2;
                $start = max(1, $current - $window);
                $end = min($lastPage, $current + $window);
                if ($end - $start < $window * 2) {
                    $start = max(1, $end - $window * 2);
                }
            @endphp
            <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
                <div class="text-muted small">Showing page {{ $current }} of {{ $lastPage }} &mdash; {{ number_format($total) }} total records</div>
                <nav>
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item @if($current <= 1) disabled @endif">
                            <a class="page-link" href="{{ $baseUrl . '?' . http_build_query(array_merge($baseQuery, ['page' => 1])) }}">First</a>
                        </li>
                        <li class="page-item @if($current <= 1) disabled @endif">
                            <a class="page-link" href="{{ $baseUrl . '?' . http_build_query(array_merge($baseQuery, ['page' => max(1, $current - 1)])) }}">Prev</a>
                        </li>
                        @for($p = $start; $p <= $end; $p++)
                            <li class="page-item @if($p == $current) active @endif">
                                <a class="page-link" href="{{ $baseUrl . '?' . http_build_query(array_merge($baseQuery, ['page' => $p])) }}">{{ $p }}</a>
                            </li>
                        @endfor
                        <li class="page-item @if($current >= $lastPage) disabled @endif">
                            <a class="page-link" href="{{ $baseUrl . '?' . http_build_query(array_merge($baseQuery, ['page' => min($lastPage, $current + 1)])) }}">Next</a>
                        </li>
                        <li class="page-item @if($current >= $lastPage) disabled @endif">
                            <a class="page-link" href="{{ $baseUrl . '?' . http_build_query(array_merge($baseQuery, ['page' => $lastPage])) }}">Last</a>
                        </li>
                    </ul>
                </nav>
            </div>
        </div>
    </div>
</div>
@endsection
