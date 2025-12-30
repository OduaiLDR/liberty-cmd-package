@extends('layouts.app')

@section('content')
    @php
        $totalRecords = method_exists($reports, 'total') ? $reports->total() : count($reports);
        $perPageValue = (int) ($perPage ?? request('per_page', 25));
        $formatCurrency = static fn($value) => $value ? '$' . number_format($value, 2) : '';
        $formatInt = static fn($value) => $value !== null ? number_format((int) $value) : '';
        $months = [1=>'January',2=>'February',3=>'March',4=>'April',5=>'May',6=>'June',7=>'July',8=>'August',9=>'September',10=>'October',11=>'November',12=>'December'];
    @endphp

    <div class="card">
        <div class="card-header d-flex align-items-center py-0">
            <h6 class="py-3 mb-0">Training Report</h6>
        </div>
        <div class="card-body">
            <form method="get" action="{{ route('cmd.reports.training_report') }}" class="bg-light rounded-3 p-2 p-md-3 mb-3" id="training-report-form">
                <input type="hidden" name="export" id="export" value="">

                <div class="row g-2 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label mb-1">Agent</label>
                        <input type="text" name="agent" value="{{ $filters['agent'] ?? '' }}" class="form-control form-control-sm" placeholder="Agent">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-1">Month</label>
                        <select name="month" class="form-select form-select-sm">
                            <option value="">All</option>
                            @foreach ($months as $num => $name)
                                <option value="{{ $num }}" {{ (int)($filters['month'] ?? 0) === $num ? 'selected' : '' }}>{{ $name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-1">Year</label>
                        <select name="year" class="form-select form-select-sm">
                            <option value="">All</option>
                            @for ($y = now()->year; $y >= now()->year - 5; $y--)
                                <option value="{{ $y }}" {{ (int)($filters['year'] ?? 0) === $y ? 'selected' : '' }}>{{ $y }}</option>
                            @endfor
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-1">Per Page</label>
                        <select name="per_page" class="form-select form-select-sm">
                            @foreach ([25,50,100,200,500,1000] as $n)
                                <option value="{{ $n }}" {{ $perPageValue === $n ? 'selected' : '' }}>{{ $n }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="row g-2 align-items-end mt-2">
                    <div class="col-md-4">
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
                    $totalContacts = 0;
                    $totalDeals = 0;
                    $totalDebt = 0;
                    $totalTotalDeals = 0;
                    $totalTotalDebt = 0;
                    $totalTotal = 0;
                    foreach ($reports as $r) {
                        $totalContacts += (int) ($r->contacts ?? 0);
                        $totalDeals += (int) ($r->deals ?? 0);
                        $totalDebt += (float) ($r->debt ?? 0);
                        $totalTotalDeals += (int) ($r->total_deals ?? 0);
                        $totalTotalDebt += (float) ($r->total_debt ?? 0);
                        $totalTotal += (float) ($r->total ?? 0);
                    }
                    $avgConversion = $totalContacts > 0 ? ($totalDeals / $totalContacts * 100) : 0;
                    $avgTotalConversion = $totalTotalDeals > 0 ? ($totalDeals / $totalTotalDeals * 100) : 0;
                @endphp

                <div class="alert alert-warning mb-3">
                    <div class="row">
                        <div class="col-md-2"><strong>Total Records:</strong> {{ $totalRecords }}</div>
                        <div class="col-md-2"><strong>Total Contacts:</strong> {{ number_format($totalContacts) }}</div>
                        <div class="col-md-2"><strong>Total Deals:</strong> {{ number_format($totalDeals) }}</div>
                        <div class="col-md-3"><strong>Total Debt:</strong> {{ $formatCurrency($totalDebt) }}</div>
                        <div class="col-md-3"><strong>Grand Total:</strong> {{ $formatCurrency($totalTotal) }}</div>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-striped table-hover" style="font-size: 0.85rem;">
                        <thead class="table-light">
                            <tr>
                                <th>Agent</th>
                                <th>Location</th>
                                <th>On Phone Date</th>
                                <th class="text-center">Month</th>
                                <th>Start Date</th>
                                <th>End Date</th>
                                <th class="text-center">Contacts</th>
                                <th class="text-center">Deals</th>
                                <th class="text-center">Conversion</th>
                                <th class="text-end">Debt</th>
                                <th class="text-center">Total Deals</th>
                                <th class="text-center">Total Conv.</th>
                                <th class="text-end">Total Debt</th>
                                <th class="text-end">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="table-warning fw-bold">
                                <td>TOTAL</td>
                                <td>-</td>
                                <td>-</td>
                                <td>-</td>
                                <td>-</td>
                                <td>-</td>
                                <td class="text-center">{{ number_format($totalContacts) }}</td>
                                <td class="text-center">{{ number_format($totalDeals) }}</td>
                                <td class="text-center">{{ number_format($avgConversion, 2) }}%</td>
                                <td class="text-end">{{ $formatCurrency($totalDebt) }}</td>
                                <td class="text-center">{{ number_format($totalTotalDeals) }}</td>
                                <td class="text-center">{{ number_format($avgTotalConversion, 2) }}%</td>
                                <td class="text-end">{{ $formatCurrency($totalTotalDebt) }}</td>
                                <td class="text-end">{{ $formatCurrency($totalTotal) }}</td>
                            </tr>
                            @foreach($reports as $report)
                                <tr>
                                    <td>{{ $report->agent ?? '' }}</td>
                                    <td>{{ $report->location ?? '' }}</td>
                                    <td>{{ $report->on_phone_date ? \Carbon\Carbon::parse($report->on_phone_date)->format('m/d/Y') : '' }}</td>
                                    <td class="text-center">{{ $report->month ?? '' }}</td>
                                    <td>{{ $report->start_date ? \Carbon\Carbon::parse($report->start_date)->format('m/d/Y') : '' }}</td>
                                    <td>{{ $report->end_date ? \Carbon\Carbon::parse($report->end_date)->format('m/d/Y') : '' }}</td>
                                    <td class="text-center">{{ $formatInt($report->contacts) }}</td>
                                    <td class="text-center">{{ $formatInt($report->deals) }}</td>
                                    <td class="text-center">{{ $report->conversion !== null ? number_format($report->conversion, 2) . '%' : '' }}</td>
                                    <td class="text-end">{{ $formatCurrency($report->debt) }}</td>
                                    <td class="text-center">{{ $formatInt($report->total_deals) }}</td>
                                    <td class="text-center">{{ $report->total_conversion !== null ? number_format($report->total_conversion, 2) . '%' : '' }}</td>
                                    <td class="text-end">{{ $formatCurrency($report->total_debt) }}</td>
                                    <td class="text-end">{{ $formatCurrency($report->total) }}</td>
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
            const form = document.getElementById('training-report-form');
            const inputs = form.querySelectorAll('input, select');
            inputs.forEach(input => {
                if (input.type !== 'hidden') {
                    input.value = '';
                }
            });
            form.submit();
        }

        function exportCsv() {
            const form = document.getElementById('training-report-form');
            const exportInput = document.getElementById('export');
            exportInput.value = 'csv';
            form.submit();
            exportInput.value = '';
        }
    </script>
@endsection
