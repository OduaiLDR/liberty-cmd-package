# LendingUSA status reconciliation after payment changes

The status sync now checks the current Forth status for mapped applications even
when LendingUSA's status matches the saved source checkpoint. Previously a
payment change could restore ordinary `LDR Enrolled`, `PLAW Enrolled`, or
`ProLaw Enrolled` and leave the client there indefinitely.

## Scope and safeguards

- Restore ordinary same-plan Enrolled or a known same-plan LUSA status to the
  latest mapped LendingUSA status. Verify the current contact through Forth first.
- Preserve cancelled/completed stages and statuses, NSF, reconsideration, manual
  review, and existing LUSA-Funded decisions. Unknown statuses, other brands,
  and non-Client stages are not automatically reconciled.
- Leave `Not Interested` and all other unmapped LendingUSA statuses unchanged.
  Rama's business decision on `Not Interested` is still pending.
- A reconciliation updates contact status only. It never replays a payment
  modification, funded registration, pausing, or negotiator assignment.
- Do not checkpoint failed CRM reads/updates, missing contacts, or missing plans.
  They remain eligible for another attempt. Existing checkpoints are retained;
  clients marked Withdrawn by an earlier failed run can still be reconciled.
- Dry run never updates CRM or the source checkpoint, sends report email, or
  persists a refreshed Forth API key. Authentication and operational logging
  still occur.

## Preview before deployment/application

Use the candidate package through the normal installation process. Do not edit
an installed `vendor` directory, delete checkpoint files, or use
`--initialize-state` to make this correction.

First preview a known active mismatch, then a protected case:

```bash
php artisan lending-usa:update-statuses --connection=ldr --contact-id=1023169558 --dry-run --verbose-dry
php artisan lending-usa:update-statuses --connection=ldr --contact-id=1068811039 --dry-run --verbose-dry
```

These are investigation examples, not hard-coded repair targets. Fresh source
and CRM values control the outcome. The first was ordinary LDR Enrolled on the
November 16 audit; the second was Reconsideration Pending and must be preserved.

After the focused previews, preview the complete population:

```bash
php artisan lending-usa:update-statuses --connection=ldr --dry-run --verbose-dry
php artisan lending-usa:update-statuses --connection=plaw --dry-run --verbose-dry
```

`[CRM RECONCILE]` shows the previous and expected CRM status and the result.
`[REVIEW]` preserves a protected/unknown CRM state. `[RETRY]` leaves a failed
verification or missing plan for another attempt. Do not treat every differing
CRM status as an authorized correction.

The sync makes one current CRM contact read per mapped, unblocked application
with an available plan, including unchanged applications. Measure full-preview
duration against the automation timeout before enabling the installed change.
`--contact-id` limits processing, not the application feed retrieval/batch reads.

Production installation, schedule changes, or a write-enabled run require the
operator's approval. A local branch or passing tests are not deployment proof.

## Focused regression coverage

`tests/Unit/UpdateLendingUSAStatusesTest.php` exercises actual command logic with
synthetic contacts, in-memory SQL/API substitutes, and temporary checkpoint files.
It covers all three plan labels, status drift, no-op repeats, protected states,
HTTP failures/malformed responses, retries, funded side effects, unchanged
Not Interested mapping, and credential-refresh behavior in dry run.
