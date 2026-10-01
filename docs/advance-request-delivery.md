# Advance Request delivery

LDR uses GRAPH_TENANT_ID, GRAPH_CLIENT_ID, and GRAPH_CLIENT_SECRET, sending from NGF@libertydebtrelief.com.
Progress Law uses PLAW_MS_TENANT_ID, PLAW_MS_CLIENT_ID, and PLAW_MS_CLIENT_SECRET, sending from NGF@progresslaw.com.
Private tests use the same company-specific credentials and sender as production, with only oduai@libertydebtrelief.com and no CC/BCC.

`--company=PLAW` or `--company=LDR` limits delivery and PDF generation to that company. Both companies still participate in the allocation calculation. Omit the option to send both. Production recipients remain AdvanceRequest rows in TblReports with the matching company.

```sh
php artisan Generate:advance-request --month=2026-09 --company=PLAW --send-to-me
php artisan Generate:advance-request --month=2026-09 --company=PLAW
```

The second command sends live. Do not rerun both companies after a partial success: this command does not suppress duplicate sends. A retry recalculates data and the next tranche; compare the private test amount and tranche with the failed run before sending.
