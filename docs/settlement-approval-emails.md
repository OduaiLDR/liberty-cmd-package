# Settlement approval email automation

`settlement-approval:process` is a separate package command for CMD Runner. It replaces only the
email-driven `ProcessSettlementApproval` Selenium routine from `CMD LDR (3).xlsm` and
`CMD PLAW (2).xlsm`. It does not call or change the portal approval API, its date-based Path A/Path B
rules, or the separate outbound `ProcessSettlementAuthorization` routine.

## Preserved business flow

- Read `automation@libertydebtrelief.com` Inbox and select subjects starting with
  `Settlement Approval Data:`. LDR handles `no-reply@libertydebtrelief.com` and
  `no-reply@paramountlaw.com`; the PLAW route handles `no-reply@progresslaw.com`.
- Parse customer ID, settlement ID, creditor name and last four account digits.
- Require current offer status `1194`. Payment types `15`, `16`, `132` become **Accepted**;
  every other valid payment type becomes **Client complete/ P-Creditor**.
- Update the status, verify it by reading the offer back, and add the same authorization-note text.
- Send **Client Approved Settlement**, or **Client Approved Settlement - Skipped** for an
  ineligible offer, from `admin@libertydebtrelief.com`. The body is the original email text,
  HTML-escaped for display. Recipients come from `TblReports` under **Settlement Approval** or
  **Client Approved Settlement**, using the shared recipient convention without environment extras.
- Mark completed/skipped messages read and move them to the mailbox's top-level **Archive**.
  This is intentionally not the existing referral automation's `Inbox/Archive` folder.

The replacement reads the current offer from Forth instead of waiting for its Snowflake mirror.
It additionally validates the offer ID and customer association. Missing offers remain retryable.
Malformed emails and conflicting approval identities are left in Inbox and fail the run visibly.
Unrelated messages are untouched; the broader VBA reader's cleanup of unrelated mail is not migrated.
No date-based acceptance rule is introduced. The VBA's unreachable Offer Expired notification is not reproduced.

## Safe defaults and preview

The default is preview. It can read mailbox messages, Forth offers and existing `TblAPIKeys`, but
does not write processing rows, update CRM, send email, mark messages read or move them.
Preview evaluates the offer and reports an intended status label. It does not validate the status
catalog, note settings, notification recipients, ledger migration or delivery permissions.
An exit code of zero alone is not cutover evidence: inspect each outcome, including missing offers.

```sh
php artisan settlement-approval:process --dry-run --company=ldr --limit=10
php artisan settlement-approval:process --dry-run --company=plaw --message-id=IMMUTABLE_MESSAGE_ID
```

An offline preview needs no mailbox, Forth or database connections:

```sh
php artisan settlement-approval:process --file=approval.txt --sender=no-reply@libertydebtrelief.com --offer-file=offer.json
```

Example synthetic `approval.txt` (no trailing newline required):

```text
Settlement Approval For Customer Id: LLG-123
Creditor Name: Example Bank
Settlement ID: 456
Last 4 Account Number: 0012
```

Example `offer.json`:

```json
{"id":"456","contact_id":"123","offer_status":"1194","json":{"pay_type":"15"}}
```

Saved-file input is rejected in live mode. `--company=all` covers both email routes; `--limit`
is bounded to 1–500 matching messages. Pagination continues past other-brand mail. There is also a
10,000-message scan bound. Failed/malformed messages are not silently removed to clear a backlog;
operators must investigate recurring failures. `--message-id` selects an exact matching Inbox
message for a controlled test. `--keep-in-inbox` suppresses mark/archive, but in live mode still
allows CRM writes and notifications. It is not a substitute for preview.

## Deployment configuration (not performed by this change)

1. Publish the dedicated migration using the package's existing opt-in approach:
   `php artisan vendor:publish --tag=settlement-approval-email-migrations`.
   Review/apply only the resulting migration in CMD Runner's persistent application database.
   Do not use a transient, host-local or periodically cleared database for this ledger.
2. Configure the existing `GRAPH_TENANT_ID`, `GRAPH_CLIENT_ID`, `GRAPH_CLIENT_SECRET` registration
   for mailbox access. Reading bodies and moving/marking emails need appropriate mailbox access,
   including `Mail.ReadWrite`; outgoing notifications need `Mail.Send` access for the admin sender.
   Existing send-only credentials do not establish inbox access. The command does not create grants.
3. Verify exactly one current `TblAPIKeys` row for each needed category, `LDR` and `PLAW`, in the
   existing `DBConnector::fromEnvironment('plaw')` SQL Server source. Verify each key belongs to
   the intended CRM account before testing; the historical PLAW email route means **Progress Law**.
   This command reads those keys and never refreshes, prints or rotates them.
4. Verify Forth's status catalog returns one unique numeric ID for each exact target label in each
   account. IDs are resolved from `GET /settlement_offer_status`, never guessed from portal rules.
5. Set `SETTLEMENT_APPROVAL_EMAIL_NOTE_TYPE_LDR` and `_PLAW` to verified positive note-type IDs.
   Explicitly set `SETTLEMENT_APPROVAL_EMAIL_NOTE_PUBLIC_LDR` and `_PLAW` to `true` or `false` after
   confirming the legacy note visibility. Missing/invalid settings block a live status update.
6. Configure the `TblReports` recipient row(s). The VBA's recipients were Jennifer, Rama, Lana,
   Jace and Adrian at libertydebtrelief.com; this change does not insert recipient rows or hardcode
   those recipients into the worker. Preview and approve any connected configuration writes separately.
7. Keep `SETTLEMENT_APPROVAL_EMAIL_LIVE_ENABLED=false` until controlled validation/cutover.
   Both that switch and `--live` are required for writes. `--live --dry-run` is rejected.

The ledger migration is published separately and is not automatically run or loaded with PMOD migrations.
No schedule is created or enabled by installing this package change. Scheduling belongs in CMD Runner.

## Controlled validation and cutover

Before replacing Selenium, verify API effects on approved test offers in both accounts and both
payment-type branches. In particular, confirm that Forth's status API produces the same settlement/
payment completion effects as the old UI's Save + Ok, and that contact-note visibility is correct.
Mock tests cannot prove those live effects. Also verify actual notification acceptance/delivery and
that the source email reaches top-level Archive.

Coordinate stopping only the `Settlement Approval Data:*` dispatch in the old email reader before
enabling writes in the new one. The other VBA email/report tasks remain outside this cutover. Stop
any already-running old handler and define a mailbox cutoff/backlog plan: the new ledger cannot
know which emails an older worker completed but failed to archive. Inspect those against CRM history.

For a controlled live run after explicit activation/configuration:

```sh
php artisan settlement-approval:process --live --company=ldr --message-id=IMMUTABLE_MESSAGE_ID
```

Then configure this same command through CMD Runner's automation scheduler. Match the intended
legacy operating window (the workbook checks machine hours 06 through 22) only after confirming
that remote machine's timezone. Do not copy its faulty half-hour timestamp calculation.

## Processing and recovery

The ledger deduplicates by company + settlement, including repeated messages with different Graph
IDs. Conflicting customer/creditor/account details for the same settlement require review. Receipts
store a hash of the message identity, not the whole email body. A shared database lease excludes
concurrent workers and fences stale writers.

The stages are `ready`, `status_attempting`, `status_updated`, `note_attempting`,
`notification_ready`, `mail_attempting`, and terminal `completed`, `skipped`, or `needs_review`.
Before each external side effect the intent is saved. After a crash in `status_attempting`, the
worker only reads back the status: a matching target can resume, while another status goes to review
without a second PUT. A later change before note creation also goes to review.

An interrupted note or email send has an uncertain outcome. It is never automatically repeated:
the job becomes `needs_review`, the message stays in Inbox, and the command returns failure. An
operator must inspect the CRM note/history or mail-provider message trace and recipient evidence
before approving a precise ledger correction/retry. The shared sender uses `saveToSentItems=false`,
so absence from Sent Items does not establish that no notification was sent.
A generic reset of completed/reviewed rows is unsafe. Similarly,
new authorization for an already-processed settlement requires a deliberate review, not automatic replay.

Failure to archive a completed job is retryable: the next run attempts mailbox completion without
repeating the Forth update, note or notification. There is no claim of exactly-once delivery across
independent CRM, database and mail systems.

## Offline verification

Run from the sibling `cmd-runner` checkout with its installed PHP dependencies:

```sh
php vendor/bin/phpunit --bootstrap ../liberty-cmd-package/tests/bootstrap-workspace.php --no-configuration ../liberty-cmd-package/tests/Unit/SettlementApprovalEmail
```

These tests use mocked HTTP/mailbox responses and in-memory SQLite. They cover legacy branch parity,
input validation, duplicate/competing workers, uncertainty/recovery, migration/lease behavior,
Graph paging and archive placement, preview isolation and the explicit live gate. They do not prove
production Graph grants, credentials, account-specific Forth response shapes or downstream effects.
The assembled command tests also exercise the real Graph, Forth, ledger and notification adapters
with closed HTTP transport and a substituted SQL lookup boundary. They cover both companies and
payment branches, recipient validation, actual notification formatting, duplicate emails,
archive recovery and uncertain-send handling. Command registration in the deployed Runner and
its scheduler still require target-environment validation.

Forth contracts used: [offer retrieval](https://developer.setforth.com/api/forth-crm/servicing/settlement-offers/get-settlement-offer),
[status update](https://developer.setforth.com/api/forth-crm/servicing/settlement-offers/update-settlement-offer-status),
[status catalog](https://developer.setforth.com/api/forth-crm/servicing/settlement-offers/get-all-offer-statuses),
and [contact note creation](https://developer.setforth.com/api/forth-crm/core-objects/contact/notes/create-note).
Graph [message moves](https://learn.microsoft.com/en-us/graph/api/message-move?view=graph-rest-1.0)
and [immutable IDs](https://learn.microsoft.com/en-us/graph/outlook-immutable-id) document the mailbox behavior.
