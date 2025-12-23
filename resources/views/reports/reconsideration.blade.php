@extends('layouts.app')

@section('content')
    @php
        $totalRecords = method_exists($reports, 'total') ? $reports->total() : count($reports);
        $perPageValue = (int) ($perPage ?? request('per_page', 25));
    @endphp

    <div class="card">
        <div class="card-header d-flex align-items-center py-0">
            <h6 class="py-3 mb-0">Reconsideration Report</h6>
        </div>
        <div class="card-body">
            <form method="get" action="{{ route('cmd.reports.reconsideration_report') }}" class="bg-light rounded-3 p-2 p-md-3 mb-3" id="reconsideration-form">
                <input type="hidden" name="export" id="export" value="">

                <div class="row g-2 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label mb-1">ID</label>
                        <input type="text" name="id" value="{{ $filters['id'] ?? '' }}" class="form-control form-control-sm" placeholder="ID">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-1">Client</label>
                        <input type="text" name="client" value="{{ $filters['client'] ?? '' }}" class="form-control form-control-sm" placeholder="Client name">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-1">Dropped By</label>
                        <input type="text" name="dropped_by" value="{{ $filters['dropped_by'] ?? '' }}" class="form-control form-control-sm" placeholder="Dropped by">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-1">Retention Agent</label>
                        <input type="text" name="retention_agent" value="{{ $filters['retention_agent'] ?? '' }}" class="form-control form-control-sm" placeholder="Retention agent">
                    </div>
                </div>

                <div class="row g-2 align-items-end mt-2">
                    <div class="col-md-3">
                        <label class="form-label mb-1">Assigned To</label>
                        <input type="text" name="assigned_to" value="{{ $filters['assigned_to'] ?? '' }}" class="form-control form-control-sm" placeholder="Assigned to">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-1">Active Status</label>
                        <select name="active_status" class="form-select form-select-sm">
                            <option value="">All Status</option>
                            <option value="Active" {{ ($filters['active_status'] ?? '') === 'Active' ? 'selected' : '' }}>Active</option>
                            <option value="Dropped" {{ ($filters['active_status'] ?? '') === 'Dropped' ? 'selected' : '' }}>Dropped</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-1">Current Status</label>
                        <input type="text" name="current_status" value="{{ $filters['current_status'] ?? '' }}" class="form-control form-control-sm" placeholder="Current status">
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
                        <label class="form-label mb-1">Dropped Date From</label>
                        <input type="date" name="dropped_from" value="{{ $filters['dropped_from'] ?? '' }}" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-1">Dropped Date To</label>
                        <input type="date" name="dropped_to" value="{{ $filters['dropped_to'] ?? '' }}" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-1">Status Date From</label>
                        <input type="date" name="status_date_from" value="{{ $filters['status_date_from'] ?? '' }}" class="form-control form-control-sm">
                    </div>
                </div>

                <div class="row g-2 align-items-end mt-2">
                    <div class="col-md-3">
                        <label class="form-label mb-1">Status Date To</label>
                        <input type="date" name="status_date_to" value="{{ $filters['status_date_to'] ?? '' }}" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-1">Min Debt Amount</label>
                        <input type="number" name="debt_min" value="{{ $filters['debt_min'] ?? '' }}" class="form-control form-control-sm" placeholder="Min debt" step="0.01">
                    </div>
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
                                <th>CID</th>
                                <th>Client</th>
                                <th>Enrolled Date</th>
                                <th>Dropped Date</th>
                                <th>Dropped By</th>
                                <th>Enrolled Debt</th>
                                <th>Active Status</th>
                                <th>Current Status</th>
                                <th>Status Date</th>
                                <th>Last Status By</th>
                                <th>Retention Agent</th>
                                <th>Assigned To</th>
                                <th>Retention Immediate Results</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($reports as $report)
                                <tr>
                                    <td>{{ $report->llg_id }}</td>
                                    <td>{{ $report->client }}</td>
                                    <td>{{ $report->enrolled_date ? \Carbon\Carbon::parse($report->enrolled_date)->format('m/d/Y') : '' }}</td>
                                    <td>{{ $report->dropped_date ? \Carbon\Carbon::parse($report->dropped_date)->format('m/d/Y') : '' }}</td>
                                    <td>{{ $report->dropped_by }}</td>
                                    <td class="text-end">{{ $report->debt_amount ? '$' . number_format($report->debt_amount, 2) : '' }}</td>
                                    <td>
                                        @if($report->active_status === 'Active')
                                            <span class="badge bg-success">{{ $report->active_status }}</span>
                                        @else
                                            <span class="badge bg-danger">{{ $report->active_status }}</span>
                                        @endif
                                    </td>
                                    <td>{{ $report->current_status }}</td>
                                    <td>{{ $report->status_date }}</td>
                                    <td>{{ $report->last_status_by }}</td>
                                    <td>{{ $report->retention_agent }}</td>
                                    <td>{{ $report->assigned_to }}</td>
                                    <td>{{ $report->retention_immediate_results }}</td>
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
            const form = document.getElementById('reconsideration-form');
            const inputs = form.querySelectorAll('input, select');
            inputs.forEach(input => {
                if (input.type !== 'hidden') {
                    input.value = '';
                }
            });
            form.submit();
        }

        function exportCsv() {
            const form = document.getElementById('reconsideration-form');
            const exportInput = document.getElementById('export');
            exportInput.value = 'csv';
            form.submit();
            exportInput.value = '';
        }
    </script>
@endsection
