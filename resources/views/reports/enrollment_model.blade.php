@extends('layouts.app')

@section('content')
    @php
        $totalRecords = count($reports);
        $currentYear = $year ?? (int) date('Y');
        $fmtMoney = static fn($v) => $v === null || $v === '' || $v == 0 ? '$0.00' : ('$' . number_format((float) $v, 2, '.', ','));
        $fmtNumber = static fn($v) => $v === null || $v === '' ? '0' : number_format((float) $v, 0, '.', ',');
        
        // Determine if value should be shown in red (negative or loss)
        $isNegative = static fn($category, $v) => str_contains(strtolower($category), 'cost') || str_contains(strtolower($category), 'loss') || (float)$v < 0;
    @endphp

    <div class="card">
        <div class="card-header d-flex align-items-center py-0">
            <h6 class="py-3 mb-0">Enrollment Model Report - {{ $currentYear }}</h6>
        </div>
        <div class="card-body">
            <form method="get" action="{{ route('cmd.reports.enrollment_model_report') }}" class="bg-light rounded-3 p-2 p-md-3 mb-3" id="enrollment-model-form">
                <input type="hidden" name="export" id="export" value="">

                <div class="row g-2 align-items-center">
                    <div class="col-auto">
                        <span class="fw-semibold">Year:</span>
                    </div>
                    <div class="col-auto">
                        <select name="year" class="form-select form-select-sm">
                            @foreach ($availableYears ?? range(date('Y'), date('Y') - 5) as $y)
                                <option value="{{ $y }}" {{ $currentYear == $y ? 'selected' : '' }}>{{ $y }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-auto">
                        <span class="fw-semibold">From Month:</span>
                    </div>
                    <div class="col-auto">
                        <select name="from_month" class="form-select form-select-sm">
                            <option value="">All</option>
                            @foreach (['Jan' => 1, 'Feb' => 2, 'Mar' => 3, 'Apr' => 4, 'May' => 5, 'Jun' => 6, 'Jul' => 7, 'Aug' => 8, 'Sep' => 9, 'Oct' => 10, 'Nov' => 11, 'Dec' => 12] as $name => $num)
                                <option value="{{ $num }}" {{ request('from_month') == $num ? 'selected' : '' }}>{{ $name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-auto">
                        <span class="fw-semibold">To Month:</span>
                    </div>
                    <div class="col-auto">
                        <select name="to_month" class="form-select form-select-sm">
                            <option value="">All</option>
                            @foreach (['Jan' => 1, 'Feb' => 2, 'Mar' => 3, 'Apr' => 4, 'May' => 5, 'Jun' => 6, 'Jul' => 7, 'Aug' => 8, 'Sep' => 9, 'Oct' => 10, 'Nov' => 11, 'Dec' => 12] as $name => $num)
                                <option value="{{ $num }}" {{ request('to_month') == $num ? 'selected' : '' }}>{{ $name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                    </div>
                    <div class="col-auto ms-auto">
                        <button type="button" id="btn-export" class="btn btn-secondary btn-sm">Export CSV</button>
                    </div>
                </div>
            </form>

            <div class="table-responsive">
                <table class="table table-bordered align-middle" style="font-size: 0.85rem;">
                    <thead class="table-dark">
                        <tr>
                            @foreach ($columns as $key => $label)
                                <th class="{{ $key === 'category' ? '' : 'text-center' }}" style="min-width: {{ $key === 'category' ? '180px' : '80px' }}">{{ $label }} {{ $key !== 'category' ? $currentYear : '' }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($reports as $report)
                            @php $category = $report->category ?? ''; @endphp
                            <tr class="{{ str_contains(strtolower($category), 'balance') || str_contains(strtolower($category), 'profit') ? 'table-warning fw-bold' : '' }}">
                                @foreach ($columns as $key => $label)
                                    @php $value = $report->{$key} ?? 0; @endphp
                                    @if ($key === 'category')
                                        <td class="fw-semibold">{{ $value }}</td>
                                    @else
                                        <td class="text-end {{ $isNegative($category, $value) ? 'text-danger' : '' }}">
                                            {{ $fmtMoney($value) }}
                                        </td>
                                    @endif
                                @endforeach
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ max(1, count($columns)) }}" class="text-center text-muted">No records found for {{ $currentYear }}.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('enrollment-model-form');
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
