# Contact phone source and metadata repair

The command now refreshes LDR, PLAW and LT from their own Snowflake CONTACTS tables. Every inserted phone has its actual contact ID, contact creation date and explicit `DP_LDR`, `DP_PLAW` or `DP_LT` source. Deleted contacts are excluded using the NULL-safe `_FIVETRAN_DELETED` predicate.

## Confirmed cause and live evidence

Read-only verification on 2026-10-26 targeted `serverlibertydebtrelief / CommissionDatabase` and all three production Snowflake accounts.

| Azure source before repair | Rows | Missing CID | Missing Created_Date |
| --- | ---: | ---: | ---: |
| DP | 450,228 | 0 | 450,228 |
| DP_LDR | 54,280 | 54,280 | 54,280 |
| DP_PLAW | 23,971 | 23,971 | 23,971 |
| DP_LT | 548,530 | 0 | 0 |

The previous package command refreshed **only LT** and deleted only `DP_LT`. Older LDR/PLAW rows therefore remained untouched. Package history also shows the former LT label `DP` changed to `DP_LT` without removing `DP`. Earlier inserts did not include Created_Date. This explains the stale labels and missing metadata; it is not evidence of missing IDs/dates in the active Snowflake source. The exact package version installed on the production host still needs to be checked during deployment.

The new command's live SELECT-only preview returned:

| Source | Active usable phone rows | Contacts | Invalid IDs | Missing creation dates |
| --- | ---: | ---: | ---: | ---: |
| DP_LDR | 70,820 | 49,946 | 0 | 0 |
| DP_PLAW | 33,394 | 23,166 | 0 | 0 |
| DP_LT | 548,676 | 405,357 | 0 | 0 |

These are phone-slot rows, not unique people or unique telephone numbers. Multiple phones can belong to one contact, and a phone can belong to multiple contacts. Counts can change as new contacts arrive. A separate 100-row page sample from each account also passed the new ID/date normalization. No production rows were changed by these checks.

An earlier read-only exact Phone + CID comparison found 448,057 legacy DP rows represented by DP_LT and 2,171 legacy rows not represented there. Recompute overlap after the new sync. Do not guess account ownership from phone alone or blanket-rename DP. Unresolved legacy rows remain as suppression evidence pending a separate decision.

## Replacement behavior

1. Require the destination Created_Date column. A missing column is an error before any source is staged or row is deleted.
2. Acquire the existing contact-sync application lock for writes; plain dry runs do not acquire it.
3. Validate and stage every requested source before replacing any source. Fetch ordered ID/phone-slot pages into temporary JSON-lines files, with a fixed maximum ID, before/after totals and file hashes. Empty, incomplete or invalid sources stop the run before replacement.
4. Replace each source in its own SQL Server transaction. Verify staged/inserted/destination row counts before committing. A failed source replacement rolls back that source. Earlier successfully committed sources remain committed; later sources remain untouched. Rerunning safely refreshes the selected sources again.
5. Retain existing SMS availability refresh and contacted-prospect flagging behavior after successful commits. Dry runs do neither.

IDs remain strings, avoiding integer/float precision loss. Dates use the contact's CREATED value converted to America/Los_Angeles, matching the prior date conversion. Phone normalization retains the existing behavior: strip punctuation, pad short values to ten digits, and retain longer values including country codes. This change does not redefine valid phone lengths.

The count guards and keyset watermark detect incomplete pages and count changes; they are not Snowflake time-travel isolation. Concurrent in-place changes with identical counts are not a guaranteed frozen snapshot. Source writes are not locked by the command.

## Operator sequence

Deploy the reviewed package version to CMD Runner first. Inspect the **installed package** with `php artisan help Sync:phone-numbers`; it must show `--source`, `--dry-run` and `--verify-target`. Editing a sibling checkout does not update an installed Composer dependency. Do not edit vendor files by hand.

```bash
cd /var/www/cmd-runner
php artisan Sync:phone-numbers --source=all --dry-run --verify-target
```

1. Open `database/phone-sync-repair.sql` in SSMS connected to CommissionDatabase. Its default `@Mode='preview'` changes nothing.
2. For the approved one-time backup, set `@Mode='backup'` and run it. It copies the complete phone table row data into `dbo.TblPhoneNumbers_Backup_20261026`, checks count/content and refuses to overwrite an existing backup. It takes a table lock while copying; writers and ordinary locking reads can wait. This is a row-data backup, not a schema/index/trigger backup. Retain it through verification.
3. After reviewing the source preview and completing the backup, apply the reviewed sync:

```bash
php artisan Sync:phone-numbers --source=all
php artisan Sync:phone-numbers --source=all --dry-run --verify-target
```

Alternatively, repair one account with `--source=LDR`, `--source=PLAW` or `--source=LT`. All commands support the same dry-run flags.

4. Check all three current sources have zero missing CID/date. The sync preserves legacy DP rows intentionally.
5. If approved, rerun the SQL script with `@Mode='cleanup'` and `@ReviewedSyncedSources=1`. It requires all three sources to be present and complete, checks that the backup covers every legacy row exactly, and removes **only** DP rows whose identical Phone + CID is present under DP_LT with a creation date. Unmatched DP rows and all current-source rows remain. The script cooperates with the contact-sync application lock. A subsequent preview reports the remaining exceptions. It does not restore or change ownership of deleted Snowflake contacts.

Do not run older phone writers alongside the rollout. The application lock protects cooperating writers, not arbitrary external processes. If restoring is needed, stop writers, restore from the retained backup using a reviewed transaction and refresh SMS availability; do not improvise an identity-table overwrite while automations run.

## Validation

- PHPUnit covers all-source and single-source execution, strict IDs/dates, dry-run non-mutation, staging completeness, keyset boundaries within one contact, missing destination metadata, rollback and existing SMS availability commit boundaries.
- A disposable localhost SQL Server Developer database exercises replacement, exact long CID/date storage, forced insert failure rollback, SELECT-only repair preview, backup verification/no-overwrite, cleanup approval guard, overlap-only removal, unmatched preservation and cleanup idempotence.
- Live Snowflake/Azure validation is read-only. In addition to all-source aggregate previews and page samples, a complete PLAW staging fetch validated 33,394 phone rows / 23,166 contacts across real keyset pages in 25.8 seconds; its local temporary file was deleted. A full production replacement and production backup/cleanup have **not** been executed as part of implementation.

Local SQL fixture (requires an existing isolated SQL Server Developer instance on `127.0.0.1,64604` and a private JSON file with host/user/password):

```bash
CMD_RUNNER_TEST_AUTOLOAD=/path/to/cmd-runner/vendor/autoload.php php tests/fixtures/phone_sync_sqlserver.php /private/local-sql-test.json
```

The fixture creates a uniquely named disposable database and removes only that database afterward. It refuses other hosts/editions and never reads production configuration.
