@extends('layouts.app')

@section('content')
    @php
        $totalRecords = method_exists($reports, 'total') ? $reports->total() : count($reports);
        $perPageValue = (int) ($perPage ?? request('per_page', 25));
        $formatCurrency = static fn($value) => $value ? '$' . number_format($value, 2) : '';
        $formatInt = static fn($value) => $value !== null ? number_format((int) $value) : '';
    @endphp

    <div class="card">
        <div class="card-header d-flex align-items-center py-0">
            <h6 class="py-3 mb-0">Sales Team Leader Commission Report</h6>
        </div>
        <div class="card-body">
            <form method="get" action="{{ route('cmd.reports.sales_team_leader_commission_report') }}" class="bg-light rounded-3 p-2 p-md-3 mb-3" id="sales-team-leader-commission-form">
                <input type="hidden" name="export" id="export" value="">

                <div class="row g-2 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label mb-1">Agent</label>
                        <input type="text" name="agent" value="{{ $filters['agent'] ?? '' }}" class="form-control form-control-sm" placeholder="Agent">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-1">Month</label>
                        <select name="month" class="form-select form-select-sm">
                            <option value="">All</option>
                            @foreach ([1=>'January',2=>'February',3=>'March',4=>'April',5=>'May',6=>'June',7=>'July',8=>'August',9=>'September',10=>'October',11=>'November',12=>'December'] as $num => $name)
                                <option value="{{ $num }}" {{ (int)($filters['month'] ?? 0) === $num ? 'selected' : '' }}>{{ $name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-1">Year</label>
                        <select name="year" class="form-select form-select-sm">
                            <option value="">All</option>
                            @for ($y = now()->year; $y >= now()->year - 10; $y--)
                                <option value="{{ $y }}" {{ (int)($filters['year'] ?? 0) === $y ? 'selected' : '' }}>{{ $y }}</option>
                            @endfor
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-1">Per Page</label>
                        <select name="per_page" class="form-select form-select-sm">
                            @foreach ([25,50,100,200,500,1000] as $n)
                                <option value="{{ $n }}" {{ $perPageValue === $n ? 'selected' : '' }}>{{ $n }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <details class="mt-2 mb-2">
                    <summary class="small fw-semibold">Advanced Filters</summary>
                    <div class="row g-2 mt-2">
                        <div class="col-md-3">
                            <label class="form-label mb-1">Min Debt Amount</label>
                            <input type="number" step="0.01" name="debt_min" value="{{ $filters['debt_min'] ?? '' }}" class="form-control form-control-sm" placeholder="Min debt">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label mb-1">Max Debt Amount</label>
                            <input type="number" step="0.01" name="debt_max" value="{{ $filters['debt_max'] ?? '' }}" class="form-control form-control-sm" placeholder="Max debt">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label mb-1">Min Enrollments</label>
                            <input type="number" step="1" name="enrollments_min" value="{{ $filters['enrollments_min'] ?? '' }}" class="form-control form-control-sm" placeholder="Min enrollments">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label mb-1">Max Enrollments</label>
                            <input type="number" step="1" name="enrollments_max" value="{{ $filters['enrollments_max'] ?? '' }}" class="form-control form-control-sm" placeholder="Max enrollments">
                        </div>
                    </div>
                </details>

                <div class="row g-2 align-items-end mt-2">
                    <div class="col-md-4">
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary btn-sm flex-fill">
                                <i class="fas fa-search me-1"></i> Search
                            </button>
                            <button type="button" class="btn btn-secondary btn-sm" onclick="clearFilters()">
                                <i class="fas fa-times me-1"></i> Clear
                            </button>
                            <button type="button" class="btn btn-success btn-sm" onclick="exportCsv()">
                                <i class="fas fa-download me-1"></i> CSV
                            </button>
                        </div>
                    </div>
                </div>
            </form>

            @if($totalRecords > 0)
                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>Agent</th>
                                <th class="text-center">Enrollments</th>
                                <th class="text-end">Debt Amount</th>
                                <th class="text-center">Lookback Count</th>
                                <th class="text-end">Lookback Debt</th>
                                <th class="text-end">Net Debt</th>
                                <th class="text-end">Commission</th>
                            </tr>
                        </thead>
                        <tbody>
                            @isset($totals)
                                <tr class="table-secondary fw-semibold">
                                    <td>Total</td>
                                    <td class="text-center">{{ $formatInt($totals['enrollments'] ?? 0) }}</td>
                                    <td class="text-end">{{ $formatCurrency($totals['debt_amount'] ?? 0) }}</td>
                                    <td class="text-center">{{ $formatInt($totals['lookback_count'] ?? 0) }}</td>
                                    <td class="text-end">{{ $formatCurrency($totals['lookback_debt'] ?? 0) }}</td>
                                    <td class="text-end">{{ $formatCurrency($totals['net_debt'] ?? 0) }}</td>
                                    <td class="text-end">{{ $formatCurrency($totals['commission'] ?? 0) }}</td>
                                </tr>
                            @endisset
                            @foreach($reports as $report)
                                <tr>
                                    <td>{{ $report->agent }}</td>
                                    <td class="text-center">{{ $formatInt($report->enrollments) }}</td>
                                    <td class="text-end">{{ $formatCurrency($report->debt_amount) }}</td>
                                    <td class="text-center">{{ $formatInt($report->lookback_count) }}</td>
                                    <td class="text-end">{{ $formatCurrency($report->lookback_debt) }}</td>
                                    <td class="text-end">{{ $formatCurrency($report->net_debt) }}</td>
                                    <td class="text-end">{{ $formatCurrency($report->commission) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="d-flex justify-content-between align-items-center mt-3">
                    <div class="text-muted">
                        Showing {{ $reports->firstItem() ?? 0 }} to {{ $reports->lastItem() ?? 0 }} of {{ $totalRecords }} entries
                    </div>
                    {{ $reports->links() }}
                </div>
            @else
                <div class="text-center py-4">
                    <i class="fas fa-search fa-3x text-muted mb-3"></i>
                    <h5 class="text-muted">No records found</h5>
                    <p class="text-muted">Try adjusting your search criteria to find what you're looking for.</p>
                </div>
            @endif
        </div>
    </div>

    <script>
        function clearFilters() {
            const form = document.getElementById('sales-team-leader-commission-form');
            const inputs = form.querySelectorAll('input, select');
            inputs.forEach(input => {
                if (input.type !== 'hidden') {
                    input.value = '';
                }
            });
            form.submit();
        }

        function exportCsv() {
            const form = document.getElementById('sales-team-leader-commission-form');
            const exportInput = document.getElementById('export');
            exportInput.value = 'csv';
            form.submit();
            exportInput.value = '';
        }
    </script>
@endsection
