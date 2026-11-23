# Payroll retention lookback feed

`reports:generate-retention-bonus-commission` publishes the already calculated
`AGENT_DEDUCTION` for each eligible client. It does not change the existing
Lookback formulas or write `TblRetentionAdjustments`.

Feed publication runs only when `--no-azure-write` is absent and the trusted runner
supplies its validated reserved `PAYROLL_COMMISSION_RUN_ID`. An unset ID keeps
ordinary scheduled/manual runs on their existing bonus writer, without any feed
identity queries, table creation, replacement, or completion-marker writes. A
supplied blank or malformed ID fails closed. Before a correlated run writes either
component, it checks duplicate clients, positive amounts, the single matching
`TblEnrollment` assignment and the unique canonical `TblEmployees.PK`. A positive
deduction without a proven employee identity fails the command.

## Data contract

Both tables live in the same Azure SQL database and `dbo` schema as
`TblCommissionReviewResults`. Their additive definitions are created by the
writer on its first enabled write. SQL Server deployment permissions and real
source data still need environment verification.

`TblPayrollRetentionLookbackResults` has a primary key of
`Source, Period_Start, CID` and these columns:

- `Source` is `ldr` or `plaw`
- `Period_Start` is the reporting month first day
- `Run_ID` is the reserved lowercase 32 character hexadecimal command run id
- `CID` is `LLG-` followed by the existing report client ID
- `Agent` is the canonical Sales employee name for positive deductions
- `Employee_PK` is the verified canonical employee PK for positive deductions
- `Agent_Deduction` is the already computed nonnegative amount or NULL
- `Exclusion_Reason` is NULL for a numeric amount including zero

A blank existing `AGENT_DEDUCTION` remains NULL with reason
`default_rate_no_deduction`. This is the existing default commission rate rule
and must not be converted to a charge. Zero is a valid numeric result and can
retain a missing employee identity because it imposes no charge.

`TblPayrollRetentionLookbackRuns` has a primary key of `Source, Period_Start` and
records `Run_ID, Row_Count, Deduction_Row_Count, Exclusion_Row_Count,
Payload_SHA256, Completed_At`. `Deduction_Row_Count` includes all nonnull amounts
including zero. `Exclusion_Row_Count` counts only NULL amount rows. An empty
verified source writes a completed marker with all three counts zero.

Rows sort by CID using byte string order. The SHA256 input is one JSON array per
row in this order: Source, Period_Start, CID, Employee_PK, Agent,
Agent_Deduction as a two decimal string or NULL, Exclusion_Reason. JSON preserves
Unicode and slashes and rows join with a single newline without a trailing
newline. The digest describes data; consumers must separately verify run identity,
counts, uniqueness and amounts.

## Completion and transaction rules

The existing `CommissionResultsWriter` transaction commits the Retention
`Bonus_Commission` component first. The feed then replaces the entire selected
source and reporting period and updates its completion marker in one separate
transaction. A feed failure preserves its previous rows and marker, fails the
command and prevents report delivery. The bonus component may already have
committed when this happens.

Payroll Review must require both successful correlated runner completion and the
exact expected source and period feed markers. A feed marker alone is not enough
to declare the command pipeline successful.

`payroll:commission-run` supplies `PAYROLL_COMMISSION_RUN_ID` to each subprocess.
When source is `both`, LDR and Progress Law publish the same parent run id.
Uncorrelated manual/scheduled report runs do not create an ID or publish this feed.
They retain the existing bonus result replacement and report delivery behavior.

The deduction feed does not depend on the Customer Service aggregate workbook
bundle. Only a runner request explicitly reserved with `capturePayrollBundle: true`
sets `PAYROLL_CAPTURE_CUSTOMER_SERVICE_CONTEXT=1`; the source commands then capture
the six source workbooks and Rama's tranche/payment context. The runner sets `0`
for ordinary Sales refreshes, overriding inherited environment values. Those
refreshes continue publishing the same correlated Lookback feed, without archive
capture or `TblDebtTrancheSales` queries for CS leadership. Manual and scheduled
commands default to no CS capture.

In explicit CS capture, each archived source also freezes its exact per-agent
writer component under `context.commissionResults`. Retention stores Commission,
Lookback stores Bonus_Commission, and NSF stores Commission. The bundle merges
these recorded values for individual employee payroll snapshots, retaining
explicit zeros and leaving absent employees absent. It never rereads the mutable
`TblCommissionReviewResults` table after source completion.

The payment month remains separate from the source period. Payroll Review applies
the verified prior month data to its selected payment month snapshot.

## Employee calculation comparison

Lookback's employee statement uses each account's calculated Retention amount.
The Sales deduction carries its own amount or exclusion reason. A client at the
default commission rate can earn Retention commission while carrying a blank
Sales deduction.

Use a CMD Runner shell configured for approved test data. Start a fresh
two-company query-only capture and compare one captured employee by their
normalized name. For example, in PowerShell:

~~~powershell
$period = '2098-01-01'
$runId = php -r 'echo bin2hex(random_bytes(16));'
$env:PAYROLL_LOOKBACK_QUERY_ONLY = '1'
$env:PAYROLL_CAPTURE_CUSTOMER_SERVICE_CONTEXT = '1'
$env:PAYROLL_COMMISSION_RUN_ID = $runId
php artisan reports:generate-retention-bonus-commission both $period --structured-shadow --no-email
if ($LASTEXITCODE -eq 0) {
    php artisan payroll:compare-lookback-shadow $period $runId 'named retention'
}
~~~

The comparison prints a match flag and timing for each calculation path,
excluding capture and archive access. Use a fresh run ID after a partial
capture. Close the test shell after the comparison to clear the run settings.

For workbook-free replay, pass the two private `artifact.json` records under
`storage/app/payroll-report-artifacts/<period>/<runId>/lookback_<source>/`
to `CustomerServiceCalculationSnapshot::forStructuredLookbackAgent`, keyed
by `lookback_ldr` and `lookback_plaw`. Replay verifies both metadata digests,
the shared invocation identity, and reconciliation to the captured employee
totals. Historical employee statements use the existing workbook reader.
