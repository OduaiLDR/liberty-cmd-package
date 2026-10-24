-- Read-only discovery. Run separately on the operator-selected connector/database.
-- Never infers Company, changes recipients, enables a flag or sends an email.
-- Company distribution is explicitly a bounded sample, not a population count.
SET NOCOUNT ON;

SELECT DB_NAME() AS Selected_Database, SCHEMA_NAME(o.schema_id) AS Table_Schema,
       o.name AS Table_Name, o.type_desc AS Object_Type
FROM sys.objects AS o
WHERE o.name = N'TblReports' AND o.type IN ('U', 'V')
ORDER BY Table_Schema;

SELECT c.column_id AS Column_Position, c.name AS Column_Name, t.name AS Data_Type,
       c.max_length AS Maximum_Bytes, c.precision, c.scale, c.is_nullable, c.is_identity
FROM sys.columns AS c
JOIN sys.types AS t ON t.user_type_id = c.user_type_id
WHERE c.object_id = OBJECT_ID(N'dbo.TblReports', N'U')
ORDER BY c.column_id;

SELECT i.name AS Index_Name, i.is_primary_key, i.is_unique,
       ic.key_ordinal, ic.is_included_column, c.name AS Column_Name
FROM sys.indexes AS i
JOIN sys.index_columns AS ic ON ic.object_id = i.object_id AND ic.index_id = i.index_id
JOIN sys.columns AS c ON c.object_id = ic.object_id AND c.column_id = ic.column_id
WHERE i.object_id = OBJECT_ID(N'dbo.TblReports', N'U')
  AND (i.is_primary_key = 1 OR c.is_identity = 1)
ORDER BY i.index_id, ic.key_ordinal, ic.index_column_id;

IF OBJECT_ID(N'dbo.TblReports', N'U') IS NULL
BEGIN
    SELECT N'The selected database has no dbo.TblReports user table. Choose the correct existing connector/database.' AS Discovery_Status;
    RETURN;
END;

IF COL_LENGTH(N'dbo.TblReports', N'PK') IS NULL
   OR COL_LENGTH(N'dbo.TblReports', N'Report_Name') IS NULL
   OR COL_LENGTH(N'dbo.TblReports', N'Company') IS NULL
   OR COL_LENGTH(N'dbo.TblReports', N'Send_To') IS NULL
   OR COL_LENGTH(N'dbo.TblReports', N'Send_CC') IS NULL
   OR COL_LENGTH(N'dbo.TblReports', N'Send_BCC') IS NULL
   OR COL_LENGTH(N'dbo.TblReports', N'Schedule') IS NULL
BEGIN
    SELECT N'The selected table lacks required setup columns. Review the metadata; do not apply recipient setup.' AS Discovery_Status;
    RETURN;
END;

-- Dynamic compilation occurs only after the metadata checks; a missing column
-- therefore returns the diagnostic above instead of failing batch compilation.
EXEC sys.sp_executesql N'
    SELECT Company, COUNT_BIG(*) AS Rows_In_First_1000_PK_Sample
    FROM (
        SELECT TOP (1000) PK, CONVERT(nvarchar(4000), Company) AS Company
        FROM dbo.TblReports ORDER BY PK
    ) AS CompanySample
    GROUP BY Company
    ORDER BY Rows_In_First_1000_PK_Sample DESC, Company;

    SELECT TOP (50) PK, Report_Name, Company, Send_To, Send_CC, Send_BCC, Schedule
    FROM dbo.TblReports
    WHERE LTRIM(RTRIM(Report_Name)) = @ReportName
    ORDER BY PK;',
    N'@ReportName nvarchar(128)', @ReportName = N'SMS Export';

SELECT N'Company sample covers at most the first 1000 PKs; SMS Export display is limited to 50 rows. Any existing SMS Export row makes setup refuse insertion. Select Company explicitly from your real operating convention.' AS Discovery_Status;
