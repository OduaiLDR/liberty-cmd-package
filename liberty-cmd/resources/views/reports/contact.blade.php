@use('Illuminate\Support\Str')

@extends('layouts.app')

@section('content')
    <div class="card">
        <div class="card-header d-flex align-items-center py-0">
            <h6 class="py-3 mb-0">Contact Report</h6>
        </div>
        <div class="card-body">
            <form method="get" action="{{ route('cmd.reports.contact_report') }}" class="bg-light rounded-3 p-2 p-md-3 mb-3" id="contact-report-form">
                <input type="hidden" name="range" id="range" value="{{ request('range', $range) }}">
                <input type="hidden" name="export" id="export" value="">

                <div class="row g-2 align-items-center">
                    <div class="col-auto fw-semibold">
                        Contacts For :
                    </div>
                    <div class="col d-flex justify-content-between flex-wrap gap-2">
                        <div class="btn-group btn-group-sm gap-1" role="group" aria-label="Quick ranges">
                            @foreach (['all' => 'All', 'today' => 'Today', '7' => '7d', '30' => '30d', 'this_month' => 'This Month', 'last_month' => 'Last Month'] as $value => $label)
                                <button type="button" class="btn btn-outline-primary range-btn {{ (request('range', $range) ?? 'all') === $value ? 'active' : '' }}" data-range="{{ $value }}">
                                    {{ $label }}
                                </button>
                            @endforeach
                        </div>
                        <button type="button" id="btn-export" class="btn btn-secondary btn-sm">Export CSV</button>
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

                <details class="mt-2 mb-2">
                    <summary class="small fw-semibold">Advanced Filters</summary>
                    <div class="row g-2 mt-2">
                        <div class="col-md-2">
                            <label class="form-label form-label-sm">Agent (contains)</label>
                            <input type="text" name="agent" value="{{ request('agent', $filters['agent'] ?? '') }}" list="agents-list" class="form-control form-control-sm" autocomplete="off">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label form-label-sm">Client (contains)</label>
                            <input type="text" name="client" value="{{ request('client', $filters['client'] ?? '') }}" list="clients-list" class="form-control form-control-sm" autocomplete="off">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label form-label-sm">Data Source (contains)</label>
                            <input type="text" name="data_source" value="{{ request('data_source', $filters['data_source'] ?? '') }}" list="data-sources-list" class="form-control form-control-sm" autocomplete="off">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label form-label-sm">Stage (exact)</label>
                            <select name="stage" class="form-select form-select-sm">
                                <option value="">Any</option>
                                @foreach (($opts['stages'] ?? []) as $value)
                                    <option value="{{ $value }}" {{ request('stage', $filters['stage'] ?? '') === $value ? 'selected' : '' }}>{{ $value }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label form-label-sm">Status (exact)</label>
                            <select name="status" class="form-select form-select-sm">
                                <option value="">Any</option>
                                @foreach (($opts['statuses'] ?? []) as $value)
                                    <option value="{{ $value }}" {{ request('status', $filters['status'] ?? '') === $value ? 'selected' : '' }}>{{ $value }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label form-label-sm">State (exact)</label>
                            <select name="state" class="form-select form-select-sm">
                                <option value="">Any</option>
                                @foreach (($opts['states'] ?? []) as $value)
                                    <option value="{{ $value }}" {{ request('state', $filters['state'] ?? '') === $value ? 'selected' : '' }}>{{ $value }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="row g-2 mt-2">
                        <div class="col-md-2">
                            <label class="form-label form-label-sm">Debt Min</label>
                            <input type="number" name="debt_min" value="{{ request('debt_min', $filters['debt_min'] ?? '') }}" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label form-label-sm">Debt Max</label>
                            <input type="number" name="debt_max" value="{{ request('debt_max', $filters['debt_max'] ?? '') }}" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label form-label-sm">Score Min</label>
                            <input type="number" name="score_min" value="{{ request('score_min', $filters['score_min'] ?? '') }}" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label form-label-sm">Score Max</label>
                            <input type="number" name="score_max" value="{{ request('score_max', $filters['score_max'] ?? '') }}" class="form-control form-control-sm">
                        </div>
                    </div>
                </details>

                <datalist id="agents-list">
                    @foreach (($opts['agents'] ?? []) as $value)
                        <option value="{{ $value }}"></option>
                    @endforeach
                </datalist>
                <datalist id="clients-list">
                    @foreach (($opts['clients'] ?? []) as $value)
                        <option value="{{ $value }}"></option>
                    @endforeach
                </datalist>
                <datalist id="data-sources-list">
                    @foreach (($opts['data_sources'] ?? []) as $value)
                        <option value="{{ $value }}"></option>
                    @endforeach
                </datalist>
                <datalist id="negotiators-list">
                    @foreach (($opts['negotiators'] ?? []) as $value)
                        <option value="{{ $value }}"></option>
                    @endforeach
                </datalist>

                <div class="d-flex gap-2 justify-content-end mt-2">
                    <button type="submit" id="btn-filter" class="btn btn-primary btn-sm">Filter</button>
                    <a href="{{ route('cmd.reports.contact_report') }}" class="btn btn-light btn-sm">Reset</a>
                    <div>
                        <select name="per_page" class="form-select form-select-sm">
                            @foreach ([25, 50, 100, 250, 500] as $n)
                                <option value="{{ $n }}" {{ (int) request('per_page', $perPage ?? 25) === $n ? 'selected' : '' }}>{{ $n }} / page</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </form>

            @php
                $totalRecords = method_exists($reports, 'total') ? $reports->total() : count($reports);
                $formatCurrency = static fn($value) => $value ? '$' . number_format(format_currency_string($value), 2) : '';
                $formatDate = static fn($value) => $value ? \Carbon\Carbon::parse($value)->format('m/d/Y') : '';
            @endphp

*** End Patch
