<?php

declare(strict_types=1);

// Pinned SELECT-only observation; never print SQL text/bindings or credentials.
require '/var/www/cmd-runner/vendor/autoload.php';
$env = Dotenv\Dotenv::parse(file_get_contents('/var/www/cmd-runner/.env'));
if (($env['CMD_DB_HOST'] ?? '') !== 'serverlibertydebtrelief.database.windows.net' || ($env['CMD_DB_DATABASE'] ?? '') !== 'CommissionDatabase') throw new RuntimeException('Unexpected database.');
$db = new PDO('sqlsrv:Server=serverlibertydebtrelief.database.windows.net;Database=CommissionDatabase;Encrypt=yes;TrustServerCertificate=no',
    $env['CMD_DB_USERNAME'] ?? '',$env['CMD_DB_PASSWORD'] ?? '',[PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,PDO::SQLSRV_ATTR_QUERY_TIMEOUT => 45]);
$id = 'e20ca1c2-a0e6-4e6a-a034-ccb312257d44';
$queries = [
    'requests' => ["SELECT r.session_id,r.status,r.command,r.wait_type,r.wait_time,r.blocking_session_id,r.total_elapsed_time,r.cpu_time,r.logical_reads,r.writes
        FROM sys.dm_exec_requests r CROSS APPLY sys.dm_exec_sql_text(r.sql_handle) t
        WHERE r.database_id=DB_ID() AND r.session_id<>@@SPID AND (t.text LIKE '%SMSLegacy%' OR t.text LIKE '%TblProspects%' OR t.text LIKE '%TblSMSHistory%')",[]],
    'resources' => ['SELECT TOP(1) end_time,avg_cpu_percent,avg_data_io_percent,avg_log_write_percent FROM sys.dm_db_resource_stats ORDER BY end_time DESC',[]],
    'run' => ['SELECT Request_ID,Status,Selected,Sent_At FROM dbo.TblSMSRuns WHERE Request_ID=?',[$id]],
    'correction' => ["IF OBJECT_ID('dbo.TblSMSLegacyCorrectionRuns') IS NOT NULL SELECT Request_ID,Policy,Status,Changed_Rows,Applied_At FROM dbo.TblSMSLegacyCorrectionRuns WHERE Request_ID=?",[$id]],
];
if (in_array('--requests-only',$_SERVER['argv'],true)) $queries = array_intersect_key($queries,array_flip(['requests','resources']));
if (in_array('--verify',$_SERVER['argv'],true)) {
    $queries += [
        'audit_groups' => ['SELECT Observed_Date,Observed_Attempts,Reconstructed_Date,Reconstructed_Attempts,COUNT_BIG(*) AS People FROM dbo.TblSMSLegacyCorrectionRows WHERE Request_ID=? GROUP BY Observed_Date,Observed_Attempts,Reconstructed_Date,Reconstructed_Attempts ORDER BY Observed_Attempts',[$id]],
        'actual_corrected_groups' => ['SELECT p.Latest_Export_Date,p.SMS_Attempts,COUNT_BIG(*) AS People FROM dbo.TblProspects p JOIN dbo.TblSMSLegacyCorrectionRows a ON a.Prospect_PK=p.PK WHERE a.Request_ID=? GROUP BY p.Latest_Export_Date,p.SMS_Attempts ORDER BY p.SMS_Attempts OPTION(MAXDOP 1)',[$id]],
        'retained' => ['SELECT (SELECT COUNT_BIG(*) FROM dbo.TblSMSHistory WHERE Request_ID=?) AS History_Rows,(SELECT COUNT_BIG(*) FROM dbo.TblProspectsExport) AS Working_Rows,(SELECT COUNT_BIG(*) FROM dbo.TblProspects WHERE Latest_Export_Date=\'2026-10-15\') AS Remaining_Oct15_Rows',[$id]],
        'drop_records' => ['SELECT COUNT(*) AS Retained_Drops FROM dbo.TblSMSDrops WHERE PK IN (SELECT SMS_Drop_PK FROM dbo.TblSMSHistory WHERE Request_ID=?)',[$id]],
    ];
}
$report = ['read_only' => true,'checked_utc' => gmdate('c')];
foreach ($queries as $name => [$sql,$bindings]) {
    try {
        $q = $db->prepare($sql); $q->execute($bindings);
        $report[$name] = $q->columnCount() > 0 ? $q->fetchAll(PDO::FETCH_ASSOC) : [];
        $q->closeCursor();
    } catch (Throwable $error) { $report[$name] = ['unavailable_sqlstate' => (string) $error->getCode()]; }
}
if (in_array('--verify',$_SERVER['argv'],true)) {
    $artifact = '/var/www/cmd-runner/storage/app/private/sms-exports/runs/'.$id.'/sms-2026-10-15.csv';
    $report['file_retained_unchanged'] = is_file($artifact) && filesize($artifact) === 181868557
        && hash_file('sha256',$artifact) === '8b926c95924c6e7b351022801ea903e8901e02e23d0eb3ce3a662641b5fb263e';
}
echo json_encode($report,JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
$options = getopt('', ['verify','requests-only','output:']);
if (isset($options['output'])) {
    require_once __DIR__.'/sms-legacy-reconstruction-cli.php';
    SmsLegacyReconstructionFiles::json($options['output'],$report);
}
