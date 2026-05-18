@if(empty($rows))
  <div class="card-body text-muted" style="font-size:13px;">No records found for the selected filters.</div>
@else
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-sm table-striped table-hover align-middle mb-0" style="font-size:12px;">
        <thead class="table-dark">
          <tr>
            @foreach($columns as $col)
              <th class="text-nowrap" style="font-size:11px;">{{ $col }}</th>
            @endforeach
          </tr>
        </thead>
        <tbody>
          @foreach($rows as $r)
            <tr>
              @foreach($columns as $col)
                @php
                  $val = $r->{$col} ?? null;
                  $out = $val;
                  if ($val !== null) {
                    $currencyCols = ['Drop Cost','CPA','Price Per Drop','Cost Per Call','Est Revenue','Est Profit','Cost Per Lead','Revenue Per Lead'];
                    if (in_array($col, $currencyCols, true))                             { $out = '$'.number_format((float) $val, 2); }
                    elseif (in_array($col, ['Amount Dropped','Amount Per Rep'], true))   { $out = number_format((float) $val, 0); }
                    elseif (in_array($col, ['Enrolled Debt','Average Debt'], true))      { $out = '$'.number_format((float) $val, 0); }
                    elseif ($col === 'Lead Rate')                                         { $out = number_format((float) $val * 100, 4).'%'; }
                    elseif (in_array($col, ['Conversion Rate %','Retention Rate %'], true)) { $out = number_format((float) $val, 2).'%'; }
                    elseif ($col === 'ROI Ratio')                                        { $out = number_format((float) $val, 2).'x'; }
                    elseif (in_array($col, ['Veritas Enrollment','Veritas Monthly'], true)) { $out = '$'.number_format((float) $val, 2); }
                    elseif ($col === 'Tier')                                             { $out = preg_replace('/\..*$/', '', (string) $val); }
                  }
                @endphp
                <td class="{{ $col === 'Mail Style' ? 'text-nowrap' : '' }}">{{ $out }}</td>
              @endforeach
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  </div>

  @php
    $lastPage = max(1, (int) ceil(($total ?? 0) / ($perPage ?? 15)));
    $current  = $page ?? 1;
    $start    = max(1, $current - 2);
    $end      = min($lastPage, $current + 2);
    if ($end - $start < 4) { $start = max(1, $end - 4); }
  @endphp
  <div class="card-body border-top py-2">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
      <div class="text-muted" style="font-size:12px;">Page {{ $current }} of {{ $lastPage }} — {{ number_format($total) }} records</div>
      <nav>
        <ul class="pagination pagination-sm mb-0 mar-pagination">
          <li class="page-item {{ $current <= 1 ? 'disabled' : '' }}">
            <a class="page-link" href="#" data-page="1">«</a>
          </li>
          <li class="page-item {{ $current <= 1 ? 'disabled' : '' }}">
            <a class="page-link" href="#" data-page="{{ max(1, $current - 1) }}">‹</a>
          </li>
          @for($p = $start; $p <= $end; $p++)
            <li class="page-item {{ $p == $current ? 'active' : '' }}">
              <a class="page-link" href="#" data-page="{{ $p }}">{{ $p }}</a>
            </li>
          @endfor
          <li class="page-item {{ $current >= $lastPage ? 'disabled' : '' }}">
            <a class="page-link" href="#" data-page="{{ min($lastPage, $current + 1) }}">›</a>
          </li>
          <li class="page-item {{ $current >= $lastPage ? 'disabled' : '' }}">
            <a class="page-link" href="#" data-page="{{ $lastPage }}">»</a>
          </li>
        </ul>
      </nav>
    </div>
  </div>
@endif
