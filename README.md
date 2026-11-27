# cmd-reports

## NSF Team Leader comparison

The comparison command calculates the NSF Team Leader report from a trusted
two-company structured export for one run. It uses the same payroll rules as
the workbook-backed report. The export records the frozen roster, eligibility,
and readiness from each company. The calculation reads the export and prints
the result as JSON for comparison.

The query-only shadow must capture LDR and PLAW together using `both`.
The exporter checks the shared invocation ID. If a capture predates that ID
or only one company was saved, run a fresh two-company shadow with a new
reserved run ID. Use Bash in CMD Runner so the command pipe preserves UTF-8.

```bash
set -o pipefail
php artisan payroll:export-nsf-shadow "$PERIOD" "$RUN_ID" --with-invocation-proof |
  php artisan payroll:read-nsf-team-leader-shadow "$PERIOD" "$RUN_ID"
```

On Windows PowerShell 5.1, run the pipe through `cmd.exe` to preserve the
original bytes. Replace the example month and run ID with the capture identity.

```powershell
cmd.exe /d /c 'php artisan payroll:export-nsf-shadow 2098-01-01 aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa --with-invocation-proof | php artisan payroll:read-nsf-team-leader-shadow 2098-01-01 aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'
```

The export without `--with-invocation-proof` uses the App's database import format.
Both formats verify the source workbooks and shared invocation. Once
exported, the calculation can run from the captured rows alone. The command
accepts exports up to 8 MiB. The JSON result carries employee details and is
for comparison only.

## Regular Retention leadership comparison

Run the two-company shadow from CMD Runner with the updated package installed.
Use an approved test data connection, an explicit reporting month, and a fresh
32-character lowercase hexadecimal run ID. For example, in PowerShell:

```powershell
$period = '2026-10-01'
$runId = php -r 'echo bin2hex(random_bytes(16));'
$previousCapture = $env:PAYROLL_CAPTURE_CUSTOMER_SERVICE_CONTEXT
$previousRunId = $env:PAYROLL_COMMISSION_RUN_ID
try {
    $env:PAYROLL_CAPTURE_CUSTOMER_SERVICE_CONTEXT = '1'
    $env:PAYROLL_COMMISSION_RUN_ID = $runId
    php artisan reports:retention-commission both $period --structured-shadow --no-azure-write --no-email
    if ($LASTEXITCODE -eq 0) {
        php artisan payroll:compare-retention-leadership-shadow $period $runId
    }
} finally {
    $env:PAYROLL_CAPTURE_CUSTOMER_SERVICE_CONTEXT = $previousCapture
    $env:PAYROLL_COMMISSION_RUN_ID = $previousRunId
}
```

The preview stores both source workbooks and their structured evidence under
`storage/app/payroll-retention-shadow` for that month and run. This directory
contains payroll data and belongs in private storage. If capture fails partway,
use a fresh run ID for the retry. The comparison requires both companies from
the same invocation and compares the complete Rama and Nick Payroll Review
results. It prints match flags and calculation timings, with a nonzero exit
code for missing evidence or any difference. Timings exclude report generation
and archive loading.

The structured calculation uses the full cancel-request cohort and frozen
tranche evidence. `buildPayrollReviewRetentionStructuredSnapshot` can replay
the two saved `artifact.json` records keyed by `retention_ldr` and
`retention_plaw` after the workbooks are removed. Use metadata from the trusted
private archive. Historical payroll uses the existing workbook reader.
