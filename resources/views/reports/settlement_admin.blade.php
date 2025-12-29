@extends('layouts.app')

@section('content')
    @php
        $totalRecords = method_exists($reports, 'total') ? $reports->total() : count($reports);
        $perPageValue = (int) ($perPage ?? request('per_page', 25));
    @endphp

    <div class="card">
        <div class="card-header d-flex align-items-center py-0">
            <h6 class="py-3 mb-0">Settlement Admin Report</h6>
        </div>
        <div class="card-body">
            <form method="get" action="{{ route('cmd.reports.settlement_admin_report') }}" class="bg-light rounded-3 p-2 p-md-3 mb-3" id="settlement-admin-form">
                <input type="hidden" name="export" id="export" value="">

                <div class="row g-2 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label mb-1">Contact Name</label>
                        <input type="text" name="contact_name" value="{{ $filters['contact_name'] ?? '' }}" class="form-control form-control-sm" placeholder="Contact name">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-1">LLG ID</label>
                        <input type="text" name="llg_id" value="{{ $filters['llg_id'] ?? '' }}" class="form-control form-control-sm" placeholder="LLG ID">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-1">Settlement From</label>
                        <input type="date" name="settlement_from" value="{{ $filters['settlement_from'] ?? '' }}" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-1">Settlement To</label>
                        <input type="date" name="settlement_to" value="{{ $filters['settlement_to'] ?? '' }}" class="form-control form-control-sm">
                    </div>
                </div>

                <div class="row g-2 align-items-end mt-2">
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
                @php
                    // Calculate summary totals
                    $totalOriginalDebt = 0;
                    foreach ($reports as $r) {
                        $totalOriginalDebt += (float) ($r->original_debt_amount ?? 0);
                    }
                @endphp

                <div class="alert alert-warning mb-3">
                    <div class="row">
                        <div class="col-md-4">
                            <strong>Total Records:</strong> {{ $totalRecords }}
                        </div>
                        <div class="col-md-4">
                            <strong>Total Original Debt:</strong> ${{ number_format($totalOriginalDebt, 2) }}
                        </div>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>LLG ID</th>
                                <th>Original Debt Amount</th>
                                <th>Settlement ID</th>
                                <th>Creditor Name</th>
                                <th>Collection Company</th>
                                <th>Settlement Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="table-warning fw-bold">
                                <td>TOTAL</td>
                                <td class="text-end">${{ number_format($totalOriginalDebt, 2) }}</td>
                                <td>-</td>
                                <td>-</td>
                                <td>-</td>
                                <td>-</td>
                            </tr>
                            @foreach($reports as $report)
                                <tr>
                                    <td>{{ $report->llg_id ?? '' }}</td>
                                    <td class="text-end">{{ $report->original_debt_amount ? '$' . number_format($report->original_debt_amount, 2) : '' }}</td>
                                    <td>{{ $report->settlement_id ?? '' }}</td>
                                    <td>{{ $report->creditor_name ?? '' }}</td>
                                    <td>{{ $report->collection_company ?? '' }}</td>
                                    <td>{{ $report->settlement_date ? \Carbon\Carbon::parse($report->settlement_date)->format('m/d/Y') : '' }}</td>
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
            const form = document.getElementById('settlement-admin-form');
            const inputs = form.querySelectorAll('input, select');
            inputs.forEach(input => {
                if (input.type !== 'hidden') {
                    input.value = '';
                }
            });
            form.submit();
        }

        function exportCsv() {
            const form = document.getElementById('settlement-admin-form');
            const exportInput = document.getElementById('export');
            exportInput.value = 'csv';
            form.submit();
            exportInput.value = '';
        }
    </script>
@endsection
