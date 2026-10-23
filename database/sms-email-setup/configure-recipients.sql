-- Operator-run setup for the existing shared SQL Server dbo.TblReports table.
-- Default is SELECT-only. This script is never called by the SMS application.
-- Choose the real Company value from your existing operational convention, then
-- configure CMD_SMS_EMAIL_REPORT_COMPANY to exactly that value in the host.
-- Review the preview and existing rows before setting @Apply = 1.
DECLARE @Apply bit = 0;
DECLARE @Company nvarchar(128) = NULL; -- REQUIRED operator choice; do not guess LDR/PLAW/LT.
DECLARE @ReportName nvarchar(128) = N'SMS Export';
DECLARE @Recipients nvarchar(1000) = N'Jude@lendingtower.com;Jeff@gozuvo.com;Audrey@gozuvo.com';

SET NOCOUNT ON;

SELECT N'existing' AS Row_Source, PK, Report_Name, Company, Send_To, Send_CC, Send_BCC, Schedule
FROM dbo.TblReports
WHERE LTRIM(RTRIM(Report_Name)) = @ReportName
ORDER BY PK;

SELECT N'proposed_insert' AS Row_Source, @ReportName AS Report_Name, @Company AS Company,
       @Recipients AS Send_To, N'' AS Send_CC, N'' AS Send_BCC,
       CAST(NULL AS nvarchar(250)) AS Schedule;

IF @Apply IS NULL OR @Apply <> 1 RETURN;

IF @Company IS NULL OR LTRIM(RTRIM(@Company)) = N'' OR DATALENGTH(@Company) <> DATALENGTH(LTRIM(RTRIM(@Company)))
    THROW 50001, 'Choose the actual SMS email Company value explicitly before applying this setup.', 1;

SET XACT_ABORT ON;
BEGIN TRY
    BEGIN TRANSACTION;

    -- Refuse to overwrite any existing row for this dedicated report key. Review
    -- duplicate/cross-company configuration instead of silently changing it.
    IF EXISTS (
        SELECT 1 FROM dbo.TblReports WITH (UPDLOCK, HOLDLOCK)
        WHERE LTRIM(RTRIM(Report_Name)) = @ReportName
    )
        THROW 50002, 'SMS email recipients already exist; review them instead of overwriting.', 1;

    INSERT INTO dbo.TblReports (Report_Name, Company, Send_To, Send_CC, Send_BCC, Schedule)
    VALUES (@ReportName, @Company, @Recipients, N'', N'', NULL);

    IF @@ROWCOUNT <> 1
        THROW 50003, 'Unexpected SMS recipient insert count; rolling back.', 1;

    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
    THROW;
END CATCH;

SELECT N'configured' AS Row_Source, PK, Report_Name, Company, Send_To, Send_CC, Send_BCC, Schedule
FROM dbo.TblReports
WHERE LTRIM(RTRIM(Report_Name)) = @ReportName AND LTRIM(RTRIM(Company)) = @Company
ORDER BY PK;
