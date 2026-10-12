@extends('layouts.app')

@section('content')
<div class="card">
    <div class="card-header"><h6 class="mb-0">Mail Drop Export · SMS</h6></div>
    <div class="card-body">
        <p>Choose a phone target. Whole drops are selected in order: fewest SMS exports first, newest unused drops first, then oldest last export. Synced contact phone numbers are excluded. After crossing the target, the next 30 drops are checked for a smaller qualifying final drop at the same SMS-use level. The original final drop is kept if none improves the fit. Browsing shows drops immediately; eligible counts appear after selecting a target.</p>
        <p>Exports download as one CSV containing all eligible records.</p>
        @if ($errors->any())
            <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
        @endif
        <form method="get" action="{{ route('cmd.reports.mail_drop_export') }}" class="d-flex flex-wrap gap-2 align-items-end mb-3">
            <div>
                <label for="sms-target" class="form-label">SMS phone target</label>
                <input id="sms-target" name="target" type="number" min="1" max="9007199254740991" step="1" required value="{{ $target ?: '' }}" class="form-control">
            </div>
            <button class="btn btn-primary" type="submit">Select drops</button>
            <a class="btn btn-light" href="{{ route('cmd.reports.mail_drop_export') }}">Browse drops</a>
        </form>
        @if ($target > 0)
            <p><strong>{{ number_format($total) }}</strong> eligible phones across <strong>{{ $drops->count() }}</strong> whole drops. Target: {{ number_format($target) }}.</p>
            @if ($total < $target)
                <div class="alert alert-warning">There are not enough eligible phones to reach this target. Enter a smaller target.</div>
            @else
                <form id="sms-export-form" method="post" action="{{ route('cmd.reports.mail_drop_export.export') }}" class="mb-3">
                    @csrf
                    <input type="hidden" name="target" value="{{ $target }}">
                    <input type="hidden" name="request_id" value="{{ $requestId }}">
                    <button class="btn btn-primary" id="sms-export-button" type="submit">Export {{ number_format($total) }} phones</button>
                    <span id="sms-export-status" class="ms-2" role="status" aria-live="polite"></span>
                </form>
                <p class="small text-muted">Eligibility is checked again when exporting. Counts may change if new contact numbers have synced.</p>
            @endif
        @endif
        <div class="table-responsive">
            <table class="table table-striped table-bordered align-middle">
                <thead><tr><th>Source drop</th><th>Debt tier</th><th>Eligible phones</th><th>Send date</th><th>SMS exports</th><th>Last used / send date</th></tr></thead>
                <tbody>
                @forelse ($drops as $drop)
                    <tr>
                        <td>{{ $drop->Drop_Name }}</td><td>{{ $drop->Debt_Tier }}</td>
                        <td>{{ $drop->Amount_Dropped === null ? '—' : number_format((int) $drop->Amount_Dropped) }}</td>
                        <td>{{ $drop->Send_Date }}</td><td>{{ $drop->SMS_Drops }}</td>
                        <td>{{ $drop->SMS_Last_Export_Date ?: $drop->Send_Date }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6">No drops found.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if ($target === 0)
            <nav aria-label="Drop pages" class="d-flex gap-2">
                @if ($page > 1)<a class="btn btn-light" href="{{ route('cmd.reports.mail_drop_export', ['page' => $page - 1]) }}">Previous</a>@endif
                @if ($drops->count() === 25)<a class="btn btn-light" href="{{ route('cmd.reports.mail_drop_export', ['page' => $page + 1]) }}">Next</a>@endif
            </nav>
        @endif
    </div>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('sms-export-form');
    if (!form) return;
    form.addEventListener('submit', async function (event) {
        event.preventDefault();
        const button = document.getElementById('sms-export-button');
        const status = document.getElementById('sms-export-status');
        button.disabled = true;
        status.textContent = 'Building export…';
        try {
            const response = await fetch(form.action, {
                method: 'POST', body: new FormData(form), headers: {'Accept': 'text/csv, application/zip, application/json'}
            });
            if (!response.ok) {
                const data = await response.json().catch(() => ({}));
                throw new Error(Object.values(data.errors || {}).flat()[0] || 'Export failed. Refresh and try again.');
            }
            const contentType = (response.headers.get('Content-Type') || '').toLowerCase();
            const zip = contentType.includes('application/zip');
            if (!zip && !contentType.includes('text/csv')) {
                throw new Error('Your session may have expired. Refresh and sign in again.');
            }
            const blob = await response.blob();
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = zip ? 'sms_export.zip' : 'sms_export.csv';
            document.body.appendChild(link);
            link.click();
            link.remove();
            setTimeout(() => URL.revokeObjectURL(url), 60000);
            status.textContent = 'Export recorded and download ready. Refresh to select the next drops.';
        } catch (error) {
            status.textContent = error.message;
            button.disabled = false;
        }
    });
});
</script>
@endpush
