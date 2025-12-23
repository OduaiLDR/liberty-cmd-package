@extends('layouts.app')

@section('content')
    @php
        $totalRecords = method_exists($reports, 'total') ? $reports->total() : count($reports);
        $perPageValue = (int) ($perPage ?? request('per_page', 25));
    @endphp

    <div class="card">
        <div class="card-header d-flex align-items-center py-0">
            <h6 class="py-3 mb-0">Welcome Letter Report</h6>
        </div>
        <div class="card-body">
            <form method="get" action="{{ route('cmd.reports.welcome_letter_report') }}" class="bg-light rounded-3 p-2 p-md-3 mb-3" id="welcome-letter-form">
                <input type="hidden" name="export" id="export" value="">

                <div class="row g-2 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label mb-1">Client</label>
                        <input type="text" name="client" value="{{ $filters['client'] ?? '' }}" class="form-control form-control-sm" placeholder="Client name">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-1">Plan</label>
                        <input type="text" name="plan" value="{{ $filters['plan'] ?? '' }}" class="form-control form-control-sm" placeholder="Enrollment plan">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-1">Frequency</label>
                        <select name="frequency" class="form-select form-select-sm">
                            <option value="">All Frequencies</option>
                            <option value="all" {{ ($filters['frequency'] ?? '') === 'all' ? 'selected' : '' }}>All</option>
                            <option value="weekly" {{ ($filters['frequency'] ?? '') === 'weekly' ? 'selected' : '' }}>Weekly</option>
                            <option value="bi-weekly" {{ ($filters['frequency'] ?? '') === 'bi-weekly' ? 'selected' : '' }}>Bi-Weekly</option>
                            <option value="semi-monthly" {{ ($filters['frequency'] ?? '') === 'semi-monthly' ? 'selected' : '' }}>Semi-Monthly</option>
                            <option value="monthly" {{ ($filters['frequency'] ?? '') === 'monthly' ? 'selected' : '' }}>Monthly</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-1">Enrolled Date From</label>
                        <input type="date" name="enrolled_from" value="{{ $filters['enrolled_from'] ?? '' }}" class="form-control form-control-sm">
                    </div>
                </div>

                <div class="row g-2 align-items-end mt-2">
                    <div class="col-md-3">
                        <label class="form-label mb-1">Enrolled Date To</label>
                        <input type="date" name="enrolled_to" value="{{ $filters['enrolled_to'] ?? '' }}" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-1">Payment Date From</label>
                        <input type="date" name="payment_from" value="{{ $filters['payment_from'] ?? '' }}" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-1">Payment Date To</label>
                        <input type="date" name="payment_to" value="{{ $filters['payment_to'] ?? '' }}" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-1">Min Debt Amount</label>
                        <input type="number" name="debt_min" value="{{ $filters['debt_min'] ?? '' }}" class="form-control form-control-sm" placeholder="Min debt" step="0.01">
                    </div>
                </div>

                <div class="row g-2 align-items-end mt-2">
                    <div class="col-md-3">
                        <label class="form-label mb-1">Max Debt Amount</label>
                        <input type="number" name="debt_max" value="{{ $filters['debt_max'] ?? '' }}" class="form-control form-control-sm" placeholder="Max debt" step="0.01">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-1">Per Page</label>
                        <select name="per_page" class="form-select form-select-sm">
                            <option value="25" {{ $perPageValue === 25 ? 'selected' : '' }}>25</option>
                            <option value="50" {{ $perPageValue === 50 ? 'selected' : '' }}>50</option>
                            <option value="100" {{ $perPageValue === 100 ? 'selected' : '' }}>100</option>
                            <option value="200" {{ $perPageValue === 200 ? 'selected' : '' }}>200</option>
                            <option value="500" {{ $perPageValue === 500 ? 'selected' : '' }}>500</option>
                            <option value="1000" {{ $perPageValue === 1000 ? 'selected' : '' }}>1000</option>
                        </select>
                    </div>
                    <div class="col-md-3">
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
                                <th>Client</th>
                                <th>Plan</th>
                                <th>Enrolled Debt Accounts</th>
                                <th>Enrolled Debt</th>
                                <th>Payment Date</th>
                                <th>Payment</th>
                                <th>LLG ID</th>
                                <th>Frequency</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($reports as $report)
                                <tr>
                                    <td>{{ $report->client }}</td>
                                    <td>{{ $report->plan }}</td>
                                    <td class="text-center">{{ $report->enrolled_debt_accounts }}</td>
                                    <td class="text-end">{{ $report->enrolled_debt ? '$' . number_format($report->enrolled_debt, 2) : '' }}</td>
                                    <td>{{ $report->payment_date ? \Carbon\Carbon::parse($report->payment_date)->format('m/d/Y') : '' }}</td>
                                    <td class="text-end">{{ $report->payment ? '$' . number_format($report->payment, 2) : '' }}</td>
                                    <td>{{ $report->llg_id }}</td>
                                    <td>
                                        @if($report->frequency === 'Weekly')
                                            <span class="badge bg-info">{{ $report->frequency }}</span>
                                        @elseif($report->frequency === 'Bi-Weekly')
                                            <span class="badge bg-primary">{{ $report->frequency }}</span>
                                        @elseif($report->frequency === 'Semi-Monthly')
                                            <span class="badge bg-warning">{{ $report->frequency }}</span>
                                        @elseif($report->frequency === 'Monthly')
                                            <span class="badge bg-success">{{ $report->frequency }}</span>
                                        @else
                                            <span class="badge bg-secondary">{{ $report->frequency }}</span>
                                        @endif
                                    </td>
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
            const form = document.getElementById('welcome-letter-form');
            const inputs = form.querySelectorAll('input, select');
            inputs.forEach(input => {
                if (input.type !== 'hidden') {
                    input.value = '';
                }
            });
            form.submit();
        }

        function exportCsv() {
            const form = document.getElementById('welcome-letter-form');
            const exportInput = document.getElementById('export');
            exportInput.value = 'csv';
            form.submit();
            exportInput.value = '';
        }
    </script>
@endsection
