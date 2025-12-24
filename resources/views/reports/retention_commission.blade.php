@extends('layouts.app')

@section('content')
    @php
        $totalRecords = method_exists($reports, 'total') ? $reports->total() : count($reports);
        $perPageValue = (int) ($perPage ?? request('per_page', 25));
    @endphp

    <div class="card">
        <div class="card-header d-flex align-items-center py-0">
            <h6 class="py-3 mb-0">Retention Commission Report</h6>
        </div>
        <div class="card-body">
            <form method="get" action="{{ route('cmd.reports.retention_commission_report') }}" class="bg-light rounded-3 p-2 p-md-3 mb-3" id="retention-commission-form">
                <input type="hidden" name="export" id="export" value="">

                <div class="row g-2 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label mb-1">Client</label>
                        <input type="text" name="client" value="{{ $filters['client'] ?? '' }}" class="form-control form-control-sm" placeholder="Client name">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-1">Retention Agent</label>
                        <select name="retention_agent" class="form-select form-select-sm">
                            <option value="">All Agents</option>
                            @foreach($opts['retention_agents'] as $agent)
                                <option value="{{ $agent }}" {{ ($filters['retention_agent'] ?? '') === $agent ? 'selected' : '' }}>{{ $agent }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-1">Immediate Results</label>
                        <input type="text" name="immediate_results" value="{{ $filters['immediate_results'] ?? '' }}" class="form-control form-control-sm" placeholder="Immediate results">
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
                </div>

                <div class="row g-2 align-items-end mt-2">
                    <div class="col-md-3">
                        <label class="form-label mb-1">Retention Date From</label>
                        <input type="date" name="retention_date_from" value="{{ $filters['retention_date_from'] ?? '' }}" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-1">Retention Date To</label>
                        <input type="date" name="retention_date_to" value="{{ $filters['retention_date_to'] ?? '' }}" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-1">Dropped Date From</label>
                        <input type="date" name="dropped_date_from" value="{{ $filters['dropped_date_from'] ?? '' }}" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-1">Dropped Date To</label>
                        <input type="date" name="dropped_date_to" value="{{ $filters['dropped_date_to'] ?? '' }}" class="form-control form-control-sm">
                    </div>
                </div>

                <div class="row g-2 align-items-end mt-2">
                    <div class="col-md-3">
                        <label class="form-label mb-1">Min Enrolled Debt</label>
                        <input type="number" name="enrolled_debt_min" value="{{ $filters['enrolled_debt_min'] ?? '' }}" class="form-control form-control-sm" placeholder="Min debt" step="0.01">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-1">Max Enrolled Debt</label>
                        <input type="number" name="enrolled_debt_max" value="{{ $filters['enrolled_debt_max'] ?? '' }}" class="form-control form-control-sm" placeholder="Max debt" step="0.01">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-1">Reconsideration Date From</label>
                        <input type="date" name="reconsideration_date_from" value="{{ $filters['reconsideration_date_from'] ?? '' }}" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-1">Reconsideration Date To</label>
                        <input type="date" name="reconsideration_date_to" value="{{ $filters['reconsideration_date_to'] ?? '' }}" class="form-control form-control-sm">
                    </div>
                </div>

                <div class="row g-2 align-items-end mt-2">
                    <div class="col-md-3">
                        <label class="form-label mb-1">Retained Date From</label>
                        <input type="date" name="retained_date_from" value="{{ $filters['retained_date_from'] ?? '' }}" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-1">Retained Date To</label>
                        <input type="date" name="retained_date_to" value="{{ $filters['retained_date_to'] ?? '' }}" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-1">Retention Payment Date From</label>
                        <input type="date" name="retention_payment_date_from" value="{{ $filters['retention_payment_date_from'] ?? '' }}" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-1">Retention Payment Date To</label>
                        <input type="date" name="retention_payment_date_to" value="{{ $filters['retention_payment_date_to'] ?? '' }}" class="form-control form-control-sm">
                    </div>
                </div>

                <div class="row g-2 align-items-end mt-2">
                    <div class="col-md-3">
                        <label class="form-label mb-1">Cancel Request Date From</label>
                        <input type="date" name="cancel_request_date_from" value="{{ $filters['cancel_request_date_from'] ?? '' }}" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-1">Cancel Request Date To</label>
                        <input type="date" name="cancel_request_date_to" value="{{ $filters['cancel_request_date_to'] ?? '' }}" class="form-control form-control-sm">
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
                                <th>ID</th>
                                <th>Client</th>
                                <th>Retention Agent</th>
                                <th>Retention Date</th>
                                <th>Immediate Results</th>
                                <th>Enrolled Debt</th>
                                <th>Cleared Payments</th>
                                <th>Reconsideration Date</th>
                                <th>Dropped Date</th>
                                <th>Retained Date</th>
                                <th>Retention Payment Date</th>
                                <th>Commission T1</th>
                                <th>Commission T2</th>
                                <th>Commission T3</th>
                                <th>Cancel Request Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($reports as $report)
                                <tr>
                                    <td>{{ $report->id }}</td>
                                    <td>{{ $report->client }}</td>
                                    <td>{{ $report->retention_agent }}</td>
                                    <td>{{ $report->retention_date ? \Carbon\Carbon::parse($report->retention_date)->format('m/d/Y') : '' }}</td>
                                    <td>{{ $report->immediate_results }}</td>
                                    <td class="text-end">{{ $report->enrolled_debt ? '$' . number_format($report->enrolled_debt, 2) : '' }}</td>
                                    <td class="text-center">{{ $report->cleared_payments }}</td>
                                    <td>{{ $report->reconsideration_date ? \Carbon\Carbon::parse($report->reconsideration_date)->format('m/d/Y') : '' }}</td>
                                    <td>{{ $report->dropped_date ? \Carbon\Carbon::parse($report->dropped_date)->format('m/d/Y') : '' }}</td>
                                    <td>{{ $report->retained_date ? \Carbon\Carbon::parse($report->retained_date)->format('m/d/Y') : '' }}</td>
                                    <td>{{ $report->retention_payment_date ? \Carbon\Carbon::parse($report->retention_payment_date)->format('m/d/Y') : '' }}</td>
                                    <td class="text-end">{{ $report->retention_commission_t1 ? '$' . number_format($report->retention_commission_t1, 2) : '' }}</td>
                                    <td class="text-end">{{ $report->retention_commission_t2 ? '$' . number_format($report->retention_commission_t2, 2) : '' }}</td>
                                    <td class="text-end">{{ $report->retention_commission_t3 ? '$' . number_format($report->retention_commission_t3, 2) : '' }}</td>
                                    <td>{{ $report->cancel_request_date ? \Carbon\Carbon::parse($report->cancel_request_date)->format('m/d/Y') : '' }}</td>
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
            const form = document.getElementById('retention-commission-form');
            const inputs = form.querySelectorAll('input, select');
            inputs.forEach(input => {
                if (input.type !== 'hidden') {
                    input.value = '';
                }
            });
            form.submit();
        }

        function exportCsv() {
            const form = document.getElementById('retention-commission-form');
            const exportInput = document.getElementById('export');
            exportInput.value = 'csv';
            form.submit();
            exportInput.value = '';
        }
    </script>
@endsection
