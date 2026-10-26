-- One-time row-data backup / proven legacy overlap cleanup in CommissionDatabase.
-- Default is SELECT-only. Run backup BEFORE the new sync. Run cleanup AFTER it.
-- Nothing renames or guesses the owner of an unmatched legacy DP row.
SET NOCOUNT ON;
SET XACT_ABORT ON;
DECLARE @Mode varchar(10) = 'preview'; -- preview | backup | cleanup
DECLARE @ReviewedSyncedSources bit = 0; -- Set to 1 only after successful three-source sync + preview.

IF DB_NAME() <> N'CommissionDatabase' OR CONVERT(nvarchar(128),SERVERPROPERTY('ServerName')) <> N'serverlibertydebtrelief'
    THROW 51400, 'Wrong target. Expected serverlibertydebtrelief / CommissionDatabase.', 1;
IF @Mode NOT IN ('preview','backup','cleanup')
    THROW 51401, 'Invalid operation.', 1;
IF OBJECT_ID(N'dbo.TblPhoneNumbers',N'U') IS NULL OR COL_LENGTH('dbo.TblPhoneNumbers','Created_Date') IS NULL
    THROW 51402, 'Expected phone table / Created_Date column missing.', 1;

IF @Mode = 'preview'
BEGIN
    SELECT Source,COUNT_BIG(*) AS Rows,
        SUM(CONVERT(bigint,CASE WHEN NULLIF(LTRIM(RTRIM(CID)),'') IS NULL THEN 1 ELSE 0 END)) AS Missing_CID,
        SUM(CONVERT(bigint,CASE WHEN Created_Date IS NULL THEN 1 ELSE 0 END)) AS Missing_Created_Date
    FROM dbo.TblPhoneNumbers GROUP BY Source ORDER BY Source;
    ;WITH CurrentKeys AS (
        SELECT Phone,CID FROM dbo.TblPhoneNumbers
        WHERE Source=N'DP_LT' AND NULLIF(LTRIM(RTRIM(CID)),'') IS NOT NULL AND Created_Date IS NOT NULL
        GROUP BY Phone,CID
    )
    SELECT COUNT_BIG(*) AS Legacy_DP_Rows,
        COALESCE(SUM(CONVERT(bigint,CASE WHEN n.CID IS NOT NULL THEN 1 ELSE 0 END)),0) AS Proven_DP_LT_Overlap,
        COALESCE(SUM(CONVERT(bigint,CASE WHEN n.CID IS NULL THEN 1 ELSE 0 END)),0) AS Unresolved_DP_Rows_Preserved
    FROM dbo.TblPhoneNumbers p
    LEFT JOIN CurrentKeys n ON n.Phone=p.Phone AND n.CID=p.CID
    WHERE p.Source=N'DP';
    SELECT OBJECT_ID(N'dbo.TblPhoneNumbers_Backup_20261026',N'U') AS Backup_Object_ID,
           N'PREVIEW ONLY: no permanent data changed.' AS Result;
    RETURN;
END;

BEGIN TRY
    BEGIN TRANSACTION;
    DECLARE @LockResult int;
    EXEC @LockResult=sys.sp_getapplock @Resource=N'sms:contact-sync',@LockMode='Exclusive',@LockOwner='Transaction',@LockTimeout=0;
    IF @LockResult < 0 THROW 51403, 'Phone sync is running; no repair performed.', 1;
    -- Hold a stable image while copying or checking/deleting proven overlap.
    DECLARE @LiveRows bigint = (SELECT COUNT_BIG(*) FROM dbo.TblPhoneNumbers WITH (TABLOCKX,HOLDLOCK));

    IF @Mode='backup'
    BEGIN
        IF OBJECT_ID(N'dbo.TblPhoneNumbers_Backup_20261026') IS NOT NULL
            THROW 51404, 'Backup already exists; it will not be overwritten.', 1;
        SELECT PK,Phone,Source,CID,Created_Date INTO dbo.TblPhoneNumbers_Backup_20261026 FROM dbo.TblPhoneNumbers;
        IF (SELECT COUNT_BIG(*) FROM dbo.TblPhoneNumbers_Backup_20261026) <> @LiveRows
            THROW 51405, 'Backup row count differs; rolling back.', 1;
        IF EXISTS (SELECT PK,Phone,Source,CID,Created_Date FROM dbo.TblPhoneNumbers
                   EXCEPT SELECT PK,Phone,Source,CID,Created_Date FROM dbo.TblPhoneNumbers_Backup_20261026)
            THROW 51406, 'Backup contents differ; rolling back.', 1;
        COMMIT;
        SELECT @LiveRows AS Rows_Backed_Up,N'ROW-DATA BACKUP COMPLETE; source unchanged. Indexes/triggers/constraints are not copied.' AS Result;
        RETURN;
    END;

    IF @ReviewedSyncedSources <> 1 THROW 51407, 'Complete and review the new three-source sync before cleanup.', 1;
    IF OBJECT_ID(N'dbo.TblPhoneNumbers_Backup_20261026',N'U') IS NULL
        THROW 51408, 'Verified backup required.', 1;
    IF EXISTS (SELECT 1 FROM dbo.TblPhoneNumbers WHERE Source IN (N'DP_LDR',N'DP_PLAW',N'DP_LT')
               AND (NULLIF(LTRIM(RTRIM(CID)),'') IS NULL OR Created_Date IS NULL))
        THROW 51409, 'Current sources still have missing metadata; cleanup refused.', 1;
    IF (SELECT COUNT(DISTINCT Source) FROM dbo.TblPhoneNumbers WHERE Source IN (N'DP_LDR',N'DP_PLAW',N'DP_LT')) <> 3
        THROW 51410, 'All three sources must be present before cleanup.', 1;
    IF EXISTS (SELECT PK,Phone,Source,CID,Created_Date FROM dbo.TblPhoneNumbers WHERE Source=N'DP'
               EXCEPT SELECT PK,Phone,Source,CID,Created_Date FROM dbo.TblPhoneNumbers_Backup_20261026 WHERE Source=N'DP')
        THROW 51411, 'Backup does not cover every legacy row exactly; cleanup refused.', 1;

    DELETE p FROM dbo.TblPhoneNumbers p
    WHERE p.Source=N'DP' AND EXISTS (
        SELECT 1 FROM dbo.TblPhoneNumbers n
        WHERE n.Source=N'DP_LT' AND n.Phone=p.Phone AND n.CID=p.CID
          AND NULLIF(LTRIM(RTRIM(n.CID)),'') IS NOT NULL AND n.Created_Date IS NOT NULL
    );
    DECLARE @Removed int=@@ROWCOUNT;
    DECLARE @Unresolved bigint=(SELECT COUNT_BIG(*) FROM dbo.TblPhoneNumbers WHERE Source=N'DP');
    COMMIT;
    SELECT @Removed AS Proven_Legacy_Overlap_Removed,@Unresolved AS Unresolved_DP_Rows_Preserved;
END TRY
BEGIN CATCH
    IF XACT_STATE()<>0 ROLLBACK;
    THROW;
END CATCH;
