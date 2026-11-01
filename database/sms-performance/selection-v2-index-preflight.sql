/*
Read-only preflight for current-source-tier-windowed-v2.

This file never creates columns/indexes and never changes a run. Run it only in
CommissionDatabase during the reviewed resource window, save the result, then
review exact storage/log/locking options before authoring the apply script.
The proposed DDL strings are design evidence, not executable acceptance.
*/
SET NOCOUNT ON;
SET LOCK_TIMEOUT 3000;
SET DEADLOCK_PRIORITY LOW;

SELECT type_desc,SUM(CAST(size AS bigint))*8.0/1024 AS allocated_mb,
    SUM(CAST(FILEPROPERTY(name,'SpaceUsed') AS bigint))*8.0/1024 AS used_mb,
    SUM(CAST(max_size AS bigint))*8.0/1024 AS file_max_mb
FROM sys.database_files GROUP BY type_desc;
SELECT total_log_size_in_bytes,used_log_space_in_bytes,used_log_space_in_percent FROM sys.dm_db_log_space_usage;

IF DB_NAME() <> N'CommissionDatabase'
    THROW 51080, 'Selection-v2 preflight requires CommissionDatabase.', 1;

IF OBJECT_ID('sys.dm_db_resource_stats') IS NOT NULL
    EXEC sys.sp_executesql N'SELECT TOP (1)
        SYSUTCDATETIME() AS captured_at_utc,
        end_time AS resource_sample_at_utc,
        avg_cpu_percent,avg_data_io_percent,avg_log_write_percent,
        CASE WHEN DATEDIFF(SECOND,end_time,SYSUTCDATETIME()) BETWEEN 0 AND 90 THEN 1 ELSE 0 END AS sample_is_current
    FROM sys.dm_db_resource_stats ORDER BY end_time DESC';
ELSE
    SELECT SYSUTCDATETIME() AS captured_at_utc,NULL AS resource_sample_at_utc,
        NULL AS avg_cpu_percent,NULL AS avg_data_io_percent,NULL AS avg_log_write_percent,
        CAST(0 AS bit) AS sample_is_current;

SELECT Request_ID, Status, Target, Selected, Created_At, Updated_At
FROM dbo.TblSMSRuns
WHERE Status NOT IN ('approved','discarded','sent','review_only','not_sent')
ORDER BY Created_At;

WITH RowCounts AS (
    SELECT t.object_id,t.name,SUM(p.rows) AS metadata_rows
    FROM sys.tables t
    JOIN sys.partitions p ON p.object_id=t.object_id AND p.index_id IN (0,1)
    WHERE SCHEMA_NAME(t.schema_id)='dbo' AND t.name IN ('TblProspects','TblTUData','TblMailers','TblMarketing')
    GROUP BY t.object_id,t.name
), SpaceUse AS (
    SELECT p.object_id,SUM(p.reserved_page_count) * 8 AS reserved_kb,SUM(p.used_page_count) * 8 AS used_kb
    FROM sys.dm_db_partition_stats p
    GROUP BY p.object_id
)
SELECT r.name AS table_name,r.metadata_rows,s.reserved_kb,s.used_kb
FROM RowCounts r JOIN SpaceUse s ON s.object_id=r.object_id
ORDER BY r.name;

SELECT t.name AS table_name,c.column_id,c.name AS column_name,ty.name AS type_name,
       c.max_length,c.precision,c.scale,c.collation_name,c.is_nullable,c.is_computed,cc.definition AS computed_definition
FROM sys.tables t
JOIN sys.columns c ON c.object_id=t.object_id
JOIN sys.types ty ON ty.user_type_id=c.user_type_id
LEFT JOIN sys.computed_columns cc ON cc.object_id=c.object_id AND cc.column_id=c.column_id
WHERE (t.name='TblProspects' AND c.name IN ('PK','Creation_Date','Last_SMS_Date','Phone','Client','Address','City','State','Zip','Email','Contacted','Opt_Out','Invalid_Phone'))
   OR (t.name='TblTUData' AND c.name IN ('PK','Creation_Date','ncoaAddr_frstNme','ncoaAddr_lastNme','ncoaAddr_addrLine1Txt','ncoaAddr_zipCde','derivedFields1_unsecdebtbalance','Tier_Tag','SMS_Zip5'))
   OR (t.name='TblMailers' AND c.name IN ('PK','Client','Address','Zip','Drop_Name','Debt_Amount','Debt_Tier','SMS_Zip5'))
   OR (t.name='TblMarketing' AND c.name IN ('Drop_Name','Send_Date'))
ORDER BY t.name,c.column_id;

SELECT t.name AS table_name,i.name AS index_name,i.type_desc,i.filter_definition,i.is_disabled,i.is_hypothetical,
       c.name AS column_name,ic.key_ordinal,ic.is_descending_key,ic.is_included_column
FROM sys.tables t
JOIN sys.indexes i ON i.object_id=t.object_id AND i.index_id>0
JOIN sys.index_columns ic ON ic.object_id=i.object_id AND ic.index_id=i.index_id
JOIN sys.columns c ON c.object_id=ic.object_id AND c.column_id=ic.column_id
WHERE t.name IN ('TblProspects','TblTUData','TblMailers','TblMarketing')
ORDER BY t.name,i.index_id,ic.key_ordinal,ic.index_column_id;

SELECT proposed_object, proposed_ddl
FROM (VALUES
 (1,N'TblProspects never-texted priority',N'CREATE INDEX IX_TblProspects_SMS_Never_Priority ON dbo.TblProspects (Creation_Date DESC,PK ASC) INCLUDE (Phone,Client,Address,City,State,Zip,Email,Last_SMS_Date) WHERE Contacted=0 AND Opt_Out=0 AND Invalid_Phone=0 AND Phone IS NOT NULL AND Last_SMS_Date IS NULL'),
 (2,N'TblProspects previously-texted priority',N'CREATE INDEX IX_TblProspects_SMS_Previous_Priority ON dbo.TblProspects (Last_SMS_Date ASC,PK ASC) INCLUDE (Phone,Client,Address,City,State,Zip,Email,Creation_Date) WHERE Contacted=0 AND Opt_Out=0 AND Invalid_Phone=0 AND Phone IS NOT NULL AND Last_SMS_Date IS NOT NULL'),
 (3,N'TblTUData ZIP5 expression',N'ALTER TABLE dbo.TblTUData ADD SMS_Zip5 AS LEFT(ncoaAddr_zipCde,5)'),
 (4,N'TblTUData identity seek',N'CREATE INDEX IX_TblTUData_SMS_Identity ON dbo.TblTUData (ncoaAddr_addrLine1Txt,SMS_Zip5) INCLUDE (PK,Creation_Date,ncoaAddr_frstNme,ncoaAddr_lastNme,derivedFields1_unsecdebtbalance,Tier_Tag)'),
 (5,N'TblMailers ZIP5 expression',N'ALTER TABLE dbo.TblMailers ADD SMS_Zip5 AS LEFT(Zip,5)'),
 (6,N'TblMailers identity seek',N'CREATE INDEX IX_TblMailers_SMS_Identity ON dbo.TblMailers (Client,Address,SMS_Zip5) INCLUDE (PK,Drop_Name,Debt_Amount,Debt_Tier)'),
 (7,N'TblMarketing drop date consensus',N'CREATE INDEX IX_TblMarketing_SMS_Drop_SendDate ON dbo.TblMarketing (Drop_Name,Send_Date)')
) d(sort_order,proposed_object,proposed_ddl)
ORDER BY sort_order;

SELECT N'REVIEW_ONLY' AS outcome,
       N'No DDL was executed. Validate expression matching with actual plans and approve storage/log/locking options before a separate apply script.' AS next_gate;
