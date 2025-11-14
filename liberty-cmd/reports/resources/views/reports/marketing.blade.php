@use('Illuminate\Support\Str')

@extends('layouts.app')

@section('content')
    @php
        $formatCurrency = static fn($value) => '$' . number_format((float) ($value ?? 0), 2);
        $formatDate = static fn($value) => $value ? \Carbon\Carbon::parse($value)->format('m/d/Y') : '';
        $formatPerPiece = static fn($value) => '$' . number_format((float) ($value ?? 0), 4);
        $totalRecords = method_exists($reports, 'total') ? $reports->total() : count($reports);
        $perPageValue = $perPage ?? (int) request('per_page', 25);
        $currentRange = $range ?? request('range');
        if (!$currentRange) {
            $currentRange = request('from') || request('to') ? 'custom' : 'all';
        }
        $options = $options ?? [];
    @endphp

    <div class="card">
        <div class="card-header d-flex align-items-center py-0">
            <h6 class="py-3 mb-0">Marketing Report</h6>
        </div>
        <div class="card-body">
            <form method="get" action="{{ route('cmd.reports.marketing_report') }}" class="bg-light rounded-3 p-2 p-md-3 mb-3" id="marketing-report-form">
                <input type="hidden" name="range" id="range" value="{{ $currentRange }}">
                <input type="hidden" name="export" id="export" value="">

                <div class="row g-2 align-items-center">
                    <div class="col-auto fw-semibold">Marketing Drops For:</div>
                    <div class="col">
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <div class="btn-group btn-group-sm gap-1" role="group" aria-label="Quick ranges">
                                @php
                                    $ranges = [
                                        'all' => 'All',
                                        'today' => 'Today',
                                        '7' => '7d',
                                        '30' => '30d',
                                        'this_month' => 'This Month',
                                        'last_month' => 'Last Month',
                                    ];
                                @endphp
                                @foreach ($ranges as $value => $label)
                                    <button type="button" class="btn btn-outline-primary range-btn {{ $currentRange === $value ? 'active' : '' }}" data-range="{{ $value }}">
                                        {{ $label }}
                                    </button>
                                @endforeach
                            </div>
                            <button type="button" id="btn-export" class="btn btn-secondary btn-sm">Export CSV</button>
                        </div>
                    </div>
                </div>

                <div class="row g-2 align-items-center justify-content-end mt-2">
                    <div class="col-auto">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text">From</span>
                            <input type="date" name="from" id="from" value="{{ old('from', $from ?? request('from')) }}" class="form-control">
                        </div>
                    </div>
                    <div class="col-auto">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text">To</span>
                            <input type="date" name="to" id="to" value="{{ old('to', $to ?? request('to')) }}" class="form-control">
                        </div>
                    </div>
                </div>

                <div class="row g-2 mt-2">
                    <div class="col-md-3">
                        <label class="form-label form-label-sm">Drop Name</label>
                        <input type="text" name="drop_name" value="{{ request('drop_name') }}" class="form-control form-control-sm" autocomplete="off">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label form-label-sm">Vendor</label>
                        <select name="vendor" class="form-select form-select-sm">
                            <option value="">Any</option>
                            @foreach (($options['vendors'] ?? []) as $value)
                                <option value="{{ $value }}" {{ request('vendor') === $value ? 'selected' : '' }}>{{ $value }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label form-label-sm">Drop Type</label>
                        <select name="drop_type" class="form-select form-select-sm">
                            <option value="">Any</option>
                            @foreach (($options['drop_types'] ?? []) as $value)
                                <option value="{{ $value }}" {{ request('drop_type') === $value ? 'selected' : '' }}>{{ $value }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label form-label-sm">Debt Tier</label>
                        <select name="debt_tier" class="form-select form-select-sm">
                            <option value="">Any</option>
                            @foreach (($options['debt_tiers'] ?? []) as $value)
                                <option value="{{ $value }}" {{ request('debt_tier') === $value ? 'selected' : '' }}>{{ $value }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="row g-2 mt-2">
                    <div class="col-md-3">
                        <label class="form-label form-label-sm">Data Type</label>
                        <select name="data_type" class="form-select form-select-sm">
                            <option value="">Any</option>
                            @foreach (($options['data_types'] ?? []) as $value)
                                <option value="{{ $value }}" {{ request('data_type') === $value ? 'selected' : '' }}>{{ $value }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label form-label-sm">Mail Style</label>
                        <select name="mail_style" class="form-select form-select-sm">
                            <option value="">Any</option>
                            @foreach (($options['mail_styles'] ?? []) as $value)
                                <option value="{{ $value }}" {{ request('mail_style') === $value ? 'selected' : '' }}>{{ $value }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label form-label-sm">Language</label>
                        <select name="language" class="form-select form-select-sm">
                            <option value="">Any</option>
                            @foreach (($options['languages'] ?? []) as $value)
                                <option value="{{ $value }}" {{ request('language') === $value ? 'selected' : '' }}>{{ $value }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label form-label-sm">Per Page</label>
                        <select name="per_page" class="form-select form-select-sm">
                            @foreach ([25, 50, 100, 250, 500] as $n)
                                <option value="{{ $n }}" {{ $perPageValue === $n ? 'selected' : '' }}>{{ $n }} / page</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="d-flex justify-content-end gap-2 mt-3">
                    <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                    <a href="{{ route('cmd.reports.marketing_report') }}" class="btn btn-light btn-sm">Reset</a>
                </div>
            </form>

            <div class="d-flex justify-content-center align-items-center mb-2">
                <span class="border p-2 rounded small text-warning">
                    Results: {{ $totalRecords }} {{ $totalRecords === 1 ? 'Record' : 'Records' }}
                </span>
            </div>

            <div class="table-responsive">
                <table class="table table-striped table-bordered align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Drop Name</th>
                            <th>Debt Tier</th>
                            <th>Drop Type</th>
                            <th>Vendor</th>
                            <th>Data Type</th>
                            <th>Mail Style</th>
                            <th>Send Date</th>
                            <th class="text-end">Amount Dropped</th>
                            <th>Mail Invoice #</th>
                            <th class="text-end">Mail Drop Cost</th>
                            <th class="text-end">Per Piece Mail Cost</th>
                            <th>Data Invoice #</th>
                            <th class="text-end">Data Drop Cost</th>
                            <th class="text-end">Per Piece Data Cost</th>
                            <th class="text-end">Total Drop Cost</th>
                            <th class="text-end">Per Piece Total Cost</th>
                            <th class="text-end">Calls</th>
                            <th>Language</th>
                            <th>Drop Name Sequential</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($reports as $report)
                            <tr>
                                <td>{{ $report->Drop_Name }}</td>
                                <td>{{ $report->Debt_Tier }}</td>
                                <td>{{ $report->Drop_Type }}</td>
                                <td>{{ $report->Vendor }}</td>
                                <td>{{ $report->Data_Type }}</td>
                                <td>{{ $report->Mail_Style }}</td>
                                <td>{{ $formatDate($report->Send_Date) }}</td>
                                <td class="text-end">{{ number_format((float) ($report->Amount_Dropped ?? 0)) }}</td>
                                <td>{{ $report->Mail_Invoice_Number }}</td>
                                <td class="text-end">
                                    <div class="mail-drop-cost">
                                        <div class="mail-drop-display">{{ $formatCurrency($report->Mail_Drop_Cost) }}</div>
                                        <button type="button" class="btn btn-link btn-sm p-0 mail-drop-edit-btn" data-value="{{ $report->Mail_Drop_Cost ?? 0 }}">
                                            Edit
                                        </button>
                                        <div class="mail-drop-input d-none">
                                            <form method="post" action="{{ route('cmd.reports.marketing_report.mail.update', $report->PK) }}" class="d-inline">
                                                @csrf
                                                @method('patch')
                                                <input type="number" step="0.01" min="0" name="mail_drop_cost"
                                                    class="form-control form-control-sm mail-drop-input-field"
                                                    value="{{ $report->Mail_Drop_Cost ?? 0 }}" autocomplete="off">
                                            </form>
                                        </div>
                                    </div>
                                </td>
                                <td class="text-end">{{ $formatPerPiece($report->Per_Piece_Mail_Cost) }}</td>
                                <td>{{ $report->Data_Invoice_Number }}</td>
                                <td class="text-end">
                                    <div class="data-drop-cost">
                                        <div class="data-drop-display">{{ $formatCurrency($report->Data_Drop_Cost) }}</div>
                                        <button type="button" class="btn btn-link btn-sm p-0 data-drop-edit-btn" data-value="{{ $report->Data_Drop_Cost ?? 0 }}">
                                            Edit
                                        </button>
                                        <div class="data-drop-input d-none">
                                            <form method="post" action="{{ route('cmd.reports.marketing_report.data.update', $report->PK) }}" class="d-inline">
                                                @csrf
                                                @method('patch')
                                                <input type="number" step="0.01" min="0" name="data_drop_cost"
                                                    class="form-control form-control-sm data-drop-input-field"
                                                    value="{{ $report->Data_Drop_Cost ?? 0 }}" autocomplete="off">
                                            </form>
                                        </div>
                                    </div>
                                </td>
                                <td class="text-end">{{ $formatPerPiece($report->Per_Piece_Data_Cost) }}</td>
                                <td class="text-end">{{ $formatCurrency($report->Total_Drop_Cost) }}</td>
                                <td class="text-end">{{ $formatPerPiece($report->Per_Piece_Total_Cost) }}</td>
                                <td class="text-end">{{ number_format((float) ($report->Calls ?? 0)) }}</td>
                                <td>{{ $report->Language }}</td>
                                <td>{{ $report->Drop_Name_Sequential }}</td>
                                <td class="text-end"></td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="20" class="text-center text-muted">No records found.</td>
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
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('marketing-report-form');
            if (!form) return;

            const fromInput = document.getElementById('from');
            const toInput = document.getElementById('to');
            const rangeInput = document.getElementById('range');
            const exportInput = document.getElementById('export');

            const fmt = (date) => {
                const yyyy = date.getFullYear();
                const mm = String(date.getMonth() + 1).padStart(2, '0');
                const dd = String(date.getDate()).padStart(2, '0');
                return `${yyyy}-${mm}-${dd}`;
            };

            const setDateInputs = (from, to) => {
                if (fromInput) fromInput.value = from;
                if (toInput) toInput.value = to;
            };

            const setActiveRange = (value) => {
                document.querySelectorAll('.range-btn').forEach((btn) => {
                    btn.classList.toggle('active', btn.dataset.range === value);
                });
            };

            document.querySelectorAll('.range-btn').forEach((button) => {
                button.addEventListener('click', () => {
                    const rangeValue = button.dataset.range;
                    const today = new Date();

                    switch (rangeValue) {
                        case 'all':
                            setDateInputs('', '');
                            break;
                        case 'today':
                            setDateInputs(fmt(today), fmt(today));
                            break;
                        case '7':
                        case '30': {
                            const days = parseInt(rangeValue, 10);
                            const start = new Date(today);
                            start.setDate(start.getDate() - (days - 1));
                            setDateInputs(fmt(start), fmt(today));
                            break;
                        }
                        case 'this_month': {
                            const start = new Date(today.getFullYear(), today.getMonth(), 1);
                            const end = new Date(today.getFullYear(), today.getMonth() + 1, 0);
                            setDateInputs(fmt(start), fmt(end));
                            break;
                        }
                        case 'last_month': {
                            const start = new Date(today.getFullYear(), today.getMonth() - 1, 1);
                            const end = new Date(today.getFullYear(), today.getMonth(), 0);
                            setDateInputs(fmt(start), fmt(end));
                            break;
                        }
                        default:
                            setDateInputs('', '');
                            break;
                    }

                    if (rangeInput) rangeInput.value = rangeValue;
                    setActiveRange(rangeValue);
                    if (exportInput) exportInput.value = '';
                    form.submit();
                });
            });

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

            const mailButtons = document.querySelectorAll('.mail-drop-edit-btn');
            mailButtons.forEach((btn) => {
                btn.addEventListener('click', () => {
                    const container = btn.closest('.mail-drop-cost');
                    if (!container) return;
                    container.querySelector('.mail-drop-display')?.classList.add('d-none');
                    btn.classList.add('d-none');
                    const inputWrapper = container.querySelector('.mail-drop-input');
                    const inputField = container.querySelector('.mail-drop-input-field');
                    if (inputWrapper) inputWrapper.classList.remove('d-none');
                    if (inputField) {
                        inputField.value = btn.dataset.value ?? inputField.value ?? '0';
                        inputField.focus();
                        inputField.select();
                    }
                });
            });

            const dataButtons = document.querySelectorAll('.data-drop-edit-btn');
            dataButtons.forEach((btn) => {
                btn.addEventListener('click', () => {
                    const container = btn.closest('.data-drop-cost');
                    if (!container) return;
                    container.querySelector('.data-drop-display')?.classList.add('d-none');
                    btn.classList.add('d-none');
                    const inputWrapper = container.querySelector('.data-drop-input');
                    const inputField = container.querySelector('.data-drop-input-field');
                    if (inputWrapper) inputWrapper.classList.remove('d-none');
                    if (inputField) {
                        inputField.value = btn.dataset.value ?? inputField.value ?? '0';
                        inputField.focus();
                        inputField.select();
                    }
                });
            });

            const autoSubmit = (inputSelector) => {
                document.querySelectorAll(inputSelector).forEach((input) => {
                    const submitForm = () => {
                        const formNode = input.closest('form');
                        if (formNode) formNode.submit();
                    };

                    input.addEventListener('keydown', (event) => {
                        if (event.key === 'Enter') {
                            event.preventDefault();
                            submitForm();
                        }
                    });

                    input.addEventListener('blur', submitForm);
                });
            };

            autoSubmit('.mail-drop-input-field');
            autoSubmit('.data-drop-input-field');
        });
    </script>
@endpush
