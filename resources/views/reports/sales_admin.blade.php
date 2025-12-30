@extends('layouts.app')

@section('content')
    @php
        $totalRecords = method_exists($reports, 'total') ? $reports->total() : count($reports);
        $perPageValue = (int) ($perPage ?? request('per_page', 25));
    @endphp

    <div class="card">
        <div class="card-header d-flex align-items-center py-0">
            <h6 class="py-3 mb-0">Sales Admin Report</h6>
        </div>
        <div class="card-body">
            <form method="get" action="{{ route('cmd.reports.sales_admin_report') }}" class="bg-light rounded-3 p-2 p-md-3 mb-3" id="sales-admin-form">
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
                        <label class="form-label mb-1">Payment Date From</label>
                        <input type="date" name="payment_date_from" value="{{ $filters['payment_date_from'] ?? '' }}" class="form-control form-control-sm">
                    </div>
                </div>

                <div class="row g-2 align-items-end mt-2">
                    <div class="col-md-3">
                        <label class="form-label mb-1">Payment Date To</label>
                        <input type="date" name="payment_date_to" value="{{ $filters['payment_date_to'] ?? '' }}" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-1">Min Debt</label>
                        <input type="number" step="0.01" name="debt_min" value="{{ $filters['debt_min'] ?? '' }}" class="form-control form-control-sm" placeholder="Min debt">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-1">Max Debt</label>
                        <input type="number" step="0.01" name="debt_max" value="{{ $filters['debt_max'] ?? '' }}" class="form-control form-control-sm" placeholder="Max debt">
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
                            <label class="form-label mb-1">Min Program Length</label>
                            <input type="number" step="0.01" name="program_length_min" value="{{ $filters['program_length_min'] ?? '' }}" class="form-control form-control-sm" placeholder="Min length">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label mb-1">Max Program Length</label>
                            <input type="number" step="0.01" name="program_length_max" value="{{ $filters['program_length_max'] ?? '' }}" class="form-control form-control-sm" placeholder="Max length">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label mb-1">Min Payments</label>
                            <input type="number" step="0.01" name="payments_min" value="{{ $filters['payments_min'] ?? '' }}" class="form-control form-control-sm" placeholder="Min payments">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label mb-1">Max Payments</label>
                            <input type="number" step="0.01" name="payments_max" value="{{ $filters['payments_max'] ?? '' }}" class="form-control form-control-sm" placeholder="Max payments">
                        </div>
                    </div>
                    <div class="row g-2 mt-2">
                        <div class="col-md-3">
                            <label class="form-label mb-1">Cancel From</label>
                            <input type="date" name="cancel_from" value="{{ $filters['cancel_from'] ?? '' }}" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label mb-1">Cancel To</label>
                            <input type="date" name="cancel_to" value="{{ $filters['cancel_to'] ?? '' }}" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label mb-1">NSF From</label>
                            <input type="date" name="nsf_from" value="{{ $filters['nsf_from'] ?? '' }}" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label mb-1">NSF To</label>
                            <input type="date" name="nsf_to" value="{{ $filters['nsf_to'] ?? '' }}" class="form-control form-control-sm">
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
                                <th>LLG ID</th>
                                <th>Agent</th>
                                <th>Client</th>
                                <th>Payment Date</th>
                                <th>Debt Amount</th>
                                <th>Program Length</th>
                                <th>Monthly Deposit</th>
                                <th>Payments</th>
                                <th>Cancel Date</th>
                                <th>NSF Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($reports as $report)
                                <tr>
                                    <td>{{ $report->llg_id }}</td>
                                    <td>{{ $report->agent }}</td>
                                    <td>{{ $report->client }}</td>
                                    <td>{{ $report->payment_date ? \Carbon\Carbon::parse($report->payment_date)->format('m/d/Y') : '' }}</td>
                                    <td class="text-end">{{ $report->debt_amount ? '$' . number_format($report->debt_amount, 2) : '' }}</td>
                                    <td class="text-end">{{ $report->program_length }}</td>
                                    <td class="text-end">{{ $report->monthly_deposit ? '$' . number_format($report->monthly_deposit, 2) : '' }}</td>
                                    <td class="text-end">{{ $report->payments }}</td>
                                    <td>{{ $report->cancel_date ? \Carbon\Carbon::parse($report->cancel_date)->format('m/d/Y') : '' }}</td>
                                    <td>{{ $report->nsf_date ? \Carbon\Carbon::parse($report->nsf_date)->format('m/d/Y') : '' }}</td>
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
            const form = document.getElementById('sales-admin-form');
            const inputs = form.querySelectorAll('input, select');
            inputs.forEach(input => {
                if (input.type !== 'hidden') {
                    input.value = '';
                }
            });
            form.submit();
        }

        function exportCsv() {
            const form = document.getElementById('sales-admin-form');
            const exportInput = document.getElementById('export');
            exportInput.value = 'csv';
            form.submit();
            exportInput.value = '';
        }
    </script>
@endsection
