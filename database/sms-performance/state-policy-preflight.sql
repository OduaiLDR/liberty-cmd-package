SET NOCOUNT ON;
SET XACT_ABORT ON;

IF DB_NAME() IN (N'master', N'model', N'msdb', N'tempdb')
    THROW 52930, 'Run the SMS State preflight only in the reviewed business database.', 1;
IF OBJECT_ID(N'dbo.TblProspects', N'U') IS NULL OR COL_LENGTH(N'dbo.TblProspects', N'State') IS NULL
    THROW 52931, 'TblProspects.State is required for the SMS State preflight.', 1;

SELECT DB_NAME() AS Database_Name, SYSUTCDATETIME() AS Observed_At_UTC,
    N'sms-state-exclusion-v1' AS Policy_Version;

WITH Normalized AS (
    SELECT UPPER(LTRIM(RTRIM(State))) COLLATE Latin1_General_100_BIN2 AS State_Code,
        State, Phone, Contacted, Opt_Out, Invalid_Phone
    FROM dbo.TblProspects
), Classified AS (
    SELECT CASE
        WHEN State IS NULL OR LTRIM(RTRIM(State)) = '' THEN 'missing_state'
        WHEN LEN(LTRIM(RTRIM(State))) <> 2
          OR UPPER(LTRIM(RTRIM(State))) COLLATE Latin1_General_100_BIN2 LIKE '%[^A-Z]%' THEN 'invalid_state'
        WHEN State_Code IN ('CO','OR','RI','WI','WV') THEN 'blocked_state'
        WHEN State_Code NOT IN ('AL','AK','AZ','AR','CA','CO','CT','DE','FL','GA','HI','ID','IL','IN','IA','KS','KY','LA','ME','MD','MA','MI','MN','MS','MO',
            'MT','NE','NV','NH','NJ','NM','NY','NC','ND','OH','OK','OR','PA','RI','SC','SD','TN','TX','UT','VT','VA','WA','WV','WI','WY','DC') THEN 'unknown_state'
        ELSE 'allowed'
    END AS State_Disposition, State_Code, Phone, Contacted, Opt_Out, Invalid_Phone
    FROM Normalized
)
SELECT State_Disposition, COUNT_BIG(*) AS Prospect_Records,
    SUM(CONVERT(bigint, CASE WHEN Phone IS NOT NULL THEN 1 ELSE 0 END)) AS With_Phone,
    SUM(CONVERT(bigint, CASE WHEN Phone IS NOT NULL AND Contacted=0 AND Opt_Out=0 AND Invalid_Phone=0 THEN 1 ELSE 0 END)) AS Clear_Flag_With_Phone
FROM Classified
GROUP BY State_Disposition
ORDER BY CASE State_Disposition WHEN 'blocked_state' THEN 1 WHEN 'missing_state' THEN 2 WHEN 'invalid_state' THEN 3 WHEN 'unknown_state' THEN 4 ELSE 5 END;

SELECT UPPER(LTRIM(RTRIM(State))) COLLATE Latin1_General_100_BIN2 AS Blocked_State,
    COUNT_BIG(*) AS Prospect_Records
FROM dbo.TblProspects
WHERE UPPER(LTRIM(RTRIM(State))) COLLATE Latin1_General_100_BIN2 IN ('CO','OR','RI','WI','WV')
GROUP BY UPPER(LTRIM(RTRIM(State))) COLLATE Latin1_General_100_BIN2
ORDER BY Blocked_State;

IF OBJECT_ID(N'dbo.TblProspectsExport', N'U') IS NOT NULL AND OBJECT_ID(N'dbo.TblSMSRuns', N'U') IS NOT NULL
BEGIN
    WITH Frozen AS (
        SELECT r.Status,
            UPPER(LTRIM(RTRIM(x.State))) COLLATE Latin1_General_100_BIN2 AS Frozen_State,
            UPPER(LTRIM(RTRIM(p.State))) COLLATE Latin1_General_100_BIN2 AS Current_State,
            x.State AS Frozen_Raw_State, p.State AS Current_Raw_State, p.PK AS Current_PK
        FROM dbo.TblProspectsExport x
        JOIN dbo.TblSMSRuns r ON r.Request_ID=x.Request_ID
        LEFT JOIN dbo.TblProspects p ON p.PK=x.Prospect_PK
        WHERE r.Status NOT IN ('approved','sent','discarded','review_only','not_sent','external_sent')
    )
    SELECT Status,
        SUM(CONVERT(bigint, CASE WHEN Current_PK IS NULL THEN 1 ELSE 0 END)) AS Missing_Current_Prospects,
        SUM(CONVERT(bigint, CASE WHEN Frozen_Raw_State IS NULL OR Frozen_State NOT IN ('AL','AK','AZ','AR','CA','CT','DE','FL','GA','HI','ID','IL','IN','IA','KS','KY','LA','ME','MD','MA','MI','MN','MS','MO','MT','NE','NV','NH','NJ','NM','NY','NC','ND','OH','OK','PA','SC','SD','TN','TX','UT','VT','VA','WA','WY','DC') THEN 1 ELSE 0 END)) AS Unsafe_Frozen_State,
        SUM(CONVERT(bigint, CASE WHEN Current_Raw_State IS NULL OR Current_State NOT IN ('AL','AK','AZ','AR','CA','CT','DE','FL','GA','HI','ID','IL','IN','IA','KS','KY','LA','ME','MD','MA','MI','MN','MS','MO','MT','NE','NV','NH','NJ','NM','NY','NC','ND','OH','OK','PA','SC','SD','TN','TX','UT','VT','VA','WA','WY','DC') THEN 1 ELSE 0 END)) AS Unsafe_Current_State,
        SUM(CONVERT(bigint, CASE WHEN Current_PK IS NOT NULL AND (Frozen_State<>Current_State OR Frozen_State IS NULL OR Current_State IS NULL) THEN 1 ELSE 0 END)) AS Changed_State
    FROM Frozen
    GROUP BY Status
    ORDER BY Status;
END;
