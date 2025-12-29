@extends('layouts.app')

@section('content')
    @php
        $totalRecords = method_exists($reports, 'total') ? $reports->total() : count($reports);
        $perPageValue = (int) ($perPage ?? request('per_page', 25));
    @endphp

    <div class="card">
        <div class="card-header d-flex align-items-center py-0">
            <h6 class="py-3 mb-0">Legal Report</h6>
        </div>
        <div class="card-body">
            <form method="get" action="{{ route('cmd.reports.legal_report') }}" class="bg-light rounded-3 p-2 p-md-3 mb-3" id="legal-form">
                <input type="hidden" name="export" id="export" value="">

                @php
                    $allowed = $allowedFilters ?? array_keys($filters ?? []);
                    $show = fn(string $key) => in_array($key, $allowed, true);
                @endphp

                <div class="row g-2 align-items-end">
                    @if($show('contact_id'))
                        <div class="col-md-3">
                            <label class="form-label mb-1">Contact ID</label>
                            <input type="text" name="contact_id" value="{{ $filters['contact_id'] ?? '' }}" class="form-control form-control-sm" placeholder="Contact ID">
                        </div>
                    @endif
                    @if($show('first_name'))
                        <div class="col-md-3">
                            <label class="form-label mb-1">First Name</label>
                            <input type="text" name="first_name" value="{{ $filters['first_name'] ?? '' }}" class="form-control form-control-sm" placeholder="First name">
                        </div>
                    @endif
                    @if($show('last_name'))
                        <div class="col-md-3">
                            <label class="form-label mb-1">Last Name</label>
                            <input type="text" name="last_name" value="{{ $filters['last_name'] ?? '' }}" class="form-control form-control-sm" placeholder="Last name">
                        </div>
                    @endif
                    @if($show('state'))
                        <div class="col-md-3">
                            <label class="form-label mb-1">State</label>
                            <input type="text" name="state" value="{{ $filters['state'] ?? '' }}" class="form-control form-control-sm" placeholder="State">
                        </div>
                    @endif
                </div>

                <div class="row g-2 align-items-end mt-2">
                    @if($show('enrolled'))
                        <div class="col-md-3">
                            <label class="form-label mb-1">Enrolled</label>
                            <input type="text" name="enrolled" value="{{ $filters['enrolled'] ?? '' }}" class="form-control form-control-sm" placeholder="Enrolled">
                        </div>
                    @endif
                    @if($show('has_summons'))
                        <div class="col-md-3">
                            <label class="form-label mb-1">Has Summons</label>
                            <input type="text" name="has_summons" value="{{ $filters['has_summons'] ?? '' }}" class="form-control form-control-sm" placeholder="Yes/No">
                        </div>
                    @endif
                    @if($show('settled'))
                        <div class="col-md-3">
                            <label class="form-label mb-1">Settled</label>
                            <input type="text" name="settled" value="{{ $filters['settled'] ?? '' }}" class="form-control form-control-sm" placeholder="Yes/No">
                        </div>
                    @endif
                    @if($show('debt_buyer'))
                        <div class="col-md-3">
                            <label class="form-label mb-1">Debt Buyer</label>
                            <input type="text" name="debt_buyer" value="{{ $filters['debt_buyer'] ?? '' }}" class="form-control form-control-sm" placeholder="Debt Buyer">
                        </div>
                    @endif
                </div>

                <div class="row g-2 align-items-end mt-2">
                    @if($show('creditor_id'))
                        <div class="col-md-3">
                            <label class="form-label mb-1">Creditor ID</label>
                            <input type="text" name="creditor_id" value="{{ $filters['creditor_id'] ?? '' }}" class="form-control form-control-sm" placeholder="Creditor ID">
                        </div>
                    @endif
                    @if($show('plan_id'))
                        <div class="col-md-3">
                            <label class="form-label mb-1">Plan ID</label>
                            <input type="text" name="plan_id" value="{{ $filters['plan_id'] ?? '' }}" class="form-control form-control-sm" placeholder="Plan ID">
                        </div>
                    @endif
                    @if($show('verified_min'))
                        <div class="col-md-3">
                            <label class="form-label mb-1">Verified Amount Min</label>
                            <input type="number" step="0.01" name="verified_min" value="{{ $filters['verified_min'] ?? '' }}" class="form-control form-control-sm" placeholder="0.00">
                        </div>
                    @endif
                    @if($show('verified_max'))
                        <div class="col-md-3">
                            <label class="form-label mb-1">Verified Amount Max</label>
                            <input type="number" step="0.01" name="verified_max" value="{{ $filters['verified_max'] ?? '' }}" class="form-control form-control-sm" placeholder="0.00">
                        </div>
                    @endif
                </div>

                <div class="row g-2 align-items-end mt-2">
                    @if($show('summons_from'))
                        <div class="col-md-3">
                            <label class="form-label mb-1">Summons Date From</label>
                            <input type="date" name="summons_from" value="{{ $filters['summons_from'] ?? '' }}" class="form-control form-control-sm">
                        </div>
                    @endif
                    @if($show('summons_to'))
                        <div class="col-md-3">
                            <label class="form-label mb-1">Summons Date To</label>
                            <input type="date" name="summons_to" value="{{ $filters['summons_to'] ?? '' }}" class="form-control form-control-sm">
                        </div>
                    @endif
                    @if($show('answer_from'))
                        <div class="col-md-3">
                            <label class="form-label mb-1">Answer Date From</label>
                            <input type="date" name="answer_from" value="{{ $filters['answer_from'] ?? '' }}" class="form-control form-control-sm">
                        </div>
                    @endif
                    @if($show('answer_to'))
                        <div class="col-md-3">
                            <label class="form-label mb-1">Answer Date To</label>
                            <input type="date" name="answer_to" value="{{ $filters['answer_to'] ?? '' }}" class="form-control form-control-sm">
                        </div>
                    @endif
                </div>

                <div class="row g-2 align-items-end mt-2">
                    @if($show('poa_from'))
                        <div class="col-md-3">
                            <label class="form-label mb-1">POA Sent From</label>
                            <input type="date" name="poa_from" value="{{ $filters['poa_from'] ?? '' }}" class="form-control form-control-sm">
                        </div>
                    @endif
                    @if($show('poa_to'))
                        <div class="col-md-3">
                            <label class="form-label mb-1">POA Sent To</label>
                            <input type="date" name="poa_to" value="{{ $filters['poa_to'] ?? '' }}" class="form-control form-control-sm">
                        </div>
                    @endif
                    @if($show('settlement_from'))
                        <div class="col-md-3">
                            <label class="form-label mb-1">Settlement From</label>
                            <input type="date" name="settlement_from" value="{{ $filters['settlement_from'] ?? '' }}" class="form-control form-control-sm">
                        </div>
                    @endif
                    @if($show('settlement_to'))
                        <div class="col-md-3">
                            <label class="form-label mb-1">Settlement To</label>
                            <input type="date" name="settlement_to" value="{{ $filters['settlement_to'] ?? '' }}" class="form-control form-control-sm">
                        </div>
                    @endif
                </div>

                

                <div class="row g-2 align-items-center mt-3">
                    <div class="col">
                        <span class="border p-2 rounded small text-warning">
                            Results: {{ $totalRecords }} {{ $totalRecords === 1 ? 'Record' : 'Records' }}
                        </span>
                    </div>
                    <div class="col d-flex justify-content-end align-items-center gap-2">
                        <select name="per_page" class="form-select form-select-sm" onchange="this.form.submit()" style="width:auto">
                            @foreach ([25, 50, 100, 250, 500] as $n)
                                <option value="{{ $n }}" {{ $perPageValue === $n ? 'selected' : '' }}>{{ $n }} / page</option>
                            @endforeach
                        </select>
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                            <a href="{{ route('cmd.reports.legal_report') }}" class="btn btn-light btn-sm">Reset</a>
                            <button type="button" id="btn-export" class="btn btn-secondary btn-sm">Export CSV</button>
                        </div>
                    </div>
                </div>
            </form>

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
                                    @if (str_contains(strtolower($key), 'verified_amount'))
                                        <td class="text-end">${{ number_format((float) $value, 2, '.', ',') }}</td>
                                    @else
                                        <td class="text-end">{{ number_format((float) $value, 2, '.', ',') }}</td>
                                    @endif
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
        document.addEventListener('DOMContentLoaded', function () {
            const form = document.getElementById('legal-form');
            const exportInput = document.getElementById('export');
            const exportBtn = document.getElementById('btn-export');
            if (!form || !exportBtn || !exportInput) return;

            exportBtn.addEventListener('click', () => {
                exportInput.value = 'csv';
                form.submit();
                setTimeout(() => exportInput.value = '', 0);
            });
        });
    </script>
@endpush
