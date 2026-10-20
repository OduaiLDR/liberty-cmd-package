<?php

declare(strict_types=1);

// SELECT-only preflight; no Laravel boot, migrations, schema/data writes or provider calls.
// php sms-recovery-preflight.php --root=/var/www/cmd-runner
$options = getopt('', ['root:', 'env:', 'output:', 'sample:']);
$root = $options['root'] ?? getcwd();
require rtrim($root, '/\\').'/vendor/autoload.php';
$envFile = $options['env'] ?? rtrim($root, '/\\').'/.env';
$env = Dotenv\Dotenv::parse(file_get_contents($envFile));
$host = $env['CMD_DB_HOST'] ?? '';
$database = $env['CMD_DB_DATABASE'] ?? '';
if ($host === '' || $database === '' || str_contains($host.$database, ';')) throw new RuntimeException('Invalid CMD database configuration.');
$run = 'e20ca1c2-a0e6-4e6a-a034-ccb312257d44';
try {
    $db = new PDO('sqlsrv:Server='.$host.';Database='.$database.';Encrypt=yes;TrustServerCertificate=no',
        $env['CMD_DB_USERNAME'] ?? '', $env['CMD_DB_PASSWORD'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::SQLSRV_ATTR_QUERY_TIMEOUT => 45]);
} catch (Throwable $error) {
    fwrite(STDERR, 'Connection failed (SQLSTATE '.$error->getCode().'). Credentials were not printed.'.PHP_EOL);
    exit(1);
}
$report = ['read_only' => true, 'collected_utc' => gmdate('c'), 'run_id' => $run];
$queries = [
    'identity' => ['SELECT @@SERVERNAME AS Server_Name, DB_NAME() AS Database_Name, SYSUTCDATETIME() AS Server_UTC,
        DATABASEPROPERTYEX(DB_NAME(), \'Collation\') AS Collation, SERVERPROPERTY(\'EngineEdition\') AS Engine_Edition', []],
    'tables' => ["SELECT s.name AS Schema_Name, t.name AS Table_Name, SUM(p.row_count) AS Metadata_Rows,
        SUM(p.reserved_page_count) * 8.0 / 1024 AS Reserved_MB
        FROM sys.tables t JOIN sys.schemas s ON s.schema_id=t.schema_id
        JOIN sys.dm_db_partition_stats p ON p.object_id=t.object_id AND p.index_id IN (0,1)
        WHERE t.name LIKE 'TblSMS%' OR t.name LIKE 'TblSms%' OR t.name LIKE 'TblProspects%'
          OR t.name IN ('TblPhoneNumbers','TblMailersUniqueEnriched','TblTUData','TblMailers')
        GROUP BY s.name,t.name ORDER BY s.name,t.name", []],
    'target_run' => ['SELECT Request_ID, Status, Selected, Created_At, Sent_At, File_Bytes FROM dbo.TblSMSRuns WHERE Request_ID=?', [$run]],
    'later_runs' => ["SELECT Request_ID, Status, Selected, Created_At, Sent_At FROM dbo.TblSMSRuns
        WHERE Created_At >= '2026-10-15T18:50:27' OR Sent_At >= '2026-10-15T18:50:27'
        ORDER BY Created_At DESC", []],
    'history_summary' => ['SELECT COUNT_BIG(*) AS History_Rows, MIN(Export_Date) AS First_Export_Date,
        MAX(Export_Date) AS Last_Export_Date, COUNT(DISTINCT SMS_Drop_PK) AS SMS_Drops
        FROM dbo.TblSMSHistory WHERE Request_ID=?', [$run]],
    'audit_table' => ["SELECT OBJECT_ID('dbo.TblSMSAttemptAudit') AS Before_Value_Audit_Object,
        OBJECT_ID('dbo.TblSMSLegacySends') AS Phone_Send_Ledger_Object", []],
    'row_history' => ["SELECT temporal_type_desc, is_tracked_by_cdc,
        OBJECT_SCHEMA_NAME(history_table_id) AS History_Schema, OBJECT_NAME(history_table_id) AS History_Table
        FROM sys.tables WHERE object_id=OBJECT_ID('dbo.TblProspects')", []],
    'tracking_backups' => ["SELECT s.name AS Schema_Name,t.name AS Table_Name,
        t.create_date,t.modify_date FROM sys.tables t JOIN sys.schemas s ON s.schema_id=t.schema_id
        WHERE t.name LIKE '%Prospect%' OR t.name LIKE '%SMS%Audit%' OR t.name LIKE '%SMS%Backup%'
        ORDER BY s.name,t.name", []],
    'tracking_triggers' => ["SELECT name,is_disabled FROM sys.triggers WHERE parent_id=OBJECT_ID('dbo.TblProspects')", []],
    'legacy_sms_dates' => ["SELECT CONVERT(date,Exported_At) AS Recorded_Export_Date,
        COUNT_BIG(*) AS Tier_Drops,SUM(SMS_Count) AS Recorded_Phones FROM dbo.TblSMSExports
        GROUP BY CONVERT(date,Exported_At) ORDER BY Recorded_Export_Date", []],
    'prior_marketing_sms_dates' => ["SELECT CONVERT(date,SMS_Last_Export_Date) AS Recorded_Source_Date,
        COUNT_BIG(*) AS Source_Drops FROM dbo.TblMarketing WHERE SMS_Last_Export_Date IS NOT NULL
        GROUP BY CONVERT(date,SMS_Last_Export_Date) ORDER BY Recorded_Source_Date", []],
    'outside_target_dates' => ["SELECT Latest_Export_Date,SMS_Attempts,COUNT_BIG(*) AS People
        FROM dbo.TblProspects WHERE Latest_Export_Date IS NOT NULL AND Latest_Export_Date<>'2026-10-15'
        GROUP BY Latest_Export_Date,SMS_Attempts ORDER BY Latest_Export_Date,SMS_Attempts OPTION(MAXDOP 1)", []],
    'legacy_export_files' => ["SELECT r.name AS Column_Name,TYPE_NAME(r.user_type_id) AS Data_Type
        FROM sys.columns r WHERE r.object_id IN (OBJECT_ID('dbo.TblSMSExportRequests'),OBJECT_ID('dbo.TblSMSSelectionRequests'))
        ORDER BY r.object_id,r.column_id", []],
    'backups' => ['SELECT TOP (20) backup_type, backup_start_date, backup_finish_date FROM sys.dm_database_backups ORDER BY backup_finish_date DESC', []],
    'backup_extents' => ['SELECT backup_type, in_retention, MIN(backup_start_date) AS Earliest_Metadata_Start, MAX(backup_finish_date) AS Latest_Metadata_Finish
        FROM sys.dm_database_backups GROUP BY backup_type,in_retention', []],
    'database_options' => ['SELECT snapshot_isolation_state_desc,is_read_committed_snapshot_on FROM sys.databases WHERE name=DB_NAME()', []],
    'source_columns' => ["SELECT OBJECT_SCHEMA_NAME(c.object_id) AS Schema_Name,OBJECT_NAME(c.object_id) AS Table_Name,
        c.name AS Column_Name,TYPE_NAME(c.user_type_id) AS Data_Type,c.max_length,c.is_nullable
        FROM sys.columns c WHERE c.object_id IN (OBJECT_ID('dbo.TblMailersUniqueEnriched'),OBJECT_ID('tu_repair_20261014.Lineage'))
        ORDER BY Schema_Name,Table_Name,c.column_id", []],
    'indexes' => ["SELECT OBJECT_SCHEMA_NAME(i.object_id) AS Schema_Name, OBJECT_NAME(i.object_id) AS Table_Name,
        i.name AS Index_Name, i.type_desc, i.is_unique, i.is_disabled, i.filter_definition,
        ic.key_ordinal, ic.is_included_column, c.name AS Column_Name
        FROM sys.indexes i JOIN sys.index_columns ic ON ic.object_id=i.object_id AND ic.index_id=i.index_id
        JOIN sys.columns c ON c.object_id=ic.object_id AND c.column_id=ic.column_id
        WHERE i.object_id IN (OBJECT_ID('dbo.TblProspects'),OBJECT_ID('dbo.TblMailersUniqueEnriched'),
            OBJECT_ID('dbo.TblTUData'),OBJECT_ID('dbo.TblSMSHistory')) AND i.index_id>0
        ORDER BY Table_Name,Index_Name,ic.key_ordinal,ic.index_column_id", []],
    'repair' => ["SELECT c.Status, CASE WHEN OBJECT_ID('dbo.TblMailersUniqueEnriched')=c.ShadowObjectID THEN 1 ELSE 0 END AS Correct_Table_Is_Live,
        (SELECT SUM(row_count) FROM sys.dm_db_partition_stats WHERE object_id=c.ShadowObjectID AND index_id IN (0,1)) AS Live_Rows,
        (SELECT SUM(OutputRows) FROM tu_repair_20261014.Buckets) AS Verified_Rows,
        (SELECT SUM(CONVERT(bigint,Imported)) FROM dbo.TblEnrichedDropNames) AS Summary_Rows,
        (SELECT SUM(SyntheticRowsRemaining) FROM tu_repair_20261014.Buckets) AS Synthetic_Rows,
        c.PostPublishWrites FROM tu_repair_20261014.Control c WHERE c.ID=1", []],
    'active_sms_work' => ["SELECT r.session_id,r.status,r.command,r.wait_type,r.wait_time,r.blocking_session_id,r.total_elapsed_time
        FROM sys.dm_exec_requests r CROSS APPLY sys.dm_exec_sql_text(r.sql_handle) t
        WHERE r.database_id=DB_ID() AND r.session_id<>@@SPID
          AND (t.text LIKE '%TblProspects%' OR t.text LIKE '%TblSMSHistory%')", []],
];
if (isset($options['sample'])) {
    $queries['source_sample'] = [<<<'SQL'
WITH Bounds AS (SELECT MAX(PK) AS Max_PK FROM dbo.TblProspects),
Sample AS (SELECT band.Band,p.* FROM Bounds b CROSS JOIN (VALUES (0),(1),(2),(3),(4)) band(Band)
 CROSS APPLY (SELECT TOP (100) PK,Client,Address,Zip,Phone,Email FROM dbo.TblProspects
    WHERE PK>=CONVERT(bigint,b.Max_PK)*band.Band/5 ORDER BY PK) p)
SELECT p.Band,COUNT(*) AS Sample_Size,
 SUM(CASE WHEN e.PK IS NULL THEN 1 ELSE 0 END) AS No_Exact_Source_Match,
 SUM(CASE WHEN e.PK IS NOT NULL AND COALESCE(p.Phone,'')<>COALESCE(e.Phone,'') THEN 1 ELSE 0 END) AS Raw_Phone_Differs,
 SUM(CASE WHEN e.PK IS NOT NULL AND COALESCE(p.Email,'')<>COALESCE(e.Email,'') THEN 1 ELSE 0 END) AS Raw_Email_Differs,
 SUM(CASE WHEN e.PK IS NULL AND z.Has_Name_Address=1 THEN 1 ELSE 0 END) AS Unmatched_Has_Same_Name_Address,
 SUM(CASE WHEN e.PK IS NULL AND z.Has_Same_Phone=1 THEN 1 ELSE 0 END) AS Unmatched_Phone_Supported_At_Name_Address
FROM Sample p
OUTER APPLY (SELECT TOP (1) e.PK,e.Phone,e.Email FROM dbo.TblMailersUniqueEnriched e
 WHERE e.Address=CONVERT(nvarchar(255),p.Address) AND e.Client=CONVERT(nvarchar(150),p.Client)
 AND COALESCE(NULLIF(e.Zip5_Normalized,''),LEFT(LTRIM(e.Zip),5))=CONVERT(nvarchar(10),p.Zip)
 ORDER BY e.PK DESC) e
OUTER APPLY (SELECT TOP (1) 1 AS Has_Name_Address,
 CASE WHEN EXISTS (SELECT 1 FROM dbo.TblMailersUniqueEnriched v
 WHERE v.Address=CONVERT(nvarchar(255),p.Address) AND v.Client=CONVERT(nvarchar(150),p.Client)
 AND v.Phone=CONVERT(nvarchar(50),p.Phone)) THEN 1 ELSE 0 END AS Has_Same_Phone
 FROM dbo.TblMailersUniqueEnriched z WHERE e.PK IS NULL
 AND z.Address=CONVERT(nvarchar(255),p.Address) AND z.Client=CONVERT(nvarchar(150),p.Client)) z
GROUP BY p.Band OPTION (MAXDOP 1)
SQL, []];
}
foreach ($queries as $label => [$sql, $bindings]) {
    $started = microtime(true);
    try {
        $query = $db->prepare($sql);
        $query->execute($bindings);
        $report[$label] = ['rows' => $query->fetchAll(PDO::FETCH_ASSOC), 'seconds' => round(microtime(true)-$started, 3)];
    } catch (Throwable $error) {
        $report[$label] = ['unavailable_sqlstate' => (string) $error->getCode()];
    }
}
$json = json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
if (isset($options['output'])) {
    $out = fopen($options['output'], 'x');
    if ($out === false) throw new RuntimeException('Choose a new private metadata report path.');
    chmod($options['output'], 0600);
    if (fwrite($out, $json)!==strlen($json) || !fflush($out)) throw new RuntimeException('Cannot save metadata report.');
    fclose($out);
    echo json_encode(['read_only'=>true, 'report'=>$options['output'],
        'target_run'=>$report['target_run'], 'history_summary'=>$report['history_summary'],
        'backup_extents'=>$report['backup_extents'], 'source_sample'=>$report['source_sample'] ?? null,
        'row_history'=>$report['row_history'], 'tracking_backups'=>$report['tracking_backups'],
        'tracking_triggers'=>$report['tracking_triggers'], 'later_runs'=>$report['later_runs'],
        'legacy_sms_dates'=>$report['legacy_sms_dates'], 'prior_marketing_sms_dates'=>$report['prior_marketing_sms_dates'],
        'outside_target_dates'=>$report['outside_target_dates'],
        'database_options'=>$report['database_options']], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
} else {
    echo $json;
}
