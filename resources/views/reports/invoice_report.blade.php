@extends('layouts.app')

@section('content')
    @php
        $perPageValue = (int) ($perPage ?? request('per_page', 25));
        $formatCurrency = static fn($value) => $value ? '$' . number_format($value, 2) : '';
        $formatDate = static fn($value) => $value ? \Carbon\Carbon::parse($value)->format('m/d/Y') : '';
    @endphp

    <div class="card shadow-sm border-0">
        <div class="card-header d-flex justify-content-between align-items-center py-3" style="background: linear-gradient(135deg, var(--bs-body-bg), var(--bs-light)); border-bottom: 1px solid var(--bs-border-color);">
            <div>
                <h5 class="mb-0 text-body">Invoice Reports</h5>
                <small class="text-muted">Choose a variant, then filter/export</small>
            </div>
            <button type="button" class="btn btn-outline-primary btn-sm" onclick="exportCsv()">
                <i class="fas fa-download me-1"></i> CSV
            </button>
        </div>
        <div class="card-body" style="background: var(--bs-body-bg);">
            <form method="get" action="{{ route('cmd.reports.invoice_report') }}" class="bg-body border rounded-3 p-3 shadow-sm mb-3" id="invoice-report-form">
                <input type="hidden" name="export" id="export" value="">
                <div class="row g-3 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label mb-1">Invoice Variant</label>
                        <select name="report_type" class="form-select form-select-sm" required>
                            <option value="">Select a variant...</option>
                            @foreach ($reportTypes as $type)
                                <optgroup label="{{ $type['group'] }}">
                                    <option value="{{ $type['key'] }}" {{ ($filters['report_type'] ?? '') === $type['key'] ? 'selected' : '' }}>
                                        {{ $type['label'] }}
                                    </option>
                                </optgroup>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-1">Agent</label>
                        <input type="text" name="agent" value="{{ $filters['agent'] ?? '' }}" class="form-control form-control-sm" placeholder="Agent">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label mb-1">Date From</label>
                        <input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label mb-1">Date To</label>
                        <input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-1">
                        <label class="form-label mb-1">Per Page</label>
                        <select name="per_page" class="form-select form-select-sm">
                            @foreach ([25,50,100,200,500] as $n)
                                <option value="{{ $n }}" {{ $perPageValue === $n ? 'selected' : '' }}>{{ $n }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="row g-2 align-items-end mt-2">
                    <div class="col-md-4 d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm flex-fill">
                            <i class="fas fa-search me-1"></i> Search
                        </button>
                        <button type="button" class="btn btn-secondary btn-sm" onclick="clearFilters()">
                            <i class="fas fa-undo me-1"></i> Reset
                        </button>
                    </div>
                </div>
            </form>

            <div class="card border-0 shadow-sm">
                <div class="card-header bg-secondary text-white py-2 d-flex justify-content-between">
                    <span class="fw-semibold">Results</span>
                    <small class="text-white-50">{{ $filters['report_type'] ?? 'Select a variant' }}</small>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-striped align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Report</th>
                                    <th>Submitted Date</th>
                                    <th>Agent</th>
                                    <th class="text-end">Debt Amount</th>
                                    <th>LLG ID</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($reports as $row)
                                    <tr>
                                        <td>{{ $row->report_type }}</td>
                                        <td>{{ $formatDate($row->submitted_date) }}</td>
                                        <td>{{ $row->agent }}</td>
                                        <td class="text-end">{{ $formatCurrency($row->debt_amount) }}</td>
                                        <td>{{ $row->llg_id }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="text-center py-3 text-muted">No records found. Choose a variant and adjust filters.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card-footer d-flex justify-content-between align-items-center py-2">
                    <div class="text-muted small">Showing {{ $reports->firstItem() ?? 0 }} to {{ $reports->lastItem() ?? 0 }} of {{ $reports->total() ?? 0 }} entries</div>
                    {{ $reports->links() }}
                </div>
            </div>
        </div>
    </div>

    <script>
        function clearFilters() {
            const form = document.getElementById('invoice-report-form');
            form.reset();
            form.submit();
        }

        function exportCsv() {
            const form = document.getElementById('invoice-report-form');
            document.getElementById('export').value = 'csv';
            form.submit();
            document.getElementById('export').value = '';
        }
    </script>
@endsection
