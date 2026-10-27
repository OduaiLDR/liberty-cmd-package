SET NOCOUNT ON;
SET XACT_ABORT ON;

IF DB_NAME() IN (N'master', N'model', N'msdb', N'tempdb')
    THROW 52940, 'Run the lean SMS history preflight only in the reviewed business database.', 1;
IF OBJECT_ID(N'dbo.TblTUData', N'U') IS NULL OR OBJECT_ID(N'dbo.TblMailers', N'U') IS NULL
    THROW 52941, 'TblTUData and TblMailers are required for the lean SMS history preflight.', 1;
IF COL_LENGTH(N'dbo.TblTUData', N'PK') IS NULL OR COL_LENGTH(N'dbo.TblMailers', N'PK') IS NULL
    THROW 52942, 'Both SMS debt sources require a PK lookup column.', 1;

SELECT DB_NAME() AS Database_Name, SYSUTCDATETIME() AS Observed_At_UTC,
    N'current-source-tier-windowed-lean-v3' AS Policy_Version;

SELECT t.name AS Table_Name,c.name AS Column_Name,ty.name AS Type_Name,c.is_nullable,
    i.name AS Unique_Index_Name,i.is_primary_key,i.is_unique_constraint,i.type_desc,
    i.is_disabled,i.is_hypothetical,i.has_filter
FROM sys.tables t
JOIN sys.schemas s ON s.schema_id=t.schema_id
JOIN sys.columns c ON c.object_id=t.object_id AND c.name='PK'
JOIN sys.types ty ON ty.user_type_id=c.user_type_id
LEFT JOIN sys.index_columns ic ON ic.object_id=t.object_id AND ic.column_id=c.column_id AND ic.key_ordinal=1
LEFT JOIN sys.indexes i ON i.object_id=ic.object_id AND i.index_id=ic.index_id
    AND i.is_unique=1
    AND NOT EXISTS (
        SELECT 1 FROM sys.index_columns extra
        WHERE extra.object_id=i.object_id AND extra.index_id=i.index_id AND extra.key_ordinal>1
    )
WHERE s.name='dbo' AND t.name IN ('TblTUData','TblMailers')
ORDER BY t.name,i.is_primary_key DESC,i.name;

IF OBJECT_ID(N'dbo.TblProspectsExport', N'U') IS NOT NULL
   AND OBJECT_ID(N'dbo.TblSMSRuns', N'U') IS NOT NULL
   AND COL_LENGTH(N'dbo.TblSMSRuns', N'Run_Options_JSON') IS NOT NULL
BEGIN
    SELECT JSON_VALUE(r.Run_Options_JSON, '$.tier_selection_policy') AS Selection_Policy,
        x.Debt_Source,
        CASE
            WHEN x.Debt_Source IN ('TblTUData','TblMailers') AND x.Debt_Source_PK>0 THEN 'exact_pk_shape'
            WHEN x.Debt_Source='Tier' AND x.Debt_Source_PK IS NULL THEN 'tier_without_second_id'
            ELSE 'invalid_for_lean_v3'
        END AS Provenance_Shape,
        COUNT_BIG(*) AS Frozen_Records
    FROM dbo.TblProspectsExport x
    JOIN dbo.TblSMSRuns r ON r.Request_ID=x.Request_ID
    GROUP BY JSON_VALUE(r.Run_Options_JSON, '$.tier_selection_policy'),x.Debt_Source,
        CASE
            WHEN x.Debt_Source IN ('TblTUData','TblMailers') AND x.Debt_Source_PK>0 THEN 'exact_pk_shape'
            WHEN x.Debt_Source='Tier' AND x.Debt_Source_PK IS NULL THEN 'tier_without_second_id'
            ELSE 'invalid_for_lean_v3'
        END
    ORDER BY Selection_Policy,x.Debt_Source,Provenance_Shape;

    SELECT
        SUM(CONVERT(bigint, CASE WHEN x.Debt_Source='TblTUData' AND t.PK IS NULL THEN 1 ELSE 0 END)) AS Missing_TU_Lookups,
        SUM(CONVERT(bigint, CASE WHEN x.Debt_Source='TblMailers' AND m.PK IS NULL THEN 1 ELSE 0 END)) AS Missing_Mailer_Lookups,
        SUM(CONVERT(bigint, CASE WHEN x.Debt_Source='Tier' AND x.Debt_Source_PK IS NOT NULL THEN 1 ELSE 0 END)) AS Tier_With_Forbidden_Second_ID
    FROM dbo.TblProspectsExport x
    JOIN dbo.TblSMSRuns r ON r.Request_ID=x.Request_ID
    LEFT JOIN dbo.TblTUData t ON x.Debt_Source='TblTUData' AND t.PK=x.Debt_Source_PK
    LEFT JOIN dbo.TblMailers m ON x.Debt_Source='TblMailers' AND m.PK=x.Debt_Source_PK
    WHERE JSON_VALUE(r.Run_Options_JSON, '$.tier_selection_policy')='current-source-tier-windowed-lean-v3';
END;

IF OBJECT_ID(N'dbo.TblSMSHistory', N'U') IS NOT NULL
   AND OBJECT_ID(N'dbo.TblSMSRuns', N'U') IS NOT NULL
   AND COL_LENGTH(N'dbo.TblSMSRuns', N'Run_Options_JSON') IS NOT NULL
BEGIN
    SELECT JSON_VALUE(r.Run_Options_JSON, '$.tier_selection_policy') AS Selection_Policy,
        h.Debt_Source,
        CASE
            WHEN h.Debt_Source IN ('TblTUData','TblMailers') AND h.Debt_Source_PK>0 THEN 'exact_pk_shape'
            WHEN h.Debt_Source='Tier' AND h.Debt_Source_PK IS NULL THEN 'tier_without_second_id'
            ELSE 'invalid_for_lean_v3'
        END AS Provenance_Shape,
        COUNT_BIG(*) AS Approved_History_Records
    FROM dbo.TblSMSHistory h
    JOIN dbo.TblSMSRuns r ON r.Request_ID=h.Request_ID
    GROUP BY JSON_VALUE(r.Run_Options_JSON, '$.tier_selection_policy'),h.Debt_Source,
        CASE
            WHEN h.Debt_Source IN ('TblTUData','TblMailers') AND h.Debt_Source_PK>0 THEN 'exact_pk_shape'
            WHEN h.Debt_Source='Tier' AND h.Debt_Source_PK IS NULL THEN 'tier_without_second_id'
            ELSE 'invalid_for_lean_v3'
        END
    ORDER BY Selection_Policy,h.Debt_Source,Provenance_Shape;

    SELECT
        SUM(CONVERT(bigint, CASE WHEN h.Debt_Source='TblTUData' AND t.PK IS NULL THEN 1 ELSE 0 END)) AS Approved_Missing_TU_Lookups,
        SUM(CONVERT(bigint, CASE WHEN h.Debt_Source='TblMailers' AND m.PK IS NULL THEN 1 ELSE 0 END)) AS Approved_Missing_Mailer_Lookups,
        SUM(CONVERT(bigint, CASE WHEN h.Debt_Source='Tier' AND h.Debt_Source_PK IS NOT NULL THEN 1 ELSE 0 END)) AS Approved_Tier_With_Forbidden_Second_ID
    FROM dbo.TblSMSHistory h
    JOIN dbo.TblSMSRuns r ON r.Request_ID=h.Request_ID
    LEFT JOIN dbo.TblTUData t ON h.Debt_Source='TblTUData' AND t.PK=h.Debt_Source_PK
    LEFT JOIN dbo.TblMailers m ON h.Debt_Source='TblMailers' AND m.PK=h.Debt_Source_PK
    WHERE JSON_VALUE(r.Run_Options_JSON, '$.tier_selection_policy')='current-source-tier-windowed-lean-v3';
END;
