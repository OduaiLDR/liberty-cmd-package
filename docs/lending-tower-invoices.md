# Lending Tower invoice status billing

Progress Law bills $275 per Lending Tower contact in a state classified PLAW, with DEL=false and ISCOAPP=0.
Read the `lt` Snowflake connection, not `plaw`: Jacob's IDs are in LT's source account.
The earliest CONTACTS_STATUS row with one of these exact IDs wins (names retained for reference):

- 293527: Rejected (Partial DS)
- 293531: Rejected (Pitched DS)
- 293533: Rejected (Not Interested DS)
- 293539: Contract Sent
- 293285: Submitted

Rank the full qualifying history by STAMP ascending, then ID ascending, before applying the
inclusive month start / exclusive next-month start. Convert STAMP from TIMESTAMP_TZ to Pacific
wall-clock time. Contact creation date does not affect eligibility. Subsequent qualifying statuses
do not bill the contact again. The duplicate-contact guard also rejects duplicate result rows.
Current status comes from CONTACTS.LEADSTATUS at query time, separately from the billable status.
No current-status, first-name, or duplicate-lead-status exclusion is applied beyond the rules above.

The Progress Law backup includes Contact ID, Client, State, Qualifying Status Date (date only, Pacific),
Billable Status, and Current Status. Both companies use the same workbook formatting, fixed ID
column width, literal text IDs, filters, frozen header rows only (no frozen columns), borders, and hidden gridlines. LDR billing
calculations are unchanged.

## State table and deployment

The package publishes a dedicated migration into the host application's default Laravel database:

```sh
php artisan vendor:publish --tag=lending-tower-migrations
php artisan migrate --path=database/migrations/2026_09_29_000001_create_us_state_classifications_table.php
```

The migration creates `us_state_classifications` with a unique two-letter state_code, state_name,
and classification constrained to LDR, PLAW, Open, Excluded (or NULL pending classification).
All 50 states are inserted; the supplied 26 are PLAW. The other 24 remain NULL until the business
confirms them. NULL is not Open: Open means not currently operating there but possibly in future;
Excluded means the business will not operate there. DC and territories are not US states.

The invoice reads PLAW classifications from this table on every run. Missing tables/read errors,
invalid state codes, or no PLAW states stop generation rather than silently billing zero leads.
This migration is deliberately separate from the package's PMOD migrations. It has not been
applied to a shared environment as part of the local code change.

After deploying the package and applying this migration, verify using:

```sh
php artisan reports:generate-lending-tower-invoices --month=2026-08 --company=PLAW --dry-run
php artisan reports:generate-lending-tower-invoices --month=2026-08 --save=storage/app/lt-review
```

Keep the automation inactive pending review. If a test email is requested, use
`--test-to=oduai@libertydebtrelief.com`. Delivery flags, live recipients, archive markers, and the
live-send gate are unchanged. Do not use `--force` to rebill an already sent month without review.

## Source verification, 29 September 2026

Read-only checks across LT, PLAW, LDR, and CCS confirmed all five IDs and names exist in LT only.
Do not substitute matching titles or other IDs from PLAW's account. An independent run of Jacob's
MIN(CONVERT_TIMEZONE(..., STAMP)) query returned 3,056 leads for August and 2,516 for September
as of September 29, 2026. September is partial until month end. Historical data and state
classification changes can change rerun totals. The original PLAW-source 1,029-lead test is superseded.
The existing command still defaults to the previous calendar month in America/Los_Angeles.

Run focused tests from the sibling cmd-runner checkout:

```sh
php vendor/bin/phpunit --bootstrap ../liberty-cmd-package/tests/bootstrap-workspace.php --no-configuration ../liberty-cmd-package/tests/Unit/GenerateLendingTowerInvoicesTest.php
```
