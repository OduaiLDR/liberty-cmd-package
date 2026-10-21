-- Run against the shared SQL Server source used by DBConnector::fromEnvironment('plaw').
-- This is an operator-run setup script, not a Laravel migration.
-- Default: SELECT-only preview. Review both result sets before approving a write.
-- Set @Apply = 1 only after approving these exact two rows and all five recipients.
-- Existing approval recipient rows cause apply mode to abort without overwriting them.
DECLARE @Apply bit = 0;
DECLARE @Recipients nvarchar(1000) = N'jennifer@libertydebtrelief.com;rama@libertydebtrelief.com;lana@libertydebtrelief.com;jace@libertydebtrelief.com;adrian@libertydebtrelief.com';

SET NOCOUNT ON;

SELECT N'existing' AS Row_Source, PK, Report_Name, Company, Send_To, Send_CC, Send_BCC, Schedule
FROM dbo.TblReports
WHERE LTRIM(RTRIM(Report_Name)) IN (N'Settlement Approval', N'Client Approved Settlement')
ORDER BY PK;

SELECT N'proposed_insert' AS Row_Source, N'Settlement Approval' AS Report_Name,
       Companies.Company, @Recipients AS Send_To, N'' AS Send_CC, N'' AS Send_BCC,
       CAST(NULL AS nvarchar(250)) AS Schedule
FROM (VALUES (N'LDR'), (N'PLAW')) AS Companies(Company);

IF @Apply IS NULL OR @Apply <> 1 RETURN;

-- Acquire the guard and insert together so another setup cannot race this one.
SET XACT_ABORT ON;
BEGIN TRY
    BEGIN TRANSACTION;

    IF EXISTS (
        SELECT 1
        FROM dbo.TblReports WITH (UPDLOCK, HOLDLOCK)
        WHERE LTRIM(RTRIM(Report_Name)) IN (N'Settlement Approval', N'Client Approved Settlement')
    )
        THROW 50001, 'Approval recipients already exist; review them instead of overwriting.', 1;

    INSERT INTO dbo.TblReports (Report_Name, Company, Send_To, Send_CC, Send_BCC, Schedule)
    VALUES
        (N'Settlement Approval', N'LDR', @Recipients, N'', N'', NULL),
        (N'Settlement Approval', N'PLAW', @Recipients, N'', N'', NULL);

    IF @@ROWCOUNT <> 2
        THROW 50002, 'Unexpected insert count; rolling back.', 1;

    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
    THROW;
END CATCH;

SELECT N'configured' AS Row_Source, PK, Report_Name, Company, Send_To, Send_CC, Send_BCC, Schedule
FROM dbo.TblReports
WHERE LTRIM(RTRIM(Report_Name)) IN (N'Settlement Approval', N'Client Approved Settlement')
ORDER BY PK;
