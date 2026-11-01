<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Services\SmsAvailabilityLedger;
use Cmd\Reports\Services\SmsDurableAvailabilityService;
use Cmd\Reports\Services\SmsExternalArtifact;
use Cmd\Reports\Services\SmsExternalEventSeal;
use Cmd\Reports\Services\SmsExternalEventService;
use Cmd\Reports\Services\SmsRecordPolicy;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Isolated in-memory workflow tests. Real SQL Server UPDATE OUTPUT has a separate loopback fixture. */
class SmsExternalEventServiceTest extends TestCase
{
    private const ID='12345678-1234-4234-8234-123456789abc';
    private Container $previous;
    private $db;
    private string $root;
    private array $members;
    private array $plan;
    private SmsExternalEventService $writer;

    protected function setUp(): void
    {
        $this->previous=Container::getInstance(); $container=new Container; Container::setInstance($container);
        $this->root=str_replace('\\','/',sys_get_temp_dir()).'/sms-external-writer-test-'.bin2hex(random_bytes(8)); mkdir($this->root,0700);
        $container->instance('config',new Repository(['app'=>['env'=>'testing'],'database'=>['default'=>'sqlsrv'],
            'sms-exports'=>['local_root'=>$this->root,'record_workflow_enabled'=>true,'durable_availability_enabled'=>true,'external_event_writer_enabled'=>true]]));
        $capsule=new Manager($container); $capsule->addConnection($this->connectionConfig(),'sqlsrv');
        $capsule->getDatabaseManager()->setDefaultConnection('sqlsrv'); $container->instance('db',$capsule->getDatabaseManager());
        Facade::clearResolvedInstances(); Facade::setFacadeApplication($container); $this->db=$capsule->getConnection('sqlsrv');
        $this->db->setTransactionManager(new DatabaseTransactionsManager);
        $this->db->getSchemaBuilder()->create('TblPhoneNumbers',fn (Blueprint $t)=>$t->string('Phone'));
        foreach (['2026_10_12_000001_create_sms_prospects_tables.php','2026_10_19_000001_complete_sms_workflow.php',
            '2026_10_20_000001_create_sms_availability_summary.php','2026_10_20_000003_add_sms_record_approval.php',
            '2026_10_22_000001_add_sms_run_file_options.php','2026_10_24_000001_create_sms_external_events.php'] as $file) {
            (require dirname(__DIR__,2).'/database/sms-migrations/'.$file)->up();
        }
        $refresh=\Mockery::mock(SmsDurableAvailabilityService::class); $refresh->shouldReceive('request')->andReturn(['state'=>'pending']);
        $container->instance(SmsDurableAvailabilityService::class,$refresh); $container->instance(SmsAvailabilityLedger::class,new SmsAvailabilityLedger);
        $this->members=[]; $parts=[]; $root=SmsExternalArtifact::root((object)['Request_ID'=>self::ID]); mkdir($root,0700,true);
        if ($this->db->getDriverName()==='sqlsrv') $this->db->unprepared('SET IDENTITY_INSERT dbo.TblProspects ON');
        foreach ([1,4,9] as $index=>$pk) {
            $part=$index+1; $phone='000555010'.$part; $address='Fixture '.$part;
            $this->db->table('TblProspects')->insert(['PK'=>$pk,'Phone'=>$phone,'Client'=>'Fixture Person','Address'=>$address,
                'SMS_Attempts'=>$index===1?5:0,'Last_SMS_Date'=>$index===1?'2026-10-23':null,
                'Latest_Export_Date'=>$index===1?'2026-10-24':null,'Contacted'=>$index===0?1:0,'Invalid_Phone'=>$index===1?1:0,'Opt_Out'=>$index===2?1:0]);
            $raw=['Prospect_PK'=>$pk,'Original_Phone'=>$phone,'Original_First_Name'=>'Fixture','Original_Address'=>$address,
                'Original_Debt_Text'=>'13000','Original_Send_Date'=>'2026-10-21','Part_Number'=>$part,'Part_Row'=>1,
                'Expected_Attempts'=>$index===1?5:0,'Expected_Last_SMS_Date'=>$index===1?'2026-10-23':null,'Expected_Latest_Export_Date'=>$index===1?'2026-10-24':null];
            $this->members[]=$raw;
            $name='SMS_Data_Part'.$part.'.csv'; $path=$root.'/'.$name; $stream=fopen($path,'xb');
            fputcsv($stream,SmsExternalArtifact::HEADER,',','"',''); fputcsv($stream,['Fixture',$address,'13000',$phone,'','','','','2026-10-21'],',','"',''); fclose($stream);
            $parts[]=['part'=>$part,'name'=>$name,'rows'=>1,'bytes'=>filesize($path),'sha256'=>hash_file('sha256',$path),'private_relative_path'=>$name];
        }
        $this->db->table('TblProspects')->insert(['PK'=>77,'Phone'=>'0005550101','Client'=>'Unselected Person','Address'=>'Other Address',
            'SMS_Attempts'=>9,'Last_SMS_Date'=>'2026-10-22','Latest_Export_Date'=>'2026-10-22']);
        if ($this->db->getDriverName()==='sqlsrv') $this->db->unprepared('SET IDENTITY_INSERT dbo.TblProspects OFF');
        $download=SmsExternalArtifact::buildOriginalZip($root,$parts,'Historical originals.zip');
        $manifest=['version'=>1,'csv_format'=>SmsExternalArtifact::FORMAT,'header'=>SmsExternalArtifact::HEADER,'parts'=>$parts,
            'pk_list'=>['name'=>'selected_TblProspects_PKs.csv','rows'=>3,'bytes'=>1,'sha256'=>str_repeat('a',64),'private_relative_path'=>'selected_TblProspects_PKs.csv'],'download'=>$download];
        $this->plan=['request_id'=>self::ID,'event_key'=>SmsExternalEventSeal::EVENT,'occurred_at'=>SmsExternalEventSeal::OCCURRED,
            'business_date'=>SmsExternalEventSeal::DAY,'expected_rows'=>3,'plan_sha256'=>str_repeat('a',64),
            'membership_sha256'=>SmsExternalEventSeal::membership(array_map([SmsExternalEventSeal::class,'member'],$this->members)),
            'before_state_sha256'=>SmsExternalEventSeal::membership(array_map([SmsExternalEventSeal::class,'member'],$this->members),'Before_SHA256'),
            'preview_sha256'=>str_repeat('b',64),'registered_by'=>'operator@example.invalid','original_artifacts_json'=>json_encode($manifest,JSON_THROW_ON_ERROR),
            'file_path'=>$root.'/'.$download['name'],'file_bytes'=>$download['bytes'],'file_sha256'=>$download['sha256']];
        $this->writer=new SmsExternalEventService;
    }

    protected function connectionConfig(): array
    {
        return ['driver'=>'sqlite','database'=>':memory:'];
    }

    protected function tearDown(): void
    {
        $this->db->disconnect(); \Mockery::close();
        self::assertMatchesRegularExpression('~/sms-external-writer-test-[a-f0-9]{16}$~D',$this->root);
        $files=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) { if ($file->isDir()&&!$file->isLink()) rmdir($file->getPathname()); else unlink($file->getPathname()); }
        rmdir($this->root); Facade::clearResolvedInstances(); Container::setInstance($this->previous); Facade::setFacadeApplication($this->previous);
    }

    private function staged(): void
    {
        $this->writer->begin($this->plan); $this->writer->stage(self::ID,$this->plan['plan_sha256'],$this->members);
        // Exact staging replay must recover identically across PDO decimal representations.
        $this->writer->stage(self::ID,$this->plan['plan_sha256'],$this->members);
        $this->writer->seal(self::ID,$this->plan['plan_sha256']);
    }

    public function test_registration_preserves_unselected_same_phone_and_later_dates_then_replay_adds_nothing(): void
    {
        $before=$this->db->table('TblProspects')->orderBy('PK')->get()->keyBy('PK'); $this->staged();
        self::assertSame('external_registering',$this->db->table('TblSMSRuns')->first()->Status);
        self::assertTrue(SmsExternalEventService::pending());
        self::assertSame(3,$this->writer->applyNext(self::ID,$this->plan['plan_sha256'],null)['updated']);
        $this->writer->finish(self::ID,$this->plan['plan_sha256']);
        self::assertFalse(SmsExternalEventService::pending());
        $after=$this->db->table('TblProspects')->orderBy('PK')->get()->keyBy('PK');
        self::assertEquals($before[77],$after[77]);
        foreach ([1,4,9] as $pk) {
            self::assertSame((int)$before[$pk]->SMS_Attempts+1,(int)$after[$pk]->SMS_Attempts);
            self::assertSame(SmsExternalEventSeal::later($before[$pk]->Last_SMS_Date,'2026-10-21'),$after[$pk]->Last_SMS_Date);
            self::assertSame(SmsExternalEventSeal::later($before[$pk]->Latest_Export_Date,'2026-10-21'),$after[$pk]->Latest_Export_Date);
            foreach (['Contacted','Contacted_Date','Response_Date','Opt_Out','Invalid_Phone','Client','Address','Phone'] as $key) self::assertSame($before[$pk]->$key,$after[$pk]->$key);
        }
        $history=$this->db->table('TblSMSHistory')->get(); self::assertCount(3,$history);
        foreach ($history as $row) foreach (['Debt_Tier','Debt_Source','Debt_Source_PK','Client','City','State','Zip','Email','Was_Previously_Texted'] as $key) self::assertNull($row->$key);
        $saved=$after->toJson(); $revision=$this->db->table('TblSMSAvailabilitySummary')->orderBy('scope')->get()->toJson();
        self::assertSame('completed',$this->writer->begin($this->plan)->State);
        self::assertSame(0,$this->writer->applyNext(self::ID,$this->plan['plan_sha256'],9)['updated']);
        self::assertSame('already_completed',$this->writer->finish(self::ID,$this->plan['plan_sha256'])['outcome']);
        self::assertSame($saved,$this->db->table('TblProspects')->orderBy('PK')->get()->keyBy('PK')->toJson());
        self::assertSame($revision,$this->db->table('TblSMSAvailabilitySummary')->orderBy('scope')->get()->toJson());
        self::assertSame(3,$this->db->table('TblSMSHistory')->count());
        config(['sms-exports.external_event_writer_enabled'=>false]); self::assertTrue($this->writer->verify(self::ID,$this->plan['plan_sha256'])['verified']);
    }

    public function test_changed_before_state_rolls_back_whole_chunk_and_can_resume_from_the_same_receipt(): void
    {
        $this->staged(); $this->db->table('TblProspects')->where('PK',4)->update(['SMS_Attempts'=>6]);
        try { $this->writer->applyNext(self::ID,$this->plan['plan_sha256'],null); self::fail('Changed preflight must hold.'); } catch (RuntimeException) {}
        self::assertSame(0,$this->db->table('TblSMSHistory')->count()); self::assertSame(0,$this->db->table('TblSMSExternalChunks')->count());
        self::assertSame(0,(int)$this->db->table('TblProspects')->where('PK',1)->value('SMS_Attempts'));
        $this->db->table('TblProspects')->where('PK',4)->update(['SMS_Attempts'=>5]);
        self::assertSame(3,$this->writer->applyNext(self::ID,$this->plan['plan_sha256'],null)['updated']);
    }

    public function test_duplicate_event_under_new_uuid_and_changed_staging_are_rejected(): void
    {
        $this->writer->begin($this->plan); $changed=$this->plan; $changed['request_id']='22345678-1234-4234-8234-123456789abc';
        try { $this->writer->begin($changed); self::fail('Business event key cannot be bypassed.'); } catch (RuntimeException) {}
        $this->writer->stage(self::ID,$this->plan['plan_sha256'],$this->members);
        $changed=$this->members; $changed[0]['Original_Address']='Different';
        try { $this->writer->stage(self::ID,$this->plan['plan_sha256'],$changed); self::fail('Stage replay may not replace evidence.'); } catch (RuntimeException) {}
        self::assertSame(3,$this->db->table('TblSMSExternalMembers')->count()); self::assertSame(1,$this->db->table('TblSMSRuns')->count());
    }

    public function test_history_insert_failure_rolls_back_attempts_audits_and_summary_revision(): void
    {
        $this->staged(); $before=$this->db->table('TblProspects')->orderBy('PK')->get()->toJson();
        $this->db->unprepared($this->db->getDriverName()==='sqlsrv'
            ? 'ALTER TABLE dbo.TblSMSHistory ADD CONSTRAINT synthetic_fail_history CHECK (Prospect_PK<>4)'
            : "CREATE TRIGGER synthetic_fail_history BEFORE INSERT ON TblSMSHistory BEGIN SELECT RAISE(ABORT,'synthetic_fail_history'); END");
        try { $this->writer->applyNext(self::ID,$this->plan['plan_sha256'],null); self::fail('Injected failure must roll back.'); } catch (\Throwable $error) { self::assertStringContainsString('synthetic_fail_history',$error->getMessage()); }
        self::assertSame($before,$this->db->table('TblProspects')->orderBy('PK')->get()->toJson());
        self::assertSame(0,$this->db->table('TblSMSExternalMembers')->whereNotNull('Applied_Chunk')->count());
        self::assertSame(0,$this->db->table('TblSMSExternalChunks')->count()); self::assertSame(0,(int)$this->writer->status(self::ID)->Applied_Rows);
        self::assertSame(0,$this->db->table('TblSMSAvailabilitySummary')->sum('revision'));
    }

    public function test_derived_debt_and_before_proof_corruption_are_rejected_before_any_attempt_update(): void
    {
        $this->writer->begin($this->plan); $this->writer->stage(self::ID,$this->plan['plan_sha256'],$this->members);
        foreach ([['Original_Debt_Amount'=>14000],['Original_Debt_Text'=>'14000'],['Expected_Attempts'=>1],
            ['Expected_Attempts'=>1,'Before_SHA256'=>hash('sha256',SmsExternalEventSeal::json([1,1,null,null]))]] as $corruption) {
            $this->db->table('TblSMSExternalMembers')->where('Prospect_PK',1)->update($corruption);
            $caught=false;
            try { $this->writer->seal(self::ID,$this->plan['plan_sha256']); } catch (\Throwable) { $caught=true; }
            self::assertTrue($caught,'A changed stored field or before-proof must prevent sealing.');
            self::assertSame(0,$this->db->table('TblSMSHistory')->count());
            self::assertSame(0,(int)$this->db->table('TblProspects')->where('PK',1)->value('SMS_Attempts'));
            $original=SmsExternalEventSeal::member($this->members[0]);
            $this->db->table('TblSMSExternalMembers')->where('Prospect_PK',1)->update($original);
        }
    }

    public function test_verification_detects_case_whitespace_and_raw_original_corruption(): void
    {
        $this->staged(); $this->writer->applyNext(self::ID,$this->plan['plan_sha256'],null); $this->writer->finish(self::ID,$this->plan['plan_sha256']);
        self::assertTrue($this->writer->verify(self::ID,$this->plan['plan_sha256'])['verified']);
        foreach (['fixture 1','Fixture 1 '] as $bad) {
            $this->db->table('TblSMSHistory')->where('Prospect_PK',1)->update(['Address'=>$bad]); $caught=false;
            try { $this->writer->verify(self::ID,$this->plan['plan_sha256']); } catch (\Throwable) { $caught=true; }
            self::assertTrue($caught,'Immutable original address bytes must match even under a CI collation.');
        }
        $this->db->table('TblSMSHistory')->where('Prospect_PK',1)->update(['Address'=>'Fixture 1']);
        $this->db->table('TblSMSExternalMembers')->where('Prospect_PK',1)->update(['Original_Debt_Text'=>'different']); $caught=false;
        try { $this->writer->verify(self::ID,$this->plan['plan_sha256']); } catch (\Throwable) { $caught=true; }
        self::assertTrue($caught);
    }

    public function test_committed_chunk_survives_process_recovery_and_explicit_hold_resume(): void
    {
        $root=SmsExternalArtifact::root((object)['Request_ID'=>self::ID]); $stream=fopen($root.'/SMS_Data_Part3.csv','ab'); $new=[];
        foreach (range(100,1097) as $offset=>$pk) {
            $phone=str_pad((string)$pk,10,'0',STR_PAD_LEFT); $address='Extra '.$pk;
            $new[]=['PK'=>$pk,'Phone'=>$phone,'Client'=>'Fixture Person','Address'=>$address];
            $this->members[]=['Prospect_PK'=>$pk,'Original_Phone'=>$phone,'Original_First_Name'=>'Fixture','Original_Address'=>$address,
                'Original_Debt_Text'=>'13000','Original_Send_Date'=>'2026-10-21','Part_Number'=>3,'Part_Row'=>$offset+2,
                'Expected_Attempts'=>0,'Expected_Last_SMS_Date'=>null,'Expected_Latest_Export_Date'=>null];
            fputcsv($stream,['Fixture',$address,'13000',$phone,'','','','','2026-10-21'],',','"','');
        }
        fclose($stream);
        if ($this->db->getDriverName()==='sqlsrv') $this->db->unprepared('SET IDENTITY_INSERT dbo.TblProspects ON');
        foreach (array_chunk($new,100) as $rows) $this->db->table('TblProspects')->insert($rows);
        if ($this->db->getDriverName()==='sqlsrv') $this->db->unprepared('SET IDENTITY_INSERT dbo.TblProspects OFF');
        clearstatcache(); $manifest=json_decode($this->plan['original_artifacts_json'],true);
        $manifest['parts'][2]['rows']=999; $manifest['parts'][2]['bytes']=filesize($root.'/SMS_Data_Part3.csv');
        $manifest['parts'][2]['sha256']=hash_file('sha256',$root.'/SMS_Data_Part3.csv');
        $download=SmsExternalArtifact::buildOriginalZip($root,$manifest['parts'],'Historical large originals.zip');
        $manifest['download']=$download; $manifest['pk_list']['rows']=1001;
        $this->plan['expected_rows']=1001; $this->plan['original_artifacts_json']=json_encode($manifest,JSON_THROW_ON_ERROR);
        $this->plan['file_path']=$root.'/'.$download['name']; $this->plan['file_bytes']=$download['bytes']; $this->plan['file_sha256']=$download['sha256'];
        $this->plan['membership_sha256']=SmsExternalEventSeal::membership(array_map([SmsExternalEventSeal::class,'member'],$this->members));
        $this->plan['before_state_sha256']=SmsExternalEventSeal::membership(array_map([SmsExternalEventSeal::class,'member'],$this->members),'Before_SHA256');
        $this->writer->begin($this->plan);
        foreach (array_chunk($this->members,1000) as $rows) $this->writer->stage(self::ID,$this->plan['plan_sha256'],$rows);
        $this->writer->seal(self::ID,$this->plan['plan_sha256']);
        $first=$this->writer->applyNext(self::ID,$this->plan['plan_sha256'],null); self::assertSame(1000,$first['updated']);
        $this->writer=new SmsExternalEventService; $caught=false;
        try { $this->writer->applyNext(self::ID,$this->plan['plan_sha256'],null); } catch (RuntimeException) { $caught=true; }
        self::assertTrue($caught,'An uncertain old cursor must inspect the committed receipt instead of incrementing again.');
        self::assertSame('held',$this->writer->hold(self::ID,$this->plan['plan_sha256'],'synthetic_pause')->State);
        self::assertTrue(SmsExternalEventService::pending());
        $committed=$this->db->table('TblSMSHistory')->where('Prospect_PK',1)->first();
        $this->db->table('TblSMSHistory')->where('PK',$committed->PK)->update(['Prospect_PK'=>1097,'Phone'=>'0000001097','Address'=>'Extra 1097']);
        $caught=false; try { $this->writer->resume(self::ID,$this->plan['plan_sha256']); } catch (\Throwable) { $caught=true; }
        self::assertTrue($caught,'A committed-prefix history row cannot move to an unapplied member.');
        self::assertSame('held',$this->writer->status(self::ID)->State);
        self::assertSame(0,(int)$this->db->table('TblProspects')->where('PK',1097)->value('SMS_Attempts'));
        $this->db->table('TblSMSHistory')->where('PK',$committed->PK)->update(['Prospect_PK'=>1,'Phone'=>$committed->Phone,'Address'=>$committed->Address]);
        self::assertSame('registering',$this->writer->resume(self::ID,$this->plan['plan_sha256'])->State);
        self::assertSame(1,$this->writer->applyNext(self::ID,$this->plan['plan_sha256'],$first['after_pk'])['updated']);
        self::assertSame('completed',$this->writer->finish(self::ID,$this->plan['plan_sha256'])['outcome']);
        self::assertSame(1001,$this->db->table('TblSMSHistory')->count()); self::assertSame(2,$this->db->table('TblSMSExternalChunks')->count());
        self::assertSame(1,(int)$this->db->table('TblProspects')->where('PK',1)->value('SMS_Attempts'));
        self::assertSame(1,(int)$this->db->table('TblProspects')->where('PK',1097)->value('SMS_Attempts'));
        self::assertSame(9,(int)$this->db->table('TblProspects')->where('PK',77)->value('SMS_Attempts'));
    }
}
