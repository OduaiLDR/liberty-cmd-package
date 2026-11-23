# cmd-reports

## NSF Team Leader comparison

The comparison command calculates the NSF Team Leader report from a trusted
two-company structured export for one run. It uses the same payroll rules as
the workbook-backed report. The export records the frozen roster, eligibility,
and readiness from each company. The calculation reads the export and prints
the result as JSON for comparison.

For an existing query-only shadow capture, run this from CMD Runner with the
updated package installed. Use Bash so the command pipe preserves UTF-8.

```bash
set -o pipefail
php artisan payroll:export-nsf-shadow "$PERIOD" "$RUN_ID" |
  php artisan payroll:read-nsf-team-leader-shadow "$PERIOD" "$RUN_ID"
```

On Windows PowerShell 5.1, run the pipe through `cmd.exe` to preserve the
original bytes. Replace the example month and run ID with the capture identity.

```powershell
cmd.exe /d /c 'php artisan payroll:export-nsf-shadow 2098-01-01 aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa | php artisan payroll:read-nsf-team-leader-shadow 2098-01-01 aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'
```

The exporter verifies the source workbooks when it creates the export. Once
exported, the calculation can run from the captured rows alone. The command
accepts exports up to 8 MiB. The JSON result carries employee details and is
for comparison only.
