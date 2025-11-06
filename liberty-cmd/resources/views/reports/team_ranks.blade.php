@extends('layouts.app')

@section('content')
    @php
        $formatCurrency = static fn($value) => '$' . number_format((float) ($value ?? 0), 2);
        $formatPercent = static fn($value) => $value === null ? '' : number_format((float) $value * 100, 2) . '%';
        $currentRange = $range ?? request('range');
        if (!$currentRange) {
            $currentRange = request('from') || request('to') ? 'custom' : 'all';
        }
    @endphp

    <div class="card">
        <div class="card-header d-flex align-items-center py-0">
            <h6 class="py-3 mb-0">Team Ranks</h6>
        </div>
        <div class="card-body">
            <form method="get" action="{{ route('cmd.reports.team_ranks') }}" class="bg-light rounded-3 p-2 p-md-3 mb-3">
                <div class="row g-2 align-items-center mb-2">
                    <div class="col-auto fw-semibold">For:</div>
                    <div class="col">
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <div class="btn-group btn-group-sm gap-1" role="group" aria-label="Quick ranges">
                                @php $ranges = ['all' => 'All','today'=>'Today','7'=>'7d','30'=>'30d','this_month'=>'This Month','last_month'=>'Last Month']; @endphp
                                @foreach ($ranges as $value => $label)
                                    <button type="submit" name="range" value="{{ $value }}" class="btn btn-outline-primary {{ $currentRange === $value ? 'active' : '' }}">{{ $label }}</button>
                                @endforeach
                            </div>
                            <button type="submit" name="export" value="csv" class="btn btn-secondary btn-sm">Export CSV</button>
                        </div>
                    </div>
                </div>

                <div class="row g-2 align-items-center justify-content-end mt-2">
                    <div class="col-auto">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text">From</span>
                            <input type="date" name="from" value="{{ old('from', request('from')) }}" class="form-control">
                        </div>
                    </div>
                    <div class="col-auto">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text">To</span>
                            <input type="date" name="to" value="{{ old('to', request('to')) }}" class="form-control">
                        </div>
                    </div>
                    <div class="col-auto">
                        <label class="form-label form-label-sm mb-0">Data Source</label>
                        <select name="data_source" class="form-select form-select-sm">
                            @foreach (($opts['data_sources'] ?? []) as $ds)
                                <option value="{{ $ds }}" {{ ($dataSource ?? request('data_source', 'All Data Sources')) === $ds ? 'selected' : '' }}>{{ $ds }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-auto">
                        <select name="per_page" class="form-select form-select-sm">
                            @foreach ([25, 50, 100, 250, 500] as $n)
                                <option value="{{ $n }}" {{ (int) request('per_page', $perPage ?? 25) === $n ? 'selected' : '' }}>{{ $n }} / page</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-primary btn-sm">Apply</button>
                        <a href="{{ route('cmd.reports.team_ranks') }}" class="btn btn-light btn-sm">Reset</a>
                    </div>
                </div>
            </form>

            <div class="table-responsive">
                <table class="table table-striped align-middle">
                    <thead>
                        <tr>
                            <th>Team</th>
                            <th>Agent</th>
                            <th class="text-end">Contacts</th>
                            <th class="text-end">WCC</th>
                            <th class="text-end">Cancels</th>
                            <th class="text-end">NSFs</th>
                            <th class="text-end">Enrolled Debt</th>
                            <th class="text-end">Net</th>
                            <th class="text-end">Ratio</th>
                            <th class="text-end">Rank Ratio</th>
                            <th class="text-end">Rank WCC</th>
                            <th class="text-end">Rank Debt</th>
                            <th class="text-end">Score</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($agents as $r)
                            <tr>
                                <td>{{ $r->team }}</td>
                                <td>{{ $r->agent }}</td>
                                <td class="text-end">{{ (int) $r->contacts }}</td>
                                <td class="text-end">{{ (int) $r->wcc }}</td>
                                <td class="text-end">{{ (int) $r->cancels }}</td>
                                <td class="text-end">{{ (int) $r->nsfs }}</td>
                                <td class="text-end">{{ $formatCurrency($r->enrolled_debt) }}</td>
                                <td class="text-end">{{ (int) $r->net }}</td>
                                <td class="text-end">{{ $formatPercent($r->ratio) }}</td>
                                <td class="text-end">{{ $r->rank_ratio ? number_format((float) $r->rank_ratio, 2) : '' }}</td>
                                <td class="text-end">{{ $r->rank_wcc ? number_format((float) $r->rank_wcc, 2) : '' }}</td>
                                <td class="text-end">{{ $r->rank_debt ? number_format((float) $r->rank_debt, 2) : '' }}</td>
                                <td class="text-end">{{ $r->score ? number_format((float) $r->score, 2) : '' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="13" class="text-center text-muted">No records found.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if (method_exists($agents, 'links'))
                <div class="d-flex justify-content-center mt-3">
                    {{ $agents->appends(request()->query())->links() }}
                </div>
            @endif

            <hr>

            <h6>Team Summary</h6>
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead>
                        <tr>
                            <th>Team</th>
                            <th class="text-end">Contacts</th>
                            <th class="text-end">WCC</th>
                            <th class="text-end">Cancels</th>
                            <th class="text-end">NSFs</th>
                            <th class="text-end">Enrolled Debt</th>
                            <th class="text-end">Net</th>
                            <th class="text-end">Ratio</th>
                            <th class="text-end">Score</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($teams as $t)
                            <tr>
                                <td>{{ $t->team }}</td>
                                <td class="text-end">{{ (int) $t->contacts }}</td>
                                <td class="text-end">{{ (int) $t->wcc }}</td>
                                <td class="text-end">{{ (int) $t->cancels }}</td>
                                <td class="text-end">{{ (int) $t->nsfs }}</td>
                                <td class="text-end">{{ $formatCurrency($t->enrolled_debt) }}</td>
                                <td class="text-end">{{ (int) $t->net }}</td>
                                <td class="text-end">{{ $formatPercent($t->ratio) }}</td>
                                <td class="text-end">{{ $t->score ? number_format((float) $t->score, 2) : '' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="text-center text-muted">No team data.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <h6>Company Summary</h6>
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead>
                        <tr>
                            <th>Contacts</th>
                            <th>WCC</th>
                            <th>Cancels</th>
                            <th>NSFs</th>
                            <th>Enrolled Debt</th>
                            <th>Net</th>
                            <th>Ratio</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>{{ (int) $company->contacts }}</td>
                            <td>{{ (int) $company->wcc }}</td>
                            <td>{{ (int) $company->cancels }}</td>
                            <td>{{ (int) $company->nsfs }}</td>
                            <td>{{ $formatCurrency($company->enrolled_debt) }}</td>
                            <td>{{ (int) $company->net }}</td>
                            <td>{{ $formatPercent($company->ratio) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
