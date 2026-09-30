/*
 * One-time prerequisite for Sync:contacts-data suffix fallback lookups.
 * Run against the SQL Server database containing dbo.TblMailers.
 * The filtered index keeps the repeated contact-page lookups seekable.
 */
IF COL_LENGTH('dbo.TblMailers', 'External_ID_Last9') IS NULL
BEGIN
    ALTER TABLE dbo.TblMailers
        ADD External_ID_Last9 AS (CAST(RIGHT(External_ID, 9) AS varchar(9))) PERSISTED;
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.TblMailers')
      AND name = 'IX_TblMailers_External_ID_Last9'
)
BEGIN
    CREATE INDEX IX_TblMailers_External_ID_Last9
        ON dbo.TblMailers (External_ID_Last9)
        INCLUDE (External_ID, Drop_Name)
        WHERE Drop_Name IS NOT NULL;
END;
GO
