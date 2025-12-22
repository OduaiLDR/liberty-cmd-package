@extends('layouts.app')

@section('content')
    @php
        $totalRecords = method_exists($reports, 'total') ? $reports->total() : count($reports);
        $perPageValue = (int) ($perPage ?? request('per_page', 25));
    @endphp

    <div class="card">
        <div class="card-header d-flex align-items-center py-0">
            <h6 class="py-3 mb-0">Enrollment Frequency Report</h6>
        </div>
        <div class="card-body">
            <form method="get" action="{{ route('cmd.reports.enrollment_frequency_report') }}" class="bg-light rounded-3 p-2 p-md-3 mb-3" id="enrollment-frequency-form">
                <input type="hidden" name="export" id="export" value="">

                <div class="row g-2 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label mb-1">ID</label>
                        <input type="text" name="id" value="{{ $filters['id'] ?? '' }}" class="form-control form-control-sm" placeholder="ID">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-1">First Name</label>
                        <input type="text" name="first_name" value="{{ $filters['first_name'] ?? '' }}" class="form-control form-control-sm" placeholder="First name">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-1">Last Name</label>
                        <input type="text" name="last_name" value="{{ $filters['last_name'] ?? '' }}" class="form-control form-control-sm" placeholder="Last name">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-1">Frequency</label>
                        <input type="text" name="frequency" value="{{ $filters['frequency'] ?? '' }}" class="form-control form-control-sm" placeholder="Frequency">
                    </div>
                </div>

                <div class="row g-2 align-items-end mt-2">
                    <div class="col-md-3">
                        <label class="form-label mb-1">Created Date From</label>
                        <input type="date" name="created_from" value="{{ $filters['created_from'] ?? '' }}" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-1">Created Date To</label>
                        <input type="date" name="created_to" value="{{ $filters['created_to'] ?? '' }}" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-1">Assigned Date From</label>
                        <input type="date" name="assigned_from" value="{{ $filters['assigned_from'] ?? '' }}" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-1">Assigned Date To</label>
                        <input type="date" name="assigned_to" value="{{ $filters['assigned_to'] ?? '' }}" class="form-control form-control-sm">
                    </div>
                </div>

                <div class="row g-2 align-items-center mt-2 justify-content-between">
                    <div class="col-auto d-flex gap-2">
                        <button type="submit" id="btn-filter" class="btn btn-primary btn-sm">Filter</button>
                        <a href="{{ route('cmd.reports.enrollment_frequency_report') }}" class="btn btn-light btn-sm">Reset</a>
                        <button type="button" id="btn-export" class="btn btn-secondary btn-sm">Export CSV</button>
                    </div>
                    <div class="col-auto">
                        <select name="per_page" class="form-select form-select-sm">
                            @foreach ([25, 50, 100, 250, 500] as $n)
                                <option value="{{ $n }}" {{ $perPageValue === $n ? 'selected' : '' }}>{{ $n }} / page</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </form>

            <div class="d-flex justify-content-center align-items-center mb-2">
                <span class="border p-2 rounded small text-warning">
                    Results: {{ $totalRecords }} {{ $totalRecords === 1 ? 'Record' : 'Records' }}
                </span>
            </div>

            <div class="table-responsive">
                <table class="table table-striped table-bordered align-middle">
                    <thead class="table-light">
                        <tr>
                            @foreach ($columns as $label)
                                <th>{{ $label }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($reports as $report)
                            <tr>
                                @foreach ($columns as $key => $label)
                                    @php $value = $report->{$key} ?? null; @endphp
                                    @if (is_numeric($value))
                                        <td class="text-end">{{ number_format((float) $value, 0, '.', ',') }}</td>
                                    @else
                                        <td>{{ $value ?? '' }}</td>
                                    @endif
                                @endforeach
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ max(1, count($columns)) }}" class="text-center text-muted">No records found.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if (method_exists($reports, 'links'))
                <div class="d-flex justify-content-center mt-3">
                    {{ $reports->appends(request()->query())->links() }}
                </div>
            @endif
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('enrollment-frequency-form');
            if (!form) return;

            const exportInput = document.getElementById('export');
            const exportButton = document.getElementById('btn-export');
            if (exportButton) {
                exportButton.addEventListener('click', () => {
                    if (exportInput) exportInput.value = 'csv';
                    form.submit();
                    setTimeout(() => {
                        if (exportInput) exportInput.value = '';
                    }, 0);
                });
            }
        });
    </script>
@endpush
