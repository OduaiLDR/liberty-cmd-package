<?php
declare(strict_types=1);

// Explicit operator tool, never a Laravel auto-migration. Preview is the default.
// Invoke one reviewed object per process from the production runner host.
// Rollback: disable the v2 creation flag; retain additive indexes as evidence.
umask(0077);
$objects = [
    'never' => ['TblProspects','IX_TblProspects_SMS_Never_Priority',"CREATE INDEX IX_TblProspects_SMS_Never_Priority ON dbo.TblProspects(Creation_Date DESC,PK ASC) INCLUDE(Phone,Client,Address,City,State,Zip,Email,Last_SMS_Date) WHERE Contacted=0 AND Opt_Out=0 AND Invalid_Phone=0 AND Phone IS NOT NULL AND Last_SMS_Date IS NULL"],
    'previous' => ['TblProspects','IX_TblProspects_SMS_Previous_Priority',"CREATE INDEX IX_TblProspects_SMS_Previous_Priority ON dbo.TblProspects(Last_SMS_Date ASC,PK ASC) INCLUDE(Phone,Client,Address,City,State,Zip,Email,Creation_Date) WHERE Contacted=0 AND Opt_Out=0 AND Invalid_Phone=0 AND Phone IS NOT NULL AND Last_SMS_Date IS NOT NULL"],
    'tu-zip' => ['TblTUData','SMS_Zip5','ALTER TABLE dbo.TblTUData ADD SMS_Zip5 AS LEFT(ncoaAddr_zipCde,5)'],
    'mailer-zip' => ['TblMailers','SMS_Zip5','ALTER TABLE dbo.TblMailers ADD SMS_Zip5 AS LEFT(Zip,5)'],
    'tu' => ['TblTUData','IX_TblTUData_SMS_Identity','CREATE INDEX IX_TblTUData_SMS_Identity ON dbo.TblTUData(ncoaAddr_addrLine1Txt,SMS_Zip5) INCLUDE(PK,Creation_Date,ncoaAddr_frstNme,ncoaAddr_lastNme,derivedFields1_unsecdebtbalance,Tier_Tag)'],
    'mailers' => ['TblMailers','IX_TblMailers_SMS_Identity','CREATE INDEX IX_TblMailers_SMS_Identity ON dbo.TblMailers(Client,Address,SMS_Zip5) INCLUDE(PK,Drop_Name,Debt_Amount,Debt_Tier)'],
    'marketing' => ['TblMarketing','IX_TblMarketing_SMS_Drop_SendDate','CREATE INDEX IX_TblMarketing_SMS_Drop_SendDate ON dbo.TblMarketing(Drop_Name,Send_Date)'],
];
try {
    $args=getopt('', ['object:','apply']); $object=$args['object']??null;
    if (!is_string($object) || !isset($objects[$object])) throw new RuntimeException('Choose one reviewed object.');
    [$table,$name,$ddl]=$objects[$object]; $apply=array_key_exists('apply',$args);
    if (str_starts_with($ddl,'CREATE INDEX')) $ddl.=' WITH(ONLINE=ON(WAIT_AT_LOW_PRIORITY(MAX_DURATION=1 MINUTES,ABORT_AFTER_WAIT=SELF)),MAXDOP=2)';
    chdir('/var/www/cmd-runner'); require 'vendor/autoload.php';
    $app=require 'bootstrap/app.php'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $db=Illuminate\Support\Facades\DB::connection('sqlsrv'); $pdo=$db->getPdo();
    $pdo->setAttribute(PDO::SQLSRV_ATTR_QUERY_TIMEOUT,10);
    $pdo->exec('SET LOCK_TIMEOUT 5000; SET DEADLOCK_PRIORITY LOW; SET ANSI_NULLS ON; SET ANSI_PADDING ON; SET ANSI_WARNINGS ON; SET ARITHABORT ON; SET CONCAT_NULL_YIELDS_NULL ON; SET QUOTED_IDENTIFIER ON; SET NUMERIC_ROUNDABORT OFF');
    $identity=$db->selectOne("SELECT DB_NAME() AS name,OBJECT_ID('dbo.TblProspects') AS prospects,compatibility_level FROM sys.databases WHERE database_id=DB_ID()");
    if (!app()->environment('production') || $identity->name!=='CommissionDatabase' || (int)$identity->prospects!==1847065716 || (int)$identity->compatibility_level<90) throw new RuntimeException('Database identity guard failed.');
    if ($apply) {
        $lock=$db->selectOne("DECLARE @r int; EXEC @r=sys.sp_getapplock @Resource='sms-selection-v2-index-install',@LockMode='Exclusive',@LockOwner='Session',@LockTimeout=0; SELECT @r AS result");
        if ((int)$lock->result<0) throw new RuntimeException('Another index installation is active.');
    }
    $required = [
        'TblProspects'=>['PK'=>['int',4],'Creation_Date'=>['date',3],'Last_SMS_Date'=>['date',3],'Phone'=>['varchar',10],'Client'=>['varchar',150],'Address'=>['varchar',200],'City'=>['varchar',100],'State'=>['char',2],'Zip'=>['varchar',10],'Email'=>['varchar',254]],
        'TblTUData'=>['PK'=>['int',4],'Creation_Date'=>['date',3],'ncoaAddr_frstNme'=>['varchar',100],'ncoaAddr_lastNme'=>['varchar',100],'ncoaAddr_addrLine1Txt'=>['nvarchar',510],'ncoaAddr_zipCde'=>['nvarchar',20],'derivedFields1_unsecdebtbalance'=>['decimal',9],'Tier_Tag'=>['varchar',30]],
        'TblMailers'=>['PK'=>['int',4],'Client'=>['nvarchar',100],'Address'=>['nvarchar',100],'Zip'=>['nvarchar',100],'Drop_Name'=>['nvarchar',100],'Debt_Amount'=>['decimal',9],'Debt_Tier'=>['nvarchar',10]],
        'TblMarketing'=>['Drop_Name'=>['nvarchar',100],'Send_Date'=>['date',3]],
    ];
    $metadata=$db->select("SELECT c.name,ty.name AS type_name,c.max_length,c.collation_name,c.is_computed,cc.definition FROM sys.columns c JOIN sys.types ty ON ty.user_type_id=c.user_type_id LEFT JOIN sys.computed_columns cc ON cc.object_id=c.object_id AND cc.column_id=c.column_id WHERE c.object_id=OBJECT_ID(?)",['dbo.'.$table]);
    $actual=[]; foreach($metadata as $column) $actual[$column->name]=$column;
    foreach($required[$table] as $column=>$expected) {
        $found=$actual[$column]??null;
        if (!$found || [$found->type_name,(int)$found->max_length]!==$expected
            || ($found->collation_name!==null && $found->collation_name!=='SQL_Latin1_General_CP1_CI_AS')) throw new RuntimeException('Source type/collation changed: '.$table.'.'.$column);
    }
    if (in_array($object,['tu','mailers'],true)) {
        $zip=$actual['SMS_Zip5']??null;
        $expression=strtolower((string)preg_replace('/[\s\[\]()]+/','',(string)($zip->definition??'')));
        if (!$zip || (int)$zip->is_computed!==1 || $expression!==($object==='tu'?'leftncoaaddr_zipcde,5':'leftzip,5') || $zip->type_name!=='nvarchar' || (int)$zip->max_length!==10) throw new RuntimeException('Computed ZIP5 prerequisite differs.');
    }
    $out=['at_utc'=>gmdate('c'),'object'=>$object,'apply'=>$apply,'ddl'=>$ddl,'identity'=>$identity];
    // Conservative release budget: reserve 80GB for one output/build plus scratch
    // (mailer base currently ~22GB, TU ~81GB wide heap but narrow covering keys),
    // and 8GB allocated free log before each build. Never build concurrently.
    $out['storage_policy']=['data_headroom_mb'=>80000,'allocated_log_headroom_mb'=>8000,'maxdop'=>2,'builds_at_once'=>1];
    $out['source_metadata_sha256']=hash('sha256',json_encode($metadata,JSON_THROW_ON_ERROR));
    $out['resources']=$db->select('SELECT TOP(4) end_time,avg_cpu_percent,avg_data_io_percent,avg_log_write_percent FROM sys.dm_db_resource_stats ORDER BY end_time DESC');
    $out['budget']=$db->selectOne("SELECT (CONVERT(bigint,DATABASEPROPERTYEX(DB_NAME(),'MaxSizeInBytes'))/1048576.0-SUM(CONVERT(bigint,FILEPROPERTY(name,'SpaceUsed')))*8.0/1024) AS data_free_mb FROM sys.database_files WHERE type=0");
    $out['log']=$db->selectOne('SELECT (total_log_size_in_bytes-used_log_space_in_bytes)/1048576.0 AS log_free_mb,used_log_space_in_percent FROM sys.dm_db_log_space_usage');
    $out['active']=$db->select("SELECT r.session_id,r.command,r.status,r.wait_type,r.open_transaction_count FROM sys.dm_exec_requests r JOIN sys.dm_exec_sessions s ON s.session_id=r.session_id WHERE s.is_user_process=1 AND r.session_id<>@@SPID AND s.program_name<>'TdService'");
    $exists=$db->selectOne(str_starts_with($ddl,'CREATE INDEX')
        ? 'SELECT COUNT(*) AS n FROM sys.indexes WHERE object_id=OBJECT_ID(?) AND name=?'
        : 'SELECT COUNT(*) AS n FROM sys.columns WHERE object_id=OBJECT_ID(?) AND name=?', ['dbo.'.$table,$name]);
    $out['already_exists']=(int)$exists->n!==0;
    if (!$apply) {echo json_encode($out,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;exit(0);}
    if ($out['already_exists']) throw new RuntimeException('Object exists: review its definition rather than overwrite it.');
    if (count($out['resources'])!==4 || strtotime($out['resources'][0]->end_time.' UTC')<time()-90) throw new RuntimeException('Fresh resource samples required.');
    foreach($out['resources'] as $sample) foreach(['avg_cpu_percent','avg_data_io_percent','avg_log_write_percent'] as $metric) if ((float)$sample->$metric>=80) throw new RuntimeException('Resource window is not quiet.');
    if ((float)$out['budget']->data_free_mb<80000 || (float)$out['log']->log_free_mb<8000 || $out['active']!==[]) throw new RuntimeException('Storage/log/active-workload guard failed.');
    if (config('sms-exports.tier_selection_windowed_enabled',false) || config('sms-exports.email_handoff_enabled',false) || config('sms-exports.file_publication_enabled',false)) throw new RuntimeException('Creation/integration gates must be off.');
    if ($db->table('TblSMSRuns')->whereNotIn('Status',['approved','discarded','sent','review_only','not_sent'])->exists()) throw new RuntimeException('Open SMS run remains.');
    // The operator gracefully stops this one existing worker while retaining queued summaries.
    exec("pgrep -f '[a]rtisan queue:work --queue=tu-export'",$processes,$processStatus);
    if ($processStatus!==1 || $processes!==[]) throw new RuntimeException('The TU/SMS worker must be gracefully stopped first.');
    $queue=config('queue.connections.'.config('queue.default'));
    if (($queue['driver']??null)!=='database') throw new RuntimeException('Unreviewed queue driver.');
    foreach(Illuminate\Support\Facades\DB::connection($queue['connection']??null)->table($queue['table'])->where('queue',config('sms-exports.queue','tu-export'))->get(['payload','reserved_at']) as $job) {
        $payload=json_decode($job->payload,true,512,JSON_THROW_ON_ERROR);
        if ($job->reserved_at!==null || ($payload['displayName']??null)!==Cmd\Reports\Jobs\CountDurableSmsAvailabilityJob::class) throw new RuntimeException('Non-summary or reserved queue work remains.');
    }
    $transactions=$db->select("SELECT st.session_id,dt.database_transaction_log_record_count FROM sys.dm_tran_session_transactions st JOIN sys.dm_tran_database_transactions dt ON dt.transaction_id=st.transaction_id WHERE dt.database_id=DB_ID() AND st.session_id<>@@SPID AND st.is_user_transaction=1");
    if ($transactions!==[]) throw new RuntimeException('Another user database transaction remains active.');
    // Recheck after the metadata/queue inspections under the same install lock.
    $latest=$db->selectOne('SELECT TOP(1) end_time,avg_cpu_percent,avg_data_io_percent,avg_log_write_percent FROM sys.dm_db_resource_stats ORDER BY end_time DESC');
    if (!$latest || strtotime($latest->end_time.' UTC')<time()-90 || max((float)$latest->avg_cpu_percent,(float)$latest->avg_data_io_percent,(float)$latest->avg_log_write_percent)>=80) throw new RuntimeException('Resource window changed.');
    if ($db->select("SELECT r.session_id FROM sys.dm_exec_requests r JOIN sys.dm_exec_sessions s ON s.session_id=r.session_id WHERE r.session_id<>@@SPID AND s.is_user_process=1 AND (s.program_name<>'TdService' OR r.command NOT IN('SELECT','WAITFOR'))")!==[]) throw new RuntimeException('Another user request started.');
    if ($db->table('TblSMSRuns')->whereNotIn('Status',['approved','discarded','sent','review_only','not_sent'])->exists()) throw new RuntimeException('A new SMS run started.');
    $out['before_indexes']=$db->select("SELECT i.name,i.filter_definition,i.is_disabled FROM sys.indexes i WHERE i.object_id=OBJECT_ID(?)",['dbo.'.$table]);
    $directory=__DIR__.'/receipts'; if(!is_dir($directory) && !mkdir($directory,0700)) throw new RuntimeException('Cannot create audit.');
    $receipt=$directory.'/'.$object.'-'.gmdate('Ymd\THis\Z').'.json';
    if(file_put_contents($receipt,json_encode($out,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR))===false) throw new RuntimeException('Cannot preserve audit.');
    $started=microtime(true); $pdo->setAttribute(PDO::SQLSRV_ATTR_QUERY_TIMEOUT,0);
    $pdo->exec($ddl);
    $out['completed_at_utc']=gmdate('c');$out['seconds']=round(microtime(true)-$started,3);$out['outcome']='created';
    if(file_put_contents($receipt,json_encode($out,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR))===false) throw new RuntimeException('DDL succeeded but final receipt write failed.');
    echo json_encode($out,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
} catch(Throwable $error) {
    if(isset($receipt)) {
        $out['outcome']=$out['outcome']??'failed';$out['ended_at_utc']=gmdate('c');
        $out['private_error']=['class'=>get_class($error),'code'=>$error->getCode(),'message'=>$error->getMessage()];
        file_put_contents($receipt,json_encode($out,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
    }
    fwrite(STDERR,'Index installation stopped: '.get_class($error).' code='.$error->getCode().' reason='.($error instanceof RuntimeException && !$error instanceof PDOException?$error->getMessage():'database operation failed; inspect private server diagnostic').PHP_EOL);exit(1);
}
