@extends('layouts.app')

@section('content')
    @php
        $totalRecords = method_exists($reports, 'total') ? $reports->total() : count($reports);
        $perPageValue = (int) ($perPage ?? request('per_page', 25));
        $months = [1=>'January',2=>'February',3=>'March',4=>'April',5=>'May',6=>'June',7=>'July',8=>'August',9=>'September',10=>'October',11=>'November',12=>'December'];
        $formatCurrency = static fn($value) => $value ? '$' . number_format($value, 2) : '';
        $formatInt = static fn($value) => $value !== null ? number_format((int) $value) : '';
    @endphp

    <div class="card shadow-lg border-0" style="overflow:hidden;">
        <div class="card-header text-white py-3" style="background: linear-gradient(135deg,#111827,#1f2937);">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h4 class="mb-0 fw-semibold text-uppercase">Leaderboard</h4>
                    <small class="text-white-50">{{ $categoryLabel ?? 'Leaderboard' }} · {{ $periodLabel ?? 'Monthly' }} · {{ $windowMeta['label'] ?? '' }}</small>
                </div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-outline-light btn-sm" onclick="exportCsv()">
                        <i class="fas fa-download me-1"></i> CSV
                    </button>
                </div>
            </div>
        </div>
        <div class="card-body bg-light">
            <form method="get" action="{{ route('cmd.reports.leaderboard_report') }}" class="bg-white rounded-3 p-3 shadow-sm mb-3" id="leaderboard-form">
                <input type="hidden" name="export" id="export" value="">
                <div class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label mb-1">Category</label>
                        <input type="text" name="category" value="{{ $filters['category'] ?? '' }}" class="form-control form-control-sm" placeholder="e.g. Cancellation Ratio">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-1">Period</label>
                        <select name="period" class="form-select form-select-sm">
                            @foreach (['monthly' => 'Monthly', 'weekly' => 'Weekly', 'daily' => 'Daily'] as $value => $label)
                                <option value="{{ $value }}" {{ ($filters['period'] ?? 'monthly') === $value ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label mb-1">Month</label>
                        <select name="month" class="form-select form-select-sm">
                            <option value="">All</option>
                            @foreach ($months as $num => $name)
                                <option value="{{ $num }}" {{ (int)($filters['month'] ?? 0) === $num ? 'selected' : '' }}>{{ $name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label mb-1">Year</label>
                        <select name="year" class="form-select form-select-sm">
                            <option value="">All</option>
                            @for ($y = now()->year; $y >= now()->year - 5; $y--)
                                <option value="{{ $y }}" {{ (int)($filters['year'] ?? 0) === $y ? 'selected' : '' }}>{{ $y }}</option>
                            @endfor
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label mb-1">Agent</label>
                        <input type="text" name="agent" value="{{ $filters['agent'] ?? '' }}" class="form-control form-control-sm" placeholder="Agent">
                    </div>
                </div>
                <div class="row g-3 align-items-end mt-2">
                    <div class="col-md-3">
                        <label class="form-label mb-1">Per Page</label>
                        <select name="per_page" class="form-select form-select-sm">
                            @foreach ([25,50,100,200,500,1000] as $n)
                                <option value="{{ $n }}" {{ $perPageValue === $n ? 'selected' : '' }}>{{ $n }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-9 d-flex gap-2 justify-content-end">
                        <button type="submit" class="btn btn-primary btn-sm">
                            <i class="fas fa-search me-1"></i> Search
                        </button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="clearFilters()">
                            <i class="fas fa-undo me-1"></i> Reset
                        </button>
                    </div>
                </div>
            </form>

            <div class="row g-3">
                <div class="col-12">
                    <div class="card border-0 shadow-sm mb-3">
                        <div class="card-header bg-gradient" style="background:linear-gradient(135deg,#0f172a,#1e293b);">
                            <h6 class="mb-0 text-white">Record Holders (All Time)</h6>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th style="width:100px">Leaderboard Rank</th>
                                            <th>Agent</th>
                                            <th class="text-center">Contacts</th>
                                            <th class="text-center">Deals</th>
                                            <th class="text-end">Debt Enrolled</th>
                                            <th class="text-center">Leaderboard Date</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse($recordHolders ?? [] as $holder)
                                            <tr>
                                                <td class="fw-semibold">{{ $holder->rank === 1 ? '1st Place' : ($holder->rank === 2 ? '2nd Place' : ($holder->rank === 3 ? '3rd Place' : 'Company-Wide')) }}</td>
                                                <td>{{ $holder->agent }}</td>
                                                <td class="text-center">{{ $formatInt($holder->contacts) }}</td>
                                                <td class="text-center">{{ $formatInt($holder->deals) }}</td>
                                                <td class="text-end">{{ $formatCurrency($holder->debt) }}</td>
                                                <td class="text-center">{{ $holder->leaderboard_date ? \Carbon\Carbon::parse($holder->leaderboard_date)->format('m/d/Y') : '' }}</td>
                                            </tr>
                                        @empty
                                            <tr><td colspan="6" class="text-center py-3 text-muted">No record holders</td></tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-12">
                    <div class="card border-0 shadow-sm mb-3">
                        <div class="card-header bg-primary text-white">
                            <div class="d-flex justify-content-between align-items-center">
                                <h6 class="mb-0">Current Leaders · {{ $windowMeta['label'] ?? '' }}</h6>
                                <small class="text-white-50">Period: {{ $periodLabel ?? 'Monthly' }}</small>
                            </div>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-striped align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th style="width:100px">Current Rank</th>
                                            <th>Agent</th>
                                            <th class="text-center">Contacts</th>
                                            <th class="text-center">Deals</th>
                                            <th class="text-end">Debt Enrolled</th>
                                            <th class="text-center">Potential Rank</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @php $shown = 0; @endphp
                                        @forelse($reports as $report)
                                            @if($shown < 4)
                                                <tr>
                                                    <td class="fw-semibold">{{ ['1st Place','2nd Place','3rd Place','4th Place'][$shown] ?? 'Rank' }}</td>
                                                    <td>{{ $report->agent }}</td>
                                                    <td class="text-center">{{ $formatInt($report->contacts) }}</td>
                                                    <td class="text-center">{{ $formatInt($report->deals) }}</td>
                                                    <td class="text-end">{{ $formatCurrency($report->debt) }}</td>
                                                    <td class="text-center">—</td>
                                                </tr>
                                            @endif
                                            @php $shown++; @endphp
                                        @empty
                                            <tr><td colspan="6" class="text-center py-3 text-muted">No leaders in this window</td></tr>
                                        @endforelse
                                        <tr class="table-secondary fw-semibold">
                                            <td>Company-Wide</td>
                                            <td></td>
                                            <td class="text-center">{{ $formatInt($companyTotals['contacts'] ?? 0) }}</td>
                                            <td class="text-center">{{ $formatInt($companyTotals['deals'] ?? 0) }}</td>
                                            <td class="text-end">{{ $formatCurrency($companyTotals['debt'] ?? 0) }}</td>
                                            <td class="text-center">—</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-12">
                    <div class="card border-0 shadow-sm mb-3">
                        <div class="card-header bg-dark text-white">
                            <h6 class="mb-0">Leaderboard Rules</h6>
                        </div>
                        <div class="card-body">
                            <dl class="row mb-0 small">
                                <dt class="col-3 text-muted">Required Deals Enrolled</dt>
                                <dd class="col-9">Not configured (data unavailable)</dd>
                                <dt class="col-3 text-muted">Client Activity Cutoff</dt>
                                <dd class="col-9">Not configured (data unavailable)</dd>
                                <dt class="col-3 text-muted">Tiebreaker</dt>
                                <dd class="col-9">Debt Enrolled, then Contacts</dd>
                                <dt class="col-3 text-muted">Notes</dt>
                                <dd class="col-9">Auto-calculated from TblEnrollment in selected window.</dd>
                                <dt class="col-3 text-muted">Record Breaking Bonus</dt>
                                <dd class="col-9">Not configured (no bonus table found)</dd>
                            </dl>
                        </div>
                    </div>
                </div>

                <div class="col-12">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-gradient" style="background:linear-gradient(135deg,#1e293b,#0f172a);">
                            <h6 class="mb-0 text-white">Total Records (Top 20 · Current Window)</h6>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th style="width:40px"></th>
                                            <th>Agent</th>
                                            <th class="text-center">Contacts</th>
                                            <th class="text-center">Deals</th>
                                            <th class="text-end">Debt</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @php $rowNum = 0; @endphp
                                        @forelse($reports as $report)
                                            @if($rowNum < 20)
                                                <tr>
                                                    <td class="text-muted small">#{{ $report->rank }}</td>
                                                    <td>{{ $report->agent }}</td>
                                                    <td class="text-center">{{ $formatInt($report->contacts) }}</td>
                                                    <td class="text-center">{{ $formatInt($report->deals) }}</td>
                                                    <td class="text-end">{{ $formatCurrency($report->debt) }}</td>
                                                </tr>
                                            @endif
                                            @php $rowNum++; @endphp
                                        @empty
                                            <tr><td colspan="5" class="text-center py-3 text-muted">No records found</td></tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="card-footer d-flex justify-content-between align-items-center py-2">
                            <div class="text-muted small">Showing {{ $reports->firstItem() ?? 0 }} to {{ $reports->lastItem() ?? 0 }} of {{ $totalRecords }} entries</div>
                            {{ $reports->links() }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function clearFilters() {
            const form = document.getElementById('leaderboard-form');
            const inputs = form.querySelectorAll('input, select');
            inputs.forEach(input => {
                if (input.type !== 'hidden') {
                    input.value = '';
                }
            });
            form.submit();
        }

        function exportCsv() {
            const form = document.getElementById('leaderboard-form');
            const exportInput = document.getElementById('export');
            exportInput.value = 'csv';
            form.submit();
            exportInput.value = '';
        }
    </script>
@endsection
