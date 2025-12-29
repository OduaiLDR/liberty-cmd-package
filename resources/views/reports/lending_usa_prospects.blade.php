@extends('layouts.app')

@section('content')
    @php
        $totalRecords = method_exists($reports, 'total') ? $reports->total() : count($reports);
        $perPageValue = (int) ($perPage ?? request('per_page', 25));
    @endphp

    <div class="card">
        <div class="card-header d-flex align-items-center py-0">
            <h6 class="py-3 mb-0">Lending USA Prospects</h6>
        </div>
        <div class="card-body">
            <form method="get" action="{{ route('cmd.reports.lending_usa_prospects_report') }}" class="bg-light rounded-3 p-2 p-md-3 mb-3" id="lending-usa-prospects-form">
                <input type="hidden" name="export" id="export" value="">

                <div class="row g-2 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label mb-1">LLG ID</label>
                        <input type="text" name="llg_id" value="{{ $filters['llg_id'] ?? '' }}" class="form-control form-control-sm" placeholder="LLG ID">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-1">Client</label>
                        <input type="text" name="client" value="{{ $filters['client'] ?? '' }}" class="form-control form-control-sm" placeholder="Client name">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-1">State</label>
                        <select name="state" class="form-select form-select-sm">
                            <option value="">All States</option>
                            @foreach($opts['states'] ?? [] as $state)
                                <option value="{{ $state }}" {{ ($filters['state'] ?? '') === $state ? 'selected' : '' }}>{{ $state }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-1">First Payment From</label>
                        <input type="date" name="first_payment_from" value="{{ $filters['first_payment_from'] ?? '' }}" class="form-control form-control-sm">
                    </div>
                </div>

                <div class="row g-2 align-items-end mt-2">
                    <div class="col-md-3">
                        <label class="form-label mb-1">First Payment To</label>
                        <input type="date" name="first_payment_to" value="{{ $filters['first_payment_to'] ?? '' }}" class="form-control form-control-sm">
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
                        <label class="form-label mb-1">Min Balance</label>
                        <input type="number" name="balance_min" value="{{ $filters['balance_min'] ?? '' }}" class="form-control form-control-sm" placeholder="Min balance" step="0.01">
                    </div>
                </div>

                <div class="row g-2 align-items-end mt-2">
                    <div class="col-md-3">
                        <label class="form-label mb-1">Max Balance</label>
                        <input type="number" name="balance_max" value="{{ $filters['balance_max'] ?? '' }}" class="form-control form-control-sm" placeholder="Max balance" step="0.01">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-1">Min Payments</label>
                        <input type="number" name="payments_min" value="{{ $filters['payments_min'] ?? '' }}" class="form-control form-control-sm" placeholder="Min payments" step="0.01">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-1">Max Payments</label>
                        <input type="number" name="payments_max" value="{{ $filters['payments_max'] ?? '' }}" class="form-control form-control-sm" placeholder="Max payments" step="0.01">
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
                                <th>Client</th>
                                <th>Email</th>
                                <th>Phone</th>
                                <th>Balance</th>
                                <th>First Payment Date</th>
                                <th>Debt Amount</th>
                                <th>Total Income</th>
                                <th>Payments</th>
                                <th>State</th>
                                <th>Debt Ratio</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($reports as $report)
                                <tr>
                                    <td>{{ $report->llg_id }}</td>
                                    <td>{{ $report->client }}</td>
                                    <td>{{ $report->email }}</td>
                                    <td>{{ $report->phone }}</td>
                                    <td class="text-end">{{ $report->balance ? '$' . number_format($report->balance, 2) : '' }}</td>
                                    <td>{{ $report->first_payment_date ? \Carbon\Carbon::parse($report->first_payment_date)->format('m/d/Y') : '' }}</td>
                                    <td class="text-end">{{ $report->debt_amount ? '$' . number_format($report->debt_amount, 2) : '' }}</td>
                                    <td class="text-end">{{ $report->total_income ? '$' . number_format($report->total_income, 2) : '' }}</td>
                                    <td class="text-end">{{ $report->payments }}</td>
                                    <td>{{ $report->state }}</td>
                                    <td class="text-end">{{ $report->debt_ratio }}</td>
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
            const form = document.getElementById('lending-usa-prospects-form');
            const inputs = form.querySelectorAll('input, select');
            inputs.forEach(input => {
                if (input.type !== 'hidden') {
                    input.value = '';
                }
            });
            form.submit();
        }

        function exportCsv() {
            const form = document.getElementById('lending-usa-prospects-form');
            const exportInput = document.getElementById('export');
            exportInput.value = 'csv';
            form.submit();
            exportInput.value = '';
        }
    </script>
@endsection
