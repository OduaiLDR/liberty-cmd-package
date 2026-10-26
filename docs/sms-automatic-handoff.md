# Automatic publication and email handoff

`CMD_SMS_HANDOFF_AUTOMATIC` defaults to false. It requires both
`CMD_SMS_FILE_PUBLICATION_ENABLED` and `CMD_SMS_EMAIL_HANDOFF_ENABLED` to be true.
Keep all three off until the Dropbox destination, sharing/access policy, dynamic
recipient scope and accepted live test are confirmed. DebtPlete import has its own
flags, ledger and outcome; it does not control publication or email.

The first successful approval transaction saves one metadata-only intent in
`TblSMSAutomaticHandoffs`. It pins the exact approved source fingerprint,
publication identity/policy, approving actor and email report scope. It opens no
CSV, contacts no provider and performs no recipient-table lookup inside approval.
Queue dispatch follows commit. Queue or logger outages retain the intent, and a
duplicate approval never adopts older history or creates another handoff.

Publish only the exact new migration through the dedicated
`reports-sms-automatic-handoff-migrations` tag. Installation is explicit and does
not enable work. It requires the existing approval, publication and email schemas,
creates only the intent ledger and recovery index, and uses `datetime2(6)` for its
timestamps. Existing tables/evidence are refused, never repaired or dropped. Do
not rerun core migrations, performance indexes or earlier optional migrations.

Each reserved job performs one phase: publish/reconcile the original parts,
verify publication and prepare the frozen email, then attempt that exact prepared
payload. The existing publication and email ledgers remain authoritative. The
intent holds only their verification hashes, without copying private links or
recipients. Dynamic recipients are looked up once by the existing email workflow.

Provider proxies recheck gates, source, policy, database session and lease before
every provider operation. A gate change during a call does not discard its
acknowledgement, but prevents the next provider call. The operation pins reads to
its write session and restores the exact original read handle afterward. Ordinary
manual workflows retain their existing behavior outside this scope.

The database queue visibility must exceed the 7,000-second job timeout. A fenced
7,800-second lease and independent session lock prevent competing automatic
workers. Preparation also shares the manual email workflow's session lock for its
final absence check and frozen SHA capture. Existing ready mail with uncertain
origin is held as `email_origin_unconfirmed`; it is never silently adopted.

An interrupted or unknown email attempt becomes a hold, never an automatic
resend. If email preparation commits but the intent's SHA acknowledgement is lost,
the existing ready row is retained for review. Publication failures preserve the
same deterministic objects and receipts, with a saved recovery wait. No new UUID,
filename, manual receipt reset or invented delivery result is an automatic remedy.

The matching runner schedules
`sms:automatic-handoff-recover --limit=10 --queue` only when all three gates are
exactly true. It discovers existing intents through the recovery index, not all
approved runs. Without `--queue`, the command previews bounded saved counts. The
runner must also deploy the automatic-job/command telemetry exclusions before
activation, including Telescope's separate late failure-update path.

The UI independently shows saved file publication, email acceptance and committed
DebtPlete import progress. Provider acceptance means accepted for sending; none of
these outcomes confirms SMS delivery. Production activation still requires the
accepted destination and recipient access checks, fresh runtime/worker/privacy
checks and an explicitly accepted small new batch. Local closed-provider and
native database tests do not establish those live outcomes.
