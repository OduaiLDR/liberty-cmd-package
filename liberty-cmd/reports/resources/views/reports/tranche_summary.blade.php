@extends('layouts.app')

@section('content')
    @php
        $formatCurrency = static fn($value) => $value === null || $value === '' ? '' : '$' . number_format((float) $value, 2);
        $formatDate = static fn($value) => $value ? \Carbon\Carbon::parse($value)->format('m/d/Y') : '';
    @endphp

    <div class="card">
        <div class="card-header d-flex align-items-center py-0">
            <h6 class="py-3 mb-0">Tranche Summary</h6>
        </div>
        <div class="card-body">
            <form method="get" action="{{ route('cmd.reports.tranche_summary') }}" class="bg-light rounded-3 p-2 p-md-3 mb-3">
                <div class="row g-2 align-items-center mb-2">
                    <div class="col-auto fw-semibold">Date Range:</div>
                    <div class="col">
                        <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between">
                            <div class="btn-group btn-group-sm" role="group">
                                @php $ranges = ['all' => 'All','today'=>'Today','7'=>'7d','30'=>'30d','this_month'=>'This Month','last_month'=>'Last Month']; @endphp
                                @foreach ($ranges as $value => $label)
                                    <button type="submit" name="range" value="{{ $value }}" class="btn btn-outline-primary {{ ($range ?? request('range')) === $value ? 'active' : '' }}">{{ $label }}</button>
                                @endforeach
                            </div>
                            <button type="submit" name="export" value="csv" class="btn btn-secondary btn-sm">Export CSV</button>
                        </div>
                    </div>
                </div>

                <div class="row g-2 align-items-center mb-2">
                    <div class="col-auto">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text">From</span>
                            <input type="date" name="from" value="{{ old('from', $from ?? request('from')) }}" class="form-control">
                        </div>
                    </div>
                    <div class="col-auto">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text">To</span>
                            <input type="date" name="to" value="{{ old('to', $to ?? request('to')) }}" class="form-control">
                        </div>
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
                        <a href="{{ route('cmd.reports.tranche_summary') }}" class="btn btn-light btn-sm">Reset</a>
                    </div>
                </div>
            </form>

            <div class="table-responsive">
                <table class="table table-striped table-sm align-middle">
                    <thead>
                        <tr>
                            <th>Tranche</th>
                            <th>Payment Date</th>
                            <th>Report Date</th>
                            <th class="text-end">Total Debt</th>
                            <th class="text-end">LDR</th>
                            <th class="text-end">PLAW</th>
                            <th class="text-end">Progress</th>
                            <th class="text-end">Total</th>
                            <th class="text-end">Payment</th>
                            <th class="text-end">Lookback</th>
                            <th class="text-end">8% of Lookback</th>
                            <th class="text-end">EPF All</th>
                            <th class="text-end">EPF Pending</th>
                            <th class="text-end">EPF Amount</th>
                            <th class="text-end">EPF Dist</th>
                            <th class="text-end">EPF Total (Q)</th>
                            <th class="text-end">Payment + 10% (N)</th>
                            <th class="text-end">R</th>
                            <th class="text-end">S</th>
                            <th class="text-end">T</th>
                            <th class="text-end">U</th>
                            <th>Flip Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($reports as $r)
                            <tr>
                                <td>{{ $r->Tranche }}</td>
                                <td>{{ $formatDate($r->Payment_Date) }}</td>
                                <td>{{ $formatDate($r->Report_Date) }}</td>
                                <td class="text-end">{{ $formatCurrency($r->Total_Debt) }}</td>
                                <td class="text-end">{{ (int) ($r->Count_LDR ?? 0) }}</td>
                                <td class="text-end">{{ (int) ($r->Count_PLAW ?? 0) }}</td>
                                <td class="text-end">{{ (int) ($r->Count_PROGRESS ?? 0) }}</td>
                                <td class="text-end">{{ (int) ($r->Count_Total ?? 0) }}</td>
                                <td class="text-end">{{ $formatCurrency($r->Payment) }}</td>
                                <td class="text-end">{{ $formatCurrency($r->SoldDebt_Lookback) }}</td>
                                <td class="text-end">{{ $formatCurrency($r->K_EightPercentOfLookback) }}</td>
                                <td class="text-end">{{ $formatCurrency($r->EPF_All) }}</td>
                                <td class="text-end">{{ $formatCurrency($r->EPF_Pending) }}</td>
                                <td class="text-end">{{ $formatCurrency($r->EPF_Amount) }}</td>
                                <td class="text-end">{{ $formatCurrency($r->EPFD_Amount) }}</td>
                                <td class="text-end">{{ $formatCurrency($r->Q_EpfTotal) }}</td>
                                <td class="text-end">{{ $formatCurrency($r->N_PaymentPlus10) }}</td>
                                <td class="text-end">{{ $formatCurrency($r->R_MinQN) }}</td>
                                <td class="text-end">{{ $formatCurrency($r->S_MaxNMinusQ) }}</td>
                                <td class="text-end">{{ $formatCurrency($r->T_MaxQMinusN) }}</td>
                                <td class="text-end">{{ $r->U_Ratio !== null ? number_format((float) $r->U_Ratio, 4) : '' }}</td>
                                <td>{{ $formatDate($r->Flip_Date) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="22" class="text-center text-muted">No records found.</td>
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

