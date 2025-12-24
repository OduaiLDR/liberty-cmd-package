@extends('layouts.app')

@section('content')
    @php
        $totalRecords = method_exists($reports, 'total') ? $reports->total() : count($reports);
        $perPageValue = (int) ($perPage ?? request('per_page', 25));
    @endphp

    <div class="card">
        <div class="card-header d-flex align-items-center py-0">
            <h6 class="py-3 mb-0">Uncleared Settlement Payments Report</h6>
        </div>
        <div class="card-body">
            <form method="get" action="{{ route('cmd.reports.uncleared_settlement_payments_report') }}" class="bg-light rounded-3 p-2 p-md-3 mb-3" id="uncleared-payments-form">
                <input type="hidden" name="export" id="export" value="">

                <div class="row g-2 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label mb-1">LLG ID</label>
                        <input type="text" name="llg_id" value="{{ $filters['llg_id'] ?? '' }}" class="form-control form-control-sm" placeholder="LLG ID">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-1">Process Date From</label>
                        <input type="date" name="process_date_from" value="{{ $filters['process_date_from'] ?? '' }}" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-1">Process Date To</label>
                        <input type="date" name="process_date_to" value="{{ $filters['process_date_to'] ?? '' }}" class="form-control form-control-sm">
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
                        <label class="form-label mb-1">Min Amount</label>
                        <input type="number" name="amount_min" value="{{ $filters['amount_min'] ?? '' }}" class="form-control form-control-sm" placeholder="Min amount" step="0.01">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-1">Max Amount</label>
                        <input type="number" name="amount_max" value="{{ $filters['amount_max'] ?? '' }}" class="form-control form-control-sm" placeholder="Max amount" step="0.01">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-1">Memo (contains)</label>
                        <input type="text" name="memo" value="{{ $filters['memo'] ?? '' }}" class="form-control form-control-sm" placeholder="Memo">
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
                                <th>LLG ID</th>
                                <th>Process Date</th>
                                <th>Amount</th>
                                <th>Memo</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($reports as $report)
                                <tr>
                                    <td>{{ $report->llg_id }}</td>
                                    <td>{{ $report->process_date ? \Carbon\Carbon::parse($report->process_date)->format('m/d/Y') : '' }}</td>
                                    <td class="text-end">{{ $report->amount ? '$' . number_format($report->amount, 2) : '' }}</td>
                                    <td>{{ $report->memo }}</td>
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
            const form = document.getElementById('uncleared-payments-form');
            const inputs = form.querySelectorAll('input, select');
            inputs.forEach(input => {
                if (input.type !== 'hidden') {
                    input.value = '';
                }
            });
            form.submit();
        }

        function exportCsv() {
            const form = document.getElementById('uncleared-payments-form');
            const exportInput = document.getElementById('export');
            exportInput.value = 'csv';
            form.submit();
            exportInput.value = '';
        }
    </script>
@endsection
