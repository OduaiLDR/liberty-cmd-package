# Contact repair rehearsal

`contact-sync-repair.php` is standalone and never boots the application or invokes a sync.
It accepts an explicit, reviewed plan of 1–10 physical rows. It does not discover or guess
repairs. Review authoritative company/contact identity and current assignment evidence
before preparing a plan. An unknown correct owner is unresolved, not a proposed update.

The example JSON contains synthetic data only. Real plans and reports contain personal
data; store them outside Git in the local evidence folder.

Set the dedicated environment variables `CONTACT_REPAIR_SQLSERVER_HOST`,
`CONTACT_REPAIR_SQLSERVER_DATABASE`, `CONTACT_REPAIR_SQLSERVER_USER`, and
`CONTACT_REPAIR_SQLSERVER_PASSWORD`. No application environment file is read.

```powershell
php scripts/contact-sync-repair.php --plan=C:\private\reviewed-plan.json --output=C:\private\new-preview.json
```

The default path issues only bounded, parameterized `SELECT TOP` statements. It does
not start transactions or request update locks. Azure previews are allowed; use a
read-only database principal where available. Inspect every reported status:

- `ready`: exact reviewed before-image still matches.
- `already_applied`: exact proposed values and pinned identity already match.
- `stale`, `missing`, `ambiguous_pk`, or `occupied_destination`: stop and investigate.

To rehearse writes, add `--apply` with literal loopback as the server and
`contact_sync_test` as the database. The service also checks the connected database
name and SQL Server edition. Remote writes and Azure writes are disabled; there is
no override. Use a new output path for each run so before-images are never overwritten.

Local apply locks and validates the entire plan before writing, compares exact old
values (including null, case, and spaces), changes only `LLG_ID` and/or `Agent`, requires
one affected row per update, and reads back every proposed row before committing.
Any mismatch or SQL error rolls back the entire batch. Repeating an already-applied
plan makes no writes. Unrelated rows and columns are covered by the SQL Server tests.

The report is written before local apply; `local_apply_pending` means it is a
before-image journal, not proof of a commit. The CLI emits the committed row count on
success. After an interrupted process, use a fresh preview to establish live state.

This tool cannot execute a future production repair. That requires a separate reviewed
change and explicit authorization after local rehearsal and fresh read-only validation.
