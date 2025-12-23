@extends('layouts.app')

@section('content')
    @php
        $totalRecords = method_exists($reports, 'total') ? $reports->total() : count($reports);
        $perPageValue = (int) ($perPage ?? request('per_page', 25));
    @endphp

    <div class="card">
        <div class="card-header d-flex align-items-center py-0">
            <h6 class="py-3 mb-0">Offer Authorization Report</h6>
        </div>
        <div class="card-body">
            <form method="get" action="{{ route('cmd.reports.offer_authorization_report') }}" class="bg-light rounded-3 p-2 p-md-3 mb-3" id="offer-authorization-form">
                <input type="hidden" name="export" id="export" value="">

                <div class="row g-2 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label mb-1">Offer ID</label>
                        <input type="text" name="offer_id" value="{{ $filters['offer_id'] ?? '' }}" class="form-control form-control-sm" placeholder="Offer ID">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-1">LLG ID</label>
                        <input type="text" name="llg_id" value="{{ $filters['llg_id'] ?? '' }}" class="form-control form-control-sm" placeholder="LLG ID">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-1">Title</label>
                        <input type="text" name="title" value="{{ $filters['title'] ?? '' }}" class="form-control form-control-sm" placeholder="Title">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-1">First Name</label>
                        <input type="text" name="firstname" value="{{ $filters['firstname'] ?? '' }}" class="form-control form-control-sm" placeholder="First name">
                    </div>
                </div>

                <div class="row g-2 align-items-end mt-2">
                    <div class="col-md-3">
                        <label class="form-label mb-1">Last Name</label>
                        <input type="text" name="lastname" value="{{ $filters['lastname'] ?? '' }}" class="form-control form-control-sm" placeholder="Last name">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-1">State</label>
                        <input type="text" name="state" value="{{ $filters['state'] ?? '' }}" class="form-control form-control-sm" placeholder="State">
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
                                <th>Offer ID</th>
                                <th>LLG ID</th>
                                <th>Title</th>
                                <th>First Name</th>
                                <th>Last Name</th>
                                <th>Address</th>
                                <th>Address 2</th>
                                <th>City</th>
                                <th>State</th>
                                <th>ZIP</th>
                                <th>Return Address</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($reports as $report)
                                <tr>
                                    <td>{{ $report->offer_id }}</td>
                                    <td>{{ $report->llg_id }}</td>
                                    <td>{{ $report->title }}</td>
                                    <td>{{ $report->firstname }}</td>
                                    <td>{{ $report->lastname }}</td>
                                    <td>{{ $report->address }}</td>
                                    <td>{{ $report->address2 }}</td>
                                    <td>{{ $report->city }}</td>
                                    <td>{{ $report->state }}</td>
                                    <td>{{ $report->zip }}</td>
                                    <td class="text-muted small">{{ $report->return_address }}</td>
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
            const form = document.getElementById('offer-authorization-form');
            const inputs = form.querySelectorAll('input, select');
            inputs.forEach(input => {
                if (input.type !== 'hidden') {
                    input.value = '';
                }
            });
            form.submit();
        }

        function exportCsv() {
            const form = document.getElementById('offer-authorization-form');
            const exportInput = document.getElementById('export');
            exportInput.value = 'csv';
            form.submit();
            exportInput.value = '';
        }
    </script>
@endsection
