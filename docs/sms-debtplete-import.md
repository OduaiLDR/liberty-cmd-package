# Approved SMS files: DebtPlete import

This is an independent, opt-in handoff for approved four-column SMS files. It imports
the exact approved CSV parts and planned send date. It does not send SMS, prove
provider delivery, change prospect attempts/history, or infer earlier manual imports.
Historical nine-column batches, including Jude's completed registration, are excluded.

## Release prerequisites

Keep `CMD_SMS_DEBTPLETE_IMPORT_ENABLED=false` and
`CMD_SMS_DEBTPLETE_IMPORT_AUTOMATIC=false` until the receiver, schema, private storage,
tenant/client credentials, queue, privacy filter and accepted destination have been
verified together. Installing application code does not install the optional outbox.

The host must deploy the matching `SmsImportTelescopePrivacy` filter before enabling
imports. It excludes import scopes, jobs and commands even in unredacted capture
mode. The early query-watcher guard also avoids interpolating private bindings.

The only sender schema addition is
`database/sms-migrations/2026_10_25_000001_create_sms_debtplete_imports.php`, published
under **`reports-sms-import-migrations`**. Apply that exact migration once through a
reviewed procedure after checking the installed state. Do not publish or rerun the
general SMS migration directory. An existing outbox is refused; rollback never drops
its evidence. SQL Server uses `datetime2(6)` for the four outbox timestamps.

Receiver schema is a separate explicit, fingerprint-confirmed install. It must not
run as a normal tenant migration or create a tenant. Receiver access must use the
accepted tenant host and the exact managed client. Never reuse the old phone-only
uploader for these approved files.

## Configuration

Set these through the host's private runtime configuration, not source control,
browser storage, command arguments or audit output:

| Setting | Required value |
| --- | --- |
| `CMD_SMS_DEBTPLETE_IMPORT_ORIGIN` | Accepted canonical HTTPS tenant origin, without path, port or trailing slash |
| `CMD_SMS_DEBTPLETE_IMPORT_ALLOWED_HOSTS` | Exact accepted DNS host; no wildcard or IP address |
| `CMD_SMS_DEBTPLETE_IMPORT_TENANT_ID` | Existing tenant UUID, lowercase |
| `CMD_SMS_DEBTPLETE_IMPORT_CLIENT_ID` | Existing managed API-client UUID, lowercase |
| `CMD_SMS_DEBTPLETE_IMPORT_API_KEY` | Private API-client secret; never print it |

The destination identity and sealed plan are retained in the SQL outbox. Credentials
are not included in that identity, queue payloads, status projection or receipts.
Changing the destination is refused for an existing handoff. Credential rotation is
allowed only within the same pinned destination.

The existing SMS database queue must have visibility timeout greater than 7,000
seconds. The job validates its actual reserved connection and queue before work,
uses a 1,200-second timeout, and acquires a fenced 1,500-second SQL lease. The receiver
has its own bounded import queue and recovery process; both sides need acceptance.

## Approval and recovery

With both flags enabled, the first completed approval transaction captures one
outbox entry atomically with approval. It opens no network or files inside that
transaction. Queue dispatch follows commit; an unavailable queue retains the outbox.
Replaying an already approved run never creates a new automatic import.

The runner schedules `sms:run-import-recover --limit=10 --queue` once per minute,
only when the current import gate is exactly true. It reads existing outbox entries
and queues bounded work. It does not scan earlier approvals or prepare new imports.
Keep this existing-ledger recovery active for accepted in-flight handoffs even if
automatic capture is subsequently disabled.

`sms:run-import <existing-lowercase-uuid>` reads saved status by default. Mutation
requires `--confirm-import` and an explicit `--action=prepare|step|queue`; preparation
also requires `--actor=<operator-email>`. This operator interface is not permission
to import an old approval. Review its original files, destination and acceptance
first. `sms:run-import-recover` without `--queue` previews bounded pending counts.

Each step reconciles remote status before at most one verified CSV PUT. Unknown
upload outcomes retain the same UUID/part and wait for the saved lease deadline;
there is no immediate large-file resend. After the wait, a fresh status read is
required. Held receipts or changed source files require inspection, not a new UUID,
SQL repair, manual count edit or blind retry. Retain all unsuccessful evidence.

All parts must pass the receiver's file barrier before any imported records are
written. Processing is committed in bounded chunks with exact seals and readback.
Duplicate phones remain separate approved rows. A completed replay adds no records.

## Progress and acceptance

The UI reads saved status only. `files_accepted` means files verified by the receiver;
`processed` means committed imported rows. Null means unconfirmed, not zero. A run is
complete only after every expected file and row is confirmed. Status reads do not
upload, queue jobs or recount SMS eligibility. Email/publication status remains
independent, and completion is not SMS delivery confirmation.

Before activation, verify the accepted tenant and client, schema fingerprints,
private storage permissions, fresh worker source/config, queue routing/visibility,
strict disabled behavior and telemetry privacy. Then use an explicitly accepted
small new batch to check files, exact dates/rows, progress, lost acknowledgements,
same-plan recovery and completed zero-write replay. Local native SQL Server,
receiver MySQL/HTTP, queue and cross-application contract tests are evidence for
the implementation; they do not replace that production acceptance.
