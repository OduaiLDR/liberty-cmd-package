<?php

declare(strict_types=1);

namespace Cmd\Reports\Console\Commands;

use Cmd\Reports\Services\SmsExternalEventSeal;
use Cmd\Reports\Services\SmsExternalEventService;
use Cmd\Reports\Services\SmsJudeExternalPlan;
use Cmd\Reports\Services\SmsRecordPolicy;
use Cmd\Reports\Services\SmsRunService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/** Operator-only historical event registration. Default is local preview. */
class RegisterJudeSmsEvent extends Command
{
    protected $signature='sms:register-jude-event
        {--prepare : Prepare a NEW private sealed plan and unchanged original ZIP; no SQL writes}
        {--apply : Explicitly stage/register one previously prepared event}
        {--resume : Explicitly revalidate a held event and continue its saved checkpoint}
        {--status : Read only the saved SQL event status}
        {--verify : Verify saved event/history without writing}
        {--plan= : Existing absolute private plan.json}
        {--original-plan= : Original pinned closed membership-plan directory}
        {--membership= : Complete verified membership-preview directory}
        {--tracking= : Complete clear tracking-preflight directory}
        {--input= : Private directory with the four pinned original input files}
        {--out= : NEW private writer-plan directory, only for --prepare}
        {--actor= : Explicit registration operator, only for --prepare}
        {--wait-seconds=900 : Maximum resource waiting at each committed boundary (0..3600)}';
    protected $description='Preview or explicitly register Jude\'s exact already-used 3M historical event; never send SMS or call providers.';

    public static function mode(array $options): string
    {
        $modes=[];foreach(['prepare','apply','resume','status','verify'] as $key)if(($options[$key]??false)===true)$modes[]=$key;
        if(count($modes)>1)throw new RuntimeException('Choose one explicit action; preview is the default.');return $modes[0]??'preview';
    }

    protected function plans(): SmsJudeExternalPlan{return app(SmsJudeExternalPlan::class);}
    protected function writer(): SmsExternalEventService{return app(SmsExternalEventService::class);}

    protected function packageReference(): string
    {
        $reference=\Composer\InstalledVersions::getReference('oduaildr/liberty-cmd-package');
        if(!is_string($reference)||!preg_match('/\A[a-f0-9]{40}\z/',$reference))throw new RuntimeException('An immutable installed package reference is required.');return $reference;
    }

    private function value(string $name): string
    {
        $value=$this->option($name);if(!is_string($value)||$value===''||preg_match('/[\x00-\x1f\x7f]/',$value))throw new RuntimeException('Required explicit option missing.');return $value;
    }

    private function emit(array $value): void{$this->line(json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));}

    /** No fixture override: the command can only mutate the actual reviewed production target. */
    protected function runtime(bool $writing,?string $ownId=null): void
    {
        if(!app()->environment('production')||config('services.cmd.auth_enabled',true)!==true)throw new RuntimeException('Authenticated production runtime required.');
        $db=DB::connection('sqlsrv');
        if($db->getDriverName()!=='sqlsrv'||$db->getConfig('host')!=='serverlibertydebtrelief.database.windows.net'||$db->transactionLevel()!==0||$db->getPdo()->inTransaction())throw new RuntimeException('Dedicated pinned SQL Server connection required.');
        $db->getPdo()->setAttribute(\PDO::SQLSRV_ATTR_QUERY_TIMEOUT,60);
        $db->unprepared('SET LOCK_TIMEOUT 5000;SET DEADLOCK_PRIORITY LOW');
        $identity=$db->selectOne("SELECT DB_NAME() AS name,OBJECT_ID('dbo.TblProspects','U') AS object_id");
        if($identity->name!=='CommissionDatabase'||(int)$identity->object_id!==1847065716)throw new RuntimeException('Production database identity differs.');
        $schema=$db->getSchemaBuilder();
        foreach([SmsExternalEventService::EVENTS=>['Request_ID','State','Plan_SHA256','Before_State_SHA256','Last_Applied_PK','Last_Chunk'],
            SmsExternalEventService::MEMBERS=>['Request_ID','Prospect_PK','Before_SHA256','Expected_Attempts','Expected_Last_SMS_Date','Expected_Latest_Export_Date'],
            SmsExternalEventService::CHUNKS=>['Request_ID','Chunk_Number','After_PK','Through_PK','Membership_SHA256']] as $table=>$columns)if(!$schema->hasTable($table)||!$schema->hasColumns($table,$columns))throw new RuntimeException('Reviewed external-event schema required.');
        if(!$writing)return;
        if(config('sms-exports.external_event_writer_enabled',false)!==true)throw new RuntimeException('Historical registration writer remains disabled.');
        foreach(['durable_availability_enabled','record_workflow_enabled','run_file_options_enabled','tier_selection_enabled','tier_selection_windowed_enabled'] as $key)if(config('sms-exports.'.$key)!==true)throw new RuntimeException('Core rollout gate differs.');
        foreach(['prospect_enrichment_enabled','tu_completion_date_enabled','contact_attribution_enabled','run_archive_enabled','file_publication_enabled','email_handoff_enabled','demo'] as $key)if(config('sms-exports.'.$key,false)!==false)throw new RuntimeException('Unexpected optional integration gate.');
        if(config('sms-exports.email_allowed_link_hosts',[])!==[])throw new RuntimeException('Unexpected email host configuration.');
        SmsRecordPolicy::assertReady();
        $ordinary=$db->table('TblSMSRuns')->whereNotIn('Status',SmsRunService::CLOSED);if($ownId!==null)$ordinary->where('Request_ID','<>',$ownId);
        if($ordinary->exists())throw new RuntimeException('Another SMS run is open.');
        $status=shell_exec('sudo -n supervisorctl status cmd-runner-tu-worker:cmd-runner-tu-worker_00 2>/dev/null');
        if(!is_string($status)||!preg_match('/\bSTOPPED\b/',$status))throw new RuntimeException('Exact TU/SMS worker must be gracefully stopped; retain its queue.');
    }

    /** Only wait outside transactions, after a committed checkpoint or before beginning. */
    protected function quiet(int $wait,?string $id): void
    {
        $started=microtime(true);$announced=false;
        do{
            $this->runtime(true,$id);$db=DB::connection('sqlsrv');
            $samples=$db->select('SELECT TOP(4) DATEDIFF(second,end_time,SYSUTCDATETIME()) AS age_seconds,avg_cpu_percent,avg_data_io_percent,avg_log_write_percent FROM sys.dm_db_resource_stats ORDER BY end_time DESC');
            $ready=count($samples)===4;
            foreach($samples as $sample){if(!is_numeric($sample->age_seconds)||(int)$sample->age_seconds<0||(int)$sample->age_seconds>90)$ready=false;foreach(['avg_cpu_percent','avg_data_io_percent','avg_log_write_percent'] as $field)if(!is_numeric($sample->$field)||!is_finite((float)$sample->$field)||(float)$sample->$field<0||(float)$sample->$field>=80)$ready=false;}
            $active=$db->select("SELECT r.session_id FROM sys.dm_exec_requests r JOIN sys.dm_exec_sessions s ON s.session_id=r.session_id WHERE r.session_id<>@@SPID AND s.is_user_process=1 AND (s.program_name<>'TdService' OR r.command NOT IN('SELECT','WAITFOR'))");
            $transactions=$db->select('SELECT st.session_id FROM sys.dm_tran_session_transactions st JOIN sys.dm_tran_database_transactions dt ON dt.transaction_id=st.transaction_id WHERE st.session_id<>@@SPID AND dt.database_id=DB_ID() AND st.is_user_transaction=1');
            if($ready&&$active===[]&&$transactions===[])return;
            if(microtime(true)-$started>=$wait)throw new RuntimeException('Resource window stayed busy; keep the committed checkpoint.');
            if(!$announced){$this->emit(['phase'=>'waiting_for_quiet','checkpoint_unchanged'=>true]);$announced=true;}
            sleep(min(15,max(1,$wait-(int)(microtime(true)-$started))));
        }while(true);
    }

    private function audit(array $plan,string $phase,array $data): void
    {
        $directory=dirname($plan['path']);SmsJudeExternalPlan::privatePath($directory,true);
        if(!preg_match('/\A[a-z_]{1,40}\z/',$phase))throw new RuntimeException('Unexpected receipt phase.');
        $path=$directory.'/operator-'.$phase.'-'.gmdate('Ymd\THis\Z').'-'.bin2hex(random_bytes(4)).'.json';$handle=fopen($path,'x+b');if($handle===false)throw new RuntimeException('Cannot preserve operator receipt.');
        try{$text=SmsExternalEventSeal::json(['phase'=>$phase,'at_utc'=>gmdate('c'),'request_id'=>$plan['request_id'],'plan_sha256'=>$plan['plan_sha256']]+$data)."\n";if(fwrite($handle,$text)!==strlen($text)||!fflush($handle))throw new RuntimeException('Operator receipt incomplete.');}finally{fclose($handle);}chmod($path,0600);
    }

    private function stopped(array $plan): bool
    {
        foreach(new \DirectoryIterator(dirname($plan['path'])) as $entry){
            if(!$entry->isFile()||!str_starts_with($entry->getFilename(),'operator-stopped-')||!str_ends_with($entry->getFilename(),'.json'))continue;
            $path=$entry->getPathname();SmsJudeExternalPlan::privatePath($path);if(filesize($path)>1048576)throw new RuntimeException('Unexpected stop receipt size.');
            $receipt=json_decode(file_get_contents($path),true,32,JSON_THROW_ON_ERROR);
            if(($receipt['plan_sha256']??null)===$plan['plan_sha256']&&($receipt['uncertain_commit_must_be_inspected']??false)===true)return true;
        }
        return false;
    }

    public function handle(): int
    {
        set_error_handler(static function(int $severity,string $message,string $file,int $line):never{throw new \ErrorException($message,0,$severity,$file,$line);});
        $plan=null;$writer=null;$sqlAttempted=false;
        try{
            $mode=self::mode($this->options());$manager=$this->plans();
            if($mode==='prepare'){
                if($this->option('plan')!==null)throw new RuntimeException('Preparation cannot replace/reidentify an existing plan.');
                $plan=$manager->prepare($this->value('original-plan'),$this->value('membership'),$this->value('tracking'),$this->value('input'),$this->value('out'),$this->value('actor'),$this->packageReference());
                $this->emit($plan['public']+['prepared'=>true]);return self::SUCCESS;
            }
            if($mode==='preview'&&$this->option('plan')===null){$this->emit($manager->preview($this->value('original-plan'),$this->value('membership'),$this->value('tracking')));return self::SUCCESS;}
            $plan=$manager->inspect($this->value('plan'));
            if($mode==='preview'){$this->emit($plan['public']);return self::SUCCESS;}
            if($plan['package_reference']!==$this->packageReference())throw new RuntimeException('Installed package differs from the sealed writer plan.');
            $writing=in_array($mode,['apply','resume'],true);$this->runtime($writing,$plan['request_id']);$writer=$this->writer();
            if($mode==='status'){$header=$writer->status($plan['request_id']);if($header->Plan_SHA256!==$plan['plan_sha256'])throw new RuntimeException('Stored plan differs.');$this->emit(['request_id'=>$plan['request_id'],'state'=>$header->State,'staged'=>(int)$header->Staged_Rows,'applied'=>(int)$header->Applied_Rows,'last_chunk'=>(int)$header->Last_Chunk,'last_pk'=>$header->Last_Applied_PK===null?null:(int)$header->Last_Applied_PK,'sql_writes'=>false]);return self::SUCCESS;}
            if($mode==='verify'){$this->emit($writer->verify($plan['request_id'],$plan['plan_sha256'])+['sql_writes'=>false]);return self::SUCCESS;}
            $wait=$this->option('wait-seconds');if(!is_string($wait)||!preg_match('/\A(?:0|[1-9][0-9]{0,3})\z/',$wait)||(int)$wait>3600)throw new RuntimeException('Invalid quiet-window wait budget.');$wait=(int)$wait;
            $this->quiet($wait,$plan['request_id']);
            if($mode==='resume'){
                // Resume never creates a new event/UUID. A failed hold during an
                // earlier lost connection still requires explicit prefix review.
                $header=$writer->status($plan['request_id']);if($header->Plan_SHA256!==$plan['plan_sha256'])throw new RuntimeException('Resume plan differs.');
                if($header->State!=='completed'){
                    if(!method_exists($writer,'resume')||!method_exists($writer,'hold'))throw new RuntimeException('Reviewed resume interfaces unavailable.');
                    $sqlAttempted=true;if($header->State!=='held')$writer->hold($plan['request_id'],$plan['plan_sha256'],'explicit_resume_checkpoint_review');
                    $header=$writer->resume($plan['request_id'],$plan['plan_sha256']);
                }
            }else{
                $sqlAttempted=true;$header=$writer->begin($plan['service_plan']);
                if($header->State!=='completed'&&($header->State==='held'||$this->stopped($plan)))throw new RuntimeException('Stopped event requires explicit reviewed --resume.');
            }
            if($header->State==='completed'){$result=$writer->finish($plan['request_id'],$plan['plan_sha256']);$this->audit($plan,'completed_replay',$result);$this->emit($result);return self::SUCCESS;}
            if($header->State==='staging'){
                foreach($manager->chunks($plan) as $rows){$this->quiet($wait,$plan['request_id']);$staged=(int)$header->Staged_Rows;$header=$writer->stage($plan['request_id'],$plan['plan_sha256'],$rows);if((int)$header->Staged_Rows>$staged&&(int)$header->Staged_Rows%100000===0)$this->emit(['phase'=>'staging','staged'=>(int)$header->Staged_Rows,'total'=>SmsJudeExternalPlan::ROWS]);}
                $this->quiet($wait,$plan['request_id']);$header=$writer->seal($plan['request_id'],$plan['plan_sha256']);
            }
            while((int)$header->Applied_Rows<SmsJudeExternalPlan::ROWS){$this->quiet($wait,$plan['request_id']);$after=$header->Last_Applied_PK===null?null:(int)$header->Last_Applied_PK;
                $receipt=$writer->applyNext($plan['request_id'],$plan['plan_sha256'],$after);$this->audit($plan,'committed_chunk',$receipt);$header=$writer->status($plan['request_id']);
                if($header->Plan_SHA256!==$plan['plan_sha256'])throw new RuntimeException('Durable plan changed after commit.');
                if((int)$header->Applied_Rows%100000===0)$this->emit(['phase'=>'registering','applied'=>(int)$header->Applied_Rows,'total'=>SmsJudeExternalPlan::ROWS,'last_chunk'=>(int)$header->Last_Chunk]);
                if(($receipt['updated']??0)===0&&(int)$header->Applied_Rows<SmsJudeExternalPlan::ROWS)throw new RuntimeException('Unexpected zero progress; inspect checkpoints.');
            }
            $this->quiet($wait,$plan['request_id']);$result=$writer->finish($plan['request_id'],$plan['plan_sha256']);$this->audit($plan,'completed',$result);$this->emit($result);return self::SUCCESS;
        }catch(Throwable $error){
            if($plan!==null){$data=['error'=>['class'=>get_class($error),'message'=>$error->getMessage()],'uncertain_commit_must_be_inspected'=>$sqlAttempted];
                if($sqlAttempted&&$writer!==null){try{$header=$writer->status($plan['request_id']);$data['durable_state']=['state'=>$header->State,'staged'=>(int)$header->Staged_Rows,'applied'=>(int)$header->Applied_Rows,'last_chunk'=>(int)$header->Last_Chunk,'last_pk'=>$header->Last_Applied_PK];}catch(Throwable $statusError){$data['status_error_class']=get_class($statusError);}
                    try{if(method_exists($writer,'hold'))$writer->hold($plan['request_id'],$plan['plan_sha256'],'operator_execution_stopped');}catch(Throwable $holdError){$data['hold_error_class']=get_class($holdError);}
                }
                try{$this->audit($plan,'stopped',$data);}catch(Throwable){/* Never retry a committed mutation because the local audit filesystem failed. */}
            }
            $this->error('Historical operation stopped. Inspect private proof/error and durable SQL checkpoints before any explicit resume. No provider send was requested.');return self::FAILURE;
        }finally{restore_error_handler();}
    }
}
