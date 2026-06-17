@extends('layouts.app')

@section('content')
    <style>
        .lb-head th { background: #1f2a36 !important; color: #fff !important; border-color: #2c3e50 !important; }
        .card { border-radius: .85rem; }
        .card > .card-header:first-child { border-radius: .85rem .85rem 0 0; }
        .table-responsive { border: 1px solid var(--bs-border-color); border-radius: .65rem; overflow: hidden; }
        .table-responsive > .table { margin-bottom: 0; }
    </style>

    <div class="card">
        <div class="card-header d-flex align-items-center py-0">
            <h6 class="py-3 mb-0">Total Records <span class="small text-muted fw-normal ms-1">All-time standings</span></h6>
            <a href="{{ route('cmd.reports.leaderboard_report') }}" class="ms-auto btn btn-outline-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to Leaderboard
            </a>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped table-hover align-middle">
                    <thead class="lb-head">
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
                                <td class="text-nowrap">{{ $row->agent }}</td>
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
@endsection
