@extends('layouts.app')

@section('content')
    <div id="export-overlay" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.85); z-index: 9999; align-items: center; justify-content: center; flex-direction: column; color: white; backdrop-filter: blur(4px);">
        <h3 class="mb-4 fw-light text-white">Exporting Mail Drop Data...</h3>
        <p class="mb-3 text-light" style="font-size: 1.1rem;">Processing <strong id="overlay-phone-count" class="text-warning">0</strong> phone numbers</p>
        <p id="export-status-text" class="mt-2 text-warning fw-semibold">Preparing CSV download...</p>
        <button type="button" id="export-dismiss" class="btn btn-primary mt-4 px-4 py-2 fw-semibold" style="display: none;">
            Return to Dashboard
        </button>
    </div>

    @php
        $formatDate = static fn($value) => $value ? \Carbon\Carbon::parse($value)->format('m/d/Y') : '';
    @endphp

    <div class="card">
        <div class="card-header d-flex align-items-center py-0">
            <h6 class="py-3 mb-0">Mail Drop Export</h6>
        </div>
        <div class="card-body">

            <div class="d-flex justify-content-between align-items-center mb-3">
                <span class="border p-2 rounded small text-warning">
                    Selected Phone Count: <strong id="running-total">0</strong>
                </span>
                <button type="button" id="btn-export" class="btn btn-primary btn-sm" disabled>
                    Export Selected
                </button>
            </div>

            <form method="post" action="{{ route('cmd.reports.mail_drop_export.export') }}" id="export-form" target="download_iframe">
                @csrf

                <iframe id="download_iframe" name="download_iframe" style="display:none;"></iframe>

                <div class="table-responsive">
                    <table class="table table-striped table-bordered align-middle">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 40px;">
                                    <input type="checkbox" id="select-all" class="form-check-input" title="Select all">
                                </th>
                                <th>Drop Name</th>
                                <th>Debt Tier</th>
                                <th class="text-end">Phone Count</th>
                                <th>Send Date</th>
                                <th>Latest Export Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($drops as $drop)
                                <tr>
                                    <td>
                                        <input
                                            type="checkbox"
                                            name="pks[]"
                                            value="{{ $drop->PK }}"
                                            class="form-check-input drop-checkbox"
                                            data-count="{{ (int) ($drop->Amount_Dropped ?? 0) }}"
                                        >
                                    </td>
                                    <td>{{ $drop->Drop_Name }}</td>
                                    <td>{{ $drop->Debt_Tier ?? '' }}</td>
                                    <td class="text-end fw-semibold">{{ number_format((int) ($drop->Amount_Dropped ?? 0)) }}</td>
                                    <td>{{ $formatDate($drop->Send_Date) }}</td>
                                    <td class="latest-export-date">
                                        @if ($drop->Latest_Export_Date)
                                            {{ $formatDate($drop->Latest_Export_Date) }}
                                        @else
                                            <span class="text-muted">&mdash;</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="text-center text-muted">No drops found.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </form>

        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const form       = document.getElementById('export-form');
            const exportBtn  = document.getElementById('btn-export');
            const selectAll  = document.getElementById('select-all');
            const totalEl    = document.getElementById('running-total');
            const checkboxes = document.querySelectorAll('.drop-checkbox');

            function recalculate() {
                let total   = 0;
                let checked = 0;

                checkboxes.forEach(function (cb) {
                    if (cb.checked) {
                        total  += parseInt(cb.dataset.count || '0', 10);
                        checked++;
                    }
                });

                totalEl.textContent = total.toLocaleString();
                exportBtn.disabled  = checked === 0;

                if (checked === 0) {
                    selectAll.checked       = false;
                    selectAll.indeterminate = false;
                } else if (checked === checkboxes.length) {
                    selectAll.checked       = true;
                    selectAll.indeterminate = false;
                } else {
                    selectAll.checked       = false;
                    selectAll.indeterminate = true;
                }
            }

            function updateLatestExportDates(selectedBoxes) {
                const today = new Date().toLocaleDateString('en-US', {
                    month: '2-digit',
                    day: '2-digit',
                    year: 'numeric'
                });

                selectedBoxes.forEach(function (cb) {
                    const row = cb.closest('tr');
                    const dateCell = row ? row.querySelector('.latest-export-date') : null;

                    if (dateCell) {
                        dateCell.textContent = today;
                    }
                });
            }

            checkboxes.forEach(function (cb) {
                cb.addEventListener('change', recalculate);
            });

            selectAll.addEventListener('change', function () {
                checkboxes.forEach(function (cb) {
                    cb.checked = selectAll.checked;
                });
                recalculate();
            });

            exportBtn.addEventListener('click', function () {
                const selectedBoxes = Array.from(checkboxes).filter(function (cb) {
                    return cb.checked;
                });

                if (selectedBoxes.length === 0) {
                    return;
                }

                const overlay = document.getElementById('export-overlay');
                const overlayCount = document.getElementById('overlay-phone-count');
                const statusText = document.getElementById('export-status-text');
                const dismissBtn = document.getElementById('export-dismiss');

                overlay.style.display = 'flex';
                overlayCount.textContent = totalEl.textContent;
                exportBtn.disabled = true;
                statusText.classList.remove('text-success');
                statusText.classList.remove('text-danger');
                statusText.classList.add('text-warning');
                statusText.textContent = 'Preparing CSV download...';
                dismissBtn.style.display = 'none';

                form.submit();

                setTimeout(function () {
                    statusText.innerHTML = "The CSV request has started.<br><small class='text-light mt-2 d-block'>Large files can take a few seconds to appear in the downloads panel.</small>";
                    statusText.classList.remove('text-warning');
                    statusText.classList.add('text-success');
                    updateLatestExportDates(selectedBoxes);
                    exportBtn.disabled = false;
                    dismissBtn.style.display = 'inline-block';
                }, 1500);
            });

            document.getElementById('export-dismiss').addEventListener('click', function () {
                document.getElementById('export-overlay').style.display = 'none';
            });
        });
    </script>
@endpush
