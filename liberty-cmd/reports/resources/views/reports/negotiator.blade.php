@extends('layouts.app')

@section('content')
    @php
        $formatCurrency = static fn($value) => $value === null || $value === '' ? '' : '$' . number_format((float) $value, 2);
        $formatRatio = static fn($value) => $value === null || $value === '' ? '' : number_format((float) $value, 4);
        $formatDate = static fn($value) => $value ? \Carbon\Carbon::parse($value)->format('m/d/Y') : '';
    @endphp

    <div class="card">
        <div class="card-header d-flex align-items-center py-0">
            <h6 class="py-3 mb-0">Negotiator Report</h6>
        </div>
        <div class="card-body">
            <form method="get" action="{{ route('cmd.reports.negotiator_report') }}" class="bg-light rounded-3 p-2 p-md-3 mb-3">
                <div class="row g-2 align-items-end">
                    <div class="col-md-2">
                        <label class="form-label form-label-sm mb-0">Date By</label>
                        <select name="date_field" class="form-select form-select-sm">
                            @php $fields = ['payment' => 'Payment', 'welcome_call' => 'Welcome Call', 'submitted' => 'Submitted']; @endphp
                            @foreach ($fields as $k => $label)
                                <option value="{{ $k }}" {{ (request('date_field', $dateField ?? 'payment') === $k) ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label form-label-sm mb-0">From</label>
                        <input type="date" name="from" value="{{ old('from', $from ?? request('from')) }}" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label form-label-sm mb-0">To</label>
                        <input type="date" name="to" value="{{ old('to', $to ?? request('to')) }}" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label form-label-sm mb-0">NGO</label>
                        <select name="ngo" class="form-select form-select-sm">
                            <option value="all">All</option>
                            @foreach (($opts['ngos'] ?? []) as $ngo)
                                <option value="{{ $ngo }}" {{ request('ngo') === $ngo ? 'selected' : '' }}>{{ $ngo }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label form-label-sm mb-0">Negotiator</label>
                        <select name="negotiator" class="form-select form-select-sm">
                            <option value="">All</option>
                            @foreach (($opts['negotiators'] ?? []) as $negotiator)
                                <option value="{{ $negotiator }}" {{ request('negotiator') === $negotiator ? 'selected' : '' }}>{{ $negotiator }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label form-label-sm mb-0">Enrollment</label>
                        <select name="enrollment_status" class="form-select form-select-sm">
                            <option value="">All</option>
                            @foreach (($opts['enrollment_statuses'] ?? []) as $status)
                                <option value="{{ $status }}" {{ request('enrollment_status') === $status ? 'selected' : '' }}>{{ $status }}</option>
                            @endforeach
                            <option value="active" {{ request('enrollment_status') === 'active' ? 'selected' : '' }}>Active</option>
                            <option value="cancels" {{ request('enrollment_status') === 'cancels' ? 'selected' : '' }}>Cancels</option>
                            <option value="nsfs" {{ request('enrollment_status') === 'nsfs' ? 'selected' : '' }}>NSFs</option>
                            <option value="not_closed" {{ request('enrollment_status') === 'not_closed' ? 'selected' : '' }}>Not Closed</option>
                        </select>
                    </div>
                </div>
                <div class="row g-2 align-items-center mt-2">
                    <div class="col-auto">
                        <select name="per_page" class="form-select form-select-sm">
                            @foreach ([25, 50, 100, 250, 500] as $n)
                                <option value="{{ $n }}" {{ (int) request('per_page', $perPage ?? 25) === $n ? 'selected' : '' }}>{{ $n }} / page</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-primary btn-sm">Apply</button>
                        <a href="{{ route('cmd.reports.negotiator_report') }}" class="btn btn-light btn-sm">Reset</a>
                        <button type="submit" name="export" value="csv" class="btn btn-secondary btn-sm">Export CSV</button>
                    </div>
                </div>
                <details class="mt-3">
                    <summary class="small fw-semibold">Advanced Filters</summary>
                    <div class="row g-2 mt-2">
                        <div class="col-md-3">
                            <label class="form-label form-label-sm">Assignment Status</label>
                            <select name="assignment_status" class="form-select form-select-sm">
                                <option value="">All</option>
                                @foreach (($opts['assignment_statuses'] ?? []) as $status)
                                    <option value="{{ $status }}" {{ request('assignment_status') === $status ? 'selected' : '' }}>{{ $status }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label form-label-sm">Ready Flag</label>
                            <select name="ready_flag" class="form-select form-select-sm">
                                <option value="">All</option>
                                <option value="ready" {{ request('ready_flag') === 'ready' ? 'selected' : '' }}>Ready</option>
                                <option value="not_ready" {{ request('ready_flag') === 'not_ready' ? 'selected' : '' }}>Not Ready</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label form-label-sm">Creditor</label>
                            <input type="text" name="creditor" value="{{ request('creditor') }}" list="neg-creditors" class="form-control form-control-sm" autocomplete="off">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label form-label-sm">Collection Company</label>
                            <input type="text" name="collection_company" value="{{ request('collection_company') }}" list="neg-collections" class="form-control form-control-sm" autocomplete="off">
                        </div>
                    </div>
                    <div class="row g-2 mt-2">
                        <div class="col-md-3">
                            <label class="form-label form-label-sm">Debt Min</label>
                            <input type="number" name="debt_min" value="{{ request('debt_min') }}" class="form-control form-control-sm" step="0.01">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label form-label-sm">Debt Max</label>
                            <input type="number" name="debt_max" value="{{ request('debt_max') }}" class="form-control form-control-sm" step="0.01">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label form-label-sm">Follow Up From</label>
                            <input type="date" name="follow_up_from" value="{{ request('follow_up_from') }}" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label form-label-sm">Follow Up To</label>
                            <input type="date" name="follow_up_to" value="{{ request('follow_up_to') }}" class="form-control form-control-sm">
                        </div>
                    </div>
                    <div class="row g-2 mt-2">
                        <div class="col-md-3">
                            <label class="form-label form-label-sm">Ready From</label>
                            <input type="date" name="ready_from" value="{{ request('ready_from') }}" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label form-label-sm">Ready To</label>
                            <input type="date" name="ready_to" value="{{ request('ready_to') }}" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label form-label-sm">Settlement From</label>
                            <input type="date" name="settlement_from" value="{{ request('settlement_from') }}" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label form-label-sm">Settlement To</label>
                            <input type="date" name="settlement_to" value="{{ request('settlement_to') }}" class="form-control form-control-sm">
                        </div>
                    </div>
                    <div class="row g-2 mt-2">
                        <div class="col-md-3">
                            <label class="form-label form-label-sm">Last Payment From</label>
                            <input type="date" name="last_payment_from" value="{{ request('last_payment_from') }}" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label form-label-sm">Last Payment To</label>
                            <input type="date" name="last_payment_to" value="{{ request('last_payment_to') }}" class="form-control form-control-sm">
                        </div>
                    </div>
                </details>

                <datalist id="neg-creditors">
                    @foreach (($opts['creditors'] ?? []) as $v)
                        <option value="{{ $v }}"></option>
                    @endforeach
                </datalist>
                <datalist id="neg-collections">
                    @foreach (($opts['collection_companies'] ?? []) as $v)
                        <option value="{{ $v }}"></option>
                    @endforeach
                </datalist>
            </form>

            <div class="table-responsive">
                <table class="table table-striped table-sm align-middle">
                    <thead>
                        <tr>
                            <th>LLG-ID</th>
                            <th>Contact</th>
                            <th>Enrollment Status</th>
                            <th>Assignment Status</th>
                            <th>Negotiator</th>
                            <th>Agent</th>
                            <th>NGO</th>
                            <th>Assigned Date</th>
                            <th>Debt ID</th>
                            <th class="text-end">Debt Amount</th>
                            <th class="text-end">Custodial Balance</th>
                            <th class="text-end">Debt/Balance %</th>
                            <th class="text-end">Payments</th>
                            <th>Debt Tier</th>
                            <th>Creditor</th>
                            <th>Collection Company</th>
                            <th>Creditor Group</th>
                            <th>Follow Up Date</th>
                            <th>Ready To Settle</th>
                            <th>Account Not Ready Date</th>
                            <th>Account Not Ready Reason</th>
                            <th>Last Payment Date</th>
                            <th>Settlement Date</th>
                            <th>Settlements</th>
                            <th class="text-end">Days Since Activity</th>
                            <th>WCC Date</th>
                            <th class="text-end">Balance 2 Mo Ago</th>
                            <th class="text-end">Balance Last Mo</th>
                            <th class="text-end">Balance Current</th>
                            <th>Send POA</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($reports as $r)
                            <tr>
                                <td>{{ $r->CID }}</td>
                                <td>{{ $r->Contact_Name }}</td>
                                <td>{{ $r->Enrollment_Status }}</td>
                                <td>{{ $r->Assignment_Status }}</td>
                                <td>{{ $r->Negotiator }}</td>
                                <td>{{ $r->Agent }}</td>
                                <td>{{ $r->NGO }}</td>
                                <td>{{ $formatDate($r->Negotiator_Assigned_Date) }}</td>
                                <td>{{ $r->Debt_ID }}</td>
                                <td class="text-end">{{ $formatCurrency($r->Debt_Amount) }}</td>
                                <td class="text-end">{{ $formatCurrency($r->Balance) }}</td>
                                <td class="text-end">{{ $formatRatio($r->Debt_Balance_Ratio) }}</td>
                                <td class="text-end">{{ $formatCurrency($r->Payments) }}</td>
                                <td>{{ $r->Debt_Tier }}</td>
                                <td>{{ $r->Creditor }}</td>
                                <td>{{ $r->Collection_Company }}</td>
                                <td>{{ $r->Creditor_Group }}</td>
                                <td>{{ $formatDate($r->Follow_Up_Date) }}</td>
                                <td>{{ $formatDate($r->Ready_To_Settle_Date) }}</td>
                                <td>{{ $formatDate($r->Account_Not_Ready_Date) }}</td>
                                <td>{{ $r->Account_Not_Ready_Reason }}</td>
                                <td>{{ $formatDate($r->Last_Payment_Date) }}</td>
                                <td>{{ $formatDate($r->Settlement_Date) }}</td>
                                <td>{{ $r->Settlements }}</td>
                                <td class="text-end">{{ $r->Days_Since_Activity }}</td>
                                <td>{{ $formatDate($r->WCC_Date) }}</td>
                                <td class="text-end">{{ $formatCurrency($r->Balance_Two_Months_Ago) }}</td>
                                <td class="text-end">{{ $formatCurrency($r->Balance_Last_Month) }}</td>
                                <td class="text-end">{{ $formatCurrency($r->Balance_Current) }}</td>
                                <td>{{ $r->Send_POA }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="30" class="text-center text-muted">No records found.</td>
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
