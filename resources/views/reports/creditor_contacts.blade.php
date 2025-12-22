@extends('layouts.app')

@section('content')
    @php
        $totalRecords = method_exists($reports, 'total') ? $reports->total() : count($reports);
        $perPageValue = (int) ($perPage ?? request('per_page', 25));
    @endphp

    <div class="card">
        <div class="card-header d-flex align-items-center py-0">
            <h6 class="py-3 mb-0">Creditor Contacts</h6>
        </div>
        <div class="card-body">
            <form method="get" action="{{ route('cmd.reports.creditor_contacts_report') }}" class="bg-light rounded-3 p-2 p-md-3 mb-3" id="creditor-contacts-form">
                <input type="hidden" name="export" id="export" value="">

                <div class="d-flex justify-content-between flex-wrap gap-2 align-items-center">
                    <div class="fw-semibold">Search</div>
                    <button type="button" id="btn-export" class="btn btn-secondary btn-sm">Export CSV</button>
                </div>

                <div class="row g-2 mt-2">
                    <div class="col-md-3">
                        <label class="form-label form-label-sm">Creditor Name</label>
                        <input type="text" name="creditor_name" value="{{ request('creditor_name') }}" class="form-control form-control-sm" autocomplete="off">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label form-label-sm">Parent Account</label>
                        <input type="text" name="parent_account" value="{{ request('parent_account') }}" class="form-control form-control-sm" autocomplete="off">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label form-label-sm">POA Exclusion</label>
                        <input type="text" name="poa_exclusion" value="{{ request('poa_exclusion') }}" class="form-control form-control-sm" autocomplete="off">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label form-label-sm">Email</label>
                        <input type="text" name="email" value="{{ request('email') }}" class="form-control form-control-sm" autocomplete="off">
                    </div>
                </div>

                <div class="row g-2 mt-2">
                    <div class="col-md-3">
                        <label class="form-label form-label-sm">Fax</label>
                        <input type="text" name="fax" value="{{ request('fax') }}" class="form-control form-control-sm" autocomplete="off">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label form-label-sm">Contact Name</label>
                        <input type="text" name="contact_name" value="{{ request('contact_name') }}" class="form-control form-control-sm" autocomplete="off">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label form-label-sm">Contact Phone</label>
                        <input type="text" name="contact_phone" value="{{ request('contact_phone') }}" class="form-control form-control-sm" autocomplete="off">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label form-label-sm">Creditor Address</label>
                        <input type="text" name="creditor_address" value="{{ request('creditor_address') }}" class="form-control form-control-sm" autocomplete="off">
                    </div>
                </div>

                <div class="row g-2 mt-2">
                    <div class="col-md-9">
                        <label class="form-label form-label-sm">Notes</label>
                        <input type="text" name="notes" value="{{ request('notes') }}" class="form-control form-control-sm" autocomplete="off">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label form-label-sm">Per Page</label>
                        <select name="per_page" class="form-select form-select-sm">
                            @foreach ([25, 50, 100, 250, 500] as $n)
                                <option value="{{ $n }}" {{ $perPageValue === $n ? 'selected' : '' }}>{{ $n }} / page</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="d-flex justify-content-end gap-2 mt-3">
                    <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                    <a href="{{ route('cmd.reports.creditor_contacts_report') }}" class="btn btn-light btn-sm">Reset</a>
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
                                @foreach (array_keys($columns) as $alias)
                                    <td>{{ $report->{$alias} ?? '' }}</td>
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
            const form = document.getElementById('creditor-contacts-form');
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
