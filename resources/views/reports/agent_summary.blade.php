@extends('layouts.app')

@section('content')
    @php
        $totalRecords = method_exists($reports, 'total') ? $reports->total() : count($reports);
        $perPageValue = (int) ($perPage ?? request('per_page', 25));
    @endphp

    <div class="card">
        <div class="card-header d-flex align-items-center py-0">
            <h6 class="py-3 mb-0">Agent Summary</h6>
        </div>
        <div class="card-body">
            <form method="get" action="{{ route('cmd.reports.agent_summary_report') }}" class="bg-light rounded-3 p-2 p-md-3 mb-3" id="agent-summary-form">
                <input type="hidden" name="export" id="export" value="">

                <div class="row g-2 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label mb-1">Agent</label>
                        <input type="text" name="agent" value="{{ $filters['agent'] ?? '' }}" class="form-control form-control-sm" placeholder="Agent">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-1">Date From</label>
                        <input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-1">Date To</label>
                        <input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}" class="form-control form-control-sm">
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

                <details class="mt-2 mb-2">
                    <summary class="small fw-semibold">Advanced Filters</summary>
                    <div class="row g-2 mt-2">
                        <div class="col-md-3">
                            <label class="form-label mb-1">Min Debt Assigned</label>
                            <input type="number" step="0.01" name="debt_min" value="{{ $filters['debt_min'] ?? '' }}" class="form-control form-control-sm" placeholder="Min debt assigned">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label mb-1">Max Debt Assigned</label>
                            <input type="number" step="0.01" name="debt_max" value="{{ $filters['debt_max'] ?? '' }}" class="form-control form-control-sm" placeholder="Max debt assigned">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label mb-1">Min Avg Debt</label>
                            <input type="number" step="0.01" name="avg_debt_min" value="{{ $filters['avg_debt_min'] ?? '' }}" class="form-control form-control-sm" placeholder="Min avg debt">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label mb-1">Max Avg Debt</label>
                            <input type="number" step="0.01" name="avg_debt_max" value="{{ $filters['avg_debt_max'] ?? '' }}" class="form-control form-control-sm" placeholder="Max avg debt">
                        </div>
                    </div>
                    <div class="row g-2 mt-2">
                        <div class="col-md-3">
                            <label class="form-label mb-1">Min Leads</label>
                            <input type="number" step="1" name="leads_min" value="{{ $filters['leads_min'] ?? '' }}" class="form-control form-control-sm" placeholder="Min leads">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label mb-1">Max Leads</label>
                            <input type="number" step="1" name="leads_max" value="{{ $filters['leads_max'] ?? '' }}" class="form-control form-control-sm" placeholder="Max leads">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label mb-1">Min Assigned</label>
                            <input type="number" step="1" name="assigned_min" value="{{ $filters['assigned_min'] ?? '' }}" class="form-control form-control-sm" placeholder="Min assigned">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label mb-1">Max Assigned</label>
                            <input type="number" step="1" name="assigned_max" value="{{ $filters['assigned_max'] ?? '' }}" class="form-control form-control-sm" placeholder="Max assigned">
                        </div>
                    </div>
                </details>

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
                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        @php
                            // Calculate summary totals
                            $totalAvailableUnit = 0;
                            $totalMaxLeads = 0;
                            $totalLeads = 0;
                            $totalAssigned = 0;
                            $totalDebtAssigned = 0;
                            $totalTarget = 0;
                            $totalVariance = 0;
                            foreach ($reports as $r) {
                                $totalAvailableUnit += (int) ($r->available_unit ?? 0);
                                $totalMaxLeads += (int) ($r->max_leads ?? 0);
                                $totalLeads += (int) ($r->leads ?? 0);
                                $totalAssigned += (int) ($r->assigned ?? 0);
                                $totalDebtAssigned += (float) ($r->debt_assigned ?? 0);
                                $totalTarget += (float) ($r->target ?? 0);
                                $totalVariance += (float) ($r->variance ?? 0);
                            }
                            $avgConversionRatio = count($reports) > 0 ? collect($reports)->avg('conversion_ratio') : 0;
                            $avgDebtAssigned = count($reports) > 0 ? collect($reports)->avg('avg_debt_assigned') : 0;
                        @endphp
                        <thead class="table-light">
                            <tr>
                                <th>Agent ID</th>
                                <th>Agent</th>
                                <th>Available Unit</th>
                                <th>Max Leads</th>
                                <th>Conversion Ratio</th>
                                <th>Average Debt Assigned</th>
                                <th>Target</th>
                                <th>Variance</th>
                                <th>Leads</th>
                                <th>Assigned</th>
                                <th>Debt Assigned</th>
                                <th>Average Debt Assigned (90d)</th>
                                <th>T1</th>
                                <th>T2</th>
                                <th>T3</th>
                                <th>T4</th>
                                <th>T5</th>
                                <th>T6</th>
                                <th>T7</th>
                                <th>T8</th>
                                <th>T9</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="table-warning fw-bold">
                                <td>TOTAL</td>
                                <td>-</td>
                                <td class="text-end">{{ number_format($totalAvailableUnit) }}</td>
                                <td class="text-end">{{ number_format($totalMaxLeads) }}</td>
                                <td class="text-end">{{ number_format($avgConversionRatio, 2) }}%</td>
                                <td class="text-end">${{ number_format($avgDebtAssigned, 2) }}</td>
                                <td class="text-end">${{ number_format($totalTarget, 2) }}</td>
                                <td class="text-end">${{ number_format($totalVariance, 2) }}</td>
                                <td class="text-end">{{ number_format($totalLeads) }}</td>
                                <td class="text-end">{{ number_format($totalAssigned) }}</td>
                                <td class="text-end">${{ number_format($totalDebtAssigned, 2) }}</td>
                                <td class="text-end">-</td>
                                <td>-</td>
                                <td>-</td>
                                <td>-</td>
                                <td>-</td>
                                <td>-</td>
                                <td>-</td>
                                <td>-</td>
                                <td>-</td>
                                <td>-</td>
                            </tr>
                            @foreach($reports as $report)
                                <tr>
                                    <td>{{ $report->agent_id }}</td>
                                    <td>{{ $report->agent }}</td>
                                    <td class="text-end">{{ $report->available_unit }}</td>
                                    <td class="text-end">{{ $report->max_leads }}</td>
                                    <td class="text-end">{{ $report->conversion_ratio !== null ? number_format($report->conversion_ratio, 2) . '%' : '' }}</td>
                                    <td class="text-end">{{ $report->avg_debt_assigned ? '$' . number_format($report->avg_debt_assigned, 2) : '' }}</td>
                                    <td class="text-end">{{ $report->target !== null ? '$' . number_format($report->target, 2) : '' }}</td>
                                    <td class="text-end">{{ $report->variance !== null ? '$' . number_format($report->variance, 2) : '' }}</td>
                                    <td class="text-end">{{ $report->leads }}</td>
                                    <td class="text-end">{{ $report->assigned }}</td>
                                    <td class="text-end">{{ $report->debt_assigned ? '$' . number_format($report->debt_assigned, 2) : '' }}</td>
                                    <td class="text-end">{{ $report->avg_debt_assigned_dup ? '$' . number_format($report->avg_debt_assigned_dup, 2) : '' }}</td>
                                    <td class="text-end">{{ $report->t1 }}</td>
                                    <td class="text-end">{{ $report->t2 }}</td>
                                    <td class="text-end">{{ $report->t3 }}</td>
                                    <td class="text-end">{{ $report->t4 }}</td>
                                    <td class="text-end">{{ $report->t5 }}</td>
                                    <td class="text-end">{{ $report->t6 }}</td>
                                    <td class="text-end">{{ $report->t7 }}</td>
                                    <td class="text-end">{{ $report->t8 }}</td>
                                    <td class="text-end">{{ $report->t9 }}</td>
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
            const form = document.getElementById('agent-summary-form');
            const inputs = form.querySelectorAll('input, select');
            inputs.forEach(input => {
                if (input.type !== 'hidden') {
                    input.value = '';
                }
            });
            form.submit();
        }

        function exportCsv() {
            const form = document.getElementById('agent-summary-form');
            const exportInput = document.getElementById('export');
            exportInput.value = 'csv';
            form.submit();
            exportInput.value = '';
        }
    </script>
@endsection
