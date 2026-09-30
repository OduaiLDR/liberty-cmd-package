# Paramount invoices

The existing `Generate:paramount-epf-summary` command retains its EPF calculation and Excel backup. Add `--invoice` to generate the Liberty-branded PDF. Payment terms are Upon Receipt.

```sh
php artisan Generate:paramount-epf-summary --month=2026-08 --invoice
php artisan Generate:paramount-epf-summary --month=2026-08 --invoice --send-to-me
```

The first command saves only. `--send-to-me` sends only to oduai@libertydebtrelief.com with empty CC/BCC. `--test-to=ADDRESS` permits an explicit reviewer; conflicting reviewer options are rejected. Lending Tower's `reports:generate-lending-tower-invoices` also supports `--send-to-me` and produces separate Progress Law and LDR emails.

Paramount live delivery requires `--send`. It reads `dbo.TblReports` using Report_Name `LT Invoices ParamountLaw` and Company `LDR`, without extra environment recipients or cross-company fallback. Apply `paramount-recipients.sql` to configure the approved recipients; this script is not automatically executed. Sender: invoices@libertydebtrelief.com. The configured Graph application must have mailbox send permission.

Paramount's billing contact defaults to Evan McMurtrey at emcmurtrey@Higbee.law, 1504 Brookhollow Drive Suite 112, Santa Ana, CA 92705. Optional overrides are `PARAMOUNT_INVOICE_BILL_TO_NAME` and `PARAMOUNT_INVOICE_BILL_TO_ADDRESS` (pipe-separated lines).

Invoice live delivery blocks before month end and prevents a second send using a month-specific lock and sent marker. Default month is the previous calendar month in America/Los_Angeles. Local generation and private review do not create a live-send marker. Removing review notes from the PDF does not remove these checks.

Validate from the sibling cmd-runner checkout:

```sh
php vendor/bin/phpunit --bootstrap ../liberty-cmd-package/tests/bootstrap-workspace.php --no-configuration ../liberty-cmd-package/tests/Unit/ParamountInvoiceTest.php ../liberty-cmd-package/tests/Unit/GenerateLendingTowerInvoicesTest.php
```

Publishing this branch does not deploy the package, apply the state migration or recipient SQL, or activate production automation.
