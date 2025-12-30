@extends('layouts.app')

@section('content')
    @php
        $totalRecords = method_exists($reports, 'total') ? $reports->total() : count($reports);
        $perPageValue = (int) ($perPage ?? request('per_page', 25));
    @endphp

    <div class="card">
        <div class="card-header d-flex align-items-center py-0">
            <h6 class="py-3 mb-0">Client Submission Report</h6>
        </div>
        <div class="card-body">
            <form method="get" action="{{ route('cmd.reports.client_submission_report') }}" class="bg-light rounded-3 p-2 p-md-3 mb-3" id="client-submission-form">
                <input type="hidden" name="export" id="export" value="">

                <div class="row g-2 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label mb-1">Agent</label>
                        <input type="text" name="agent" value="{{ $filters['agent'] ?? '' }}" class="form-control form-control-sm" placeholder="Agent">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-1">Client</label>
                        <input type="text" name="client" value="{{ $filters['client'] ?? '' }}" class="form-control form-control-sm" placeholder="Client">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-1">LLG ID</label>
                        <input type="text" name="llg_id" value="{{ $filters['llg_id'] ?? '' }}" class="form-control form-control-sm" placeholder="LLG ID">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-1">Date From</label>
                        <input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-1">Date To</label>
                        <input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-1">First Payment From</label>
                        <input type="date" name="first_payment_from" value="{{ $filters['first_payment_from'] ?? '' }}" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-1">First Payment To</label>
                        <input type="date" name="first_payment_to" value="{{ $filters['first_payment_to'] ?? '' }}" class="form-control form-control-sm">
                    </div>
                </div>

                <div class="row g-2 align-items-end mt-2">
                    <div class="col-md-3">
                        <label class="form-label mb-1">Min Enrolled Debt</label>
                        <input type="number" name="debt_min" value="{{ $filters['debt_min'] ?? '' }}" class="form-control form-control-sm" placeholder="Min debt" step="0.01">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-1">Max Enrolled Debt</label>
                        <input type="number" name="debt_max" value="{{ $filters['debt_max'] ?? '' }}" class="form-control form-control-sm" placeholder="Max debt" step="0.01">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-1">Min Program Length</label>
                        <input type="number" name="program_length_min" value="{{ $filters['program_length_min'] ?? '' }}" class="form-control form-control-sm" placeholder="Min length" step="0.01">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-1">Max Program Length</label>
                        <input type="number" name="program_length_max" value="{{ $filters['program_length_max'] ?? '' }}" class="form-control form-control-sm" placeholder="Max length" step="0.01">
                    </div>
                </div>

                <div class="row g-2 align-items-end mt-2">
                    <div class="col-md-3">
                        <label class="form-label mb-1">Min Monthly Deposit</label>
                        <input type="number" name="monthly_deposit_min" value="{{ $filters['monthly_deposit_min'] ?? '' }}" class="form-control form-control-sm" placeholder="Min deposit" step="0.01">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-1">Max Monthly Deposit</label>
                        <input type="number" name="monthly_deposit_max" value="{{ $filters['monthly_deposit_max'] ?? '' }}" class="form-control form-control-sm" placeholder="Max deposit" step="0.01">
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
                                <th>Date</th>
                                <th>Agent</th>
                                <th>Record ID</th>
                                <th>Client</th>
                                <th>First Payment Date</th>
                                <th>Enrolled Debt Amount</th>
                                <th>Program Length</th>
                                <th>Monthly Deposit</th>
                                <th>Veritas Initial Setup Fee</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($reports as $report)
                                <tr>
                                    <td>{{ $report->date ? \Carbon\Carbon::parse($report->date)->format('m/d/Y') : '' }}</td>
                                    <td>{{ $report->agent }}</td>
                                    <td>{{ $report->record_id }}</td>
                                    <td>{{ $report->client }}</td>
                                    <td>{{ $report->first_payment_date ? \Carbon\Carbon::parse($report->first_payment_date)->format('m/d/Y') : '' }}</td>
                                    <td class="text-end">{{ $report->enrolled_debt_amount ? '$' . number_format($report->enrolled_debt_amount, 2) : '' }}</td>
                                    <td class="text-end">{{ $report->program_length }}</td>
                                    <td class="text-end">{{ $report->monthly_deposit ? '$' . number_format($report->monthly_deposit, 2) : '' }}</td>
                                    <td class="text-end">{{ $report->veritas_initial_setup_fee ? '$' . number_format($report->veritas_initial_setup_fee, 2) : '' }}</td>
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
            const form = document.getElementById('client-submission-form');
            const inputs = form.querySelectorAll('input, select');
            inputs.forEach(input => {
                if (input.type !== 'hidden') {
                    input.value = '';
                }
            });
            form.submit();
        }

        function exportCsv() {
            const form = document.getElementById('client-submission-form');
            const exportInput = document.getElementById('export');
            exportInput.value = 'csv';
            form.submit();
            exportInput.value = '';
        }
    </script>
@endsection
