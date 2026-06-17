@extends('layouts.app')

@section('content')
    @php
        $fmtAmount = function ($v, $fmt) {
            if ($v === null || $v === '') {
                return '';
            }
            return match ($fmt) {
                'currency' => '$' . number_format((float) $v, 0),
                'percent' => number_format((float) $v * 100, 2) . '%',
                default => number_format((float) $v, 0),
            };
        };
        $fmtInt = fn($v) => ($v === null || $v === '') ? '' : number_format((float) $v, 0);
        $fmtCurrency = fn($v) => ($v === null || $v === '') ? '' : '$' . number_format((float) $v, 0);
        $fmtDate = fn($v) => $v ? \Carbon\Carbon::parse($v)->format('n/j/Y') : '';

        $threshold = (float) ($settings->Threshold ?? 0);
        $ratioCategory = in_array($category, ['Cancellation Ratio', 'NSF Ratio']);

        $placeLabel = fn($n) => [1 => '1st', 2 => '2nd', 3 => '3rd', 4 => '4th'][$n] ?? ($n . 'th');

        $recordAmounts = $recordHolders->pluck('amount')->filter(fn($v) => $v !== null)->values();
        $potentialRank = function ($amount) use ($recordAmounts, $ratioCategory) {
            if ($amount === null || $amount === '') {
                return null;
            }
            $better = 0;
            foreach ($recordAmounts as $r) {
                if ($ratioCategory ? ((float) $r < (float) $amount) : ((float) $r > (float) $amount)) {
                    $better++;
                }
            }
            $pos = $better + 1;
            return $pos <= 4 ? $pos : null;
        };

        $optCols = ($layout['show_contacts'] ? 1 : 0) + ($layout['show_deals'] ? 1 : 0) + ($layout['show_debt'] ? 1 : 0);
        $currentCols = 3 + $optCols + 1;
        $recordCols = 3 + $optCols + 1;

        $bonusParts = [];
        if ($settings) {
            foreach ([1 => '1st', 2 => '2nd', 3 => '3rd', 4 => '4th'] as $n => $lbl) {
                $b = (float) ($settings->{"Bonus_$n"} ?? 0);
                if ($b > 0) {
                    $bonusParts[] = $lbl . ' - $' . number_format($b, 0);
                }
            }
        }
        $bonusText = implode(', ', $bonusParts);

        $anyBelow = false;
    @endphp

    <style>
        .lb-section { font-weight: 700; border-bottom: 2px solid #e9ecef; padding-bottom: .4rem; }
        .lb-company td { background: #eef2ff; font-weight: 600; }
        .lb-pace { box-shadow: inset 3px 0 0 #f1c40f; }
        .badge-soft { background: #fff7e0; color: #8a6d0b; border: 1px solid #f3e2a8; }
        .card { border-radius: .85rem; }
        .card > .card-header:first-child { border-radius: .85rem .85rem 0 0; }
        .table-responsive { border: 1px solid #e9ecef; border-radius: .65rem; overflow: hidden; }
        .table-responsive > .table { margin-bottom: 0; }
    </style>

    <div class="card">
        <div class="card-header d-flex align-items-center py-0">
            <h6 class="py-3 mb-0">{{ $title }}</h6>
            <span class="ms-auto small text-muted">
                <span class="badge bg-primary">{{ $category }}</span>
                <span class="badge bg-secondary">{{ $period }}</span>
            </span>
        </div>
        <div class="card-body">
            <form method="get" action="{{ route('cmd.reports.leaderboard_report') }}" class="bg-light rounded-3 p-2 p-md-3 mb-3" id="leaderboard-form">
                <input type="hidden" name="export" id="export" value="">
                <div class="row g-2 align-items-end">
                    <div class="col-md-5">
                        <label class="form-label mb-1">Category</label>
                        <select name="category" class="form-select form-select-sm" onchange="this.form.submit()">
                            @foreach($categories as $c)
                                <option value="{{ $c }}" {{ $c === $category ? 'selected' : '' }}>{{ $c }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label mb-1">Period</label>
                        <select name="period" class="form-select form-select-sm" onchange="this.form.submit()">
                            @forelse($periods as $p)
                                <option value="{{ $p }}" {{ $p === $period ? 'selected' : '' }}>{{ $p }}</option>
                            @empty
                                <option value="">(none configured)</option>
                            @endforelse
                        </select>
                    </div>
                    <div class="col-md-3">
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary btn-sm flex-fill">
                                <i class="fas fa-rotate me-1"></i> Refresh
                            </button>
                            <button type="button" class="btn btn-success btn-sm" onclick="exportCsv()">
                                <i class="fas fa-download me-1"></i> Excel
                            </button>
                        </div>
                    </div>
                </div>
            </form>

            {{-- ============ Current Leaders ============ --}}
            <h6 class="lb-section mb-3">
                Current Leaders
                <span class="small text-muted fw-normal ms-1">{{ $header }}</span>
            </h6>
            <div class="table-responsive mb-4">
                <table class="table table-striped table-hover align-middle">
                    <thead class="table-dark">
                        <tr>
                            <th>Current Rank</th>
                            <th>Agent</th>
                            <th class="text-end">{{ $layout['amount_label'] }}</th>
                            @if($layout['show_contacts'])<th class="text-end">Contacts</th>@endif
                            @if($layout['show_deals'])<th class="text-end">{{ $layout['deals_label'] }}</th>@endif
                            @if($layout['show_debt'])<th class="text-end">Debt</th>@endif
                            <th class="text-center">Potential Rank</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($currentLeaders as $i => $row)
                            @php
                                $pr = $potentialRank($row->amount ?? null);
                                $below = $threshold > 0 && (
                                    $ratioCategory
                                        ? ((float) ($row->deals ?? 0) < $threshold)
                                        : ((float) ($row->contacts ?? 0) < $threshold)
                                );
                                $anyBelow = $anyBelow || $below;
                            @endphp
                            <tr @class(['lb-pace' => $pr !== null])>
                                <td class="fw-semibold text-nowrap">{{ $placeLabel($i + 1) }} Place</td>
                                <td>{{ $row->agent }}</td>
                                <td class="text-end fw-bold">{{ $fmtAmount($row->amount ?? null, $layout['amount_format']) }}</td>
                                @if($layout['show_contacts'])<td class="text-end">{{ $fmtInt($row->contacts ?? null) }}{{ (!$ratioCategory && $below) ? ' *' : '' }}</td>@endif
                                @if($layout['show_deals'])<td class="text-end">{{ $fmtInt($row->deals ?? null) }}{{ ($ratioCategory && $below) ? ' *' : '' }}</td>@endif
                                @if($layout['show_debt'])<td class="text-end">{{ $fmtCurrency($row->debt ?? null) }}</td>@endif
                                <td class="text-center">
                                    @if($pr !== null)
                                        <span class="badge rounded-pill badge-soft">{{ $placeLabel($pr) }}</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="{{ $currentCols }}" class="text-center text-muted py-3">No current leaders for this period.</td></tr>
                        @endforelse
                        <tr class="lb-company">
                            <td>Company-Wide</td>
                            <td></td>
                            <td class="text-end fw-bold">{{ $currentCompany ? $fmtAmount($currentCompany->amount ?? null, $layout['amount_format']) : '' }}</td>
                            @if($layout['show_contacts'])<td class="text-end">{{ $currentCompany ? $fmtInt($currentCompany->contacts ?? null) : '' }}</td>@endif
                            @if($layout['show_deals'])<td class="text-end">{{ $currentCompany ? $fmtInt($currentCompany->deals ?? null) : '' }}</td>@endif
                            @if($layout['show_debt'])<td class="text-end">{{ $currentCompany ? $fmtCurrency($currentCompany->debt ?? null) : '' }}</td>@endif
                            <td></td>
                        </tr>
                    </tbody>
                </table>
                @if($anyBelow)
                    <small class="text-muted">* below the activity threshold ({{ $fmtInt($threshold) }}).</small>
                @endif
            </div>

            {{-- ============ Record Holders ============ --}}
            <h6 class="lb-section mb-3">
                Record Holders
                <span class="small text-muted fw-normal ms-1">All-time</span>
            </h6>
            <div class="table-responsive mb-4">
                <table class="table table-striped table-hover align-middle">
                    <thead class="table-dark">
                        <tr>
                            <th>Leaderboard Rank</th>
                            <th>Agent</th>
                            <th class="text-end">{{ $layout['amount_label'] }}</th>
                            @if($layout['show_contacts'])<th class="text-end">Contacts</th>@endif
                            @if($layout['show_deals'])<th class="text-end">{{ $layout['deals_label'] }}</th>@endif
                            @if($layout['show_debt'])<th class="text-end">Debt</th>@endif
                            <th>Leaderboard Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($recordHolders as $i => $row)
                            <tr>
                                <td class="fw-semibold text-nowrap">{{ $placeLabel($i + 1) }} Place</td>
                                <td>{{ $row->agent }}</td>
                                <td class="text-end fw-bold">{{ $fmtAmount($row->amount ?? null, $layout['amount_format']) }}</td>
                                @if($layout['show_contacts'])<td class="text-end">{{ $fmtInt($row->contacts ?? null) }}</td>@endif
                                @if($layout['show_deals'])<td class="text-end">{{ $fmtInt($row->deals ?? null) }}</td>@endif
                                @if($layout['show_debt'])<td class="text-end">{{ $fmtCurrency($row->debt ?? null) }}</td>@endif
                                <td>{{ $fmtDate($row->record_date ?? null) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="{{ $recordCols }}" class="text-center text-muted py-3">No records yet.</td></tr>
                        @endforelse
                        <tr class="lb-company">
                            <td>Company-Wide</td>
                            <td></td>
                            <td class="text-end fw-bold">{{ $companyRecord ? $fmtAmount($companyRecord->amount ?? null, $layout['amount_format']) : '' }}</td>
                            @if($layout['show_contacts'])<td></td>@endif
                            @if($layout['show_deals'])<td class="text-end">{{ $companyRecord ? $fmtInt($companyRecord->deals ?? null) : '' }}</td>@endif
                            @if($layout['show_debt'])<td class="text-end">{{ $companyRecord ? $fmtCurrency($companyRecord->debt ?? null) : '' }}</td>@endif
                            <td>{{ $companyRecord ? $fmtDate($companyRecord->record_date ?? null) : '' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            {{-- ============ Leaderboard Rules ============ --}}
            <h6 class="lb-section mb-3">Leaderboard Rules</h6>
            <div class="table-responsive mb-4">
                <table class="table table-striped align-middle" style="max-width: 720px;">
                    <tbody>
                        <tr><th class="table-secondary" style="width:240px;">Required Contacts</th><td>{{ $settings ? (int) ($settings->Threshold ?? 0) : 0 }}</td></tr>
                        @if(!empty($settings->Activity_Cutoff))
                            <tr><th class="table-secondary">Client Activity Cutoff</th><td>{{ $settings->Activity_Cutoff }}</td></tr>
                        @endif
                        @if(!empty($settings->Tiebreaker))
                            <tr><th class="table-secondary">Tiebreaker</th><td>{{ $settings->Tiebreaker }}</td></tr>
                        @endif
                        @if(!empty($settings->Notes))
                            <tr><th class="table-secondary">Notes</th><td>{{ $settings->Notes }}</td></tr>
                        @endif
                        @if($bonusText !== '')
                            <tr><th class="table-secondary">Record Breaking Bonus</th><td><span class="badge bg-success">{{ $bonusText }}</span></td></tr>
                        @endif
                    </tbody>
                </table>
            </div>

            {{-- ============ Total Records ============ --}}
            <h6 class="lb-section mb-3">
                Total Records
                <span class="small text-muted fw-normal ms-1">All-time standings</span>
            </h6>
            <div class="table-responsive">
                <table class="table table-striped table-hover align-middle">
                    <thead class="table-dark">
                        <tr>
                            <th style="width:48px;">#</th>
                            <th>Agent</th>
                            <th class="text-end" style="width:90px;">Records</th>
                            <th>Breakdown</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($totalRecords as $i => $row)
                            @php
                                $parts = [];
                                foreach (['1st' => $row->first_count ?? 0, '2nd' => $row->second_count ?? 0, '3rd' => $row->third_count ?? 0, '4th' => $row->fourth_count ?? 0] as $lbl => $c) {
                                    if ((int) $c > 0) {
                                        $parts[] = (int) $c . 'x ' . $lbl;
                                    }
                                }
                                $breakdown = implode(', ', $parts);
                            @endphp
                            <tr>
                                <td class="fw-semibold">{{ $i + 1 }}</td>
                                <td>{{ $row->agent }}</td>
                                <td class="text-end fw-semibold">{{ $row->records }}</td>
                                <td class="text-muted">{{ $breakdown }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-center text-muted py-3">No records.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        function exportCsv() {
            const form = document.getElementById('leaderboard-form');
            const exportInput = document.getElementById('export');
            exportInput.value = 'csv';
            form.submit();
            exportInput.value = '';
        }
    </script>
@endsection
