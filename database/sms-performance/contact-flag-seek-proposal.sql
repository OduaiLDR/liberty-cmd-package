-- REVIEW SPECIFICATION ONLY. Not a migration, preflight or authorized operator tool.
-- All schema text is commented out. No production install/compile is approved.
PRINT 'Contact flag index proposal only; no schema action is implemented by this file.';

/*
Expected local fixture schema (NOT an approved production installation procedure):

ALTER TABLE dbo.TblProspects
    ADD SMS_ContactPhone AS CONVERT(nvarchar(10),Phone);
CREATE INDEX IX_TblProspects_SMS_ContactPhone
    ON dbo.TblProspects(Contacted,SMS_ContactPhone) INCLUDE(Phone);

Why: preserve the original Unicode comparison, without truncating or mapping contacts
to varchar. The base Phone must be varchar(10); both phone columns and the computed
column must share the same collation. PK must be a unique clustered key.
The computed column is NOT PERSISTED, but its index occupies storage and is maintained.

Before a real operator plan:
1. Review actual production column/index metadata, database identity and every writer's
   indexed-computed-column SET options and SELECT-star/bulk-copy consumers. An installed
   index affects writes even with
   CMD_SMS_CONTACT_FLAG_SEEK_ENABLED=false. Do not assume all non-package writers comply.
2. Budget full-population index build/log/temp/storage, locks and steady-state write costs;
   choose supported online/resumable options and recovery/rollback boundaries explicitly.
3. Seal the exact approved DDL and source identity, quiet-resource admission, lifecycle
   coordination, receipts and post-install catalog/plan checks. No generic auto-migration.
4. Keep the new flag OFF until Unicode equivalence, real workload density, concurrency,
   actual plan/runtime and all-writer acceptance are approved. Local timing is not a SLA.
5. Deploy package/runner coherently. Keep PR118 timeout-only work separate.
6. Observe one newly authorized normal LT run; phone snapshot commit and flag commit
   are separate. Verify bounded before/after population and availability revision.

Operational fallback: turn the seek gate OFF to retain the old query. That does not
remove the index or its writer/session obligations. Any DROP needs its own plan.
Legacy Response_Date schemas always retain the existing earliest-date query.
No Contacted_Date attribution, source ownership repair, history reset or campaign send.

Microsoft indexed computed-column requirements:
https://learn.microsoft.com/en-us/sql/relational-databases/indexes/indexes-on-computed-columns?view=sql-server-ver17
*/
