@if(!$summaryAggregates)
  <div class="card-body text-center text-muted py-4" style="font-size:13px;">No data for the selected filters</div>
@else
  @php $s = $summaryAggregates; @endphp
  <div class="card-body p-0" style="overflow-y:auto;max-height:70vh;">
    <table class="table table-sm mar-summary-table mb-0">
      <tbody>
        <tr><td>Amount Dropped</td><td>{{ number_format($s->amount_dropped) }}</td></tr>
        <tr><td>Average Reps</td><td>{{ number_format($s->avg_reps) }}</td></tr>
        <tr><td>Amount Per Rep</td><td>{{ number_format($s->amount_per_rep) }}</td></tr>
        <tr><td>Cost Per Drop</td><td>${{ number_format($s->cost_per_drop, 2) }}</td></tr>
        <tr class="row-lead row-div"><td><strong>Total Leads</strong></td><td><strong>{{ number_format($s->total_leads) }}</strong></td></tr>
        <tr><td>Qualified Leads</td><td>{{ number_format($s->qualified_leads) }}</td></tr>
        <tr><td>Unqualified Leads</td><td>{{ number_format($s->unqualified_leads) }}</td></tr>
        <tr><td>Assigned Leads</td><td>{{ number_format($s->assigned_leads) }}</td></tr>
        <tr><td>Qualified Rate</td><td>{{ number_format($s->qualified_leads_rate, 2) }}%</td></tr>
        <tr><td>Unqualified Rate</td><td>{{ number_format($s->unqualified_leads_rate, 2) }}%</td></tr>
        <tr><td>Assigned Rate</td><td>{{ number_format($s->assigned_leads_rate, 2) }}%</td></tr>
        <tr class="row-div"><td>Calls Per Rep</td><td>{{ number_format($s->calls_per_rep, 2) }}</td></tr>
        <tr><td>Cost Per Call</td><td>${{ number_format($s->cost_per_call, 2) }}</td></tr>
        <tr><td>CPA</td><td>${{ number_format($s->cpa, 0) }}</td></tr>
        <tr><td>Response Rate</td><td>{{ number_format($s->response_rate, 4) }}%</td></tr>
        <tr><td>Drop Costs</td><td>${{ number_format($s->drop_costs, 0) }}</td></tr>
        <tr><td>Active Deals</td><td>{{ number_format($s->active_deals) }}</td></tr>
        <tr><td>Conversion Rate</td><td>{{ number_format($s->conversion_rate, 2) }}%</td></tr>
        <tr class="row-div"><td>Total Debt Enrolled</td><td>${{ number_format($s->total_debt_enrolled, 0) }}</td></tr>
        <tr><td>Average Debt</td><td>${{ number_format($s->average_debt, 0) }}</td></tr>
        <tr><td>Debt Buyer 8%</td><td>${{ number_format($s->debt_buyer_8pct, 2) }}</td></tr>
        <tr><td>Veritas Enroll Fees</td><td>${{ number_format($s->veritas_enrollment_fees, 0) }}</td></tr>
        <tr><td>Veritas Monthly Fees</td><td>${{ number_format($s->veritas_monthly_fees, 0) }}</td></tr>
        <tr><td>Total Gross Revenue</td><td>${{ number_format($s->total_gross_revenue, 0) }}</td></tr>
        <tr class="row-div"><td>Cancels</td><td>{{ number_format($s->cancels) }}</td></tr>
        <tr><td>NSFs</td><td>{{ number_format($s->nsfs) }}</td></tr>
        <tr><td>Retention Ratio</td><td>{{ number_format($s->retention_ratio, 2) }}%</td></tr>
        <tr class="row-roi row-div">
          <td class="fw-semibold">ROI</td>
          <td class="fw-bold {{ $s->roi >= 0 ? 'text-success' : 'text-danger' }}">${{ number_format($s->roi, 0) }}</td>
        </tr>
        <tr><td>PPROI</td><td>${{ number_format($s->pproi, 4) }}</td></tr>
      </tbody>
    </table>
  </div>
@endif
